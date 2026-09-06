<?php
// Handle Print Action
$is_print = ($_GET['action'] ?? '') === 'print';

// Fetch Asset Stats
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$stats_raw = $db->query("SELECT type, COUNT(*) as count FROM infrastructure_assets WHERE tenant_id = $tenant_id GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR);
$total_investment = $db->query("SELECT SUM(price) FROM infrastructure_assets WHERE tenant_id = $tenant_id")->fetchColumn() ?: 0;

// Total Port Usage Calculation (Direct approach as requested previously)
$total_ports_capacity = $db->query("SELECT SUM(total_ports) FROM infrastructure_assets WHERE tenant_id = $tenant_id")->fetchColumn() ?: 0;
$used_by_customers = $db->query("SELECT COUNT(*) FROM customers WHERE odp_id > 0 AND tenant_id = $tenant_id")->fetchColumn() ?: 0;
$used_by_child_assets = $db->query("SELECT COUNT(*) FROM infrastructure_assets WHERE parent_id > 0 AND tenant_id = $tenant_id")->fetchColumn() ?: 0;
$total_ports_used = $used_by_customers + $used_by_child_assets;

if ($is_print) {
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $company = $db->query("SELECT * FROM settings WHERE tenant_id = $tenant_id")->fetch();
    if (!$company) $company = ['company_name' => 'ISP', 'company_address' => '', 'company_contact' => '', 'company_logo' => ''];
    $logo_src = '';
    if(!empty($company['company_logo'])) {
        $logo_src = preg_match('/^http/', $company['company_logo']) ? $company['company_logo'] : '/' . str_replace(' ', '%20', $company['company_logo']);
    }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Laporan Inventaris Aset Perusahaan - <?= date('d/m/Y') ?></title>
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
                <div style="font-size:18px; font-weight:800;">LAPORAN INVENTARIS ASET PERUSAHAAN</div>
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
                <h3>Utilisasi Kapasitas Aset</h3>
                <div class="val"><?= $total_ports_used ?> / <?= $total_ports_capacity ?> Unit</div>
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
                    <th>INDUK</th>
                    <th>KAPASITAS</th>
                    <th>TERPAKAI</th>
                    <th>SISA</th>
                    <th>HARGA BELI</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $tenant_id = $_SESSION['tenant_id'] ?? 1;
                $assets = $db->query("SELECT a.*, p.name as parent_name FROM infrastructure_assets a LEFT JOIN infrastructure_assets p ON a.parent_id = p.id WHERE a.tenant_id = $tenant_id ORDER BY a.type DESC, a.name ASC")->fetchAll();
                foreach($assets as $a):
                    $usage_c = $db->prepare("SELECT COUNT(*) FROM customers WHERE odp_id = ? AND tenant_id = ?");
                    $usage_c->execute([$a['id'], $tenant_id]);
                    $c_count = $usage_c->fetchColumn();
                    
                    $usage_a = $db->prepare("SELECT COUNT(*) FROM infrastructure_assets WHERE parent_id = ? AND tenant_id = ?");
                    $usage_a->execute([$a['id'], $tenant_id]);
                    $a_count = $usage_a->fetchColumn();
                    
                    $total_u = $c_count + $a_count;
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($a['name']) ?></strong><br><small style="color:#64748b"><?= htmlspecialchars($a['brand'] ?: '-') ?></small></td>
                    <td><?= $a['type'] ?></td>
                    <td><?= htmlspecialchars($a['parent_name'] ?: 'ROOT') ?></td>
                    <td><?= $a['total_ports'] ?> Unit</td>
                    <td><?= $total_u ?> Unit</td>
                    <td style="font-weight:700; color:<?= ($a['total_ports'] - $total_u <= 1) ? '#ef4444' : '#10b981' ?>"><?= $a['total_ports'] - $total_u ?> Unit</td>
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

<!-- Page header -->
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Laporan inventaris aset perusahaan</h2>
        <p class="m-0 mt-1 text-sm text-muted-foreground">Unit perangkat, utilisasi kapasitas, dan nilai perolehan aset.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="index.php?page=admin_report_assets&action=print" target="_blank" class="ui-btn ui-btn-primary"><i class="fas fa-print"></i> Cetak laporan formal</a>
    </div>
</div>

<!-- Stats -->
<div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Total unit aset</div>
        <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums sm:text-2xl"><?= array_sum($stats_raw) ?> <span class="text-sm font-medium text-muted-foreground">pcs</span></div>
        <div class="text-xs text-muted-foreground">Kategori 1: <?= $stats_raw['OLT']??0 ?> · Kategori 2: <?= $stats_raw['ODC']??0 ?> · Kategori 3: <?= $stats_raw['ODP']??0 ?></div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Utilisasi kapasitas</div>
        <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums sm:text-2xl"><?= $total_ports_used ?> <span class="text-sm font-medium text-muted-foreground">/ <?= $total_ports_capacity ?> unit</span></div>
        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-sm bg-muted">
            <div class="h-full bg-primary" style="width:<?= ($total_ports_capacity > 0) ? ($total_ports_used / $total_ports_capacity) * 100 : 0 ?>%;"></div>
        </div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Valuasi aset perusahaan</div>
        <div class="mt-1 text-xl font-extrabold leading-tight tabular-nums sm:text-2xl">Rp <?= number_format($total_investment, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground">Berdasarkan total harga perolehan yang terinput.</div>
    </div>
</div>

<!-- Detailed table -->
<section class="ui-card overflow-hidden">
    <div class="flex items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
        <div>
            <h3 class="m-0 text-[15px] font-bold">Daftar aset</h3>
            <p class="m-0 text-xs text-muted-foreground">Kapasitas terpakai dan nilai perolehan tiap perangkat</p>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold sm:px-5">Detail aset</th>
                    <th class="px-3 py-2.5 font-semibold">Kategori</th>
                    <th class="px-3 py-2.5 font-semibold">Status kapasitas</th>
                    <th class="px-3 py-2.5 font-semibold">Sisa kapasitas</th>
                    <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Nilai aset</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $tenant_id = $_SESSION['tenant_id'] ?? 1;
                $assets = $db->query("SELECT a.*, p.name as parent_name FROM infrastructure_assets a LEFT JOIN infrastructure_assets p ON a.parent_id = p.id WHERE a.tenant_id = $tenant_id ORDER BY a.type DESC, a.name ASC")->fetchAll();
                foreach($assets as $a):
                    $usage_c = $db->prepare("SELECT COUNT(*) FROM customers WHERE odp_id = ? AND tenant_id = ?");
                    $usage_c->execute([$a['id'], $tenant_id]);
                    $c_count = $usage_c->fetchColumn();

                    $usage_a = $db->prepare("SELECT COUNT(*) FROM infrastructure_assets WHERE parent_id = ? AND tenant_id = ?");
                    $usage_a->execute([$a['id'], $tenant_id]);
                    $a_count = $usage_a->fetchColumn();

                    $total_u = $c_count + $a_count;
                    $rem = $a['total_ports'] - $total_u;
                    $pct = ($a['total_ports'] > 0) ? ($total_u / $a['total_ports']) * 100 : 0;
                ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 sm:px-5">
                        <div class="font-semibold"><?= htmlspecialchars($a['name']) ?></div>
                        <div class="text-xs text-muted-foreground">Parent: <?= htmlspecialchars($a['parent_name'] ?: 'ROOT') ?></div>
                    </td>
                    <td class="px-3 py-3"><span class="ui-badge ui-badge-muted"><?= $a['type'] ?></span></td>
                    <td class="px-3 py-3">
                        <div class="flex items-center justify-between gap-3 text-xs">
                            <span class="tabular-nums"><?= $total_u ?> / <?= $a['total_ports'] ?> port</span>
                            <span class="font-semibold tabular-nums <?= $pct > 90 ? 'text-danger' : '' ?>"><?= round($pct) ?>%</span>
                        </div>
                        <div class="mt-1 h-1 w-28 overflow-hidden rounded-sm bg-muted">
                            <div class="h-full <?= $pct > 90 ? 'bg-danger' : 'bg-primary' ?>" style="width:<?= $pct ?>%;"></div>
                        </div>
                    </td>
                    <td class="px-3 py-3">
                        <div class="font-semibold tabular-nums <?= $rem <= 1 ? 'text-danger' : '' ?>"><?= $rem ?> port tersedia</div>
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-right font-bold tabular-nums sm:px-5">Rp <?= number_format($a['price'], 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($assets) == 0): ?>
                    <tr><td colspan="5" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada data aset.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
