<?php
$site = $db->query("SELECT company_name, company_logo FROM settings WHERE id=1")->fetch();
$comp_name = $site['company_name'] ?: 'RT RW NET';

$customer = null;
$invoices = [];
$code_input = $_GET['code'] ?? '';

if ($code_input) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE customer_code = ?");
    $stmt->execute([strtoupper(trim($code_input))]);
    $customer = $stmt->fetch();
    
    if ($customer) {
        $invoices = $db->query("
            SELECT i.* FROM invoices i 
            WHERE i.customer_id = " . intval($customer['id']) . "
            ORDER BY i.id DESC
        ")->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cek Tagihan - <?= htmlspecialchars($comp_name) ?></title>
    <link rel="stylesheet" href="public/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script>(function(){const t=localStorage.getItem('billing_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})()</script>
    <style>
        .portal-container { max-width: 700px; margin: 0 auto; padding: 20px; min-height: 100vh; display: flex; flex-direction: column; justify-content: center; }
        .portal-header { text-align: center; margin-bottom: 30px; }
        .portal-header h1 { font-size: 28px; font-weight: 700; background: linear-gradient(to right, var(--gradient-text-from), var(--gradient-text-to)); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 8px; }
        .invoice-card { padding: 16px; border-radius: 12px; border: 1px solid var(--glass-border); margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; background: var(--hover-bg); transition: all 0.2s; }
        .invoice-card:hover { background: var(--nav-active-bg); }
    </style>
</head>
<body>
    <div class="portal-container">
        <div class="portal-header">
            <?php if(!empty($site['company_logo'])): ?>
                <img src="<?= htmlspecialchars($site['company_logo']) ?>" style="max-height:60px; margin-bottom:15px;" alt="Logo">
            <?php else: ?>
                <i class="fas fa-wifi" style="font-size:40px; color:var(--primary); margin-bottom:15px;"></i>
            <?php endif; ?>
            <h1><?= htmlspecialchars($comp_name) ?></h1>
            <p style="color:var(--text-secondary);">Portal Cek Tagihan Pelanggan</p>
        </div>

        <!-- Form Cek -->
        <div class="glass-panel" style="padding:24px; margin-bottom:20px;">
            <form method="GET" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                <input type="hidden" name="page" value="customer_portal">
                <div style="flex:1; min-width:200px;">
                    <label style="font-size:13px; color:var(--text-secondary); display:block; margin-bottom:6px;">
                        <i class="fas fa-id-card"></i> Masukkan ID Pelanggan Anda
                    </label>
                    <input type="text" name="code" class="form-control" value="<?= htmlspecialchars($code_input) ?>" 
                           placeholder="Masukkan kode pelanggan Anda" required 
                           style="text-transform:uppercase; font-family:monospace; font-size:18px; letter-spacing:2px; padding:14px 16px;">
                </div>
                <button type="submit" class="btn btn-primary" style="padding:14px 24px; white-space:nowrap;">
                    <i class="fas fa-search"></i> Cek Tagihan
                </button>
            </form>
        </div>

        <?php if($code_input && !$customer): ?>
            <div class="glass-panel" style="padding:24px; text-align:center;">
                <i class="fas fa-times-circle" style="font-size:40px; color:var(--danger); margin-bottom:15px;"></i>
                <h3>ID Pelanggan Tidak Ditemukan</h3>
                <p style="color:var(--text-secondary); margin-top:8px;">Pastikan ID yang Anda masukkan benar. Hubungi admin jika Anda tidak mengetahui ID pelanggan Anda.</p>
            </div>
        <?php endif; ?>

        <?php if($customer): ?>
            <!-- Info Pelanggan -->
            <div class="glass-panel" style="padding:24px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:15px;">
                    <div>
                        <div style="font-size:20px; font-weight:700;"><?= htmlspecialchars($customer['name']) ?></div>
                        <div style="font-size:13px; color:var(--primary); font-family:monospace;"><?= htmlspecialchars($customer['customer_code']) ?></div>
                    </div>
                    <span class="badge badge-success" style="font-size:13px; padding:6px 14px;"><i class="fas fa-check-circle"></i> Aktif</span>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; font-size:14px; color:var(--text-secondary);">
                    <div><i class="fas fa-box" style="width:20px;"></i> <?= htmlspecialchars($customer['package_name']) ?></div>
                    <div><i class="fas fa-money-bill" style="width:20px;"></i> Rp <?= number_format($customer['monthly_fee'], 0, ',', '.') ?>/bulan</div>
                    <div><i class="fas fa-map-marker-alt" style="width:20px;"></i> <?= htmlspecialchars($customer['address'] ?: '-') ?></div>
                    <div><i class="fas fa-calendar" style="width:20px;"></i> Tagih tgl <?= $customer['billing_date'] ?> setiap bulan</div>
                </div>
            </div>

            <!-- Ringkasan -->
            <?php
                $total_inv = count($invoices);
                $lunas = array_filter($invoices, fn($i) => $i['status'] === 'Lunas');
                $belum = array_filter($invoices, fn($i) => $i['status'] === 'Belum Lunas');
                $total_belum = array_sum(array_column(array_values($belum), 'amount'));
            ?>
            <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:12px; margin-bottom:20px;">
                <div class="glass-panel" style="padding:16px; text-align:center;">
                    <div style="font-size:24px; font-weight:800; color:var(--stat-value-color);"><?= $total_inv ?></div>
                    <div style="font-size:12px; color:var(--text-secondary);">Total Tagihan</div>
                </div>
                <div class="glass-panel" style="padding:16px; text-align:center;">
                    <div style="font-size:24px; font-weight:800; color:var(--success);"><?= count($lunas) ?></div>
                    <div style="font-size:12px; color:var(--text-secondary);">Lunas</div>
                </div>
                <div class="glass-panel" style="padding:16px; text-align:center;">
                    <div style="font-size:24px; font-weight:800; color:var(--danger);"><?= count($belum) ?></div>
                    <div style="font-size:12px; color:var(--text-secondary);">Belum Lunas</div>
                </div>
            </div>

            <?php if($total_belum > 0): ?>
            <div style="padding:12px 20px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); border-radius:10px; margin-bottom:20px; text-align:center;">
                <div style="font-size:13px; color:var(--danger);">Total Tagihan Belum Lunas</div>
                <div style="font-size:28px; font-weight:800; color:var(--danger);">Rp <?= number_format($total_belum, 0, ',', '.') ?></div>
            </div>
            <?php endif; ?>

            <!-- Daftar Tagihan -->
            <div class="glass-panel" style="padding:20px;">
                <h4 style="margin-bottom:15px;"><i class="fas fa-file-invoice-dollar"></i> Riwayat Tagihan</h4>
                <?php foreach($invoices as $inv): ?>
                <div class="invoice-card">
                    <div>
                        <div style="font-weight:600; font-size:14px;">INV-<?= str_pad($inv['id'], 5, '0', STR_PAD_LEFT) ?></div>
                        <div style="font-size:12px; color:var(--text-secondary);">Jatuh tempo: <?= date('d M Y', strtotime($inv['due_date'])) ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:700; margin-bottom:4px;">Rp <?= number_format($inv['amount'], 0, ',', '.') ?></div>
                        <?php if($inv['status'] === 'Lunas'): ?>
                            <span class="badge badge-success" style="font-size:11px;"><i class="fas fa-check"></i> Lunas</span>
                        <?php else: ?>
                            <span class="badge" style="background:rgba(239,68,68,0.15); color:var(--danger); border:1px solid rgba(239,68,68,0.3); font-size:11px;">Belum Lunas</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(count($invoices) == 0): ?>
                <div style="text-align:center; padding:20px; color:var(--text-secondary);">Belum ada tagihan.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Back Links -->
        <div style="text-align:center; margin-top:20px;">
            <a href="index.php?page=landing" style="color:var(--text-secondary); text-decoration:none; font-size:13px;">
                <i class="fas fa-arrow-left"></i> Kembali ke Halaman Utama
            </a>
            &nbsp;&nbsp;|&nbsp;&nbsp;
            <a href="index.php?page=login" style="color:var(--text-secondary); text-decoration:none; font-size:13px;">
                <i class="fas fa-sign-in-alt"></i> Login Admin
            </a>
        </div>
    </div>

    <button class="theme-toggle" onclick="toggleTheme()" title="Ganti Tema" style="position:fixed; bottom:25px; right:25px; z-index:999;">
        <i class="fas fa-sun theme-icon-dark"></i>
        <i class="fas fa-moon theme-icon-light"></i>
    </button>
    <script>
    function toggleTheme() {
        const html = document.documentElement;
        const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('billing_theme', next);
    }
    </script>
</body>
</html>
