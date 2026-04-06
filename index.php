<?php
require_once __DIR__ . '/app/init.php';

$page = $_GET['page'] ?? 'home';

// Handle Logout
if ($page === 'logout') {
    session_destroy();
    header("Location: index.php?page=login");
    exit;
}

// Handle Login POST
if ($page === 'login_post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $req_role = $_POST['requested_role'] ?? '';
        
        // Enforce Role Restriction: Partner must use Partner Portal, Staff must use Staff Portal
        if ($req_role === 'partner' && $user['role'] !== 'partner') {
            $error = "Akses Ditolak! Akun Anda bukan Akun Partner.";
            $page = 'login';
        } elseif ($req_role === 'staff' && !in_array($user['role'], ['admin', 'collector'])) {
            $error = "Akses Ditolak! Silakan gunakan Portal Partner.";
            $page = 'login';
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['name'];
            header("Location: index.php");
            exit;
        }
    } else {
        $error = "Username atau password salah!";
        $page = 'login';
    }
}

// Authentication Check
$public_pages = ['login', 'landing', 'customer_portal'];
if (!isset($_SESSION['user_id']) && !in_array($page, $public_pages)) {
    header("Location: index.php?page=landing");
    exit;
}

// License Enforcement Check
$license_exempt_pages = ['login', 'logout', 'admin_license', 'admin_license_post', 'landing', 'customer_portal'];
if (LICENSE_ST === 'EXPIRED' && !in_array($page, $license_exempt_pages)) {
    header("Location: index.php?page=admin_license");
    exit;
}

// Default page based on role
if ($page === 'home') {
    if (isset($_SESSION['user_role'])) {
        if ($_SESSION['user_role'] === 'admin') $page = 'admin_dashboard';
        elseif ($_SESSION['user_role'] === 'collector') $page = 'collector';
        elseif ($_SESSION['user_role'] === 'partner') $page = 'partner';
    } else {
        $page = 'landing';
    }
}

// Access Control (RBAC)
$permissions = [
    'admin' => '*', // Full access
    'collector' => ['collector', 'admin_customers', 'admin_invoices', 'router_data', 'admin_areas', 'admin_map'],
    'partner' => ['partner', 'partner_collection', 'admin_invoices', 'admin_customers', 'admin_packages', 'admin_reports', 'admin_expenses', 'admin_map', 'admin_router'] // Added 'admin_router' for sync
];

$user_role = $_SESSION['user_role'] ?? 'guest';
$is_allowed = false;

if ($user_role === 'admin') {
    $is_allowed = true;
} elseif (isset($permissions[$user_role])) {
    if (in_array($page, $public_pages) || in_array($page, $permissions[$user_role])) {
        $is_allowed = true;
    }
} elseif (in_array($page, $public_pages)) {
    $is_allowed = true;
}

if (!$is_allowed) {
    $page = '403'; // Set to a forbidden page
}

// Minimalistic Templating Route
ob_start();

switch ($page) {
    case '403':
        echo "<div class='glass-panel' style='padding:40px; text-align:center; min-height:300px; display:flex; flex-direction:column; justify-content:center; align-items:center;'>
                <i class='fas fa-shield-alt' style='font-size:64px; color:#ef4444; margin-bottom:20px; opacity:0.5;'></i>
                <h1 style='font-size:24px; margin-bottom:10px;'>Akses Ditolak</h1>
                <p style='color:var(--text-secondary); max-width:400px;'>Maaf, akun Anda tidak memiliki izin untuk mengakses halaman ini. Silakan hubungi Administrator jika ini adalah kesalahan.</p>
                <a href='index.php' class='btn btn-primary' style='margin-top:20px;'><i class='fas fa-home'></i> Beranda</a>
              </div>";
        break;
    case 'landing':
        require __DIR__ . '/views/landing.php';
        break;
    case 'customer_portal':
        require __DIR__ . '/views/customer_portal.php';
        break;
    case 'login':
        require __DIR__ . '/views/login.php';
        break;
    case 'admin_dashboard':
        require __DIR__ . '/views/admin/dashboard.php';
        break;
    case 'admin_customers':
        require __DIR__ . '/views/admin/customers.php';
        break;
    case 'admin_invoices':
        require __DIR__ . '/views/admin/invoices.php';
        break;
    case 'admin_expenses':
        require __DIR__ . '/views/admin/expenses.php';
        break;
    case 'admin_report_assets':
        require __DIR__ . '/views/admin/report_assets.php';
        break;
    case 'admin_reports':
        require __DIR__ . '/views/admin/reports.php';
        break;
    case 'admin_banners':
        require __DIR__ . '/views/admin/banners.php';
        break;
    case 'admin_landing':
        require __DIR__ . '/views/admin/landing_settings.php';
        break;
    case 'admin_users':
        require __DIR__ . '/views/admin/users.php';
        break;
    case 'admin_settings':
        require __DIR__ . '/views/admin/settings.php';
        break;
    case 'admin_router':
        require __DIR__ . '/views/admin/router.php';
        break;
    case 'admin_packages':
        require __DIR__ . '/views/admin/packages.php';
        break;
    case 'admin_areas':
        require __DIR__ . '/views/admin/areas.php';
        break;
    case 'admin_assets':
        require __DIR__ . '/views/admin/assets.php';
        break;
    case 'admin_map':
        require __DIR__ . '/views/admin/map.php';
        break;
    case 'admin_backup':
        require __DIR__ . '/views/admin/backup.php';
        break;
    case 'admin_license':
    case 'admin_license_post':
        require __DIR__ . '/views/admin/license.php';
        break;
    case 'admin_license_keygen':
        require __DIR__ . '/app/keygen.php';
        break;
    case 'admin_updater':
    case 'admin_updater_run':
        require __DIR__ . '/views/admin/updater.php';
        break;
    case 'router_data':
        require __DIR__ . '/app/router_data.php';
        exit;
    case 'collector':
        require __DIR__ . '/views/collector/dashboard.php';
        break;
    case 'partner':
        require __DIR__ . '/views/partner/dashboard.php';
        break;
    case 'partner_collection':
        require __DIR__ . '/views/partner/collection.php';
        break;
    default:
        if ($page !== 'login') {
            echo "<div class='glass-panel p-5 text-center'><h1>404 Not Found</h1></div>";
        }
        break;
}

$content = ob_get_clean();

// Print / Modal actions don't need layout if we want, but let's just wrap everything in layout except login
if ($page === 'login' || $page === 'landing' || $page === 'customer_portal') {
    echo $content;
} else {
    require __DIR__ . '/views/layout.php';
}
?>
