<?php
// Dashboard: 6-month trend (revenue vs expenses, collection rate) + owner WhatsApp summary. Admin only.
if (($_SESSION['user_role'] ?? '') !== 'admin' || !function_exists('finance_trend')) return;
$tenant_id = intval($_SESSION['tenant_id'] ?? 1);
$trend = finance_trend($db, $tenant_id, 6);
$last = end($trend); $prev = $trend[count($trend) - 2] ?? null;
$company_row = $db->query("SELECT company_name, company_contact FROM settings WHERE tenant_id = $tenant_id")->fetch(PDO::FETCH_ASSOC) ?: [];
$owner_wa = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', (string)($company_row['company_contact'] ?? '')));
$prev_ym = date('Y-m', strtotime('first day of -1 month'));
$summary_prev = finance_month_summary_text($db, $tenant_id, $prev_ym, $company_row);
$summary_cur = finance_month_summary_text($db, $tenant_id, date('Y-m'), $company_row);
$pct = function ($now, $before) { if (!$before) return null; return round(($now - $before) / abs($before) * 100); };
$rp6 = fn($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');
$d_rev = $pct($last['revenue'], $prev['revenue'] ?? 0); $d_exp = $pct($last['expenses'], $prev['expenses'] ?? 0);
$sum6_rev = array_sum(array_column($trend, 'revenue')); $sum6_exp = array_sum(array_column($trend, 'expenses'));
?>
<section class="ui-card mt-6 overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <div>
            <h3 class="m-0 text-[15px] font-bold">Tren enam bulan</h3>
            <p class="m-0 text-xs text-muted-foreground">Pendapatan diterima vs pengeluaran dibayar, tingkat tagih, dan pelanggan baru. Keuangan mitra tidak termasuk.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="ui-btn ui-btn-sm ui-btn-outline" onclick="toggleTrendTable()" id="trendTableBtn">Tabel</button>
            <?php if ($owner_wa): ?>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-wa" title="Kirim ringkasan bulan lalu ke WhatsApp pemilik (<?= htmlspecialchars($company_row['company_contact']) ?>)" onclick="sendWAGateway('<?= $owner_wa ?>', <?= htmlspecialchars(json_encode($summary_prev)) ?>, 'https://api.whatsapp.com/send?phone=<?= $owner_wa ?>&text=<?= urlencode($summary_prev) ?>', this)"><i class="fab fa-whatsapp"></i> Ringkasan bulan lalu</button>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-outline" title="Kirim ringkasan bulan berjalan" onclick="sendWAGateway('<?= $owner_wa ?>', <?= htmlspecialchars(json_encode($summary_cur)) ?>, 'https://api.whatsapp.com/send?phone=<?= $owner_wa ?>&text=<?= urlencode($summary_cur) ?>', this)"><i class="fab fa-whatsapp"></i> Bulan ini</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid gap-0 xl:grid-cols-[minmax(0,1fr)_280px]">
        <div class="p-4 sm:p-5">
            <div class="mb-2 flex flex-wrap items-center gap-4 text-xs">
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background:#2a78d6"></span>Pendapatan</span>
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm" style="background:#d0691e"></span>Pengeluaran</span>
            </div>
            <?= finance_trend_svg($trend) ?>
            <div class="mt-4 text-xs font-medium text-muted-foreground">Tingkat tagih (tagihan jatuh tempo bulan itu yang sudah lunas)</div>
            <?= finance_rate_svg($trend) ?>
        </div>
        <div class="grid content-start gap-3 border-t border-solid border-border p-4 sm:p-5 xl:border-l xl:border-t-0">
            <div>
                <div class="text-xs font-medium text-muted-foreground">Pendapatan <?= htmlspecialchars($last['label_long']) ?></div>
                <div class="text-xl font-extrabold tabular-nums"><?= $rp6($last['revenue']) ?></div>
                <div class="text-xs <?= $d_rev === null ? 'text-muted-foreground' : ($d_rev >= 0 ? 'text-signal' : 'text-danger') ?>"><?= $d_rev === null ? 'Belum ada pembanding' : ($d_rev >= 0 ? '+' : '') . $d_rev . '% dari bulan sebelumnya' ?></div>
            </div>
            <div>
                <div class="text-xs font-medium text-muted-foreground">Pengeluaran <?= htmlspecialchars($last['label_long']) ?></div>
                <div class="text-xl font-extrabold tabular-nums"><?= $rp6($last['expenses']) ?></div>
                <div class="text-xs <?= $d_exp === null ? 'text-muted-foreground' : ($d_exp <= 0 ? 'text-signal' : 'text-danger') ?>"><?= $d_exp === null ? 'Belum ada pembanding' : ($d_exp >= 0 ? '+' : '') . $d_exp . '% dari bulan sebelumnya' ?></div>
            </div>
            <div>
                <div class="text-xs font-medium text-muted-foreground">Laba enam bulan</div>
                <div class="text-xl font-extrabold tabular-nums <?= $sum6_rev - $sum6_exp < 0 ? 'text-danger' : '' ?>"><?= $rp6($sum6_rev - $sum6_exp) ?></div>
                <div class="text-xs text-muted-foreground">Pendapatan <?= $rp6($sum6_rev) ?> · pengeluaran <?= $rp6($sum6_exp) ?></div>
            </div>
            <div>
                <div class="text-xs font-medium text-muted-foreground">Pelanggan baru enam bulan</div>
                <div class="text-xl font-extrabold tabular-nums"><?= number_format(array_sum(array_column($trend, 'new_customers'))) ?></div>
                <div class="text-xs text-muted-foreground"><?= (int)$last['new_customers'] ?> bulan ini</div>
            </div>
        </div>
    </div>

    <div id="trendTable" class="hidden border-t border-solid border-border">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                        <th class="px-4 py-2.5 font-semibold sm:px-5">Bulan</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Pendapatan</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Pengeluaran</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Laba</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Tingkat tagih</th>
                        <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Pelanggan baru</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trend as $m): ?>
                    <tr class="border-t border-solid border-border">
                        <td class="px-4 py-2.5 font-medium sm:px-5"><?= htmlspecialchars($m['label_long']) ?></td>
                        <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap"><?= $rp6($m['revenue']) ?></td>
                        <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap"><?= $rp6($m['expenses']) ?></td>
                        <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap <?= $m['profit'] < 0 ? 'text-danger' : '' ?>"><?= $rp6($m['profit']) ?></td>
                        <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap"><?= $m['rate'] === null ? '-' : $m['rate'] . '% (' . $m['paid_n'] . '/' . $m['billed_n'] . ')' ?></td>
                        <td class="px-4 py-2.5 text-right tabular-nums sm:px-5"><?= (int)$m['new_customers'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<script>
function toggleTrendTable() {
    const t = document.getElementById('trendTable'); const b = document.getElementById('trendTableBtn');
    const show = t.classList.contains('hidden'); t.classList.toggle('hidden', !show); b.textContent = show ? 'Sembunyikan tabel' : 'Tabel';
}
</script>
