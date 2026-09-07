<?php $__login_company = $db->query("SELECT company_name FROM settings WHERE id=1")->fetchColumn() ?: ""; ?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<?= theme_boot_script() ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk<?= !empty($__login_company) ? " · " . htmlspecialchars($__login_company) : "" ?></title>
    <link rel="stylesheet" href="public/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
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
        }
    </style>
</head>
<body>
    <?php
    $company = $db->query("SELECT company_name, company_logo FROM settings WHERE id=1")->fetch();
    $logo_src = '';
    if (!empty($company['company_logo'])) {
        $logo_src = preg_match('/^https?:\/\//', $company['company_logo'])
            ? $company['company_logo']
            : '/' . str_replace(' ', '%20', ltrim($company['company_logo'], '/'));
    }
    ?>
    <div class="login-container">
        <div class="glass-panel login-box">
            <div style="text-align:center; margin-bottom:25px;">
                <?php if ($logo_src): ?>
                    <img src="<?= htmlspecialchars($logo_src) ?>" alt="<?= htmlspecialchars($company['company_name'] ?? '') ?>" class="login-logo">
                <?php else: ?>
                    <i class="fas fa-network-wired" style="font-size: 46px; color: var(--primary); margin-bottom:15px; display:inline-block; opacity:0.85;"></i>
                <?php endif; ?>
                <h1 style="font-size: 22px; font-weight: 700; margin-bottom: 5px; color:var(--text-primary);"><?= htmlspecialchars($company['company_name'] ?? 'Sistem Billing') ?></h1>
                <p style="font-size: 14px; color: var(--text-secondary);">Masuk dengan akun Anda. Halaman yang terbuka menyesuaikan hak akses.</p>
            </div>
            
            <?php if(isset($error)): ?>
                <div class="badge badge-danger" style="display:block; margin-bottom:15px; background:rgba(239, 68, 68, 0.1); color:#ef4444; border:1px solid rgba(239, 68, 68, 0.2); padding:10px; border-radius:8px; font-size:13px;">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="index.php?page=login_post" method="POST">
<?= csrf_field() ?>
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
                Lupa kata sandi? Hubungi admin untuk mengatur ulang.
                <div style="margin-top:25px;">
                    <a href="index.php?page=landing" class="btn btn-ghost btn-sm" style="font-size:13px; color:var(--text-secondary); padding: 8px 15px;">
                        <i class="fas fa-home"></i> Kembali ke Beranda
                    </a>
                </div>
            </div>
        </div>
</body>
</html>
