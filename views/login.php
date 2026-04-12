<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Billing RT/RW Net</title>
    <link rel="stylesheet" href="public/neumorphism.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script>(function(){const t=localStorage.getItem('billing_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})()</script>
    <style>
        .login-box {
            padding: 50px 40px;
        }
        .login-logo {
            max-height: 80px;
            max-width: 200px;
            margin: 0 auto 15px auto;
            display: block;
            object-fit: contain;
        }
        .login-box h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        .login-box p {
            margin-bottom: 40px;
        }
        /* Mobile Specific fixes */
        @media (max-width: 480px) {
            .login-box {
                padding: 30px 20px 60px 20px; /* Extra bottom padding for floating button */
                background: transparent;
                border: none;
                box-shadow: none;
            }
            .theme-toggle {
                bottom: 15px !important;
                right: 15px !important;
                width: 40px !important;
                height: 40px !important;
            }
        }
    </style>
</head>
<body>
    <?php 
    $company = $db->query("SELECT company_name, company_logo FROM settings WHERE id=1")->fetch(); 
    $requested_role = $_GET['role'] ?? 'partner';
    
    $is_staff = ($requested_role === 'staff');
    $portal_title = $is_staff ? 'Portal Staff & Admin' : 'Portal Partner (B2B)';
    $portal_desc = $is_staff ? 'Manajemen Billing & Operasional' : 'Layanan Mandiri Khusus Mitra Terdaftar';
    $portal_icon = $is_staff ? 'fa-shield-alt' : 'fa-handshake';
    $portal_color = $is_staff ? '#ef4444' : '#23CED9';
    ?>
    <div class="login-container">
        <div class="glass-panel login-box">
            <div style="text-align:center; margin-bottom:25px;">
                <i class="fas <?= $portal_icon ?>" style="font-size: 50px; color: <?= $portal_color ?>; margin-bottom:15px; display:inline-block; opacity:0.8;"></i>
                <h1 style="font-size: 22px; font-weight: 700; margin-bottom: 5px; color:var(--text-primary);"><?= $portal_title ?></h1>
                <p style="font-size: 14px; color: var(--text-secondary);"><?= $portal_desc ?></p>
            </div>
            
            <?php if(isset($error)): ?>
                <div class="badge badge-danger" style="display:block; margin-bottom:15px; background:rgba(239, 68, 68, 0.1); color:#ef4444; border:1px solid rgba(239, 68, 68, 0.2); padding:10px; border-radius:8px; font-size:13px;">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="index.php?page=login_post" method="POST">
                <input type="hidden" name="requested_role" value="<?= $is_staff ? 'staff' : 'partner' ?>">
                <div class="form-group" style="position:relative;">
                    <i class="fas fa-user" style="position:absolute; left:16px; top:15px; color:var(--text-secondary);"></i>
                    <input type="text" name="username" class="form-control" required placeholder="Nama Pengguna" style="padding-left: 45px;">
                </div>
                <div class="form-group" style="position:relative;">
                    <i class="fas fa-lock" style="position:absolute; left:16px; top:15px; color:var(--text-secondary);"></i>
                    <input type="password" name="password" class="form-control" required placeholder="Kata Sandi" style="padding-left: 45px;">
                </div>
                <button type="submit" class="btn btn-primary btn-block" style="margin-top:20px; padding: 14px; font-size:18px;">Masuk Aplikasi</button>
            </form>
            
            <div style="text-align:center; margin-top:20px; font-size:13px; color:var(--text-secondary);">
                <?php if($is_staff): ?>
                    Bukan Staff? <a href="index.php?page=login&role=partner" style="color:var(--primary); font-weight:600; text-decoration:none;">Portal Partner</a>
                <?php else: ?>
                    Bukan Partner? <a href="index.php?page=login&role=staff" style="color:#ef4444; font-weight:600; text-decoration:none;">Akses Staff</a>
                <?php endif; ?>
                <div style="margin-top:25px;">
                    <a href="index.php?page=landing" class="btn btn-ghost btn-sm" style="font-size:13px; color:var(--text-secondary); padding: 8px 15px;">
                        <i class="fas fa-home"></i> Kembali ke Beranda
                    </a>
                </div>
            </div>
        </div>
    <!-- Floating Theme Toggle -->
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
