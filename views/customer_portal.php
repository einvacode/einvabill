<?php
$site = $db->query("SELECT company_name, company_logo, company_address, company_contact, bank_account FROM settings WHERE id=1")->fetch();
$comp_name = $site['company_name'] ?: 'RT RW NET';
$comp_logo = $site['company_logo'] ?: 'assets/img/logo.png';
$bank_info = $site['bank_account'] ?: '-';

$customer = null;
$invoices = [];
$code_input = $_GET['code'] ?? '';

if ($code_input) {
    $stmt = $db->prepare("SELECT * FROM customers WHERE customer_code = ?");
    $stmt->execute([strtoupper(trim($code_input))]);
    $customer = $stmt->fetch();
    
    if ($customer) {
        // Branding Override for Partners
        if (($customer['created_by'] ?? 0) != 0) {
            $partner_id = intval($customer['created_by']);
            $partner_brand = $db->query("SELECT brand_name, brand_logo, brand_address, brand_contact, brand_bank, brand_rekening FROM users WHERE id = $partner_id")->fetch();
            
            if ($partner_brand && !empty($partner_brand['brand_name'])) {
                $comp_name = $partner_brand['brand_name'];
                if (!empty($partner_brand['brand_logo'])) $comp_logo = $partner_brand['brand_logo'];
                if (!empty($partner_brand['brand_bank'])) $bank_info = $partner_brand['brand_bank'] . " " . $partner_brand['brand_rekening'];
            }
        }

        $invoices = $db->query("
            SELECT i.* FROM invoices i 
            WHERE i.customer_id = " . intval($customer['id']) . "
            ORDER BY i.id DESC
        ")->fetchAll();
        
        // Handle Print Special Action from Portal
        if (isset($_GET['action']) && $_GET['action'] === 'print' && isset($_GET['id'])) {
            $inv_id = intval($_GET['id']);
            // Verify this invoice belongs to this search result (security)
            $invoice = null;
            foreach ($invoices as $inv) {
                if ($inv['id'] == $inv_id) {
                    $invoice = array_merge($inv, $customer); // Merge for printing template
                    break;
                }
            }
            
            if ($invoice) {
                require __DIR__ . '/print.php';
                exit;
            }
        }
    }
}

// Fetch Active Banners for Customers
$active_banners = $db->query("SELECT * FROM banners WHERE is_active = 1 AND target_role IN ('all', 'customer') ORDER BY created_at DESC")->fetchAll();
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
            <?php if(!empty($comp_logo)): ?>
                <div class="brand-logo-wrapper" style="height:60px; width:auto; margin-bottom:15px;">
                    <img src="<?= htmlspecialchars($comp_logo) ?>" alt="Logo">
                </div>
            <?php else: ?>
                <i class="fas fa-wifi" style="font-size:40px; color:var(--primary); margin-bottom:15px;"></i>
            <?php endif; ?>
            <h1><?= htmlspecialchars($comp_name) ?></h1>
            <p style="color:var(--text-secondary);">Portal Cek Tagihan Pelanggan</p>
        </div>

        <?php if(count($active_banners) > 0): ?>
        <div class="banner-container" style="margin-bottom:20px;">
            <?php foreach($active_banners as $banner): ?>
                <div class="glass-panel banner-item" style="padding:15px; border-radius:16px; border-left:4px solid var(--primary); display:flex; gap:15px; align-items:center; margin-bottom:12px; position:relative; overflow:hidden;">
                    <?php if($banner['image_path']): ?>
                        <div class="banner-img" style="flex-shrink:0;">
                            <img src="<?= $banner['image_path'] ?>" style="width:100px; height:60px; object-fit:cover; border-radius:10px; cursor:zoom-in;" onclick="openImagePreview(this.src)" title="Klik untuk perbesar">
                        </div>
                    <?php endif; ?>
                    <div class="banner-text">
                        <h4 style="margin:0 0 5px 0; color:var(--text-primary); font-size:14px; display:flex; align-items:center; gap:6px;">
                            <i class="fas fa-bullhorn" style="color:var(--primary); font-size:12px;"></i> <?= htmlspecialchars($banner['title']) ?>
                        </h4>
                        <p style="margin:0; font-size:12px; color:var(--text-secondary); line-height:1.5;"><?= nl2br(htmlspecialchars($banner['content'])) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

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
            
            <!-- Link Pembayaran -->
            <?php if(!empty($bank_info)): ?>
            <div class="glass-panel" style="padding:20px; margin-bottom:20px; border:1px solid rgba(var(--primary-rgb), 0.2); background:rgba(var(--primary-rgb), 0.05); text-align:center;">
                <div style="font-size:11px; color:var(--text-secondary); text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">PEMBAYARAN TRANSFER KE:</div>
                <div style="font-size:20px; font-weight:800; color:var(--primary);"><?= htmlspecialchars($bank_info) ?></div>
            </div>
            <?php endif; ?>

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
                    <div style="text-align:right; display:flex; flex-direction:column; align-items:flex-end; gap:8px;">
                        <div style="font-weight:700;">Rp <?= number_format($inv['amount'], 0, ',', '.') ?></div>
                        <div style="display:flex; gap:6px;">
                            <?php if($inv['status'] === 'Lunas'): ?>
                                <span class="badge badge-success" style="font-size:10px;"><i class="fas fa-check"></i> Lunas</span>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(239,68,68,0.15); color:var(--danger); border:1px solid rgba(239,68,68,0.3); font-size:10px;">Belum Lunas</span>
                            <?php endif; ?>
                            <a href="index.php?page=customer_portal&code=<?= urlencode($code_input) ?>&action=print&id=<?= $inv['id'] ?>" target="_blank" class="btn btn-xs btn-ghost" style="padding:2px 8px; font-size:10px; border-radius:6px; border:1px solid var(--glass-border);">
                                <i class="fas fa-print"></i> Cetak
                            </a>
                        </div>
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
    <!-- Global Image Modal -->
    <div id="globalImageModal" class="image-modal" onclick="closeImagePreview()">
        <span class="image-modal-close" onclick="closeImagePreview()">&times;</span>
        <img id="modalImg" src="" alt="Preview">
    </div>

    <script>
    function toggleTheme() {
        const html = document.documentElement;
        const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('billing_theme', next);
    }

    function openImagePreview(src) {
        const modal = document.getElementById('globalImageModal');
        const modalImg = document.getElementById('modalImg');
        modal.style.display = 'flex';
        modalImg.src = src;
        document.body.style.overflow = 'hidden'; 
    }

    function closeImagePreview() {
        document.getElementById('globalImageModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }
    </script>
</body>
</html>
