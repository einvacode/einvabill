<?php
/**
 * Financial statements for the annual tax return (SPT Tahunan Badan):
 * Laporan Laba Rugi and Laporan Posisi Keuangan (neraca).
 *
 * Basis: kas (pembayaran diterima / pengeluaran dibayar) ditambah piutang
 * usaha dan aset tetap dengan penyusutan garis lurus, sesuai kebutuhan
 * wajib pajak non-PKP yang memakai PPh final 0,5% (PP 55/2022).
 *
 * Opening balances (kas awal, modal disetor, hutang) live in settings.fin_*.
 */

/**
 * @param string $scope_where   SQL fragment on customers alias c (from reports.php)
 * @param string $scope_ext     SQL fragment on invoices alias i (from reports.php)
 * @return array{pl: array, bs: array, meta: array}
 */
function finance_statements(PDO $db, int $tenant_id, string $date_from, string $date_to, string $scope_where, string $scope_ext): array {
    $settings = $db->query("SELECT * FROM settings WHERE tenant_id = $tenant_id")->fetch(PDO::FETCH_ASSOC) ?: [];
    $opening_cash   = (float)($settings['fin_opening_cash'] ?? 0);
    $opening_date   = $settings['fin_opening_date'] ?: null;
    $paid_capital   = (float)($settings['fin_paid_capital'] ?? 0);
    $liabilities    = (float)($settings['fin_liabilities'] ?? 0);
    $life_years     = max(1, (int)($settings['fin_asset_life_years'] ?? 4));
    $tax_rate       = (float)($settings['fin_tax_rate'] ?? 0.5);

    $from_dt = $date_from . ' 00:00:00';
    $to_dt   = $date_to . ' 23:59:59';
    $open_dt = $opening_date ? $opening_date . ' 00:00:00' : '1970-01-01 00:00:00';
    $open_d  = $opening_date ?: '1970-01-01';

    // --- Pendapatan usaha (kas diterima dalam periode) ---
    $q = $db->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON i.customer_id = c.id
        WHERE p.payment_date BETWEEN ? AND ? $scope_ext $scope_where");
    $q->execute([$from_dt, $to_dt]);
    $revenue = (float)$q->fetchColumn();

    // Pendapatan per jenis pelanggan (informasi)
    $q = $db->prepare("SELECT CASE WHEN c.type = 'partner' THEN 'Tagihan kolektif mitra (POP)' WHEN c.type IN ('note','temp') OR i.created_via = 'external' THEN 'Invoice eksternal' ELSE 'Langganan pelanggan' END AS jenis, COALESCE(SUM(p.amount),0) AS total
        FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON i.customer_id = c.id
        WHERE p.payment_date BETWEEN ? AND ? $scope_ext $scope_where GROUP BY jenis ORDER BY total DESC");
    $q->execute([$from_dt, $to_dt]);
    $revenue_lines = $q->fetchAll(PDO::FETCH_ASSOC);

    // --- Beban usaha per kategori ---
    $esc = function_exists('cash_company_scope') ? cash_company_scope($db, $tenant_id)['e'] : ''; // beban mitra bukan beban perusahaan
    $q = $db->prepare("SELECT COALESCE(NULLIF(TRIM(e.category),''),'Lain-lain') AS kategori, COALESCE(SUM(e.amount),0) AS total
        FROM expenses e WHERE e.tenant_id = ? AND e.date BETWEEN ? AND ? $esc GROUP BY kategori ORDER BY total DESC");
    $q->execute([$tenant_id, $date_from, $date_to]);
    $expense_lines = $q->fetchAll(PDO::FETCH_ASSOC);
    $expenses = array_sum(array_column($expense_lines, 'total'));

    // --- Aset tetap & penyusutan garis lurus ---
    $assets = $db->query("SELECT id, name, type, price, COALESCE(NULLIF(installation_date,''), substr(created_at,1,10)) AS acquired, status
        FROM infrastructure_assets WHERE tenant_id = $tenant_id AND price > 0 AND status <> 'Sold'")->fetchAll(PDO::FETCH_ASSOC);
    $dep_period = 0; $dep_accum = 0; $cost_total = 0; $asset_lines = [];
    $months_between = function (string $a, string $b): int { // full months from a to b (inclusive of b's month)
        [$ay, $am] = explode('-', substr($a, 0, 7)); [$by, $bm] = explode('-', substr($b, 0, 7));
        return max(0, ((int)$by - (int)$ay) * 12 + ((int)$bm - (int)$am) + 1);
    };
    foreach ($assets as $a) {
        if (!$a['acquired'] || $a['acquired'] > $date_to) continue;
        $cost = (float)$a['price'];
        $monthly = $cost / ($life_years * 12);
        $m_to_end   = min($life_years * 12, $months_between($a['acquired'], $date_to));
        $m_to_start = $a['acquired'] < $date_from ? min($life_years * 12, $months_between($a['acquired'], date('Y-m-d', strtotime($date_from . ' -1 day')))) : 0;
        $accum = round($monthly * $m_to_end);
        $this_period = round($monthly * ($m_to_end - $m_to_start));
        $cost_total += $cost; $dep_accum += $accum; $dep_period += $this_period;
        $asset_lines[] = ['name' => $a['name'], 'type' => $a['type'], 'acquired' => $a['acquired'], 'cost' => $cost, 'accum' => $accum, 'book' => $cost - $accum];
    }

    // --- Laba rugi ---
    $operating_profit = $revenue - $expenses - $dep_period;
    $tax = round($revenue * $tax_rate / 100);
    $net_profit = $operating_profit - $tax;

    // --- Posisi keuangan per tanggal akhir periode ---
    $q = $db->prepare("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN invoices i ON p.invoice_id = i.id JOIN customers c ON i.customer_id = c.id
        WHERE p.payment_date BETWEEN ? AND ? $scope_ext $scope_where");
    $q->execute([$open_dt, $to_dt]);
    $cash_in_all = (float)$q->fetchColumn();
    $q = $db->prepare("SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE e.tenant_id = ? AND e.date BETWEEN ? AND ? $esc");
    $q->execute([$tenant_id, $open_d, $date_to]);
    $cash_out_all = (float)$q->fetchColumn();
    // Kas: jumlah saldo seluruh akun Kas & Bank per tanggal laporan (termasuk dompet petugas);
    // fallback ke saldo awal + penerimaan - pengeluaran bila modul kas belum terpakai.
    $cash = function_exists('cash_accounts_with_balances') && count(cash_accounts_with_balances($db, $tenant_id, $date_to)) > 0
        ? cash_total_balance($db, $tenant_id, $date_to)
        : $opening_cash + $cash_in_all - $cash_out_all;

    // Piutang usaha: tagihan belum lunas yang sudah jatuh tempo per tanggal neraca, dikurangi pembayaran sebagian
    $q = $db->prepare("SELECT COALESCE(SUM((i.amount - COALESCE(i.discount,0)) - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id AND p.payment_date <= ?),0)),0)
        FROM invoices i JOIN customers c ON i.customer_id = c.id
        WHERE i.status <> 'Lunas' AND i.due_date <= ? $scope_ext $scope_where");
    $q->execute([$to_dt, $date_to]);
    $receivables = max(0, (float)$q->fetchColumn());

    $fixed_net = $cost_total - $dep_accum;
    $total_assets = $cash + $receivables + $fixed_net;
    $retained = $total_assets - $liabilities - $paid_capital; // laba ditahan termasuk laba periode berjalan
    $total_liab_equity = $liabilities + $paid_capital + $retained;

    return [
        'meta' => ['company' => $settings, 'date_from' => $date_from, 'date_to' => $date_to, 'opening_date' => $opening_date, 'life_years' => $life_years, 'tax_rate' => $tax_rate, 'has_opening' => (bool)$opening_date],
        'pl' => ['revenue' => $revenue, 'revenue_lines' => $revenue_lines, 'expense_lines' => $expense_lines, 'expenses' => $expenses, 'depreciation' => $dep_period,
                 'operating_profit' => $operating_profit, 'tax' => $tax, 'net_profit' => $net_profit],
        'bs' => ['opening_cash' => $opening_cash, 'cash' => $cash, 'cash_in' => $cash_in_all, 'cash_out' => $cash_out_all, 'receivables' => $receivables,
                 'fixed_cost' => $cost_total, 'fixed_accum' => $dep_accum, 'fixed_net' => $fixed_net, 'asset_lines' => $asset_lines, 'total_assets' => $total_assets,
                 'liabilities' => $liabilities, 'paid_capital' => $paid_capital, 'retained' => $retained, 'total_liab_equity' => $total_liab_equity],
    ];
}

function fin_rp($n): string { $n = (float)$n; return ($n < 0 ? '(' : '') . 'Rp ' . number_format(abs($n), 0, ',', '.') . ($n < 0 ? ')' : ''); }

function fin_tanggal(string $ymd): string {
    $b = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $t = strtotime($ymd); return (int)date('j', $t) . ' ' . $b[(int)date('n', $t)] . ' ' . date('Y', $t);
}

/** Shared print-page head + company header for the two statements. */
function fin_print_open(array $company, string $title, string $subtitle): void {
    $logo = '';
    if (!empty($company['company_logo'])) $logo = preg_match('/^http/', $company['company_logo']) ? $company['company_logo'] : '/' . str_replace(' ', '%20', $company['company_logo']);
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($company['company_name'] ?? '') ?></title>
<style>
  @page { size: A4; margin: 18mm 16mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 12px; margin: 0; padding: 24px; max-width: 900px; margin: 0 auto; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 18px; }
  .head img { max-height: 56px; max-width: 140px; object-fit: contain; }
  .company { font-size: 16px; font-weight: bold; }
  .muted { color: #555; }
  h1 { font-size: 16px; text-align: center; margin: 0 0 2px; letter-spacing: .04em; text-transform: uppercase; }
  .sub { text-align: center; margin: 0 0 18px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
  th, td { padding: 6px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
  th { text-align: left; background: #f2f2f2; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; }
  td.num, th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  tr.total td { font-weight: bold; border-top: 2px solid #111; border-bottom: 2px solid #111; }
  tr.sub td { font-weight: bold; background: #fafafa; }
  td.indent { padding-left: 24px; }
  .section { font-weight: bold; margin: 14px 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
  .note { font-size: 11px; color: #555; margin-top: 16px; line-height: 1.5; }
  .sign { display: flex; justify-content: flex-end; margin-top: 36px; }
  .sign div { text-align: center; width: 240px; }
  .sign .line { margin-top: 64px; border-top: 1px solid #111; padding-top: 4px; }
  .toolbar { position: fixed; top: 12px; right: 12px; }
  .toolbar button { padding: 8px 14px; border: 1px solid #111; background: #fff; cursor: pointer; font-size: 12px; }
  @media print { .toolbar { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Cetak / simpan PDF</button></div>
<div class="head">
  <div style="display:flex; gap:14px; align-items:center;">
    <?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt=""><?php endif; ?>
    <div>
      <div class="company"><?= htmlspecialchars($company['company_name'] ?? '') ?></div>
      <div class="muted"><?= htmlspecialchars($company['company_address'] ?? '') ?></div>
      <div class="muted"><?= !empty($company['company_contact']) ? 'Telp. ' . htmlspecialchars($company['company_contact']) : '' ?></div>
    </div>
  </div>
  <div class="muted" style="text-align:right;">Dicetak <?= date('d/m/Y H:i') ?></div>
</div>
<h1><?= htmlspecialchars($title) ?></h1>
<p class="sub"><?= htmlspecialchars($subtitle) ?><br><span class="muted">(dalam Rupiah)</span></p>
<?php
}

function fin_print_close(array $company): void { ?>
<div class="sign">
  <div>
    <div>Mengetahui,</div>
    <div class="line"><?= htmlspecialchars($company['company_name'] ?? '') ?><br><span class="muted">Direktur / Pemilik</span></div>
  </div>
</div>
</body>
</html>
<?php }
