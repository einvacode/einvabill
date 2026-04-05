<?php
// Handle Print Action
$is_print = ($_GET['action'] ?? '') === 'print';

// Fetch Asset Stats
$stats_raw = $db->query("SELECT type, COUNT(*) as count FROM infrastructure_assets GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR);
$total_investment = $db->query("SELECT SUM(price) FROM infrastructure_assets")->fetchColumn() ?: 0;

// Total Port Usage Calculation (Direct approach as requested previously)
$total_ports_capacity = $db->query("SELECT SUM(total_ports) FROM infrastructure_assets")->fetchColumn() ?: 0;
$used_by_customers = $db->query("SELECT COUNT(*) FROM customers WHERE odp_id > 0")->fetchColumn() ?: 0;
$used_by_child_assets = $db->query("SELECT COUNT(*) FROM infrastructure_assets WHERE parent_id > 0")->fetchColumn() ?: 0;
$total_ports_used = $used_by_customers + $used_by_child_assets;

if ($is_print) {
    $company = $db->query("SELECT * FROM settings WHERE id=1")->fetch();
    $logo_src = '';
    if(!empty($company['company_logo'])) {
        $logo_src = preg_match('/^http/', $company['company_logo']) ? $company['company_logo'] : '/' . str_replace(' ', '%20', $company['company_logo']);
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Laporan Inventaris Aset - <?= date('d/m/Y') ?></title>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
            body { font-family: 'Inter', sans-serif; color: #1e293b; padding: 40px; line-height: 1.5; }
            .report-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #334155; padding-bottom: 20px; margin-bottom: 30px; }
            .company-info h1 { margin: 0; font-size: 24px; text-transform: uppercase; }
            .company-info p { margin: 5px 0 0; font-size: 12px; color: #64748b; }
            .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
            .summary-box { padding: 15px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; }
            .summary-box h3 { margin: 0 0 5px; font-size: 10px; color: #64748b; text-transform: uppercase; }
            .summary-box .val { font-size: 20px; font-weight: 700; color: #0f172a; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background: #f1f5f9; text-align: left; padding: 12px; font-size: 11px; border-bottom: 2px solid #cbd5e1; }
            td { padding: 12px; font-size: 12px; border-bottom: 1px solid #e2e8f0; }
            .badge { padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; color: white; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body onload="window.print()">
        <div class="report-header">
            <div style="display:flex; align-items:center; gap:20px;">
                <?php if($logo_src): ?><img src="<?= $logo_src ?>" style="max-height:60px;"><?php endif; ?>
                <div class="company-info">
                    <h1><?= htmlspecialchars($company['company_name']) ?></h1>
                    <p><?= htmlspecialchars($company['company_address']) ?> | Telp: <?= htmlspecialchars($company['company_contact']) ?></p>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:18px; font-weight:800;">LAPORAN INVENTARIS ASET</div>
                <div style="font-size:12px; color:#64748b;">Per Tanggal: <?= date('d F Y') ?></div>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-box">
                <h3>Total Unit Perangkat</h3>
                <div class="val"><?= array_sum($stats_raw) ?> Unit</div>
                <div style="font-size:10px; color:#64748b; margin-top:5px;">OLT: <?= $stats_raw['OLT']??0 ?>, ODC: <?= $stats_raw['ODC']??0 ?>, ODP: <?= $stats_raw['ODP']??0 ?></div>
            </div>
            <div class="summary-box">
                <h3>Utilisasi Port Jaringan</h3>
                <div class="val"><?= $total_ports_used ?> / <?= $total_ports_capacity ?> Port</div>
                <div style="font-size:10px; color:#64748b; margin-top:5px;"><?= round(($total_ports_capacity > 0 ? $total_ports_used/$total_ports_capacity : 0) * 100, 1) ?>% Terpakai</div>
            </div>
            <div class="summary-box">
                <h3>Total Nilai Investasi</h3>
                <div class="val">Rp <?= number_format($total_investment, 0, ',', '.') ?></div>
                <div style="font-size:10px; color:#64748b; margin-top:5px;">Berdasarkan harga perolehan</div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>NAMA PERANGKAT</th>
                    <th>TIPE</th>
                    <th>UPLINK (PARENT)</th>
                    <th>KAPASITAS</th>
                    <th>TERPAKAI</th>
                    <th>SISA PORT</th>
                    <th>HARGA BELI</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $assets = $db->query("SELECT a.*, p.name as parent_name FROM infrastructure_assets a LEFT JOIN infrastructure_assets p ON a.parent_id = p.id ORDER BY a.type DESC, a.name ASC")->fetchAll();
                foreach($assets as $a):
                    $usage_c = $db->prepare("SELECT COUNT(*) FROM customers WHERE odp_id = ?");
                    $usage_c->execute([$a['id']]);
                    $c_count = $usage_c->fetchColumn();
                    
                    $usage_a = $db->prepare("SELECT COUNT(*) FROM infrastructure_assets WHERE parent_id = ?");
                    $usage_a->execute([$a['id']]);
                    $a_count = $usage_a->fetchColumn();
                    
                    $total_u = $c_count + $a_count;
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($a['name']) ?></strong><br><small style="color:#64748b"><?= htmlspecialchars($a['brand'] ?: '-') ?></small></td>
                    <td><?= $a['type'] ?></td>
                    <td><?= htmlspecialchars($a['parent_name'] ?: 'ROOT') ?></td>
                    <td><?= $a['total_ports'] ?> Port</td>
                    <td><?= $total_u ?> Port</td>
                    <td style="font-weight:700; color:<?= ($a['total_ports'] - $total_u <= 1) ? '#ef4444' : '#10b981' ?>"><?= $a['total_ports'] - $total_u ?> Port</td>
                    <td>Rp <?= number_format($a['price'], 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="margin-top:50px; display:flex; justify-content:space-between;">
            <div style="text-align:center; width:200px;">
                <p style="font-size:12px;">Disetujui Oleh,</p>
                <div style="margin-top:60px; border-top:1px solid #000; padding-top:5px; font-weight:700;">Direktur / Owner</div>
            </div>
            <div style="text-align:center; width:200px;">
                <p style="font-size:12px;">Penanggung Jawab Teknik,</p>
                <div style="margin-top:60px; border-top:1px solid #000; padding-top:5px; font-weight:700;">Admin Aset</div>
            </div>
        </div>
    </body>
    </html>
    <?php exit;
} ?>

<div class="glass-panel" style="padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap:wrap; gap:15px;">
        <h3 style="margin:0;"><i class="fas fa-file-contract text-primary"></i> Laporan Inventaris Jaringan</h3>
        <a href="index.php?page=admin_report_assets&action=print" target="_blank" class="btn btn-primary"><i class="fas fa-print"></i> Cetak Laporan Formal</a>
    </div>

    <!-- Metrics Breakdown -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:20px; margin-bottom:30px;">
        <div class="glass-panel" style="padding:20px; border-left:4px solid var(--primary);">
            <div style="font-size:12px; color:var(--text-secondary); margin-bottom:10px;">TOTAL UNIT PERANGKAT</div>
            <div style="font-size:24px; font-weight:800;"><?= array_sum($stats_raw) ?> <span style="font-size:14px; font-weight:inset;">Pcs</span></div>
            <div style="display:flex; gap:10px; margin-top:10px; font-size:11px; font-weight:600;">
                <span style="color:#3b82f6;">OLT: <?= $stats_raw['OLT']??0 ?></span>
                <span style="color:#a855f7;">ODC: <?= $stats_raw['ODC']??0 ?></span>
                <span style="color:#ec4899;">ODP: <?= $stats_raw['ODP']??0 ?></span>
            </div>
        </div>
        <div class="glass-panel" style="padding:20px; border-left:4px solid var(--success);">
            <div style="font-size:12px; color:var(--text-secondary); margin-bottom:10px;">UTILISASI PORT GLOBAL</div>
            <div style="font-size:24px; font-weight:800; color:var(--success);"><?= $total_ports_used ?> <span style="font-size:14px; color:var(--text-secondary); font-weight:normal;">/ <?= $total_ports_capacity ?> Port</span></div>
            <div style="width:100%; height:6px; background:rgba(255,255,255,0.05); border-radius:10px; margin-top:15px; overflow:hidden;">
                <div style="width:<?= ($total_ports_capacity > 0) ? ($total_ports_used / $total_ports_capacity) * 100 : 0 ?>%; height:100%; background:var(--success);"></div>
            </div>
        </div>
        <div class="glass-panel" style="padding:20px; border-left:4px solid #f59e0b;">
            <div style="font-size:12px; color:var(--text-secondary); margin-bottom:10px;">VALUASI ASET INFRASTRUKTUR</div>
            <div style="font-size:24px; font-weight:800; color:#f59e0b;">Rp <?= number_format($total_investment, 0, ',', '.') ?></div>
            <div style="font-size:11px; color:var(--text-secondary); margin-top:10px;">Berdasarkan total harga beli terinput.</div>
        </div>
    </div>

    <!-- Detailed Table -->
    <div class="table-container shadow-sm">
        <table class="table-hover">
            <thead>
                <tr>
                    <th>Detail Aset</th>
                    <th>Kategori</th>
                    <th>Status Port</th>
                    <th>Sisa Kapasitas</th>
                    <th style="text-align:right;">Nilai Aset</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $assets = $db->query("SELECT a.*, p.name as parent_name FROM infrastructure_assets a LEFT JOIN infrastructure_assets p ON a.parent_id = p.id ORDER BY a.type DESC, a.name ASC")->fetchAll();
                foreach($assets as $a):
                    $usage_c = $db->prepare("SELECT COUNT(*) FROM customers WHERE odp_id = ?");
                    $usage_c->execute([$a['id']]);
                    $c_count = $usage_c->fetchColumn();
                    
                    $usage_a = $db->prepare("SELECT COUNT(*) FROM infrastructure_assets WHERE parent_id = ?");
                    $usage_a->execute([$a['id']]);
                    $a_count = $usage_a->fetchColumn();
                    
                    $total_u = $c_count + $a_count;
                    $rem = $a['total_ports'] - $total_u;
                    $pct = ($a['total_ports'] > 0) ? ($total_u / $a['total_ports']) * 100 : 0;
                ?>
                <tr>
                    <td>
                        <div style="font-weight:700;"><?= htmlspecialchars($a['name']) ?></div>
                        <div style="font-size:11px; color:var(--text-secondary);">Parent: <?= htmlspecialchars($a['parent_name'] ?: 'ROOT') ?></div>
                    </td>
                    <td><span class="badge" style="background:rgba(255,255,255,0.05); color:var(--text-primary); border:1px solid var(--glass-border);"><?= $a['type'] ?></span></td>
                    <td>
                        <div style="display:flex; justify-content:space-between; font-size:11px; margin-bottom:4px;">
                            <span><?= $total_u ?> / <?= $a['total_ports'] ?> Port</span>
                            <span style="font-weight:700; color:<?= $pct > 90 ? 'var(--danger)' : 'var(--success)' ?>"><?= round($pct) ?>%</span>
                        </div>
                        <div style="width:100px; height:4px; background:rgba(255,255,255,0.05); border-radius:10px; overflow:hidden;">
                            <div style="width:<?= $pct ?>%; height:100%; background:<?= $pct > 90 ? 'var(--danger)' : 'var(--success)' ?>;"></div>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:700; color:<?= $rem <= 1 ? 'var(--danger)' : 'var(--text-primary)' ?>;"><?= $rem ?> Port Tersedia</div>
                    </td>
                    <td style="text-align:right; font-weight:700;">Rp <?= number_format($a['price'], 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
