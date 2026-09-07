<?php
/**
 * Kas & Bank: cash/bank accounts, transfers between them (setoran petugas,
 * setoran ke bank), balance adjustments, and a unified ledger.
 *
 * Money flows:
 *   payments  -> IN  to payments.account_id, or, when NULL, to the wallet of
 *                the user who received it (received_by), or the default account.
 *   expenses  -> OUT from expenses.account_id, or the default account when NULL.
 *   transfers -> OUT from from_account_id / IN to to_account_id
 *                (type 'adjustment' touches one account only, amount may be negative).
 *
 * Every field collector gets a wallet account (owner_user_id) so cash they hold
 * is visible until deposited to the office ("setoran"). Partner (mitra) money is
 * never company cash: only the collective invoices billed to the POP count.
 */

function cash_accounts_ensure(PDO $db, int $tenant_id): void {
    try {
        $n = $db->prepare("SELECT COUNT(*) FROM cash_accounts WHERE tenant_id = ?");
        $n->execute([$tenant_id]);
        if ((int)$n->fetchColumn() === 0) {
            // First run: the tenant's main cash account inherits the opening balance from settings.
            $s = $db->prepare("SELECT fin_opening_cash, fin_opening_date FROM settings WHERE tenant_id = ?");
            $s->execute([$tenant_id]);
            $s = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            $admin = $db->prepare("SELECT id FROM users WHERE role = 'admin' AND (tenant_id = ? OR id = ?) ORDER BY id LIMIT 1");
            $admin->execute([$tenant_id, $tenant_id]);
            $admin_id = (int)$admin->fetchColumn();
            $db->prepare("INSERT INTO cash_accounts (tenant_id, name, type, owner_user_id, account_number, opening_balance, opening_date, is_default, is_active, created_at) VALUES (?, 'Kas utama', 'cash', ?, '', ?, ?, 1, 1, ?)")
               ->execute([$tenant_id, $admin_id ?: null, (float)($s['fin_opening_cash'] ?? 0), $s['fin_opening_date'] ?: null, date('Y-m-d H:i:s')]);
        }
        // A wallet for every field collector: money they receive is company money
        // until deposited. Partners are NOT company cash (see cash_company_scope).
        $users = $db->prepare("SELECT u.id, u.name FROM users u WHERE u.tenant_id = ? AND u.role = 'collector'
            AND NOT EXISTS (SELECT 1 FROM cash_accounts a WHERE a.owner_user_id = u.id AND a.tenant_id = ?)");
        $users->execute([$tenant_id, $tenant_id]);
        $ins = $db->prepare("INSERT INTO cash_accounts (tenant_id, name, type, owner_user_id, account_number, opening_balance, opening_date, is_default, is_active, created_at) VALUES (?, ?, 'wallet', ?, '', 0, NULL, 0, 1, ?)");
        foreach ($users->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $ins->execute([$tenant_id, 'Kas petugas ' . $u['name'], $u['id'], date('Y-m-d H:i:s')]);
        }
        // Office cash + bank account exist from the start: admin picks one when recording a payment.
        $db->prepare("UPDATE cash_accounts SET name = 'Kas kantor' WHERE tenant_id = ? AND name = 'Kas utama'")->execute([$tenant_id]);
        $bank = $db->prepare("SELECT COUNT(*) FROM cash_accounts WHERE tenant_id = ? AND type = 'bank'"); $bank->execute([$tenant_id]);
        if ((int)$bank->fetchColumn() === 0) {
            $s = $db->prepare("SELECT bank_account FROM settings WHERE tenant_id = ?"); $s->execute([$tenant_id]);
            $line = trim(strtok((string)$s->fetchColumn(), "\n"));
            $name = 'Rekening bank'; $number = '';
            if ($line !== '' && preg_match('/^([A-Za-z .]+?)\s*[:\-]\s*([0-9 ]{6,})/', $line, $m)) { $name = 'Rekening ' . trim($m[1]); $number = trim($m[2]); }
            $db->prepare("INSERT INTO cash_accounts (tenant_id, name, type, owner_user_id, account_number, opening_balance, opening_date, is_default, is_active, created_at) VALUES (?, ?, 'bank', NULL, ?, 0, NULL, 0, 1, ?)")
               ->execute([$tenant_id, $name, $number, date('Y-m-d H:i:s')]);
        }
        // Remove wallets that belong to partner accounts and were never used explicitly.
        $db->prepare("DELETE FROM cash_accounts WHERE tenant_id = ? AND owner_user_id IN (SELECT id FROM users WHERE role = 'partner')
            AND id NOT IN (SELECT COALESCE(account_id,0) FROM payments) AND id NOT IN (SELECT COALESCE(account_id,0) FROM expenses)
            AND id NOT IN (SELECT COALESCE(from_account_id,0) FROM cash_transfers) AND id NOT IN (SELECT COALESCE(to_account_id,0) FROM cash_transfers)")->execute([$tenant_id]);
    } catch (Exception $e) {}
}

/**
 * Company-money scope. Partners (mitra) run their own books: what their own
 * customers pay them is not company cash, and their expenses are not company
 * expenses. The company only sees the collective invoices billed to the POP.
 * Returns SQL fragments for a customers alias c and an expenses alias e.
 */
function cash_company_scope(PDO $db, int $tenant_id): array {
    static $cache = [];
    if (!isset($cache[$tenant_id])) {
        $ids = $db->query("SELECT id FROM users WHERE role = 'partner' AND tenant_id = $tenant_id")->fetchAll(PDO::FETCH_COLUMN);
        $list = $ids ? implode(',', array_map('intval', $ids)) : '0';
        $cache[$tenant_id] = [
            'c' => " AND (c.created_by NOT IN ($list) OR c.created_by = 0 OR c.created_by IS NULL)",
            'e' => " AND (e.created_by NOT IN ($list) OR e.created_by = 0 OR e.created_by IS NULL)",
        ];
    }
    return $cache[$tenant_id];
}

function cash_default_account_id(PDO $db, int $tenant_id): int {
    $q = $db->prepare("SELECT id FROM cash_accounts WHERE tenant_id = ? ORDER BY is_default DESC, id ASC LIMIT 1");
    $q->execute([$tenant_id]);
    return (int)$q->fetchColumn();
}

/** SQL expression resolving the account a payment row (alias p) belongs to. */
function cash_payment_account_sql(int $tenant_id, int $default_id): string {
    return "COALESCE(p.account_id, (SELECT a.id FROM cash_accounts a WHERE a.owner_user_id = p.received_by AND a.tenant_id = $tenant_id LIMIT 1), $default_id)";
}

/** Accounts with balances as of $as_of (inclusive date, Y-m-d). */
function cash_accounts_with_balances(PDO $db, int $tenant_id, ?string $as_of = null): array {
    $as_of = $as_of ?: date('Y-m-d');
    $to_dt = $as_of . ' 23:59:59';
    $def = cash_default_account_id($db, $tenant_id);
    $pacc = cash_payment_account_sql($tenant_id, $def);
    $sc = cash_company_scope($db, $tenant_id);
    $q = $db->prepare("SELECT a.*, u.name AS owner_name, u.role AS owner_role,
            COALESCE((SELECT SUM(p.amount) FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN customers c ON c.id = i.customer_id
                      WHERE p.tenant_id = :t AND p.payment_date <= :to_dt AND $pacc = a.id {$sc['c']}), 0) AS in_payments,
            COALESCE((SELECT SUM(e.amount) FROM expenses e WHERE e.tenant_id = :t AND e.date <= :to_d AND COALESCE(e.account_id, :def) = a.id {$sc['e']}), 0) AS out_expenses,
            COALESCE((SELECT SUM(x.amount) FROM cash_transfers x WHERE x.tenant_id = :t AND x.date <= :to_d AND x.to_account_id = a.id), 0) AS in_transfers,
            COALESCE((SELECT SUM(x.amount) FROM cash_transfers x WHERE x.tenant_id = :t AND x.date <= :to_d AND x.from_account_id = a.id), 0) AS out_transfers
        FROM cash_accounts a LEFT JOIN users u ON u.id = a.owner_user_id
        WHERE a.tenant_id = :t ORDER BY a.is_default DESC, a.type ASC, a.name ASC");
    $q->execute([':t' => $tenant_id, ':to_dt' => $to_dt, ':to_d' => $as_of, ':def' => $def]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $opening = (!empty($r['opening_date']) && $r['opening_date'] > $as_of) ? 0 : (float)$r['opening_balance'];
        $r['balance'] = $opening + $r['in_payments'] - $r['out_expenses'] + $r['in_transfers'] - $r['out_transfers'];
    }
    return $rows;
}

function cash_total_balance(PDO $db, int $tenant_id, ?string $as_of = null): float {
    $t = 0; foreach (cash_accounts_with_balances($db, $tenant_id, $as_of) as $a) if ($a['is_active']) $t += $a['balance'];
    return $t;
}

function cash_account_save(PDO $db, int $tenant_id, int $id, array $d): string {
    $name = trim(preg_replace('/\s+/', ' ', (string)($d['name'] ?? '')));
    $type = in_array($d['type'] ?? '', ['cash', 'bank', 'ewallet', 'wallet']) ? $d['type'] : 'cash';
    if ($name === '') return 'Nama akun tidak boleh kosong.';
    $opening = (float)preg_replace('/[^0-9.\-]/', '', (string)($d['opening_balance'] ?? '0'));
    $opening_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['opening_date'] ?? '') ? $d['opening_date'] : null;
    $owner = intval($d['owner_user_id'] ?? 0) ?: null;
    $is_default = !empty($d['is_default']) ? 1 : 0;
    $is_active = isset($d['is_active']) ? (int)!!$d['is_active'] : 1;
    try {
        if ($is_default) $db->prepare("UPDATE cash_accounts SET is_default = 0 WHERE tenant_id = ?")->execute([$tenant_id]);
        if ($id > 0) {
            $db->prepare("UPDATE cash_accounts SET name = ?, type = ?, owner_user_id = ?, account_number = ?, opening_balance = ?, opening_date = ?, is_default = CASE WHEN ? = 1 THEN 1 ELSE is_default END, is_active = ? WHERE id = ? AND tenant_id = ?")
               ->execute([$name, $type, $owner, trim((string)($d['account_number'] ?? '')), $opening, $opening_date, $is_default, $is_active, $id, $tenant_id]);
        } else {
            $db->prepare("INSERT INTO cash_accounts (tenant_id, name, type, owner_user_id, account_number, opening_balance, opening_date, is_default, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)")
               ->execute([$tenant_id, $name, $type, $owner, trim((string)($d['account_number'] ?? '')), $opening, $opening_date, $is_default, date('Y-m-d H:i:s')]);
        }
        // Always keep exactly one default.
        $has = $db->prepare("SELECT COUNT(*) FROM cash_accounts WHERE tenant_id = ? AND is_default = 1"); $has->execute([$tenant_id]);
        if ((int)$has->fetchColumn() === 0) $db->prepare("UPDATE cash_accounts SET is_default = 1 WHERE id = (SELECT MIN(id) FROM cash_accounts WHERE tenant_id = ?)")->execute([$tenant_id]);
        return '';
    } catch (Exception $e) { return 'Gagal menyimpan akun.'; }
}

function cash_account_delete(PDO $db, int $tenant_id, int $id): string {
    try {
        $a = $db->prepare("SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ?"); $a->execute([$id, $tenant_id]);
        $acc = $a->fetch(PDO::FETCH_ASSOC);
        if (!$acc) return 'Akun tidak ditemukan.';
        if ($acc['is_default']) return 'Akun utama tidak bisa dihapus. Jadikan akun lain sebagai utama dulu.';
        if ($acc['owner_user_id']) return 'Dompet petugas tidak bisa dihapus; nonaktifkan saja.';
        $used = $db->prepare("SELECT (SELECT COUNT(*) FROM payments WHERE account_id = :id) + (SELECT COUNT(*) FROM expenses WHERE account_id = :id) + (SELECT COUNT(*) FROM cash_transfers WHERE from_account_id = :id OR to_account_id = :id)");
        $used->execute([':id' => $id]);
        if ((int)$used->fetchColumn() > 0) return 'Akun masih punya mutasi. Nonaktifkan saja agar riwayat tetap utuh.';
        $db->prepare("DELETE FROM cash_accounts WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
        return '';
    } catch (Exception $e) { return 'Gagal menghapus akun.'; }
}

/** Record a transfer (setoran / pindah dana) or a balance adjustment. Returns error or ''. */
function cash_transfer_save(PDO $db, int $tenant_id, int $user_id, array $d): string {
    $type = ($d['type'] ?? 'transfer') === 'adjustment' ? 'adjustment' : 'transfer';
    $amount = (float)preg_replace('/[^0-9.\-]/', '', (string)($d['amount'] ?? '0'));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date'] ?? '') ? $d['date'] : date('Y-m-d');
    $note = trim((string)($d['note'] ?? ''));
    $from = intval($d['from_account_id'] ?? 0); $to = intval($d['to_account_id'] ?? 0);
    $valid = function (int $id) use ($db, $tenant_id): bool { $q = $db->prepare("SELECT COUNT(*) FROM cash_accounts WHERE id = ? AND tenant_id = ?"); $q->execute([$id, $tenant_id]); return (int)$q->fetchColumn() > 0; };
    if ($type === 'transfer') {
        if ($amount <= 0) return 'Jumlah harus lebih dari nol.';
        if (!$valid($from) || !$valid($to)) return 'Akun asal atau tujuan tidak valid.';
        if ($from === $to) return 'Akun asal dan tujuan harus berbeda.';
    } else {
        if ($amount == 0) return 'Jumlah penyesuaian tidak boleh nol.';
        $acc = intval($d['account_id'] ?? 0);
        if (!$valid($acc)) return 'Akun tidak valid.';
        // positive adjustment = money in (to), negative = money out (from)
        if ($amount > 0) { $to = $acc; $from = null; } else { $from = $acc; $to = null; $amount = abs($amount); }
        if ($note === '') $note = 'Penyesuaian saldo';
    }
    try {
        $db->prepare("INSERT INTO cash_transfers (tenant_id, type, from_account_id, to_account_id, amount, date, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$tenant_id, $type, $from, $to, $amount, $date, $note, $user_id, date('Y-m-d H:i:s')]);
        return '';
    } catch (Exception $e) { return 'Gagal menyimpan transaksi.'; }
}

function cash_transfer_delete(PDO $db, int $tenant_id, int $id): void {
    try { $db->prepare("DELETE FROM cash_transfers WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]); } catch (Exception $e) {}
}

/** Unified ledger rows for a period, optionally one account. Newest first. */
function cash_ledger(PDO $db, int $tenant_id, string $date_from, string $date_to, int $account_id = 0, int $limit = 300): array {
    $def = cash_default_account_id($db, $tenant_id);
    $pacc = cash_payment_account_sql($tenant_id, $def);
    $sc = cash_company_scope($db, $tenant_id);
    $acc_p = ($account_id ? " AND $pacc = :acc" : '') . $sc['c'];
    $acc_e = ($account_id ? " AND COALESCE(e.account_id, $def) = :acc" : '') . $sc['e'];
    $acc_x = $account_id ? " AND (x.from_account_id = :acc OR x.to_account_id = :acc)" : '';
    $sql = "SELECT * FROM (
        SELECT 'payment' AS kind, p.id, p.payment_date AS at, p.amount AS amount, $pacc AS account_id, NULL AS counter_account_id,
               ('Pembayaran ' || COALESCE(c.name,'') || ' (INV-' || printf('%05d', i.id) || ')') AS label, u.name AS who, p.account_id AS explicit_account
        FROM payments p JOIN invoices i ON i.id = p.invoice_id LEFT JOIN customers c ON c.id = i.customer_id LEFT JOIN users u ON u.id = p.received_by
        WHERE p.tenant_id = :t AND p.payment_date BETWEEN :from_dt AND :to_dt $acc_p
        UNION ALL
        SELECT 'expense', e.id, e.date || ' 00:00:00', -e.amount, COALESCE(e.account_id, $def), NULL,
               ('Pengeluaran: ' || COALESCE(NULLIF(e.category,''),'Lain-lain') || CASE WHEN e.description <> '' THEN ' - ' || e.description ELSE '' END), u.name, e.account_id
        FROM expenses e LEFT JOIN users u ON u.id = e.created_by
        WHERE e.tenant_id = :t AND e.date BETWEEN :from_d AND :to_d $acc_e
        UNION ALL
        SELECT CASE WHEN x.type = 'adjustment' THEN 'adjustment' ELSE 'transfer' END, x.id, x.date || ' 00:00:00', x.amount, x.from_account_id, x.to_account_id,
               CASE WHEN x.type = 'adjustment' THEN 'Penyesuaian' ELSE 'Transfer' END || CASE WHEN x.note <> '' THEN ': ' || x.note ELSE '' END, u.name, NULL
        FROM cash_transfers x LEFT JOIN users u ON u.id = x.created_by
        WHERE x.tenant_id = :t AND x.date BETWEEN :from_d AND :to_d $acc_x
    ) ORDER BY at DESC, id DESC LIMIT $limit";
    $q = $db->prepare($sql);
    $params = [':t' => $tenant_id, ':from_dt' => $date_from . ' 00:00:00', ':to_dt' => $date_to . ' 23:59:59', ':from_d' => $date_from, ':to_d' => $date_to];
    if ($account_id) $params[':acc'] = $account_id;
    $q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Admin moves a payment or expense to another account. */
function cash_reassign(PDO $db, int $tenant_id, string $kind, int $id, int $account_id): string {
    $q = $db->prepare("SELECT COUNT(*) FROM cash_accounts WHERE id = ? AND tenant_id = ?"); $q->execute([$account_id, $tenant_id]);
    if ((int)$q->fetchColumn() === 0) return 'Akun tidak valid.';
    try {
        if ($kind === 'payment') $db->prepare("UPDATE payments SET account_id = ? WHERE id = ? AND tenant_id = ?")->execute([$account_id, $id, $tenant_id]);
        elseif ($kind === 'expense') $db->prepare("UPDATE expenses SET account_id = ? WHERE id = ? AND tenant_id = ?")->execute([$account_id, $id, $tenant_id]);
        else return 'Jenis transaksi tidak dikenal.';
        return '';
    } catch (Exception $e) { return 'Gagal memindahkan transaksi.'; }
}

/** Active office accounts (cash, bank, e-wallet) an admin can receive money into. */
function cash_company_accounts(PDO $db, int $tenant_id): array {
    try {
        $q = $db->prepare("SELECT id, name, type, is_default FROM cash_accounts WHERE tenant_id = ? AND is_active = 1 AND owner_user_id IS NULL OR (tenant_id = ? AND is_active = 1 AND owner_user_id IN (SELECT id FROM users WHERE role = 'admin')) ORDER BY is_default DESC, CASE type WHEN 'cash' THEN 0 WHEN 'bank' THEN 1 ELSE 2 END, name");
        $q->execute([$tenant_id, $tenant_id]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

/**
 * Account chosen on a payment form (admin only). Falls back to the last one
 * used in this session, then to the default account. Returns null for roles
 * whose payments go to their own wallet (collector, partner).
 */
function cash_posted_account(PDO $db, int $tenant_id, ?int $posted = null): ?int {
    if (($_SESSION['user_role'] ?? '') !== 'admin') return null;
    $ids = array_map(fn($a) => intval($a['id']), cash_company_accounts($db, $tenant_id));
    $posted = $posted ?? intval($_POST['account_id'] ?? 0);
    $pick = in_array($posted, $ids, true) ? $posted : (in_array(intval($_SESSION['cash_last_account'] ?? 0), $ids, true) ? intval($_SESSION['cash_last_account']) : ($ids[0] ?? null));
    if ($pick) $_SESSION['cash_last_account'] = $pick;
    return $pick;
}

/** Stamp the account on payments just inserted for an invoice (or one payment id). */
function cash_tag_payment(PDO $db, int $tenant_id, int $payment_id, ?int $account_id): void {
    if (!$account_id || !$payment_id) return;
    try { $db->prepare("UPDATE payments SET account_id = ? WHERE id = ? AND tenant_id = ?")->execute([$account_id, $payment_id, $tenant_id]); } catch (Exception $e) {}
}
