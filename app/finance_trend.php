<?php
/**
 * Monthly trend for the admin dashboard and the owner's WhatsApp summary.
 * Company scope only (partner-registered customers and partner expenses excluded).
 */

/**
 * One grouped query per metric over the whole window instead of four queries per
 * month: at 60k invoices the per-month version cost about half a second, and the
 * dashboard asks for the trend three times. Results are memoised per request, and
 * a shorter window is sliced off a longer one that was already computed.
 */
function finance_trend(PDO $db, int $tenant_id, int $months = 6): array {
    static $cache = [];
    $key = $tenant_id . ':' . $months;
    if (isset($cache[$key])) return $cache[$key];
    foreach ($cache as $k => $rows) {
        [$t, $m] = array_map('intval', explode(':', $k));
        if ($t === $tenant_id && $m > $months) return $cache[$key] = array_slice($rows, -$months);
    }

    $sc = cash_company_scope($db, $tenant_id);
    $bulan = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

    // Month keys oldest first, plus the half-open date range that covers them.
    $yms = [];
    for ($k = $months - 1; $k >= 0; $k--) $yms[] = date('Y-m', strtotime("first day of -$k month"));
    $from = $yms[0] . '-01';
    $to   = date('Y-m-01', strtotime('first day of next month'));

    // Ranges on the raw date columns keep the existing indexes usable; strftime() would not.
    $map = function (string $sql) use ($db, $tenant_id, $from, $to): array {
        $q = $db->prepare($sql);
        $q->execute([':t' => $tenant_id, ':from' => $from, ':to' => $to]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $ym = array_shift($r); $out[$ym] = $r; }
        return $out;
    };

    $rev = $map("SELECT substr(p.payment_date,1,7) AS ym, COALESCE(SUM(p.amount),0) AS v
        FROM payments p JOIN invoices i ON i.id = p.invoice_id JOIN customers c ON c.id = i.customer_id
        WHERE p.tenant_id = :t AND p.payment_date >= :from AND p.payment_date < :to {$sc['c']} GROUP BY ym");
    $exp = $map("SELECT substr(e.date,1,7) AS ym, COALESCE(SUM(e.amount),0) AS v
        FROM expenses e WHERE e.tenant_id = :t AND e.date >= :from AND e.date < :to {$sc['e']} GROUP BY ym");
    $new = $map("SELECT substr(c.registration_date,1,7) AS ym, COUNT(*) AS v
        FROM customers c WHERE c.tenant_id = :t AND c.type = 'customer'
          AND c.registration_date >= :from AND c.registration_date < :to {$sc['c']} GROUP BY ym");
    $bill = $map("SELECT substr(i.due_date,1,7) AS ym, COUNT(i.id) AS n,
            COALESCE(SUM(i.amount - COALESCE(i.discount,0)),0) AS billed,
            COALESCE(SUM(CASE WHEN i.status = 'Lunas' THEN i.amount - COALESCE(i.discount,0) ELSE 0 END),0) AS paid,
            COALESCE(SUM(CASE WHEN i.status = 'Lunas' THEN 1 ELSE 0 END),0) AS paid_n
        FROM invoices i JOIN customers c ON c.id = i.customer_id
        WHERE i.tenant_id = :t AND i.due_date >= :from AND i.due_date < :to {$sc['c']} GROUP BY ym");

    $out = [];
    foreach ($yms as $ym) {
        $r = (float)($rev[$ym]['v'] ?? 0);
        $e = (float)($exp[$ym]['v'] ?? 0);
        $b = $bill[$ym] ?? ['n' => 0, 'billed' => 0, 'paid' => 0, 'paid_n' => 0];
        $out[] = [
            'ym' => $ym, 'label' => $bulan[(int)substr($ym, 5, 2)] . ' ' . substr($ym, 2, 2),
            'label_long' => $bulan[(int)substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4),
            'revenue' => $r, 'expenses' => $e, 'profit' => $r - $e,
            'new_customers' => (int)($new[$ym]['v'] ?? 0),
            'billed' => (float)$b['billed'], 'billed_n' => (int)$b['n'],
            'paid' => (float)$b['paid'], 'paid_n' => (int)$b['paid_n'],
            'rate' => $b['billed'] > 0 ? round($b['paid'] / $b['billed'] * 100) : null,
        ];
    }
    return $cache[$key] = $out;
}

/** Plain-text monthly summary for the owner (WhatsApp). */
function finance_month_summary_text(PDO $db, int $tenant_id, string $ym, array $company): string {
    // Only the requested month and the one before it are used, so ask for that
    // window instead of a fixed year; the dashboard then reuses its cached trend.
    $back = 0; $cursor = date('Y-m');
    while ($cursor > $ym && $back < 60) { $back++; $cursor = date('Y-m', strtotime("first day of -$back month")); }
    $trend = finance_trend($db, $tenant_id, max(2, $back + 2));
    $cur = null; $prev = null;
    foreach ($trend as $i => $m) if ($m['ym'] === $ym) { $cur = $m; $prev = $trend[$i - 1] ?? null; }
    if (!$cur) return '';
    $rp = fn($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
    $delta = function ($now, $before) {
        if ($before === null || $before == 0) return '';
        $d = ($now - $before) / abs($before) * 100;
        return ' (' . ($d >= 0 ? '+' : '') . round($d) . '% vs bulan lalu)';
    };
    $receivable = 0; $over60 = 0;
    try {
        $sc = cash_company_scope($db, $tenant_id);
        $q = $db->prepare("SELECT COALESCE(SUM(o),0), COALESCE(SUM(CASE WHEN julianday('now') - julianday(due) > 60 THEN o ELSE 0 END),0) FROM (
            SELECT i.due_date AS due, (i.amount - COALESCE(i.discount,0)) - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id),0) AS o
            FROM invoices i JOIN customers c ON c.id = i.customer_id WHERE i.tenant_id = ? AND i.status <> 'Lunas' {$sc['c']})");
        $q->execute([$tenant_id]); [$receivable, $over60] = $q->fetch(PDO::FETCH_NUM);
    } catch (Exception $e) {}
    $cash = function_exists('cash_total_balance') ? cash_total_balance($db, $tenant_id) : 0;
    $lines = [
        '*Ringkasan keuangan ' . $cur['label_long'] . '*',
        ($company['company_name'] ?? ''),
        '',
        'Pendapatan: ' . $rp($cur['revenue']) . $delta($cur['revenue'], $prev['revenue'] ?? null),
        'Pengeluaran: ' . $rp($cur['expenses']) . $delta($cur['expenses'], $prev['expenses'] ?? null),
        'Laba bersih: ' . $rp($cur['profit']),
        'Tingkat tagih: ' . ($cur['rate'] === null ? '-' : $cur['rate'] . '% (' . $cur['paid_n'] . ' dari ' . $cur['billed_n'] . ' tagihan)'),
        'Pelanggan baru: ' . $cur['new_customers'],
        '',
        'Piutang berjalan: ' . $rp($receivable) . ($over60 > 0 ? ', di atas 60 hari ' . $rp($over60) : ''),
        'Saldo kas & bank: ' . $rp($cash),
        '',
        'Dikirim otomatis oleh EinvaBill ' . date('d/m/Y H:i'),
    ];
    return implode("\n", $lines);
}

/**
 * Grouped bars (revenue vs expenses) as inline SVG. Two categorical hues in fixed
 * order, 2px gap between adjacent bars, rounded 4px tops anchored at baseline,
 * direct labels on the latest month only, per-bar hover tooltip via <title>.
 */
function finance_trend_svg(array $trend, string $id = 'trendChart'): string {
    $w = 720; $h = 240; $pad_l = 8; $pad_r = 8; $pad_t = 26; $pad_b = 28;
    $n = max(1, count($trend));
    $max = 0; foreach ($trend as $m) $max = max($max, $m['revenue'], $m['expenses']);
    if ($max <= 0) $max = 1;
    $nice = pow(10, floor(log10($max))); $max = ceil($max / $nice) * $nice;
    $plot_w = $w - $pad_l - $pad_r; $plot_h = $h - $pad_t - $pad_b;
    $group_w = $plot_w / $n; $bar_w = max(8, min(44, ($group_w - 16) / 2 - 1));
    $y = fn($v) => $pad_t + $plot_h - ($v / $max) * $plot_h;
    $rp = fn($v) => 'Rp ' . number_format($v, 0, ',', '.');
    $short = function ($v) { if ($v >= 1e9) return round($v / 1e9, 1) . ' M'; if ($v >= 1e6) return round($v / 1e6, 1) . ' jt'; if ($v >= 1e3) return round($v / 1e3) . ' rb'; return (string)$v; };
    $s = '<svg id="' . $id . '" viewBox="0 0 ' . $w . ' ' . $h . '" class="h-auto w-full" role="img" aria-label="Pendapatan dan pengeluaran per bulan" font-family="inherit">';
    // gridlines (recessive)
    for ($g = 0; $g <= 4; $g++) {
        $gy = $pad_t + $plot_h - $plot_h * $g / 4;
        $s .= '<line x1="' . $pad_l . '" x2="' . ($w - $pad_r) . '" y1="' . $gy . '" y2="' . $gy . '" stroke="#E9EEEC" stroke-width="1"/>';
        if ($g > 0) $s .= '<text x="' . $pad_l . '" y="' . ($gy - 4) . '" font-size="10" fill="#5B6B72">' . $short($max * $g / 4) . '</text>';
    }
    $base_y = $pad_t + $plot_h;
    foreach ($trend as $i => $m) {
        $cx = $pad_l + $group_w * $i + $group_w / 2;
        $last = $i === $n - 1;
        foreach ([['revenue', '#2a78d6', 'Pendapatan', -1], ['expenses', '#d0691e', 'Pengeluaran', 1]] as [$key, $color, $name, $side]) {
            $v = (float)$m[$key];
            $bx = $cx + ($side < 0 ? -$bar_w - 1 : 1);
            $top = $y($v); $bh = max(0, $base_y - $top);
            $r = min(4, $bh / 2);
            $path = $bh > 0
                ? sprintf('M%.1f %.1f h%.1f v%.1f q0 %.1f -%.1f -%.1f h-%.1f q-%.1f 0 -%.1f %.1f z', $bx, $base_y, $bar_w, -($bh - $r), -$r, $r, $r, $bar_w - 2 * $r, $r, $r, $r)
                : '';
            $s .= '<g class="trend-bar" tabindex="0">';
            if ($path) $s .= '<path d="' . $path . '" fill="' . $color . '"><title>' . htmlspecialchars($m['label_long'] . ' · ' . $name . ': ' . $rp($v)) . '</title></path>';
            $s .= '<rect x="' . ($bx - 2) . '" y="' . $pad_t . '" width="' . ($bar_w + 4) . '" height="' . $plot_h . '" fill="transparent"><title>' . htmlspecialchars($m['label_long'] . ' · ' . $name . ': ' . $rp($v)) . '</title></rect>';
            if ($last && $v > 0) $s .= '<text x="' . ($bx + $bar_w / 2) . '" y="' . ($top - 6) . '" font-size="10" font-weight="600" text-anchor="middle" fill="#172026">' . $short($v) . '</text>';
            $s .= '</g>';
        }
        $s .= '<text x="' . $cx . '" y="' . ($h - 10) . '" font-size="11" text-anchor="middle" fill="#5B6B72">' . htmlspecialchars($m['label']) . '</text>';
    }
    $s .= '<line x1="' . $pad_l . '" x2="' . ($w - $pad_r) . '" y1="' . $base_y . '" y2="' . $base_y . '" stroke="#D9E0E2" stroke-width="1"/>';
    return $s . '</svg>';
}

/** Collection rate line (one series, no legend needed), with markers >= 8px. */
function finance_rate_svg(array $trend, string $id = 'rateChart'): string {
    $w = 720; $h = 160; $pad_l = 40; $pad_r = 28; $pad_t = 22; $pad_b = 28;
    $n = max(1, count($trend)); $plot_w = $w - $pad_l - $pad_r; $plot_h = $h - $pad_t - $pad_b;
    $x = fn($i) => $pad_l + $plot_w * ($n === 1 ? 0.5 : $i / ($n - 1));
    $y = fn($v) => $pad_t + $plot_h - ($v / 100) * $plot_h;
    $s = '<svg id="' . $id . '" viewBox="0 0 ' . $w . ' ' . $h . '" class="h-auto w-full" role="img" aria-label="Tingkat tagih per bulan" font-family="inherit">';
    foreach ([0, 50, 100] as $g) {
        $s .= '<line x1="' . $pad_l . '" x2="' . ($w - $pad_r) . '" y1="' . $y($g) . '" y2="' . $y($g) . '" stroke="#E9EEEC"/>';
        $s .= '<text x="' . ($pad_l - 6) . '" y="' . ($y($g) + 3) . '" font-size="10" text-anchor="end" fill="#5B6B72">' . $g . '%</text>';
    }
    $pts = []; foreach ($trend as $i => $m) if ($m['rate'] !== null) $pts[] = [$x($i), $y($m['rate']), $m];
    if (count($pts) > 1) { $d = ''; foreach ($pts as $k => $p) $d .= ($k ? ' L' : 'M') . sprintf('%.1f %.1f', $p[0], $p[1]); $s .= '<path d="' . $d . '" fill="none" stroke="#2a78d6" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>'; }
    foreach ($pts as $k => $p) {
        $last = $k === count($pts) - 1;
        $s .= '<g tabindex="0"><circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="5" fill="#2a78d6" stroke="#FFFFFF" stroke-width="2"><title>' . htmlspecialchars($p[2]['label_long'] . ' · tingkat tagih ' . $p[2]['rate'] . '% (' . $p[2]['paid_n'] . '/' . $p[2]['billed_n'] . ' tagihan)') . '</title></circle>';
        if ($last) $s .= '<text x="' . $p[0] . '" y="' . ($p[1] - 10) . '" font-size="11" font-weight="600" text-anchor="middle" fill="#172026">' . $p[2]['rate'] . '%</text>';
        $s .= '</g>';
    }
    foreach ($trend as $i => $m) $s .= '<text x="' . $x($i) . '" y="' . ($h - 10) . '" font-size="11" text-anchor="middle" fill="#5B6B72">' . htmlspecialchars($m['label']) . '</text>';
    return $s . '</svg>';
}
