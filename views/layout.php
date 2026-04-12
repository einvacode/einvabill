<?php
// Fetch minimal settings early so we can set favicon in <head>
$__layout_settings = $db->query("SELECT company_name, company_logo, site_url FROM settings WHERE id=1")->fetch();
$__favicon_src = '';
if (!empty($__layout_settings['company_logo'])) {
    if (preg_match('/^https?:\/\//', $__layout_settings['company_logo'])) {
        $__favicon_src = $__layout_settings['company_logo'];
    } else {
        $__favicon_src = '/' . str_replace(' ', '%20', ltrim($__layout_settings['company_logo'], '/'));
    }
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Dashboard</title>
    <?php if (!empty($__favicon_src)): ?>
        <link rel="icon" href="<?= htmlspecialchars($__favicon_src) ?>" sizes="any">
    <?php else: ?>
        <link rel="icon" href="public/favicon.png">
    <?php endif; ?>
    <link rel="stylesheet" href="public/neumorphism.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <script>
        // Load saved theme instantly to prevent flash
        (function() {
            const saved = localStorage.getItem('billing_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', saved);
        })();

        // Expose server-side debug flag to client
        window.APP_DEBUG = <?= (defined('APP_DEBUG') && APP_DEBUG) ? 'true' : 'false' ?>;
        if (!window.APP_DEBUG) {
            // Silence verbose debug calls in production
            if (console && console.debug) console.debug = function(){};
        }

        // Global WhatsApp API Constants (Available to all sub-views)
        window.WAGatewayCID = '<?= ($_SESSION["user_role"] === "admin") ? "admin" : "u_" . ($_SESSION["user_id"] ?? "guest") ?>';
        window.WAApiProxy = 'wa_proxy.php?path=';
    </script>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    <header class="mobile-header">
        <div style="display: flex; align-items: center; gap: 12px;">
            <button class="menu-btn" aria-label="Open menu">Menu</button>
            <span style="font-weight: 800; font-size: 16px; letter-spacing: 0.5px;"><?= htmlspecialchars($site_settings['company_name'] ?? 'BILLING') ?></span>
        </div>
        <div onclick="toggleTheme()" style="cursor: pointer; opacity: 0.8;"><i class="fas fa-moon"></i></div>
    </header>
    <script>
    // Defensive binding: ensure burger and overlay always toggle sidebar
    (function(){
        function safeToggle(){
            try {
                console.debug('safeToggle called');
                const s = document.querySelector('.sidebar'), o = document.getElementById('sidebarOverlay');
                if(!s || !o) { console.debug('safeToggle: missing sidebar or overlay', !!s, !!o); return; }
                s.classList.toggle('active'); o.classList.toggle('active');
                document.body.style.overflow = s.classList.contains('active') ? 'hidden' : 'auto';
            } catch(e) { console.warn('safeToggle error', e); }
        }
        // Provide an early, defensive toggleDropdown so inline onclicks won't break
        // if a later script fails to execute. This ensures dropdowns remain responsive.
        window.toggleDropdown = function(el){
            try {
                if(!el || !el.parentElement) return;
                el.parentElement.classList.toggle('open');
            } catch(e) { console.warn('toggleDropdown fallback error', e); }
        };

            // Universal invoice edit modal opener — ensures edit modal can be
            // triggered even if page-specific JS fails to load. Buttons should
            // include the class `btn-edit-invoice` and data attributes.
            window.openInvoiceEditModal = function(id, amount, discount, date) {
                try {
                    const modal = document.getElementById('editInvoiceModal');
                    if(!modal) return false;
                    const setVal = (sel, v) => { const el = document.getElementById(sel); if(el) el.value = v ?? ''; };
                    setVal('editInvId', id);
                    setVal('editInvAmount', amount);
                    setVal('editInvDiscount', discount || 0);
                    setVal('editInvDate', date || '');
                    const title = document.getElementById('editTitle'); if(title) title.innerText = 'Edit INV-' + String(id).padStart(5, '0');
                    modal.style.display = 'flex';
                    return true;
                } catch(e) { console.warn('openInvoiceEditModal error', e); return false; }
            };

            // Delegated click handler for edit buttons (works even if inline
            // onclick handlers are missing or JS earlier failed).
            document.addEventListener('click', function(ev){
                try {
                    const btn = ev.target.closest && ev.target.closest('.btn-edit-invoice');
                    if(!btn) return;
                    ev.preventDefault();
                    const id = btn.dataset.invId || btn.getAttribute('data-inv-id');
                    const amount = btn.dataset.invAmount || btn.getAttribute('data-inv-amount');
                    const discount = btn.dataset.invDiscount || btn.getAttribute('data-inv-discount');
                    const date = btn.dataset.invDate || btn.getAttribute('data-inv-date');
                    window.openInvoiceEditModal(id, amount, discount, date);
                } catch(e) { /* swallow */ }
            }, true);

        document.addEventListener('DOMContentLoaded', function(){
                console.debug('DOMContentLoaded: binding burger');
                const burger = document.querySelector('.burger-btn');
                if(burger) {
                    burger.addEventListener('click', function(e){ console.debug('burger click event'); safeToggle(); });
                    burger.addEventListener('touchstart', function(e){ console.debug('burger touchstart'); e.preventDefault(); safeToggle(); }, {passive:false});
                } else { console.debug('burger element not found'); }

                const menuBtn = document.querySelector('.menu-btn');
                if(menuBtn) {
                    menuBtn.addEventListener('click', function(e){ console.debug('menu click event'); safeToggle(); });
                    menuBtn.addEventListener('touchstart', function(e){ console.debug('menu touchstart'); e.preventDefault(); safeToggle(); }, {passive:false});
                } else { console.debug('menu element not found'); }
                const overlay = document.getElementById('sidebarOverlay');
                if(overlay) {
                    overlay.addEventListener('click', function(){ console.debug('overlay click'); safeToggle(); });
                    overlay.addEventListener('touchstart', function(e){ console.debug('overlay touchstart'); e.preventDefault(); safeToggle(); }, {passive:false});
                } else { console.debug('overlay element not found'); }
            });
        window.safeToggleSidebar = safeToggle;
    })();
    </script>
    <div class="app-layout">
        <aside class="sidebar">
            <div>
                <?php
                    $site_settings = $db->query("SELECT company_name, company_logo, site_url FROM settings WHERE id=1")->fetch();
                    $app_base_url = rtrim($site_settings['site_url'] ?? '', '/');
                    $logo_src = '';
                    if (!empty($site_settings['company_logo'])) {
                        if (preg_match('/^https?:\/\//', $site_settings['company_logo'])) {
                            $logo_src = $site_settings['company_logo'];
                        } else {
                            $logo_src = '/' . str_replace(' ', '%20', $site_settings['company_logo']);
                        }
                    }
                ?>
                <div class="sidebar-header" style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 20px 10px 30px;">
                    <?php if(!empty($logo_src)): ?>
                        <div class="brand-logo-wrapper sidebar-logo-box">
                            <img src="<?= htmlspecialchars($logo_src) ?>" alt="Logo" loading="eager">
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
                            <a href="index.php?page=admin_create_invoice" class="nav-link <?= $page == 'admin_create_invoice' ? 'active' : '' ?>"><i class="fas fa-plus-circle" style="color:#06b6d4;"></i> Buat Invoice</a>
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
                        
                        <a href="index.php?page=admin_license" class="nav-link <?= $page == 'admin_license' ? 'active' : '' ?>"><i class="fas fa-key" style="color:#f59e0b;"></i> Lisensi</a>
                        
                        <!-- Dropdown Laporan -->
                        <div class="nav-dropdown <?= in_array($page, ['admin_reports', 'admin_report_assets']) ? 'open' : '' ?>">
                            <div class="nav-link dropdown-toggle" onclick="toggleDropdown(this)">
                                <span><i class="fas fa-chart-bar"></i> Laporan</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="dropdown-content">
                                <a href="index.php?page=admin_reports" class="nav-link dropdown-link <?= $page == 'admin_reports' ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> Keuangan</a>
                                <a href="index.php?page=admin_report_assets" class="nav-link dropdown-link <?= $page == 'admin_report_assets' ? 'active' : '' ?>"><i class="fas fa-file-contract"></i> Aset</a>
                                <!-- KPI removed -->
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
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">PENAGIHAN LAPANGAN</div>
                        <a href="index.php?page=collector&tab=summary&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="nav-link <?= $page == 'collector' && ($coll_tab ?? 'summary') == 'summary' ? 'active' : '' ?>"><i class="fas fa-home"></i> Dashboard</a>
                        <a href="index.php?page=collector&tab=tugas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="nav-link <?= $page == 'collector' && ($coll_tab ?? '') == 'tugas' ? 'active' : '' ?>"><i class="fas fa-clock" style="color:#ef4444;"></i> Belum Lunas</a>
                        <a href="index.php?page=collector&tab=lunas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="nav-link <?= $page == 'collector' && ($coll_tab ?? '') == 'lunas' ? 'active' : '' ?>"><i class="fas fa-check-circle" style="color:#10b981;"></i> Lunas Bayar</a>
                        <a href="index.php?page=collector&tab=pelanggan&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="nav-link <?= $page == 'collector' && ($coll_tab ?? '') == 'pelanggan' ? 'active' : '' ?>"><i class="fas fa-users"></i> Daftar Pelanggan</a>
                        
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">UTILITY</div>
                        <a href="index.php?page=admin_wa_gateway" class="nav-link <?= $page == 'admin_wa_gateway' ? 'active' : '' ?>">
                            <i class="fab fa-whatsapp" style="color:#25D366;"></i> WhatsApp Perangkat
                            <span class="wa-status-sidebar-badge" style="margin-left:auto;"></span>
                        </a>
                        <a href="index.php?page=collector_settings" class="nav-link <?= $page == 'collector_settings' ? 'active' : '' ?>"><i class="fas fa-user-cog" style="color:var(--text-secondary);"></i> Profil & WhatsApp</a>
                        <a href="index.php?page=admin_map" class="nav-link <?= $page == 'admin_map' ? 'active' : '' ?>"><i class="fas fa-map-location-dot"></i> Peta Lokasi</a>

                    <?php elseif($_SESSION['user_role'] === 'partner'): ?>
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">DASHBOARD MITRA</div>
                        <a href="index.php?page=partner" class="nav-link <?= $page == 'partner' ? 'active' : '' ?>"><i class="fas fa-home" style="color:var(--primary);"></i> Ringkasan Utama</a>
                        <a href="index.php?page=partner_collection" class="nav-link <?= $page == 'partner_collection' ? 'active' : '' ?>"><i class="fas fa-motorcycle" style="color:var(--warning);"></i> Penagihan Lapangan</a>
                        <a href="index.php?page=admin_customers" class="nav-link <?= $page == 'admin_customers' ? 'active' : '' ?>"><i class="fas fa-users" style="color:#60a5fa;"></i> Pelanggan Saya</a>
                        
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">OPERASIONAL & TEKNIS</div>
                        <a href="index.php?page=admin_packages" class="nav-link <?= $page == 'admin_packages' ? 'active' : '' ?>"><i class="fas fa-box" style="color:#ec4899;"></i> Paket Internet</a>
                        <a href="index.php?page=admin_router" class="nav-link <?= $page == 'admin_router' ? 'active' : '' ?>"><i class="fas fa-network-wired" style="color:var(--success);"></i> Manajemen Router</a>
                        <a href="index.php?page=admin_map" class="nav-link <?= $page == 'admin_map' ? 'active' : '' ?>"><i class="fas fa-map-location-dot" style="color:#f97316;"></i> Peta Pelanggan</a>
                        
                        <div style="font-size: 10px; font-weight: 800; color: var(--text-secondary); margin: 20px 0 10px 15px; letter-spacing: 1px; opacity: 0.6;">KEUANGAN & TOOLS</div>
                        <div class="nav-dropdown <?= in_array($page, ['admin_invoices', 'partner_isp_invoices', 'admin_reports', 'admin_expenses']) ? 'open' : '' ?>">
                            <div class="nav-link dropdown-toggle" onclick="toggleDropdown(this)">
                                <span><i class="fas fa-wallet" style="color:#10b981;"></i> Administrasi Keuangan</span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="dropdown-content">
                                <a href="index.php?page=admin_invoices" class="nav-link dropdown-link <?= $page == 'admin_invoices' ? 'active' : '' ?>"><i class="fas fa-file-invoice-dollar"></i> Riwayat Tagihan</a>
                                <a href="index.php?page=admin_create_invoice" class="nav-link dropdown-link <?= $page == 'admin_create_invoice' ? 'active' : '' ?>"><i class="fas fa-plus-circle"></i> Buat Invoice</a>
                                <a href="index.php?page=partner_isp_invoices" class="nav-link dropdown-link <?= $page == 'partner_isp_invoices' ? 'active' : '' ?>"><i class="fas fa-receipt" style="color:#ef4444;"></i> Tagihan Ke ISP</a>
                                <a href="index.php?page=admin_expenses" class="nav-link dropdown-link <?= $page == 'admin_expenses' ? 'active' : '' ?>"><i class="fas fa-wallet"></i> Pengeluaran</a>
                                <a href="index.php?page=admin_reports" class="nav-link dropdown-link <?= $page == 'admin_reports' ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> Laporan Laba</a>
                            </div>
                        </div>

                        <a href="index.php?page=admin_wa_gateway" class="nav-link <?= $page == 'admin_wa_gateway' ? 'active' : '' ?>">
                            <i class="fab fa-whatsapp" style="color:#25D366;"></i> WhatsApp Perangkat
                            <span class="wa-status-sidebar-badge" style="margin-left:auto;"></span>
                        </a>
                        <a href="index.php?page=partner_settings" class="nav-link <?= $page == 'partner_settings' ? 'active' : '' ?>"><i class="fas fa-cog" style="color:var(--text-secondary);"></i> Profil & Branding</a>
                    <?php endif; ?>
                </div>
            </div>
            <a href="index.php?page=logout" class="nav-link" style="margin-top: auto; margin-bottom: 20px;"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </aside>

        <main class="main-content">
            <!-- Topbar (Hidden for Collector as requested) -->
            <?php if($page !== 'collector'): ?>
            <div class="topbar glass-panel <?= ($_SESSION['user_role'] === 'partner') ? 'hide-mobile' : '' ?>" style="padding:15px 24px;">
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
            <a href="index.php?page=collector&tab=tugas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="<?= $page == 'collector' && ($coll_tab ?? '') == 'tugas' ? 'active' : '' ?>">
                <i class="fas fa-clock" style="color:#ef4444;"></i><span>Tugas</span>
            </a>
            <a href="index.php?page=collector&tab=lunas&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="<?= $page == 'collector' && ($coll_tab ?? '') == 'lunas' ? 'active' : '' ?>">
                <i class="fas fa-check-circle" style="color:#10b981;"></i><span>Selesai</span>
            </a>
            <a href="index.php?page=collector&tab=pengeluaran&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="<?= $page == 'collector' && ($coll_tab ?? '') == 'pengeluaran' ? 'active' : '' ?>">
                <i class="fas fa-wallet" style="color:#f97316;"></i><span>Biaya</span>
            </a>
            <a href="index.php?page=collector&tab=summary&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="<?= $page == 'collector' && ($coll_tab ?? 'summary') == 'summary' ? 'active' : '' ?>">
                <i class="fas fa-home"></i><span>Home</span>
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
                    <a href="index.php?page=admin_license" style="display:flex; flex-direction:column; align-items:center; gap:6px; padding:14px 8px; border-radius:12px; color:var(--text-primary); text-decoration:none; background:var(--hover-bg); font-size:12px; font-weight:500; transition:all 0.2s;">
                        <i class="fas fa-key" style="font-size:22px; color:#f59e0b;"></i> Lisensi
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
    /** UI TOGGLES */
    window.toggleSidebar = function() {
        const s = document.querySelector('.sidebar'), o = document.getElementById('sidebarOverlay');
        if (s && o) {
            s.classList.toggle('active'); o.classList.toggle('active');
            document.body.style.overflow = s.classList.contains('active') ? 'hidden' : 'auto';
        }
    };
    window.toggleDropdown = (el) => el && el.parentElement && el.parentElement.classList.toggle('open');
    window.toggleTheme = () => {
        const h = document.documentElement;
        if (h) {
            const n = h.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            h.setAttribute('data-theme', n); localStorage.setItem('billing_theme', n);
        }
    };

    /** WA GATEWAY STATUS POLLING */
    async function checkWAStatus() {
        if (!['admin', 'partner', 'collector'].some(p => window.location.search.includes('page=' + p))) return;
        try {
            const r = await fetch(WAApiProxy + 'status&cid=' + WAGatewayCID);
            const data = await r.json();
            if (data.error) console.warn('WA Gateway Error:', data.debug || data.message);
            updateWAIndicators(data.connected);
        } catch (e) { console.warn('WA Status check skipped'); }
    }

    function updateWAIndicators(c) {
        const statusHtml = c ? 
            '<span class="badge badge-success" style="background:rgba(16,185,129,0.1); color:#10b981; border:1px solid rgba(16,185,129,0.3); font-size:10px;"><i class="fas fa-link"></i> WA CONNECTED</span>' : 
            '<span class="status-badge" style="background:rgba(239, 68, 68, 0.1); color:#ef4444; border:1px solid rgba(239, 68, 68, 0.2); padding:2px 8px; border-radius:10px; font-size:10px;"><i class="fas fa-times"></i> WA Disconnected</span>';
        
        document.querySelectorAll('.wa-status-indicator').forEach(el => el.innerHTML = statusHtml);
        document.querySelectorAll('.wa-status-sidebar-badge').forEach(el => {
            Object.assign(el.style, {
                width:'8px', height:'8px', borderRadius:'50%', display:'inline-block',
                background: c ? '#10b981' : '#ef4444', boxShadow: c ? '0 0 10px rgba(16,185,129,0.5)' : 'none'
            });
        });
    }

    window.sendWAGateway = async function(phone, message, fallback, btn) {
        if (!btn) return;
        const old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        try {
            const r = await fetch(WAApiProxy + 'send', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ cid: WAGatewayCID, phone, message })
            });
            if ((await r.json()).error) throw new Error();
            btn.style.color = '#10b981'; btn.innerHTML = '<i class="fas fa-check"></i>';
            setTimeout(() => { Object.assign(btn, { innerHTML: old, style: { color: '' }, disabled: false }); }, 2000);
        } catch (e) {
            window.open(fallback, '_blank'); Object.assign(btn, { innerHTML: old, disabled: false });
        }
    };

    /** INIT & PERSISTENCE */
    window.addEventListener('DOMContentLoaded', () => {
        const s = document.querySelector('.sidebar'), m = document.querySelector('.main-content');
        if (s && sessionStorage.getItem('sidebarScroll')) s.scrollTop = sessionStorage.getItem('sidebarScroll');
        const last = sessionStorage.getItem('lastUrl');
        if (last && new URL(last).searchParams.get('page') === new URL(window.location.href).searchParams.get('page')) {
            if (m && sessionStorage.getItem('mainScroll')) m.scrollTop = sessionStorage.getItem('mainScroll');
            if (sessionStorage.getItem('windowScroll')) window.scrollTo(0, sessionStorage.getItem('windowScroll'));
        }
        const p = new URLSearchParams(window.location.search);
        if (['bulk_paid', 'paid'].includes(p.get('msg'))) {
            showToast('Pembayaran Berhasil!');
            window.history.replaceState({}, '', window.location.href.replace(/[&?]msg=(bulk_paid|paid)/, ''));
        }
        let waInterval = null;
        const startPoll = () => { if (!waInterval) { checkWAStatus(); waInterval = setInterval(checkWAStatus, 60000); } };
        const stopPoll = () => { if (waInterval) { clearInterval(waInterval); waInterval = null; } };
        document.addEventListener('visibilitychange', () => document.hidden ? stopPoll() : startPoll());
        startPoll();
    });

    // Close sidebar when a nav link is clicked on small screens
    document.addEventListener('click', function(ev){
        try {
            const link = ev.target.closest && ev.target.closest('.nav-link');
            if(!link) return;
            if(window.innerWidth <= 768) {
                const s = document.querySelector('.sidebar'), o = document.getElementById('sidebarOverlay');
                if(s && s.classList.contains('active')) {
                    s.classList.remove('active');
                    if(o) o.classList.remove('active');
                    document.body.style.overflow = 'auto';
                }
            }
        } catch(e) { /* ignore */ }
    }, true);

    window.addEventListener('beforeunload', () => {
        const s = document.querySelector('.sidebar'), m = document.querySelector('.main-content');
        if (s) sessionStorage.setItem('sidebarScroll', s.scrollTop);
        if (m) sessionStorage.setItem('mainScroll', m.scrollTop);
        sessionStorage.setItem('windowScroll', window.scrollY);
        sessionStorage.setItem('lastUrl', window.location.href);
    });

    function showToast(t) {
        const el = document.createElement('div');
        el.style.cssText = `position:fixed; bottom:80px; left:50%; transform:translateX(-50%); background:#10b981; color:white; padding:12px 24px; border-radius:50px; font-weight:700; box-shadow:0 10px 25px rgba(16,185,129,0.3); z-index:10000; transition:all 0.3s ease; opacity:0;`;
        el.innerHTML = `<i class="fas fa-check-circle"></i> ${t}`;
        document.body.appendChild(el);
        setTimeout(() => { el.style.opacity = '1'; el.style.transform = 'translate(-50%, 0)'; }, 10);
        setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
    }

    /** MODALS & HELPERS */
    window.toggleMobileMenu = (e) => { if(e) e.preventDefault(); const o = document.getElementById('mobileMenuOverlay'); if(o) o.style.display = o.style.display === 'flex' ? 'none' : 'flex'; };
    window.closeMobileMenu = () => { const o = document.getElementById('mobileMenuOverlay'); if(o) o.style.display = 'none'; };
    window.openImagePreview = (src) => { const m = document.getElementById('globalImageModal'), i = document.getElementById('modalImg'); if(m && i) { m.style.display = 'flex'; i.src = src; document.body.style.overflow = 'hidden'; } };
    window.closeImagePreview = () => { const m = document.getElementById('globalImageModal'); if(m) { m.style.display = 'none'; document.body.style.overflow = 'auto'; } };
    </script>
</body>
</html>
