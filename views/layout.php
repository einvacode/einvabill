<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Dashboard</title>
    <link rel="stylesheet" href="public/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script>
        // Load saved theme instantly to prevent flash
        (function() {
            const saved = localStorage.getItem('billing_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', saved);
        })();
    </script>
</head>
<body>
    <div class="app-layout">
        <aside class="sidebar">
            <div>
                <?php $site_settings = $db->query("SELECT company_name, company_logo FROM settings WHERE id=1")->fetch(); ?>
                <div class="sidebar-header" style="display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap;">
                    <?php if(!empty($site_settings['company_logo'])): ?>
                        <img src="<?= htmlspecialchars($site_settings['company_logo']) ?>" alt="Logo" style="max-height: 40px; border-radius: 4px;">
                    <?php else: ?>
                        <i class="fas fa-wifi" style="font-size: 24px; color: var(--primary);"></i>
                    <?php endif; ?>
                    <h2 style="font-size: 18px; margin: 0; line-height: 1.2; word-break: break-word;"><?= htmlspecialchars(strtoupper($site_settings['company_name'])) ?></h2>
                </div>
                <div class="nav-links">
                    <?php if($_SESSION['user_role'] === 'admin'): ?>
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">MENU UTAMA</div>
                        <a href="index.php?page=admin_dashboard" class="nav-link <?= $page == 'admin_dashboard' ? 'active' : '' ?>"><i class="fas fa-home"></i> Dashboard</a>
                        <a href="index.php?page=admin_customers" class="nav-link <?= $page == 'admin_customers' ? 'active' : '' ?>"><i class="fas fa-users"></i> Pelanggan</a>
                        <a href="index.php?page=admin_invoices" class="nav-link <?= $page == 'admin_invoices' && ($filter_status ?? '') != 'belum' ? 'active' : '' ?>"><i class="fas fa-file-invoice-dollar"></i> Tagihan</a>
                        <a href="index.php?page=admin_invoices&filter_status=belum" class="nav-link <?= $page == 'admin_invoices' && ($filter_status ?? '') == 'belum' ? 'active' : '' ?>"><i class="fas fa-user-clock" style="color:#f43f5e;"></i> User Tunggakan</a>
                        <a href="index.php?page=admin_expenses" class="nav-link <?= $page == 'admin_expenses' ? 'active' : '' ?>"><i class="fas fa-wallet" style="color:var(--warning);"></i> Pengeluaran</a>
                        
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">INFRASTRUKTUR</div>
                        <a href="index.php?page=admin_router" class="nav-link <?= $page == 'admin_router' ? 'active' : '' ?>"><i class="fas fa-network-wired"></i> Router</a>
                        <a href="index.php?page=admin_assets" class="nav-link <?= $page == 'admin_assets' ? 'active' : '' ?>"><i class="fas fa-boxes"></i> Aset Jaringan</a>
                        <a href="index.php?page=admin_map" class="nav-link <?= $page == 'admin_map' ? 'active' : '' ?>"><i class="fas fa-map-location-dot"></i> Peta Jaringan</a>

                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">DATA MASTER</div>
                        <a href="index.php?page=admin_packages" class="nav-link <?= $page == 'admin_packages' ? 'active' : '' ?>"><i class="fas fa-box"></i> Manajemen Paket</a>
                        <a href="index.php?page=admin_areas" class="nav-link <?= $page == 'admin_areas' ? 'active' : '' ?>"><i class="fas fa-map-marker-alt"></i> Manajemen Area</a>
                        <a href="index.php?page=admin_users" class="nav-link <?= $page == 'admin_users' ? 'active' : '' ?>"><i class="fas fa-user-shield"></i> Akses Pengguna</a>

                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">SISTEM & LAPORAN</div>
                        <a href="index.php?page=admin_reports" class="nav-link <?= $page == 'admin_reports' ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> Laporan Keuangan</a>
                        <a href="index.php?page=admin_report_assets" class="nav-link <?= $page == 'admin_report_assets' ? 'active' : '' ?>"><i class="fas fa-file-contract"></i> Laporan Aset</a>
                        <a href="index.php?page=admin_landing" class="nav-link <?= $page == 'admin_landing' ? 'active' : '' ?>"><i class="fas fa-globe"></i> Web Profil</a>
                        <a href="index.php?page=admin_settings" class="nav-link <?= $page == 'admin_settings' ? 'active' : '' ?>"><i class="fas fa-cog"></i> Pengaturan</a>
                        <a href="index.php?page=admin_backup" class="nav-link <?= $page == 'admin_backup' ? 'active' : '' ?>"><i class="fas fa-shield-alt"></i> Backup & Restore</a>
                    <?php elseif($_SESSION['user_role'] === 'collector'): ?>
                        <a href="index.php?page=collector" class="nav-link active"><i class="fas fa-motorcycle"></i> Penagih</a>
                    <?php elseif($_SESSION['user_role'] === 'partner'): ?>
                        <a href="index.php?page=partner" class="nav-link active"><i class="fas fa-handshake"></i> Mitra</a>
                    <?php endif; ?>
                </div>
            </div>
            <a href="index.php?page=logout" class="nav-link" style="margin-top: auto; margin-bottom: 20px;"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </aside>

        <main class="main-content">
            <div class="topbar glass-panel" style="padding:15px 24px;">
                <div style="font-weight:600; font-size:18px;">
                    <?php
                        if($page == 'admin_dashboard') echo 'Dashboard Admin';
                        elseif($page == 'admin_customers') echo 'Manajemen Pelanggan';
                        elseif($page == 'admin_assets') echo 'Manajemen Aset (OLT/ODP)';
                        elseif($page == 'admin_map') echo 'Peta Sebaran Jaringan';
                        elseif($page == 'admin_invoices') echo 'Manajemen Tagihan';
                        elseif($page == 'admin_expenses') echo 'Manajemen Pengeluaran';
                        elseif($page == 'admin_reports') echo 'Laporan Keuangan';
                        elseif($page == 'admin_report_assets') echo 'Laporan Inventaris Aset';
                        elseif($page == 'admin_landing') echo 'Pengaturan Web Profil';
                        elseif($page == 'admin_users') echo 'Akses Pengguna';
                        elseif($page == 'admin_router') echo 'Monitoring Router';
                        elseif($page == 'admin_settings') echo 'Pengaturan Perusahaan';
                        elseif($page == 'admin_backup') echo 'Backup & Restore Database';
                        elseif($page == 'collector') echo 'Dashboard Penagih';
                        elseif($page == 'partner') echo 'Dashboard Mitra';
                    ?>
                </div>
                <div class="user-profile">
                    <!-- Theme Toggle -->
                    <button class="theme-toggle" onclick="toggleTheme()" title="Ganti Tema">
                        <i class="fas fa-sun theme-icon-dark"></i>
                        <i class="fas fa-moon theme-icon-light"></i>
                    </button>
                    <div style="text-align:right">
                        <div style="font-size:14px; font-weight:600;"><?= htmlspecialchars($_SESSION['user_name']) ?></div>
                        <div style="font-size:12px; color:var(--text-secondary); text-transform:capitalize;"><?= htmlspecialchars($_SESSION['user_role']) ?></div>
                    </div>
                    <div class="user-avatar">
                        <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>
                    </div>
                </div>
            </div>

            <!-- Page Content -->
            <?= $content ?? '' ?>
            
        </main>
    </div>

    <!-- Mobile Bottom Navigation -->
    <nav class="mobile-bottom-nav">
        <?php if($_SESSION['user_role'] === 'admin'): ?>
            <a href="index.php?page=admin_dashboard" class="<?= $page == 'admin_dashboard' ? 'active' : '' ?>">
                <i class="fas fa-home"></i><span>Home</span>
            </a>
            <a href="index.php?page=admin_customers" class="<?= $page == 'admin_customers' ? 'active' : '' ?>">
                <i class="fas fa-users"></i><span>Pelanggan</span>
            </a>
            <a href="index.php?page=admin_map" class="<?= $page == 'admin_map' ? 'active' : '' ?>">
                <i class="fas fa-map-location-dot"></i><span>Peta</span>
            </a>
            <a href="index.php?page=admin_invoices" class="<?= $page == 'admin_invoices' ? 'active' : '' ?>">
                <i class="fas fa-file-invoice-dollar"></i><span>Tagihan</span>
            </a>
            <a href="index.php?page=admin_reports" class="<?= $page == 'admin_reports' ? 'active' : '' ?>">
                <i class="fas fa-chart-line"></i><span>Keuangan</span>
            </a>
            <a href="index.php?page=admin_report_assets" class="<?= $page == 'admin_report_assets' ? 'active' : '' ?>">
                <i class="fas fa-file-contract"></i><span>Aset</span>
            </a>
            <a href="#" onclick="toggleMobileMenu(event)" id="mobileMenuToggle">
                <i class="fas fa-ellipsis-h"></i><span>Lainnya</span>
            </a>
        <?php elseif($_SESSION['user_role'] === 'collector'): ?>
            <a href="index.php?page=collector&tab=tugas" class="<?= $page == 'collector' && ($coll_tab ?? 'tugas') == 'tugas' ? 'active' : '' ?>">
                <i class="fas fa-tasks"></i><span>Tagihan</span>
            </a>
            <a href="index.php?page=collector&tab=pelanggan" class="<?= $page == 'collector' && ($coll_tab ?? '') == 'pelanggan' ? 'active' : '' ?>">
                <i class="fas fa-users"></i><span>Pelanggan</span>
            </a>
            <a href="index.php?page=logout" style="color:var(--danger);">
                <i class="fas fa-sign-out-alt"></i><span>Keluar</span>
            </a>
        <?php elseif($_SESSION['user_role'] === 'partner'): ?>
            <a href="index.php?page=partner" class="<?= $page == 'partner' ? 'active' : '' ?>">
                <i class="fas fa-handshake"></i><span>Dashboard</span>
            </a>
            <a href="index.php?page=logout" style="color:var(--danger);">
                <i class="fas fa-sign-out-alt"></i><span>Keluar</span>
            </a>
        <?php endif; ?>
    </nav>
    
    <!-- Mobile "More" Menu Overlay (Admin only) -->
    <?php if($_SESSION['user_role'] === 'admin'): ?>
    <div id="mobileMenuOverlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1001; align-items:flex-end; justify-content:center;" onclick="closeMobileMenu()">
        <div style="width:100%; max-width:500px; padding:16px; padding-bottom:80px;" onclick="event.stopPropagation()">
            <div class="glass-panel" style="padding:16px; border-radius:20px;">
                <div style="font-size:14px; font-weight:600; color:var(--text-secondary); margin-bottom:12px; padding:0 8px;">Menu Lainnya</div>
                <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:8px;">
                    <a href="index.php?page=admin_users" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;" class="<?= $page == 'admin_users' ? 'active' : '' ?>">
                        <i class="fas fa-user-shield" style="font-size:22px; color:var(--primary);"></i> Pengguna
                    </a>
                    <a href="index.php?page=admin_invoices&filter_status=belum" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;" class="<?= $page == 'admin_invoices' && ($filter_status ?? '') == 'belum' ? 'active' : '' ?>">
                        <i class="fas fa-user-clock" style="font-size:22px; color:#f43f5e;"></i> Tunggakan
                    </a>
                    <a href="index.php?page=admin_packages" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;" class="<?= $page == 'admin_packages' ? 'active' : '' ?>">
                        <i class="fas fa-box" style="font-size:22px; color:#ec4899;"></i> Paket
                    </a>
                    <a href="index.php?page=admin_areas" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;" class="<?= $page == 'admin_areas' ? 'active' : '' ?>">
                        <i class="fas fa-map-marker-alt" style="font-size:22px; color:#3b82f6;"></i> Area
                    </a>
                    <a href="index.php?page=admin_expenses" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;" class="<?= $page == 'admin_expenses' ? 'active' : '' ?>">
                        <i class="fas fa-wallet" style="font-size:22px; color:var(--warning);"></i> Pengeluaran
                    </a>
                    <a href="index.php?page=admin_router" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;">
                        <i class="fas fa-network-wired" style="font-size:22px; color:var(--success);"></i> Router
                    </a>
                    <a href="index.php?page=admin_landing" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;">
                        <i class="fas fa-globe" style="font-size:22px; color:var(--warning);"></i> Web Profil
                    </a>
                    <a href="index.php?page=admin_settings" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;">
                        <i class="fas fa-cog" style="font-size:22px; color:var(--text-secondary);"></i> Pengaturan
                    </a>
                    <a href="index.php?page=admin_backup" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;">
                        <i class="fas fa-shield-alt" style="font-size:22px; color:#8b5cf6;"></i> Backup
                    </a>
                    <a href="index.php?page=logout" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--danger); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;">
                        <i class="fas fa-sign-out-alt" style="font-size:22px;"></i> Keluar
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    function toggleTheme() {
        const html = document.documentElement;
        const current = html.getAttribute('data-theme');
        const next = current === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('billing_theme', next);
    }
    
    function toggleMobileMenu(e) {
        e.preventDefault();
        const overlay = document.getElementById('mobileMenuOverlay');
        overlay.style.display = overlay.style.display === 'flex' ? 'none' : 'flex';
    }
    
    function closeMobileMenu() {
        document.getElementById('mobileMenuOverlay').style.display = 'none';
    }
    </script>
</body>
</html>
