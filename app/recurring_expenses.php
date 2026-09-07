<?php
/**
 * Biaya berulang: monthly expenses (sewa upstream, listrik, gaji, cicilan)
 * that are posted automatically into `expenses` on their due day.
 *
 * Each template generates at most one expense per month (expenses.recurring_id
 * + date month is unique). Generation runs whenever an admin opens the
 * expenses page or dashboard (see recurring_expenses_run), so no cron is needed.
 */

function recurring_expenses_list(PDO $db, int $tenant_id): array {
    try {
        $st = $db->prepare("SELECT r.*, a.name AS account_name,
                (SELECT COUNT(*) FROM expenses e WHERE e.recurring_id = r.id) AS generated_n,
                (SELECT MAX(e.date) FROM expenses e WHERE e.recurring_id = r.id) AS last_date
            FROM recurring_expenses r LEFT JOIN cash_accounts a ON a.id = r.account_id
            WHERE r.tenant_id = ? ORDER BY r.is_active DESC, r.day_of_month ASC, r.name ASC");
        $st->execute([$tenant_id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

/** Due date of a template inside a given month (clamped to the month's last day). */
function recurring_due_date(string $ym, int $day): string {
    $last = (int)date('t', strtotime($ym . '-01'));
    return $ym . '-' . str_pad((string)min(max(1, $day), $last), 2, '0', STR_PAD_LEFT);
}

/** Next due date on or after today, or null when the template has ended. */
function recurring_next_due(array $r, ?string $today = null): ?string {
    $today = $today ?: date('Y-m-d');
    $ym = substr($today, 0, 7);
    $due = recurring_due_date($ym, (int)$r['day_of_month']);
    if ($due < $today || $due < $r['start_date']) {
        $ym = date('Y-m', strtotime($ym . '-01 +1 month'));
        $due = recurring_due_date($ym, (int)$r['day_of_month']);
        if ($due < $r['start_date']) $due = recurring_due_date(substr($r['start_date'], 0, 7), (int)$r['day_of_month']);
    }
    if (!empty($r['end_date']) && $due > $r['end_date']) return null;
    return $due;
}

function recurring_expense_save(PDO $db, int $tenant_id, int $id, array $d): string {
    $name = trim(preg_replace('/\s+/', ' ', (string)($d['name'] ?? '')));
    $category = trim((string)($d['category'] ?? ''));
    $amount = (float)preg_replace('/[^0-9.]/', '', (string)($d['amount'] ?? '0'));
    $day = max(1, min(31, intval($d['day_of_month'] ?? 1)));
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['start_date'] ?? '') ? $d['start_date'] : date('Y-m-01');
    $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['end_date'] ?? '') ? $d['end_date'] : null;
    $account = intval($d['account_id'] ?? 0) ?: null;
    $desc = trim((string)($d['description'] ?? ''));
    $active = isset($d['is_active']) ? (int)!!$d['is_active'] : 1;
    if ($name === '') return 'Nama biaya tidak boleh kosong.';
    if ($category === '') return 'Pilih kategori.';
    if ($amount <= 0) return 'Jumlah harus lebih dari nol.';
    if ($end && $end < $start) return 'Tanggal selesai harus setelah tanggal mulai.';
    try {
        if ($id > 0) {
            $db->prepare("UPDATE recurring_expenses SET name = ?, category = ?, amount = ?, account_id = ?, day_of_month = ?, start_date = ?, end_date = ?, description = ?, is_active = ? WHERE id = ? AND tenant_id = ?")
               ->execute([$name, $category, $amount, $account, $day, $start, $end, $desc, $active, $id, $tenant_id]);
        } else {
            $db->prepare("INSERT INTO recurring_expenses (tenant_id, name, category, amount, account_id, day_of_month, start_date, end_date, description, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$tenant_id, $name, $category, $amount, $account, $day, $start, $end, $desc, $active, date('Y-m-d H:i:s')]);
        }
        return '';
    } catch (Exception $e) { return 'Gagal menyimpan biaya berulang.'; }
}

function recurring_expense_delete(PDO $db, int $tenant_id, int $id): void {
    try {
        // Generated expenses stay (they are real payments); only unlink them.
        $db->prepare("UPDATE expenses SET recurring_id = NULL WHERE recurring_id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
        $db->prepare("DELETE FROM recurring_expenses WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
    } catch (Exception $e) {}
}

/**
 * Post every due-and-missing month for active templates. Months are posted
 * only once their due date has arrived (<= today). Returns number created.
 * With $force_id the template is posted for the current month even before
 * its due date ("Buat sekarang").
 */
function recurring_expenses_run(PDO $db, int $tenant_id, int $created_by, int $force_id = 0): int {
    $created = 0;
    try {
        $today = date('Y-m-d');
        $tpls = $db->prepare("SELECT * FROM recurring_expenses WHERE tenant_id = ? AND is_active = 1" . ($force_id ? " AND id = ?" : ""));
        $tpls->execute($force_id ? [$tenant_id, $force_id] : [$tenant_id]);
        $exists = $db->prepare("SELECT COUNT(*) FROM expenses WHERE recurring_id = ? AND strftime('%Y-%m', date) = ?");
        $ins = $db->prepare("INSERT INTO expenses (category, amount, description, date, created_by, tenant_id, account_id, recurring_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        foreach ($tpls->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ym = substr($r['start_date'], 0, 7);
            $end_ym = substr($today, 0, 7);
            $guard = 0;
            while ($ym <= $end_ym && $guard++ < 240) {
                $due = recurring_due_date($ym, (int)$r['day_of_month']);
                $is_current = $ym === $end_ym;
                if ($due >= $r['start_date'] && (empty($r['end_date']) || $due <= $r['end_date']) && ($due <= $today || ($force_id && $is_current))) {
                    $exists->execute([$r['id'], $ym]);
                    if ((int)$exists->fetchColumn() === 0) {
                        $label = $r['name'] . ' - ' . $bulan[(int)substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4) . ($r['description'] ? ' (' . $r['description'] . ')' : '');
                        $ins->execute([$r['category'], $r['amount'], $label, $due, $created_by, $tenant_id, $r['account_id'] ?: null, $r['id']]);
                        $created++;
                    }
                }
                $ym = date('Y-m', strtotime($ym . '-01 +1 month'));
            }
        }
    } catch (Exception $e) {}
    return $created;
}
