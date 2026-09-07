<?php
// Fetch minimal settings early so we can set favicon in <head>
$tenant_id_layout_head = $_SESSION['tenant_id'] ?? 1;
$__layout_settings = $db->query("SELECT company_name, company_logo, site_url FROM settings WHERE tenant_id = $tenant_id_layout_head")->fetch();
if (!$__layout_settings) {
    $__layout_settings = $db->query("SELECT company_name, company_logo, site_url FROM settings WHERE id=1")->fetch();
}
$__favicon_src = '';
if (!empty($__layout_settings['company_logo'])) {
    if (preg_match('/^https?:\/\//', $__layout_settings['company_logo'])) {
        $__favicon_src = $__layout_settings['company_logo'];
    } else {
        $__favicon_src = '/' . str_replace(' ', '%20', ltrim($__layout_settings['company_logo'], '/'));
    }
}

$site_settings = $__layout_settings;
$app_base_url = rtrim($site_settings['site_url'] ?? '', '/');
$logo_src = $__favicon_src;
$company_name = $site_settings['company_name'] ?? 'BILLING';
$role = $_SESSION['user_role'] ?? 'guest';
$date_from = $date_from ?? '';
$date_to = $date_to ?? '';

// Page titles for the top bar
$page_titles = [
    'admin_dashboard' => 'Dashboard Admin',
    'admin_customers' => 'Manajemen Pelanggan',
    'admin_new_customers' => 'Pelanggan Baru',
    'admin_assets' => 'Manajemen Aset (OLT/ODP)',
    'admin_map' => 'Peta Sebaran Jaringan',
    'admin_invoices' => 'Manajemen Tagihan',
    'admin_create_invoice' => 'Invoice Eksternal',
    'admin_edit_quick_invoice' => 'Edit Invoice',
    'admin_expenses' => 'Manajemen Pengeluaran',
    'admin_reports' => 'Laporan Keuangan',
    'admin_report_assets' => 'Laporan Inventaris Aset',
    'admin_updater' => 'Update System',
    'admin_updater_run' => 'Update System',
    'admin_banners' => 'Manajemen Banner Informasi',
    'admin_landing' => 'Pengaturan Web Profil',
    'admin_users' => 'Akses Pengguna',
    'admin_wa_gateway' => 'Manajemen Perangkat WhatsApp',
    'admin_settings' => 'Pengaturan Perusahaan',
    'admin_backup' => 'Backup & Restore Database',
    'admin_router' => 'Router',
    'admin_packages' => 'Manajemen Paket',
    'admin_areas' => 'Manajemen Area',
    'admin_auto_invoice' => 'Auto Tagihan',
    'admin_license' => 'Lisensi',
    'admin_temp_customers' => 'Pelanggan Sementara',
    'admin_data_validation' => 'Validasi Data',
    'database_audit' => 'Audit Database',
    'cleanup_orphans' => 'Pembersihan Data',
    'change_password' => 'Ganti Password',
    'change_password_post' => 'Ganti Password',
    'collector' => 'Dashboard Penagih',
    'collector_settings' => 'Profil & WhatsApp',
    'partner' => 'Dashboard Mitra',
    'partner_collection' => 'Penagihan Lapangan',
    'partner_settings' => 'Pengaturan Profil Mitra',
    'partner_isp_invoices' => 'Tagihan ke ISP',
    'partner_reports' => 'Laporan Keuangan',
    'partner_wa_device' => 'Perangkat WhatsApp',
];
$topbar_title = $page_titles[$page] ?? '';

/** Sidebar link helper: $active is a boolean computed by the caller. */
function nav_item(string $href, string $icon, string $label, bool $active, string $extra = ''): string {
    $cls = $active
        ? 'bg-white/10 text-white'
        : 'text-white/70 hover:bg-white/5 hover:text-white';
    return '<a href="' . htmlspecialchars($href) . '" class="nav-item ' . $cls . '"' . ($active ? ' aria-current="page"' : '') . '>'
        . '<i class="' . $icon . ' w-5 text-center text-[15px]"></i><span class="truncate">' . $label . '</span>' . $extra . '</a>';
}
function nav_heading(string $text): string {
    return '<div class="mt-5 mb-1.5 px-3 text-[11px] font-semibold text-white/40">' . $text . '</div>';
}
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($topbar_title ?: 'Billing') ?> · <?= htmlspecialchars($company_name) ?></title>
    <?php if (!empty($__favicon_src)): ?>
        <link rel="icon" href="<?= htmlspecialchars($__favicon_src) ?>" sizes="any">
    <?php else: ?>
        <link rel="icon" href="public/favicon.png">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/style.css">
    <link rel="stylesheet" href="public/ui.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="public/tw-app.css">
    <script>
        // Global WhatsApp API Constants (Available to all sub-views)
        window.WAGatewayCID = '<?= ($_SESSION["user_role"] === "admin") ? "admin_" . ($_SESSION["tenant_id"] ?? 1) : "u_" . ($_SESSION["user_id"] ?? "guest") ?>';
        window.WAApiProxy = 'wa_proxy.php?path=';

        /** CSRF: every state-changing request carries the session token. */
        window.CSRF_TOKEN = '<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>';
        (function () {
            const TOKEN = window.CSRF_TOKEN;

            // 1. fetch(): add X-CSRF-Token to every non-GET request.
            const nativeFetch = window.fetch;
            window.fetch = function (input, init) {
                init = init || {};
                const method = String(init.method || 'GET').toUpperCase();
                if (method !== 'GET' && method !== 'HEAD') {
                    const h = new Headers(init.headers || {});
                    if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', TOKEN);
                    init.headers = h;
                }
                return nativeFetch.call(this, input, init);
            };

            // 2. Forms: guarantee a _token field on every POST form, including
            //    forms built or emptied by JavaScript before form.submit().
            function ensureToken(form) {
                if (!(form instanceof HTMLFormElement)) return;
                if (String(form.method || 'get').toLowerCase() !== 'post') return;
                if (form.querySelector('input[name="_token"]')) return;
                const i = document.createElement('input');
                i.type = 'hidden'; i.name = '_token'; i.value = TOKEN;
                form.appendChild(i);
            }
            const nativeSubmit = HTMLFormElement.prototype.submit;
            HTMLFormElement.prototype.submit = function () { ensureToken(this); return nativeSubmit.apply(this, arguments); };
            document.addEventListener('submit', function (e) { ensureToken(e.target); }, true);

            // 3. Links with data-method="post": submit as a POST form instead of
            //    navigating. Runs after any inline onclick confirm(); if that
            //    returned false the click is already cancelled and we do nothing.
            document.addEventListener('click', function (e) {
                const a = e.target.closest && e.target.closest('a[data-method="post"]');
                if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey) return;
                e.preventDefault();
                const f = document.createElement('form');
                f.method = 'post'; f.action = a.getAttribute('href'); f.style.display = 'none';
                if (a.target) f.target = a.target;
                ensureToken(f);
                document.body.appendChild(f);
                f.submit();
            });
        })();

        /** UI TOGGLES (defined early so inline onclick handlers always work) */
        window.toggleSidebar = function () {
            const s = document.getElementById('appSidebar'), o = document.getElementById('sidebarOverlay');
            if (!s || !o) return;
            const open = s.classList.contains('-translate-x-full');
            s.classList.toggle('-translate-x-full', !open);
            o.classList.toggle('hidden', !open);
            document.body.style.overflow = open ? 'hidden' : '';
        };
        window.closeSidebar = function () {
            const s = document.getElementById('appSidebar'), o = document.getElementById('sidebarOverlay');
            if (s) s.classList.add('-translate-x-full');
            if (o) o.classList.add('hidden');
            document.body.style.overflow = '';
        };
        window.safeToggleSidebar = window.toggleSidebar;
        window.toggleDropdown = function (el) {
            try { if (el && el.parentElement) el.parentElement.classList.toggle('open'); } catch (e) {}
        };

        // Universal invoice edit modal opener — ensures the edit modal can be
        // triggered even if page-specific JS fails to load. Buttons should
        // include the class `btn-edit-invoice` and data attributes.
        window.openInvoiceEditModal = function (id, amount, discount, date) {
            try {
                const modal = document.getElementById('editInvoiceModal');
                if (!modal) return false;
                const setVal = (sel, v) => { const el = document.getElementById(sel); if (el) el.value = v ?? ''; };
                setVal('editInvId', id);
                setVal('editInvAmount', amount);
                setVal('editInvDiscount', discount || 0);
                setVal('editInvDate', date || '');
                const title = document.getElementById('editTitle'); if (title) title.innerText = 'Edit INV-' + String(id).padStart(5, '0');
                modal.style.display = 'flex';
                return true;
            } catch (e) { console.warn('openInvoiceEditModal error', e); return false; }
        };
        document.addEventListener('click', function (ev) {
            try {
                const btn = ev.target.closest && ev.target.closest('.btn-edit-invoice');
                if (!btn) return;
                ev.preventDefault();
                window.openInvoiceEditModal(
                    btn.dataset.invId || btn.getAttribute('data-inv-id'),
                    btn.dataset.invAmount || btn.getAttribute('data-inv-amount'),
                    btn.dataset.invDiscount || btn.getAttribute('data-inv-discount'),
                    btn.dataset.invDate || btn.getAttribute('data-inv-date')
                );
            } catch (e) { /* swallow */ }
        }, true);
    </script>
</head>
<body class="ui-shell min-h-screen">

<div class="lg:grid lg:grid-cols-[256px_minmax(0,1fr)] min-h-screen">

    <!-- Sidebar: fixed drawer on small screens, static column from lg -->
    <div id="sidebarOverlay" class="fixed inset-0 z-40 bg-black/50 hidden lg:hidden" onclick="closeSidebar()"></div>
    <aside id="appSidebar" class="fixed inset-y-0 left-0 z-50 flex w-[256px] -translate-x-full flex-col bg-primary-deep text-white transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0">
        <div class="flex h-14 shrink-0 items-center gap-3 border-b border-solid border-white/10 px-4">
            <?php if ($logo_src): ?>
                <span class="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-md bg-white p-0.5">
                    <img src="<?= htmlspecialchars($logo_src) ?>" alt="" class="max-h-full max-w-full object-contain">
                </span>
            <?php else: ?>
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-white/10"><i class="fas fa-wifi text-[15px]"></i></span>
            <?php endif; ?>
            <div class="min-w-0">
                <div class="truncate text-[13.5px] font-bold leading-tight"><?= htmlspecialchars($company_name) ?></div>
                <div class="truncate text-[11px] text-white/50 leading-tight">EinvaBill</div>
            </div>
            <button type="button" class="ml-auto grid h-8 w-8 place-items-center rounded-md text-white/70 hover:bg-white/10 lg:hidden" onclick="closeSidebar()" aria-label="Tutup menu"><i class="fas fa-times"></i></button>
        </div>

        <nav class="app-sidebar-nav flex-1 overflow-y-auto px-3 pb-4">
            <?php if ($role === 'admin'): ?>
                <?= nav_heading('Operasional') ?>
                <?= nav_item('index.php?page=admin_dashboard', 'fas fa-home', 'Dashboard', $page == 'admin_dashboard') ?>
                <?= nav_item('index.php?page=admin_customers&filter_type=customer', 'fas fa-users', 'Pelanggan Rumahan', $page == 'admin_customers' && ($_GET['filter_type'] ?? '') == 'customer') ?>
                <?= nav_item('index.php?page=admin_new_customers', 'fas fa-star', 'Pelanggan Baru', $page == 'admin_new_customers') ?>
                <?= nav_item('index.php?page=admin_customers&filter_type=partner', 'fas fa-handshake', 'Kemitraan (B2B)', $page == 'admin_customers' && ($_GET['filter_type'] ?? '') == 'partner') ?>

                <?= nav_heading('Keuangan') ?>
                <?= nav_item('index.php?page=admin_invoices&filter_type=customer', 'fas fa-file-invoice-dollar', 'Tagihan Pelanggan', $page == 'admin_invoices' && ($_GET['filter_type'] ?? '') == 'customer' && ($filter_status ?? '') != 'belum') ?>
                <?= nav_item('index.php?page=admin_invoices&filter_type=partner', 'fas fa-handshake', 'Tagihan Kemitraan', $page == 'admin_invoices' && ($_GET['filter_type'] ?? '') == 'partner') ?>
                <?= nav_item('index.php?page=admin_create_invoice', 'fas fa-file-invoice', 'Invoice Eksternal', $page == 'admin_create_invoice') ?>
                <?= nav_item('index.php?page=admin_expenses', 'fas fa-wallet', 'Pengeluaran / Biaya', $page == 'admin_expenses') ?>

                <?= nav_heading('Infrastruktur') ?>
                <?= nav_item('index.php?page=admin_router', 'fas fa-network-wired', 'Router', $page == 'admin_router') ?>
                <?= nav_item('index.php?page=admin_assets', 'fas fa-boxes', 'Aset Perusahaan', $page == 'admin_assets') ?>
                <?= nav_item('index.php?page=admin_map', 'fas fa-map-location-dot', 'Peta Aset', $page == 'admin_map') ?>

                <?= nav_heading('Data master') ?>
                <?= nav_item('index.php?page=admin_packages', 'fas fa-box', 'Manajemen Paket', $page == 'admin_packages') ?>
                <?= nav_item('index.php?page=admin_areas', 'fas fa-map-marker-alt', 'Manajemen Area', $page == 'admin_areas') ?>
                <?= nav_item('index.php?page=admin_users', 'fas fa-user-shield', 'Akses Pengguna', $page == 'admin_users') ?>

                <?php $settings_pages = ['admin_wa_gateway', 'admin_auto_invoice', 'admin_settings', 'admin_backup', 'admin_banners', 'admin_landing']; ?>
                <div class="nav-group mt-1 <?= in_array($page, $settings_pages) ? 'open' : '' ?>">
                    <button type="button" class="nav-item w-full text-left text-white/70 hover:bg-white/5 hover:text-white bg-transparent border-0" onclick="toggleDropdown(this)">
                        <i class="fas fa-sliders-h w-5 text-center text-[15px]"></i><span class="flex-1">Pengaturan</span><i class="fas fa-chevron-down nav-chevron text-[11px] transition-transform"></i>
                    </button>
                    <div class="nav-group-body ml-4 border-l border-solid border-white/10 pl-2">
                        <?= nav_item('index.php?page=admin_wa_gateway', 'fab fa-whatsapp', 'WA Perangkat', $page == 'admin_wa_gateway', '<span class="wa-status-sidebar-badge ml-auto"></span>') ?>
                        <?= nav_item('index.php?page=admin_auto_invoice', 'fas fa-magic', 'Auto Tagihan', $page == 'admin_auto_invoice') ?>
                        <?= nav_item('index.php?page=admin_settings', 'fas fa-cog', 'Profil & Apps', $page == 'admin_settings') ?>
                        <?= nav_item('index.php?page=admin_landing', 'fas fa-globe', 'Web Profil', $page == 'admin_landing') ?>
                        <?= nav_item('index.php?page=admin_banners', 'fas fa-scroll', 'Banner', $page == 'admin_banners') ?>
                        <?= nav_item('index.php?page=admin_backup', 'fas fa-shield-alt', 'Backup', $page == 'admin_backup') ?>
                    </div>
                </div>

                <div class="nav-group <?= in_array($page, ['admin_reports', 'admin_report_assets']) ? 'open' : '' ?>">
                    <button type="button" class="nav-item w-full text-left text-white/70 hover:bg-white/5 hover:text-white bg-transparent border-0" onclick="toggleDropdown(this)">
                        <i class="fas fa-chart-bar w-5 text-center text-[15px]"></i><span class="flex-1">Laporan</span><i class="fas fa-chevron-down nav-chevron text-[11px] transition-transform"></i>
                    </button>
                    <div class="nav-group-body ml-4 border-l border-solid border-white/10 pl-2">
                        <?= nav_item('index.php?page=admin_reports', 'fas fa-chart-line', 'Keuangan', $page == 'admin_reports') ?>
                        <?= nav_item('index.php?page=admin_report_assets', 'fas fa-file-contract', 'Aset', $page == 'admin_report_assets') ?>
                    </div>
                </div>

            <?php elseif ($role === 'collector'): ?>
                <?php $cq = "&date_from=" . urlencode($date_from) . "&date_to=" . urlencode($date_to); $ct = $coll_tab ?? 'summary'; ?>
                <?= nav_heading('Penagihan lapangan') ?>
                <?= nav_item("index.php?page=collector&tab=summary$cq", 'fas fa-home', 'Dashboard', $page == 'collector' && $ct == 'summary') ?>
                <?= nav_item("index.php?page=collector&tab=tugas$cq", 'fas fa-clock', 'Belum Lunas', $page == 'collector' && $ct == 'tugas') ?>
                <?= nav_item("index.php?page=collector&tab=lunas$cq", 'fas fa-check-circle', 'Lunas Bayar', $page == 'collector' && $ct == 'lunas') ?>
                <?= nav_item("index.php?page=collector&tab=pelanggan$cq", 'fas fa-users', 'Daftar Pelanggan', $page == 'collector' && $ct == 'pelanggan') ?>

                <?= nav_heading('Utility') ?>
                <?= nav_item('index.php?page=admin_wa_gateway', 'fab fa-whatsapp', 'WhatsApp Perangkat', $page == 'admin_wa_gateway', '<span class="wa-status-sidebar-badge ml-auto"></span>') ?>
                <?= nav_item('index.php?page=collector_settings', 'fas fa-user-cog', 'Profil & WhatsApp', $page == 'collector_settings') ?>
                <?= nav_item('index.php?page=admin_map', 'fas fa-map-location-dot', 'Peta Lokasi', $page == 'admin_map') ?>

            <?php elseif ($role === 'partner'): ?>
                <?= nav_heading('Dashboard mitra') ?>
                <?= nav_item('index.php?page=partner', 'fas fa-home', 'Ringkasan Utama', $page == 'partner') ?>
                <?= nav_item('index.php?page=partner_collection', 'fas fa-motorcycle', 'Penagihan Lapangan', $page == 'partner_collection' && ($_GET['tab'] ?? '') != 'pelanggan') ?>
                <?= nav_item('index.php?page=partner_collection&tab=pelanggan', 'fas fa-users', 'Pelanggan Saya', $page == 'partner_collection' && ($_GET['tab'] ?? '') == 'pelanggan') ?>

                <?= nav_heading('Keuangan & tools') ?>
                <div class="nav-group <?= in_array($page, ['partner_isp_invoices', 'partner_reports', 'admin_expenses']) ? 'open' : '' ?>">
                    <button type="button" class="nav-item w-full text-left text-white/70 hover:bg-white/5 hover:text-white bg-transparent border-0" onclick="toggleDropdown(this)">
                        <i class="fas fa-wallet w-5 text-center text-[15px]"></i><span class="flex-1">Administrasi Keuangan</span><i class="fas fa-chevron-down nav-chevron text-[11px] transition-transform"></i>
                    </button>
                    <div class="nav-group-body ml-4 border-l border-solid border-white/10 pl-2">
                        <?= nav_item('index.php?page=partner_reports', 'fas fa-chart-line', 'Laporan Keuangan', $page == 'partner_reports') ?>
                        <?= nav_item('index.php?page=partner_isp_invoices', 'fas fa-receipt', 'Tagihan Ke ISP', $page == 'partner_isp_invoices') ?>
                        <?= nav_item('index.php?page=admin_expenses', 'fas fa-wallet', 'Catat Pengeluaran', $page == 'admin_expenses') ?>
                    </div>
                </div>
                <?= nav_item('index.php?page=partner_settings', 'fas fa-cog', 'Profil & Branding', $page == 'partner_settings') ?>
                <?= nav_item('index.php?page=partner_wa_device', 'fab fa-whatsapp', 'Perangkat WhatsApp', $page == 'partner_wa_device', '<span class="wa-status-sidebar-badge ml-auto"></span>') ?>
            <?php endif; ?>
        </nav>

        <div class="shrink-0 border-t border-solid border-white/10 p-3">
            <div class="flex items-center gap-3 px-2 pb-2">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-white/10 text-xs font-bold"><?= htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['user_name'] ?? 'U', 0, 1))) ?></span>
                <div class="min-w-0">
                    <div class="truncate text-[13px] font-semibold"><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></div>
                    <div class="truncate text-[11px] capitalize text-white/50"><?= htmlspecialchars($role) ?></div>
                </div>
            </div>
            <?= nav_item('index.php?page=change_password', 'fas fa-key', 'Ganti Password', $page == 'change_password' || $page == 'change_password_post') ?>
            <?= nav_item('index.php?page=logout', 'fas fa-sign-out-alt', 'Logout', false) ?>
        </div>
    </aside>

    <!-- Main column -->
    <div class="flex min-h-screen min-w-0 flex-col">
        <?php if ($page !== 'collector'): ?>
        <header class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-solid border-border bg-background/90 px-4 backdrop-blur lg:px-8">
            <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-solid border-border bg-card text-foreground lg:hidden" onclick="toggleSidebar()" aria-label="Buka menu"><i class="fas fa-bars"></i></button>
            <div class="min-w-0 flex-1">
                <div class="truncate text-[15px] font-bold leading-tight lg:text-base"><?= htmlspecialchars($topbar_title ?: $company_name) ?></div>
                <div class="hidden truncate text-[11px] text-muted-foreground sm:block"><?= htmlspecialchars($company_name) ?></div>
            </div>
            <div class="wa-status-indicator hidden sm:block cursor-pointer" onclick="location.href='<?= $role === 'partner' ? 'index.php?page=partner_wa_device' : 'index.php?page=admin_wa_gateway' ?>'" title="Status perangkat WhatsApp"></div>
            <div class="hidden items-center gap-2 lg:flex">
                <div class="text-right leading-tight">
                    <div class="text-[13px] font-semibold"><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></div>
                    <div class="text-[11px] capitalize text-muted-foreground"><?= htmlspecialchars($role) ?></div>
                </div>
            </div>
        </header>
        <?php else: ?>
        <header class="flex h-12 items-center gap-3 px-4 lg:hidden">
            <button type="button" class="grid h-9 w-9 place-items-center rounded-md border border-solid border-border bg-card text-foreground" onclick="toggleSidebar()" aria-label="Buka menu"><i class="fas fa-bars"></i></button>
            <div class="truncate text-[15px] font-bold"><?= htmlspecialchars($company_name) ?></div>
        </header>
        <?php endif; ?>

        <main class="app-main flex-1 px-4 pb-24 pt-5 lg:px-8 lg:pb-10 lg:pt-6">
            <?= $content ?? '' ?>
        </main>
    </div>
</div>

<!-- Mobile Bottom Navigation -->
<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-solid border-border bg-card lg:hidden" style="padding-bottom: env(safe-area-inset-bottom);">
    <?php if ($role === 'admin'): ?>
    <div class="grid grid-cols-7">
        <a href="index.php?page=admin_dashboard" class="bottom-nav-item <?= $page == 'admin_dashboard' ? 'active' : '' ?>"><i class="fas fa-home"></i><span>Home</span></a>
        <a href="index.php?page=admin_customers" class="bottom-nav-item <?= $page == 'admin_customers' ? 'active' : '' ?>"><i class="fas fa-users"></i><span>Pelanggan</span></a>
        <a href="index.php?page=admin_map" class="bottom-nav-item <?= $page == 'admin_map' ? 'active' : '' ?>"><i class="fas fa-map-location-dot"></i><span>Peta</span></a>
        <a href="index.php?page=admin_invoices" class="bottom-nav-item <?= $page == 'admin_invoices' ? 'active' : '' ?>"><i class="fas fa-file-invoice-dollar"></i><span>Tagihan</span></a>
        <a href="index.php?page=admin_reports" class="bottom-nav-item <?= $page == 'admin_reports' ? 'active' : '' ?>"><i class="fas fa-chart-line"></i><span>Keuangan</span></a>
        <a href="index.php?page=admin_report_assets" class="bottom-nav-item <?= $page == 'admin_report_assets' ? 'active' : '' ?>"><i class="fas fa-file-contract"></i><span>Aset</span></a>
        <a href="#" class="bottom-nav-item" onclick="toggleMobileMenu(event)" id="mobileMenuToggle"><i class="fas fa-ellipsis-h"></i><span>Lainnya</span></a>
    </div>
    <?php elseif ($role === 'collector'): ?>
    <?php $cq = "&date_from=" . urlencode($date_from) . "&date_to=" . urlencode($date_to); $ct = $coll_tab ?? 'summary'; ?>
    <div class="grid grid-cols-6">
        <a href="index.php?page=collector&tab=tugas<?= $cq ?>" class="bottom-nav-item <?= $page == 'collector' && $ct == 'tugas' ? 'active' : '' ?>"><i class="fas fa-clock"></i><span>Tugas</span></a>
        <a href="index.php?page=collector&tab=lunas<?= $cq ?>" class="bottom-nav-item <?= $page == 'collector' && $ct == 'lunas' ? 'active' : '' ?>"><i class="fas fa-check-circle"></i><span>Selesai</span></a>
        <a href="index.php?page=collector&tab=pengeluaran<?= $cq ?>" class="bottom-nav-item <?= $page == 'collector' && $ct == 'pengeluaran' ? 'active' : '' ?>"><i class="fas fa-wallet"></i><span>Biaya</span></a>
        <a href="index.php?page=collector&tab=summary<?= $cq ?>" class="bottom-nav-item <?= $page == 'collector' && $ct == 'summary' ? 'active' : '' ?>"><i class="fas fa-home"></i><span>Home</span></a>
        <a href="#" class="bottom-nav-item" onclick="toggleSidebar(); return false;"><i class="fas fa-bars"></i><span>Menu</span></a>
        <a href="index.php?page=logout" class="bottom-nav-item text-danger"><i class="fas fa-sign-out-alt"></i><span>Keluar</span></a>
    </div>
    <?php elseif ($role === 'partner'): ?>
    <div class="grid grid-cols-6">
        <a href="index.php?page=partner" class="bottom-nav-item <?= $page == 'partner' ? 'active' : '' ?>"><i class="fas fa-home"></i><span>Home</span></a>
        <a href="index.php?page=partner_collection" class="bottom-nav-item <?= $page == 'partner_collection' && ($_GET['tab'] ?? '') != 'pelanggan' ? 'active' : '' ?>"><i class="fas fa-motorcycle"></i><span>Penagihan</span></a>
        <a href="index.php?page=partner_collection&tab=pelanggan" class="bottom-nav-item <?= ($page == 'partner_collection' && ($_GET['tab'] ?? '') == 'pelanggan') ? 'active' : '' ?>"><i class="fas fa-users"></i><span>Pelanggan</span></a>
        <a href="index.php?page=partner_isp_invoices" class="bottom-nav-item <?= $page == 'partner_isp_invoices' ? 'active' : '' ?>"><i class="fas fa-receipt"></i><span>Tagihan ISP</span></a>
        <a href="#" class="bottom-nav-item" onclick="toggleSidebar(); return false;"><i class="fas fa-bars"></i><span>Menu</span></a>
        <a href="index.php?page=logout" class="bottom-nav-item text-danger"><i class="fas fa-sign-out-alt"></i><span>Keluar</span></a>
    </div>
    <?php endif; ?>
</nav>

<!-- Mobile "More" sheet (Admin only) -->
<?php if ($role === 'admin'): ?>
<div id="mobileMenuOverlay" class="fixed inset-0 z-[1001] hidden items-end justify-center bg-black/50 lg:hidden" onclick="closeMobileMenu()">
    <div class="w-full max-w-lg p-3 pb-20" onclick="event.stopPropagation()">
        <div class="ui-card max-h-[80vh] overflow-y-auto p-4">
            <div class="mb-3 flex items-center justify-between px-1">
                <div class="text-sm font-semibold">Menu lainnya</div>
                <button type="button" class="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted bg-transparent border-0" onclick="closeMobileMenu()" aria-label="Tutup"><i class="fas fa-times"></i></button>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <a href="index.php?page=admin_users" class="sheet-item <?= $page == 'admin_users' ? 'active' : '' ?>"><i class="fas fa-user-shield"></i>Pengguna</a>
                <a href="index.php?page=admin_invoices&filter_status=belum" class="sheet-item <?= $page == 'admin_invoices' && ($filter_status ?? '') == 'belum' ? 'active' : '' ?>"><i class="fas fa-user-clock" style="color:#B42318"></i>Tunggakan</a>
                <a href="index.php?page=admin_packages" class="sheet-item <?= $page == 'admin_packages' ? 'active' : '' ?>"><i class="fas fa-box"></i>Paket</a>
                <a href="index.php?page=admin_areas" class="sheet-item <?= $page == 'admin_areas' ? 'active' : '' ?>"><i class="fas fa-map-marker-alt"></i>Area</a>
                <a href="index.php?page=admin_expenses" class="sheet-item <?= $page == 'admin_expenses' ? 'active' : '' ?>"><i class="fas fa-wallet"></i>Pengeluaran</a>
                <a href="index.php?page=admin_router" class="sheet-item <?= $page == 'admin_router' ? 'active' : '' ?>"><i class="fas fa-network-wired"></i>Router</a>
                <a href="index.php?page=admin_new_customers" class="sheet-item <?= $page == 'admin_new_customers' ? 'active' : '' ?>"><i class="fas fa-star"></i>Pelanggan Baru</a>
                <a href="index.php?page=admin_create_invoice" class="sheet-item <?= $page == 'admin_create_invoice' ? 'active' : '' ?>"><i class="fas fa-file-invoice"></i>Invoice Eksternal</a>
                <a href="index.php?page=admin_assets" class="sheet-item <?= $page == 'admin_assets' ? 'active' : '' ?>"><i class="fas fa-boxes"></i>Aset</a>
                <a href="index.php?page=admin_auto_invoice" class="sheet-item <?= $page == 'admin_auto_invoice' ? 'active' : '' ?>"><i class="fas fa-magic"></i>Auto Tagihan</a>
                <a href="index.php?page=admin_landing" class="sheet-item <?= $page == 'admin_landing' ? 'active' : '' ?>"><i class="fas fa-globe"></i>Web Profil</a>
                <a href="index.php?page=admin_settings" class="sheet-item <?= $page == 'admin_settings' ? 'active' : '' ?>"><i class="fas fa-cog"></i>Pengaturan</a>
                <a href="index.php?page=admin_banners" class="sheet-item <?= $page == 'admin_banners' ? 'active' : '' ?>"><i class="fas fa-scroll"></i>Banner</a>
                <a href="index.php?page=admin_backup" class="sheet-item <?= $page == 'admin_backup' ? 'active' : '' ?>"><i class="fas fa-shield-alt"></i>Backup</a>
                <a href="index.php?page=admin_wa_gateway" class="sheet-item <?= $page == 'admin_wa_gateway' ? 'active' : '' ?>"><i class="fab fa-whatsapp" style="color:#1DA851"></i>WA Perangkat</a>
                <a href="index.php?page=admin_license" class="sheet-item <?= $page == 'admin_license' ? 'active' : '' ?>"><i class="fas fa-key"></i>Lisensi</a>
                <a href="index.php?page=change_password" class="sheet-item"><i class="fas fa-user-lock"></i>Ganti Password</a>
                <a href="index.php?page=logout" class="sheet-item text-danger"><i class="fas fa-sign-out-alt" style="color:#B42318"></i>Keluar</a>
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
    <div style="color:white; font-size:14px; background:rgba(255,255,255,0.1); padding:8px 16px; border-radius:50px; pointer-events:none;">
        <i class="fas fa-mouse-pointer"></i> Klik di mana saja untuk menutup
    </div>
</div>

<script>
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
    const statusHtml = c
        ? '<span class="ui-badge ui-badge-signal"><span class="inline-block h-1.5 w-1.5 rounded-full bg-signal"></span> WA terhubung</span>'
        : '<span class="ui-badge ui-badge-danger"><span class="inline-block h-1.5 w-1.5 rounded-full bg-danger"></span> WA offline</span>';
    document.querySelectorAll('.wa-status-indicator').forEach(el => el.innerHTML = statusHtml);
    document.querySelectorAll('.wa-status-sidebar-badge').forEach(el => {
        Object.assign(el.style, {
            width: '8px', height: '8px', borderRadius: '50%', display: 'inline-block',
            background: c ? '#34D399' : '#F87171', boxShadow: c ? '0 0 8px rgba(52,211,153,.6)' : 'none'
        });
    });
}

window.sendWAGateway = async function (phone, message, fallback, btn) {
    if (!btn) return;
    const old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
        const r = await fetch(WAApiProxy + 'send', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cid: WAGatewayCID, phone, message })
        });
        if ((await r.json()).error) throw new Error();
        btn.style.color = '#1F8A5B'; btn.innerHTML = '<i class="fas fa-check"></i>';
        setTimeout(() => { Object.assign(btn, { innerHTML: old, style: { color: '' }, disabled: false }); }, 2000);
    } catch (e) {
        window.open(fallback, '_blank'); Object.assign(btn, { innerHTML: old, disabled: false });
    }
};

/** INIT & PERSISTENCE */
window.addEventListener('DOMContentLoaded', () => {
    const s = document.querySelector('.app-sidebar-nav');
    if (s && sessionStorage.getItem('sidebarScroll')) s.scrollTop = sessionStorage.getItem('sidebarScroll');
    const last = sessionStorage.getItem('lastUrl');
    if (last && new URL(last).searchParams.get('page') === new URL(window.location.href).searchParams.get('page')) {
        if (sessionStorage.getItem('windowScroll')) window.scrollTo(0, sessionStorage.getItem('windowScroll'));
    }
    const p = new URLSearchParams(window.location.search);
    if (['bulk_paid', 'paid'].includes(p.get('msg'))) {
        showToast('Pembayaran berhasil');
        window.history.replaceState({}, '', window.location.href.replace(/[&?]msg=(bulk_paid|paid)/, ''));
    }
    let waInterval = null;
    const startPoll = () => { if (!waInterval) { checkWAStatus(); waInterval = setInterval(checkWAStatus, 60000); } };
    const stopPoll = () => { if (waInterval) { clearInterval(waInterval); waInterval = null; } };
    document.addEventListener('visibilitychange', () => document.hidden ? stopPoll() : startPoll());
    startPoll();
});

// Close the drawer when a nav link is clicked on small screens
document.addEventListener('click', function (ev) {
    try {
        const link = ev.target.closest && ev.target.closest('#appSidebar a.nav-item');
        if (!link) return;
        if (window.innerWidth < 1024) closeSidebar();
    } catch (e) { /* ignore */ }
}, true);

window.addEventListener('beforeunload', () => {
    const s = document.querySelector('.app-sidebar-nav');
    if (s) sessionStorage.setItem('sidebarScroll', s.scrollTop);
    sessionStorage.setItem('windowScroll', window.scrollY);
    sessionStorage.setItem('lastUrl', window.location.href);
});

function showToast(t) {
    const el = document.createElement('div');
    el.style.cssText = 'position:fixed; bottom:84px; left:50%; transform:translate(-50%, 8px); background:#172026; color:#fff; padding:10px 18px; border-radius:8px; font-weight:600; font-size:14px; box-shadow:0 12px 32px -12px rgba(15,58,71,.4); z-index:10000; transition:all .25s ease; opacity:0; display:flex; align-items:center; gap:8px;';
    el.innerHTML = '<i class="fas fa-check-circle" style="color:#34D399"></i> ' + t;
    document.body.appendChild(el);
    setTimeout(() => { el.style.opacity = '1'; el.style.transform = 'translate(-50%, 0)'; }, 10);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
}

/** MODALS & HELPERS */
window.toggleMobileMenu = (e) => { if (e) e.preventDefault(); const o = document.getElementById('mobileMenuOverlay'); if (o) { const open = o.classList.contains('hidden'); o.classList.toggle('hidden', !open); o.classList.toggle('flex', open); } };
window.closeMobileMenu = () => { const o = document.getElementById('mobileMenuOverlay'); if (o) { o.classList.add('hidden'); o.classList.remove('flex'); } };
window.openImagePreview = (src) => { const m = document.getElementById('globalImageModal'), i = document.getElementById('modalImg'); if (m && i) { m.style.display = 'flex'; i.src = src; document.body.style.overflow = 'hidden'; } };
window.closeImagePreview = () => { const m = document.getElementById('globalImageModal'); if (m) { m.style.display = 'none'; document.body.style.overflow = ''; } };
</script>
</body>
</html>
