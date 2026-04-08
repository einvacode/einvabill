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
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <header class="mobile-header">
        <div style="display: flex; align-items: center; gap: 12px;">
            <button class="burger-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
            <span style="font-weight: 800; font-size: 16px; letter-spacing: 0.5px;"><?= htmlspecialchars($site_settings['company_name'] ?? 'BILLING') ?></span>
        </div>
        <div onclick="toggleTheme()" style="cursor: pointer; opacity: 0.8;"><i class="fas fa-moon"></i></div>
    </header>
    <div class="app-layout">
        <aside class="sidebar">
            <div>
                <?php $site_settings = $db->query("SELECT company_name, company_logo FROM settings WHERE id=1")->fetch(); ?>
                <div class="sidebar-header" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 20px 10px 30px;">
                    <?php if(!empty($site_settings['company_logo'])): ?>
                        <div class="brand-logo-wrapper sidebar-logo-box">
                            <img src="<?= htmlspecialchars($site_settings['company_logo']) ?>" alt="Logo">
                        </div>
                    <?php else: ?>
                        <div style="background: var(--nav-active-bg); padding: 12px; border-radius: 12px; margin-bottom: 5px;">
                            <i class="fas fa-wifi" style="font-size: 28px; color: var(--primary);"></i>
                        </div>
                    <?php endif; ?>
                    <h2 style="font-size: 16px; margin: 0; line-height: 1.3; word-break: break-word; text-align: center; font-weight: 700; color: #ffffff; text-shadow: 0 2px 4px rgba(0,0,0,0.3);"><?= htmlspecialchars(strtoupper($site_settings['company_name'])) ?></h2>
                </div>
                <div class="nav-links">
                    <?php if($_SESSION['user_role'] === 'admin'): ?>
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">MENU UTAMA</div>
                        <a href="index.php?page=admin_dashboard" class="nav-link <?= $page == 'admin_dashboard' ? 'active' : '' ?>"><i class="fas fa-home"></i> Dashboard</a>
                        <a href="index.php?page=admin_customers&filter_type=customer" class="nav-link <?= $page == 'admin_customers' && ($_GET['filter_type'] ?? '') == 'customer' ? 'active' : '' ?>"><i class="fas fa-users"></i> Pelanggan Rumahan</a>
                        <a href="index.php?page=admin_customers&filter_type=partner" class="nav-link <?= $page == 'admin_customers' && ($_GET['filter_type'] ?? '') == 'partner' ? 'active' : '' ?>"><i class="fas fa-handshake"></i> Kemitraan (B2B)</a>
                        <a href="index.php?page=admin_invoices&filter_type=customer" class="nav-link <?= $page == 'admin_invoices' && ($_GET['filter_type'] ?? '') == 'customer' && ($filter_status ?? '') != 'belum' ? 'active' : '' ?>"><i class="fas fa-file-invoice-dollar"></i> Tagihan Pelanggan</a>
                        <a href="index.php?page=admin_invoices&filter_type=partner" class="nav-link <?= $page == 'admin_invoices' && ($_GET['filter_type'] ?? '') == 'partner' ? 'active' : '' ?>"><i class="fas fa-handshake" style="color:var(--primary);"></i> Tagihan Kemitraan</a>
                        <a href="index.php?page=admin_expenses" class="nav-link <?= $page == 'admin_expenses' ? 'active' : '' ?>"><i class="fas fa-wallet" style="color:var(--warning);"></i> Pengeluaran</a>
                        
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">INFRASTRUKTUR</div>
                        <a href="index.php?page=admin_router" class="nav-link <?= $page == 'admin_router' ? 'active' : '' ?>"><i class="fas fa-network-wired"></i> Router</a>
                        <a href="index.php?page=admin_assets" class="nav-link <?= $page == 'admin_assets' ? 'active' : '' ?>"><i class="fas fa-boxes"></i> Aset Jaringan</a>
                        <a href="index.php?page=admin_map" class="nav-link <?= $page == 'admin_map' ? 'active' : '' ?>"><i class="fas fa-map-location-dot"></i> Peta Jaringan</a>

                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">DATA MASTER</div>
                        <a href="index.php?page=admin_packages" class="nav-link <?= $page == 'admin_packages' ? 'active' : '' ?>"><i class="fas fa-box"></i> Manajemen Paket</a>
                        <a href="index.php?page=admin_areas" class="nav-link <?= $page == 'admin_areas' ? 'active' : '' ?>"><i class="fas fa-map-marker-alt"></i> Manajemen Area</a>
                        <a href="index.php?page=admin_users" class="nav-link <?= $page == 'admin_users' ? 'active' : '' ?>"><i class="fas fa-user-shield"></i> Akses Pengguna</a>

                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">SISTEM & TOOLS</div>
                        
                        <!-- Dropdown Laporan -->
                        <div class="nav-dropdown <?= in_array($page, ['admin_reports', 'admin_report_assets']) ? 'open' : '' ?>">
                            <div class="nav-link dropdown-toggle" onclick="toggleDropdown(this)">
                                <span><i class="fas fa-chart-bar"></i> Laporan</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="dropdown-content">
                                <a href="index.php?page=admin_reports" class="nav-link dropdown-link <?= $page == 'admin_reports' ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> Keuangan</a>
                                <a href="index.php?page=admin_report_assets" class="nav-link dropdown-link <?= $page == 'admin_report_assets' ? 'active' : '' ?>"><i class="fas fa-file-contract"></i> Aset</a>
                            </div>
                        </div>

                        <!-- Dropdown Pengaturan -->
                        <div class="nav-dropdown <?= in_array($page, ['admin_wa_gateway', 'admin_settings', 'admin_backup', 'admin_banners', 'admin_landing']) ? 'open' : '' ?>">
                            <div class="nav-link dropdown-toggle" onclick="toggleDropdown(this)">
                                <span><i class="fas fa-sliders-h"></i> Pengaturan</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="dropdown-content">
                                <a href="index.php?page=admin_wa_gateway" class="nav-link dropdown-link <?= $page == 'admin_wa_gateway' ? 'active' : '' ?>"><i class="fab fa-whatsapp" style="color:#25D366;"></i> WA Perangkat</a>
                                <a href="index.php?page=admin_settings" class="nav-link dropdown-link <?= $page == 'admin_settings' ? 'active' : '' ?>"><i class="fas fa-cog"></i> Profil & Apps</a>
                                <a href="index.php?page=admin_landing" class="nav-link dropdown-link <?= $page == 'admin_landing' ? 'active' : '' ?>"><i class="fas fa-globe"></i> Web Profil</a>
                                <a href="index.php?page=admin_banners" class="nav-link dropdown-link <?= $page == 'admin_banners' ? 'active' : '' ?>"><i class="fas fa-scroll" style="color:var(--warning);"></i> Banner</a>
                                <a href="index.php?page=admin_backup" class="nav-link dropdown-link <?= $page == 'admin_backup' ? 'active' : '' ?>"><i class="fas fa-shield-alt"></i> Backup</a>
                            </div>
                        </div>

                    <?php elseif($_SESSION['user_role'] === 'collector'): ?>
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">TUGAS PENAGIHAN</div>
                        <a href="index.php?page=collector" class="nav-link <?= $page == 'collector' ? 'active' : '' ?>"><i class="fas fa-motorcycle"></i> Dashboard</a>
                        <a href="index.php?page=admin_customers" class="nav-link <?= $page == 'admin_customers' ? 'active' : '' ?>"><i class="fas fa-users"></i> Daftar Pelanggan</a>
                        <a href="index.php?page=admin_invoices" class="nav-link <?= $page == 'admin_invoices' ? 'active' : '' ?>"><i class="fas fa-file-invoice-dollar"></i> Data Tagihan</a>
                        
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">UTILITY</div>
                        <a href="index.php?page=admin_wa_gateway" class="nav-link <?= $page == 'admin_wa_gateway' ? 'active' : '' ?>"><i class="fab fa-whatsapp" style="color:#25D366;"></i> WhatsApp Perangkat</a>
                        <a href="index.php?page=admin_map" class="nav-link <?= $page == 'admin_map' ? 'active' : '' ?>"><i class="fas fa-map-location-dot"></i> Peta Lokasi</a>

                    <?php elseif($_SESSION['user_role'] === 'partner'): ?>
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">PORTAL MITRA</div>
                        <a href="index.php?page=partner" class="nav-link <?= $page == 'partner' ? 'active' : '' ?>"><i class="fas fa-handshake"></i> Dashboard</a>
                        <a href="index.php?page=partner_collection" class="nav-link <?= $page == 'partner_collection' ? 'active' : '' ?>"><i class="fas fa-motorcycle" style="color:var(--warning);"></i> Penagihan Lapangan</a>
                        <a href="index.php?page=admin_customers" class="nav-link <?= $page == 'admin_customers' ? 'active' : '' ?>"><i class="fas fa-users"></i> Pelanggan Saya</a>
                        
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">OPERASIONAL</div>
                        <a href="index.php?page=admin_packages" class="nav-link <?= $page == 'admin_packages' ? 'active' : '' ?>"><i class="fas fa-box"></i> Paket Internet</a>
                        <a href="index.php?page=admin_router" class="nav-link <?= $page == 'admin_router' ? 'active' : '' ?>"><i class="fas fa-network-wired"></i> Manajemen Router</a>
                        
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">KEUANGAN & TOOLS</div>
                        <div class="nav-dropdown <?= in_array($page, ['admin_invoices', 'partner_isp_invoices', 'admin_reports']) ? 'open' : '' ?>">
                            <div class="nav-link dropdown-toggle" onclick="toggleDropdown(this)">
                                <span><i class="fas fa-wallet"></i> Keuangan</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="dropdown-content">
                                <a href="index.php?page=admin_invoices" class="nav-link dropdown-link <?= $page == 'admin_invoices' ? 'active' : '' ?>"><i class="fas fa-file-invoice-dollar"></i> Riwayat Tagihan</a>
                                <a href="index.php?page=partner_isp_invoices" class="nav-link dropdown-link <?= $page == 'partner_isp_invoices' ? 'active' : '' ?>"><i class="fas fa-receipt" style="color:#ef4444;"></i> Tagihan Ke ISP</a>
                                <a href="index.php?page=admin_reports" class="nav-link dropdown-link <?= $page == 'admin_reports' ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> Laporan</a>
                            </div>
                        </div>

                        <a href="index.php?page=admin_wa_gateway" class="nav-link <?= $page == 'admin_wa_gateway' ? 'active' : '' ?>"><i class="fab fa-whatsapp" style="color:#25D366;"></i> WhatsApp Perangkat</a>
                        <a href="index.php?page=partner_settings" class="nav-link <?= $page == 'partner_settings' ? 'active' : '' ?>"><i class="fas fa-id-card-alt" style="color:#10b981;"></i> Pengaturan Profil</a>
                    <?php endif; ?>
                </div>
            </div>
            <a href="index.php?page=logout" class="nav-link" style="margin-top: auto; margin-bottom: 20px;"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </aside>

        <main class="main-content">
            <!-- Topbar (Hidden for Collector as requested) -->
            <?php if($page !== 'collector'): ?>
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
                        elseif($page == 'admin_banners') echo 'Manajemen Banner Informasi';
                        elseif($page == 'admin_landing') echo 'Pengaturan Web Profil';
                        elseif($page == 'admin_users') echo 'Akses Pengguna';
                        elseif($page == 'admin_wa_gateway') echo 'Manajemen Perangkat WhatsApp';
                        elseif($page == 'admin_settings') echo 'Pengaturan Perusahaan';
                        elseif($page == 'admin_backup') echo 'Backup & Restore Database';
                        elseif($page == 'collector') echo 'Dashboard Penagih';
                        elseif($page == 'partner') echo 'Dashboard Mitra';
                        elseif($page == 'partner_settings') echo 'Pengaturan Profil Mitra';
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
            <?php endif; ?>

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
            <a href="index.php?page=partner" class="nav-link <?= $page == 'partner' ? 'active' : '' ?>">
                <i class="fas fa-home"></i><span>Home</span>
            </a>
            <a href="index.php?page=partner_collection" class="nav-link <?= $page == 'partner_collection' ? 'active' : '' ?>">
                <i class="fas fa-motorcycle"></i><span>Penagihan</span>
            </a>
            <a href="index.php?page=admin_customers" class="nav-link <?= $page == 'admin_customers' ? 'active' : '' ?>">
                <i class="fas fa-users"></i><span>Pelanggan</span>
            </a>
            <a href="index.php?page=partner_isp_invoices" class="nav-link <?= $page == 'partner_isp_invoices' ? 'active' : '' ?>">
                <i class="fas fa-receipt"></i><span>Tagihan ISP</span>
            </a>
            <a href="index.php?page=logout" style="color:var(--danger);">
                <i class="fas fa-sign-out-alt"></i><span>Keluar</span>
            </a>
        <?php endif; ?>
    </nav>
    
    <!-- Mobile "More" Menu Overlay (Admin only) -->
    <?php if($_SESSION['user_role'] === 'admin'): ?>
    <div id="mobileMenuOverlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1001; align-items:flex-end; justify-content:center;" onclick="closeMobileMenu()">
        <div style="width:100%; max-width:500px; padding:16px; padding-bottom:80px; max-height: 100vh; overflow-y: auto; -webkit-overflow-scrolling: touch;" onclick="event.stopPropagation()">
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
                    <a href="index.php?page=admin_banners" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;" class="<?= $page == 'admin_banners' ? 'active' : '' ?>">
                        <i class="fas fa-scroll" style="font-size:22px; color:var(--warning);"></i> Banner
                    </a>
                    <a href="index.php?page=admin_backup" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;">
                        <i class="fas fa-shield-alt" style="font-size:22px; color:#8b5cf6;"></i> Backup
                    </a>
                    <a href="index.php?page=admin_wa_gateway" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;" class="<?= $page == 'admin_wa_gateway' ? 'active' : '' ?>">
                        <i class="fab fa-whatsapp" style="font-size:22px; color:#25D366;"></i> WA Perangkat
                    </a>
                    <a href="index.php?page=logout" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--danger); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;">
                        <i class="fas fa-sign-out-alt" style="font-size:22px;"></i> Keluar
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Global Image Modal -->
    <div id="globalImageModal" class="image-modal" onclick="closeImagePreview()" style="display:none; flex-direction:column; gap:15px;">
        <span class="image-modal-close" onclick="closeImagePreview()">&times;</span>
        <div style="max-width:90%; max-height:80%; display:flex; justify-content:center; align-items:center;">
            <img id="modalImg" src="" alt="Preview">
        </div>
        <div style="color:white; font-size:14px; background:rgba(255,255,255,0.1); padding:8px 16px; border-radius:50px; backdrop-filter:blur(10px); pointer-events:none;">
            <i class="fas fa-mouse-pointer"></i> Klik di mana saja untuk menutup
        </div>
    </div>

    <script>
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
        
        if (sidebar.classList.contains('active')) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = 'auto';
        }
    }

    // Scroll Persistence Script
    window.addEventListener('beforeunload', () => {
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        if (sidebar) sessionStorage.setItem('sidebarScroll', sidebar.scrollTop);
        if (mainContent) sessionStorage.setItem('mainScroll', mainContent.scrollTop);
        sessionStorage.setItem('windowScroll', window.scrollY);
        sessionStorage.setItem('lastUrl', window.location.href);
    });

    window.addEventListener('DOMContentLoaded', () => {
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        const savedSidebar = sessionStorage.getItem('sidebarScroll');
        const savedMain = sessionStorage.getItem('mainScroll');
        const savedWindow = sessionStorage.getItem('windowScroll');
        const lastUrl = sessionStorage.getItem('lastUrl');

        if (sidebar && savedSidebar) sidebar.scrollTop = savedSidebar;

        // Restore main content & window scroll only if we stay on the same base page (page param matches)
        if (lastUrl) {
            const currentUrl = window.location.href;
            const getPage = (url) => {
                try {
                    return new URL(url).searchParams.get('page');
                } catch(e) { return null; }
            };
            
            if (getPage(lastUrl) === getPage(currentUrl)) {
                if (mainContent && savedMain) mainContent.scrollTop = savedMain;
                if (savedWindow) window.scrollTo(0, savedWindow);
            }
        }
    });

    function toggleTheme() {
        const html = document.documentElement;
        const current = html.getAttribute('data-theme');
        const next = current === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('billing_theme', next);
    }
    
    function toggleMobileMenu(e) {
        if(e) e.preventDefault();
        const overlay = document.getElementById('mobileMenuOverlay');
        if(!overlay) return;
        overlay.style.display = overlay.style.display === 'flex' ? 'none' : 'flex';
    }
    
    function closeMobileMenu() {
        const overlay = document.getElementById('mobileMenuOverlay');
        if(overlay) overlay.style.display = 'none';
    }

    function openImagePreview(src) {
        const modal = document.getElementById('globalImageModal');
        const modalImg = document.getElementById('modalImg');
        modal.style.display = 'flex';
        modalImg.src = src;
        document.body.style.overflow = 'hidden'; // prevent scroll
    }

    function closeImagePreview() {
        document.getElementById('globalImageModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    // Global Notification Handler
    window.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('msg') === 'bulk_paid' || urlParams.get('msg') === 'paid') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                bottom: 80px;
                left: 50%;
                transform: translateX(-50%);
                background: #10b981;
                color: white;
                padding: 12px 24px;
                border-radius: 50px;
                font-weight: 700;
                box-shadow: 0 10px 25px rgba(16,185,129,0.3);
                z-index: 10000;
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 14px;
                transition: all 0.3s ease;
            `;
            toast.innerHTML = '<i class="fas fa-check-circle"></i> Pembayaran Berhasil Diproses!';
            document.body.appendChild(toast);
            
            // Fade in animation
            toast.style.opacity = '0';
            toast.style.transform = 'translate(-50%, 20px)';
            setTimeout(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translate(-50%, 0)';
            }, 10);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translate(-50%, 20px)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);

            // Clean URL
            const newUrl = window.location.href.replace(/[&?]msg=(bulk_paid|paid)/, '');
            window.history.replaceState({}, '', newUrl);
        }
    });

    // Configure Client ID for WhatsApp Gateway
    // Unified to 'admin' so all staff use the central company gateway for background messaging
    const WAGatewayCID = 'admin';
    
    async function checkWAStatus() {
        if (!window.location.search.includes('page=admin') && !window.location.search.includes('page=partner') && !window.location.search.includes('page=collector')) return;
        
        try {
            // Using CID to check specific user connection
            const response = await fetch('/waapi/status?cid=' + WAGatewayCID);
            const data = await response.json();
            const indicators = document.querySelectorAll('.wa-status-indicator');
            indicators.forEach(el => {
                if (data.connected) {
                    el.innerHTML = '<span class="badge badge-success" style="background:rgba(16,185,129,0.1); color:#10b981; border:1px solid rgba(16,185,129,0.3); font-size:10px;"><i class="fas fa-link"></i> WA CONNECTED</span>';
                } else {
                    el.innerHTML = '<span class="status-badge" style="background:rgba(239, 68, 68, 0.1); color:#ef4444; border:1px solid rgba(239, 68, 68, 0.2); padding:2px 8px; border-radius:10px; font-size:10px;"><i class="fas fa-times"></i> WA Disconnected</span>';
                }
            });
        } catch (e) {
            const indicators = document.querySelectorAll('.wa-status-indicator');
            indicators.forEach(el => {
                if (window.location.protocol === 'https:') {
                    el.innerHTML = '<div style="color:#f59e0b; font-size:12px; font-weight:700;"><i class="fas fa-shield-alt"></i> BLOCKED BY HTTPS<br><span style="font-weight:400; opacity:0.8; font-size:10px;">Gunakan <b>HTTP (Tanpa S)</b> atau klik Gembok -> "Allow Insecure Content" di browser.</span></div>';
                } else {
                    el.innerHTML = '<span class="badge" style="background:rgba(148,163,184,0.1); color:#94a3b8; border:1px solid rgba(148,163,184,0.3); font-size:10px;"><i class="fas fa-power-off"></i> WA OFFLINE</span>';
                }
            });
        }
    }
    
    // Initial check and set interval
    checkWAStatus();
    setInterval(checkWAStatus, 30000); // Check every 30s

    function toggleDropdown(el) {
        const dropdown = el.parentElement;
        dropdown.classList.toggle('open');
    }

    // Global WhatsApp Gateway Send Function
    async function sendWAGateway(phone, message, fallback, btn) {
        const cid = WAGatewayCID; // Use the global CID
        const endpoint = `/waapi/send?cid=${cid}`;
        
        if (btn) {
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            try {
                // Using CID to route send request to correct device
                const response = await fetch('/waapi/send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ cid: WAGatewayCID, phone, message })
                });
                const data = await response.json();
                
                if (data.error) throw new Error(data.message);
                
                // Success
                btn.style.color = '#10b981';
                btn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                    btn.style.color = '#25D366';
                    btn.disabled = false;
                }, 2000);
            } catch (e) {
                console.error('Gateway failed, using fallback:', e);
                window.open(fallback, '_blank');
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        }
    }
    </script>
</body>
</html>
