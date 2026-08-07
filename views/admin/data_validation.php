<?php
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$stats = [];
$issues = [];

function dv_count($db, $sql, $params = []) {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return -1;
    }
}

$stats['users'] = dv_count($db, "SELECT COUNT(*) FROM users");
$stats['customers'] = dv_count($db, "SELECT COUNT(*) FROM customers WHERE tenant_id = ?", [$tenant_id]);
$stats['invoices'] = dv_count($db, "SELECT COUNT(*) FROM invoices WHERE tenant_id = ?", [$tenant_id]);
$stats['payments'] = dv_count($db, "SELECT COUNT(*) FROM payments WHERE tenant_id = ?", [$tenant_id]);
$stats['expenses'] = dv_count($db, "SELECT COUNT(*) FROM expenses WHERE tenant_id = ?", [$tenant_id]);
$stats['assets'] = dv_count($db, "SELECT COUNT(*) FROM infrastructure_assets WHERE tenant_id = ?", [$tenant_id]);
$stats['routers'] = dv_count($db, "SELECT COUNT(*) FROM routers WHERE tenant_id = ?", [$tenant_id]);
$stats['packages'] = dv_count($db, "SELECT COUNT(*) FROM packages WHERE tenant_id = ?", [$tenant_id]);
$stats['areas'] = dv_count($db, "SELECT COUNT(*) FROM areas WHERE tenant_id = ?", [$tenant_id]);

$issues[] = ['label' => 'Pelanggan tanpa kode pelanggan', 'count' => dv_count($db, "SELECT COUNT(*) FROM customers WHERE tenant_id = ? AND (customer_code IS NULL OR TRIM(customer_code) = '')", [$tenant_id])];
$issues[] = ['label' => 'Pelanggan tanpa nama', 'count' => dv_count($db, "SELECT COUNT(*) FROM customers WHERE tenant_id = ? AND (name IS NULL OR TRIM(name) = '')", [$tenant_id])];
$issues[] = ['label' => 'Invoice tanpa customer', 'count' => dv_count($db, "SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND (customer_id IS NULL OR customer_id = 0)", [$tenant_id])];
$issues[] = ['label' => 'Invoice tanpa nominal', 'count' => dv_count($db, "SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND (amount IS NULL OR amount <= 0)", [$tenant_id])];
$issues[] = ['label' => 'Invoice tanpa jatuh tempo', 'count' => dv_count($db, "SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND (due_date IS NULL OR TRIM(due_date) = '')", [$tenant_id])];
$issues[] = ['label' => 'Pembayaran orphan', 'count' => dv_count($db, "SELECT COUNT(*) FROM payments p LEFT JOIN invoices i ON i.id = p.invoice_id AND i.tenant_id = p.tenant_id WHERE p.tenant_id = ? AND i.id IS NULL", [$tenant_id])];
$issues[] = ['label' => 'Biaya tanpa nominal', 'count' => dv_count($db, "SELECT COUNT(*) FROM expenses WHERE tenant_id = ? AND (amount IS NULL OR amount <= 0)", [$tenant_id])];
$issues[] = ['label' => 'Aset tanpa nama', 'count' => dv_count($db, "SELECT COUNT(*) FROM infrastructure_assets WHERE tenant_id = ? AND (name IS NULL OR TRIM(name) = '')", [$tenant_id])];
$issues[] = ['label' => 'Aset tanpa kategori', 'count' => dv_count($db, "SELECT COUNT(*) FROM infrastructure_assets WHERE tenant_id = ? AND (type IS NULL OR TRIM(type) = '')", [$tenant_id])];
$issues[] = ['label' => 'Router tanpa host', 'count' => dv_count($db, "SELECT COUNT(*) FROM routers WHERE tenant_id = ? AND (host IS NULL OR TRIM(host) = '')", [$tenant_id])];
$issues[] = ['label' => 'Paket tanpa nama', 'count' => dv_count($db, "SELECT COUNT(*) FROM packages WHERE tenant_id = ? AND (name IS NULL OR TRIM(name) = '')", [$tenant_id])];
$issues[] = ['label' => 'Area tanpa nama', 'count' => dv_count($db, "SELECT COUNT(*) FROM areas WHERE tenant_id = ? AND (name IS NULL OR TRIM(name) = '')", [$tenant_id])];

$total_problem = 0;
foreach ($issues as $issue) {
    if ($issue['count'] > 0) $total_problem += $issue['count'];
}

?>
<div class="glass-panel" style="padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:20px;">
        <div>
            <h3 style="margin:0;"><i class="fas fa-shield-check" style="color:#10b981;"></i> Validasi Data Semua Menu</h3>
            <div style="font-size:12px; color:var(--text-secondary); margin-top:6px;">Audit cepat untuk memastikan menu utama masih cocok dengan data yang ada.</div>
        </div>
        <div class="btn btn-sm btn-ghost" style="cursor:default;">
            Total Temuan: <strong style="margin-left:6px; color:<?= $total_problem > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= $total_problem ?></strong>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:24px;">
        <?php foreach ($stats as $label => $count): ?>
            <div class="glass-panel" style="padding:18px; border-left:4px solid var(--primary);">
                <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:8px;"><?= htmlspecialchars(strtoupper($label)) ?></div>
                <div style="font-size:26px; font-weight:800; color:var(--text-primary);"><?= $count >= 0 ? $count : 'ERR' ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="table-container shadow-sm">
        <table>
            <thead>
                <tr>
                    <th>Pemeriksaan</th>
                    <th>Temuan</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($issues as $issue): ?>
                    <tr>
                        <td><?= htmlspecialchars($issue['label']) ?></td>
                        <td style="font-weight:700; color:<?= $issue['count'] > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= intval($issue['count']) ?></td>
                        <td><?= $issue['count'] > 0 ? 'Perlu penyesuaian' : 'Valid' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top:18px; font-size:12px; color:var(--text-secondary);">
        Catatan: jika ada temuan pada modul tertentu, saya bisa lanjutkan menyesuaikan menu terkait agar cocok dengan data eksisting tanpa mengubah alur utama.
    </div>
</div>
