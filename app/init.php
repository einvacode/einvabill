<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

$db_file = __DIR__ . '/../database.sqlite';

// SANGAT PENTING: Cek izin tulis SEBELUM koneksi PDO agar bisa memberikan pesan error yang jelas di Linux/Proxmox
// Jika direktori tidak bisa ditulisi, PDO akan fatal error sebelum sempat menjalankan kode di bawahnya.
if (!file_exists($db_file)) {
    if (!is_writable(dirname($db_file))) {
        die("<div style='padding:40px; text-align:center; font-family:sans-serif;'>
            <h2 style='color:#ef4444;'>⚠️ Izin Akses Direktori Ditolak (Permisson Denied)</h2>
            <p>Sistem tidak dapat membuat file database. Ini terjadi karena folder <b>/var/www/html/einvabill</b> milik root, bukan web server.</p>
            <div style='background:#f3f4f6; padding:20px; border-radius:10px; display:inline-block; text-align:left; border:1px solid #d1d5db;'>
                <code>chown -R www-data:www-data " . realpath(dirname(__DIR__)) . "</code><br>
                <code>chmod -R 775 " . realpath(dirname(__DIR__)) . "</code>
            </div>
            <p style='color:#6b7280; font-size:14px; margin-top:20px;'>Jalankan 2 perintah di atas pada terminal Proxmox Anda, lalu <b>Refresh</b> halaman ini.</p>
        </div>");
    }
}

$db = new PDO('sqlite:' . $db_file);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Enable WAL Mode for better concurrency and stability on Linux (Proxmox/LXC)
$db->exec("PRAGMA journal_mode=WAL;");
$db->exec("PRAGMA synchronous=NORMAL;");

// Buat tabel jika belum ada
$db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        role TEXT NOT NULL,
        name TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        address TEXT,
        contact TEXT,
        package_name TEXT,
        monthly_fee REAL,
        ip_address TEXT,
        type TEXT DEFAULT 'customer', -- 'customer' atau 'partner'
        registration_date TEXT,
        billing_date INTEGER
    );

    CREATE TABLE IF NOT EXISTS invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        amount REAL NOT NULL,
        due_date TEXT NOT NULL,
        status TEXT DEFAULT 'Belum Lunas',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(customer_id) REFERENCES customers(id)
    );

    CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL,
        amount REAL NOT NULL,
        payment_date TEXT DEFAULT CURRENT_TIMESTAMP,
        received_by INTEGER,
        FOREIGN KEY(invoice_id) REFERENCES invoices(id),
        FOREIGN KEY(received_by) REFERENCES users(id)
    );

    CREATE TABLE IF NOT EXISTS settings (
        id INTEGER PRIMARY KEY DEFAULT 1,
        company_name TEXT NOT NULL,
        company_tagline TEXT,
        company_contact TEXT,
        company_address TEXT,
        company_logo TEXT,
        wa_template TEXT,
        wa_template_paid TEXT,
        bank_account TEXT,
        router_ip TEXT,
        router_user TEXT,
        router_pass TEXT,
        router_port INTEGER,
        site_url TEXT
    );

    CREATE TABLE IF NOT EXISTS routers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        host TEXT NOT NULL,
        port INTEGER DEFAULT 8728,
        username TEXT NOT NULL,
        password TEXT NOT NULL,
        created_by INTEGER DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS invoice_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL,
        description TEXT NOT NULL,
        amount REAL NOT NULL,
        FOREIGN KEY(invoice_id) REFERENCES invoices(id)
    );

    -- Landing Page Tables (Mandatory for Fresh Install)
    CREATE TABLE IF NOT EXISTS landing_packages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        speed TEXT NOT NULL,
        price INTEGER NOT NULL DEFAULT 0,
        description TEXT,
        features TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS landing_logos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        image_path TEXT,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
");

// Auto-migrate new columns safely
try { $db->exec("ALTER TABLE customers ADD COLUMN router_id INTEGER DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE customers ADD COLUMN pppoe_name TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE customers ADD COLUMN customer_code TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE customers ADD COLUMN area TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN area TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN customer_id INTEGER"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN brand_name TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN brand_logo TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN brand_qris TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN brand_address TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN brand_contact TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN brand_bank TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN brand_rekening TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN wa_template TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN wa_template_paid TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE customers ADD COLUMN created_by INTEGER DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE routers ADD COLUMN created_by INTEGER DEFAULT 0"); } catch(Exception $e) {}

// Create packages table
$db->exec("CREATE TABLE IF NOT EXISTS packages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    fee REAL NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INTEGER DEFAULT 0
)");

// Safely add created_by to existing packages if not present
try { $db->exec("ALTER TABLE packages ADD COLUMN created_by INTEGER DEFAULT 0"); } catch(Exception $e) {}

// Create areas table
$db->exec("CREATE TABLE IF NOT EXISTS areas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Create expenses table
$db->exec("CREATE TABLE IF NOT EXISTS expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL,
    amount REAL NOT NULL,
    description TEXT,
    date TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INTEGER DEFAULT 0
)");

// Safely add created_by to existing expenses if not present
try { $db->exec("ALTER TABLE expenses ADD COLUMN created_by INTEGER DEFAULT 0"); } catch(Exception $e) {}

// Auto-migrate existing areas from customers to areas table
try {
    $existing_areas = $db->query("SELECT DISTINCT area FROM customers WHERE area IS NOT NULL AND area != ''")->fetchAll(PDO::FETCH_COLUMN);
    $stmt_ins = $db->prepare("INSERT OR IGNORE INTO areas (name) VALUES (?)");
    foreach($existing_areas as $a_name) {
        $stmt_ins->execute([trim($a_name)]);
    }
} catch(Exception $e) {}

// Auto-generate customer_code for existing customers without one (unique random)
try {
    $db->exec("ALTER TABLE invoices ADD COLUMN discount REAL DEFAULT 0");
} catch(Exception $e) {}

try {
    $nocode = $db->query("SELECT id FROM customers WHERE customer_code IS NULL OR customer_code = ''")->fetchAll();
    if (count($nocode) > 0) {
        $stmt_code = $db->prepare("UPDATE customers SET customer_code = ? WHERE id = ?");
        $stmt_check = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
        foreach ($nocode as $nc) {
            do {
                $code = 'CUST-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
                $stmt_check->execute([$code]);
            } while ($stmt_check->fetchColumn() > 0);
            $stmt_code->execute([$code, $nc['id']]);
        }
    }
} catch(Exception $e) {}

// One-time migration: replace old sequential codes (CUST-0xxxx, 5 digits) with random ones
try {
    $old_codes = $db->query("SELECT id, customer_code FROM customers WHERE customer_code LIKE 'CUST-0%' AND LENGTH(customer_code) = 10")->fetchAll();
    if (count($old_codes) > 0) {
        $stmt_upd = $db->prepare("UPDATE customers SET customer_code = ? WHERE id = ?");
        $stmt_dup = $db->prepare("SELECT COUNT(*) FROM customers WHERE customer_code = ?");
        foreach ($old_codes as $oc) {
            do {
                $new_code = 'CUST-' . str_pad(mt_rand(100000, 999999), 6, '0', STR_PAD_LEFT);
                $stmt_dup->execute([$new_code]);
            } while ($stmt_dup->fetchColumn() > 0);
            $stmt_upd->execute([$new_code, $oc['id']]);
        }
    }
} catch(Exception $e) {}

// Licensing columns
try { $db->exec("ALTER TABLE settings ADD COLUMN license_key TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE settings ADD COLUMN license_expiry TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE settings ADD COLUMN license_type TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE settings ADD COLUMN installation_date TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE settings ADD COLUMN site_url TEXT DEFAULT 'http://fibernodeinternet.com'"); } catch(Exception $e) {}

// TR-069 ACS Settings
try { $db->exec("ALTER TABLE settings ADD COLUMN acs_url TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE settings ADD COLUMN acs_user TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE settings ADD COLUMN acs_pass TEXT"); } catch(Exception $e) {}

// Landing Page Settings
try { $db->exec("ALTER TABLE settings ADD COLUMN landing_hero_title TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE settings ADD COLUMN landing_hero_text TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE settings ADD COLUMN landing_about_us TEXT"); } catch(Exception $e) {}

// Infrastructure Assets Table (NIM)
$db->exec("CREATE TABLE IF NOT EXISTS infrastructure_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    type TEXT NOT NULL, -- OLT, ODC, ODP
    parent_id INTEGER DEFAULT 0, -- ODP -> ODC -> OLT
    lat TEXT,
    lng TEXT,
    total_ports INTEGER DEFAULT 8,
    brand TEXT,
    description TEXT,
    price REAL DEFAULT 0, -- Harga Beli
    status TEXT DEFAULT 'Deployed', -- Deployed, Stock, Repair
    installation_date TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Migrations for existing tables
try { $db->exec("ALTER TABLE infrastructure_assets ADD COLUMN price REAL DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE infrastructure_assets ADD COLUMN status TEXT DEFAULT 'Deployed'"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE infrastructure_assets ADD COLUMN installation_date TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE infrastructure_assets ADD COLUMN created_by INTEGER DEFAULT 0"); } catch(Exception $e) {}

// Customer GIS & Topology columns
try { $db->exec("ALTER TABLE customers ADD COLUMN lat TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE customers ADD COLUMN lng TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE customers ADD COLUMN odp_id INTEGER DEFAULT 0"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE customers ADD COLUMN odp_port INTEGER"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE customers ADD COLUMN path_json TEXT"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE infrastructure_assets ADD COLUMN path_json TEXT"); } catch(Exception $e) {}

// Banners Table
$db->exec("CREATE TABLE IF NOT EXISTS banners (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    content TEXT,
    image_path TEXT,
    target_role TEXT DEFAULT 'all', -- all, partner, customer
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Ensure at least one settings row exists
$check_settings = $db->query("SELECT COUNT(*) FROM settings")->fetchColumn();
if ($check_settings == 0) {
    try {
        $db->exec("INSERT INTO settings (id, company_name, company_tagline, company_address, wa_template, landing_hero_title, landing_hero_text) 
                  VALUES (1, 'EinvaBill ISP', 'Internet Cepat & Layanan Prima', 'Alamat Perusahaan Anda', 'Halo {nama}, tagihan Anda sebesar {tagihan} sudah terbit.', 'Koneksi Super Cepat & Stabil', 'Solusi internet dan IT untuk kebutuhan personal dan korporasi.')");
    } catch(Exception $e) {
        // Fallback for missing columns during insertion
        $db->exec("INSERT INTO settings (id, company_name) VALUES (1, 'EinvaBill ISP')");
    }
}

// Seed Landing Packages if empty
$check_landing = $db->query("SELECT COUNT(*) FROM landing_packages")->fetchColumn();
if ($check_landing == 0) {
    $stmt_pkg = $db->prepare("INSERT INTO landing_packages (name, speed, price, features, sort_order) VALUES (?, ?, ?, ?, ?)");
    $stmt_pkg->execute(['Paket Basic', '10 Mbps', 150000, 'Koneksi Stabil, Uptime 99%, Dukungan 24/7', 1]);
    $stmt_pkg->execute(['Paket Family', '20 Mbps', 250000, 'Koneksi Cepat, Gratis Router WiFi, Dukungan 24/7', 2]);
    $stmt_pkg->execute(['Paket Pro', '50 Mbps', 450000, 'High Speed Fiber, Prioritas Traffic, Dukungan VVIP', 3]);
}

// Fetch Settings for License Check
$site_settings = $db->query("SELECT * FROM settings WHERE id=1")->fetch();

// Auto-record installation date (start of trial)
if (empty($site_settings['installation_date'])) {
    $today = date('Y-m-d');
    $db->prepare("UPDATE settings SET installation_date = ? WHERE id=1")->execute([$today]);
    $site_settings['installation_date'] = $today;
}

// === LICENSE ENGINE ===
$MASTER_KEY = "EB-ULTIMATE-2026";
$license_key = $site_settings['license_key'] ?? '';
$install_date = $site_settings['installation_date'];
$expiry_date = $site_settings['license_expiry'] ?? '';

$LICENSE_ST = 'EXPIRED'; // Default
$LICENSE_MSG = '';

if ($license_key === $MASTER_KEY) {
    $LICENSE_ST = 'UNLIMITED';
} elseif (!empty($expiry_date) && strtotime($expiry_date) >= strtotime(date('Y-m-d'))) {
    $LICENSE_ST = 'ACTIVE';
} else {
    // Check Trial (7 Days)
    $days_since_install = (strtotime(date('Y-m-d')) - strtotime($install_date)) / 86400;
    if ($days_since_install <= 7) {
        $LICENSE_ST = 'TRIAL';
        $remaining = 7 - floor($days_since_install);
        $LICENSE_MSG = "Masa Percobaan (Trial) sisa $remaining hari.";
    } else {
        $LICENSE_ST = 'EXPIRED';
        $LICENSE_MSG = "Masa Percobaan / Lisensi Anda telah habis. Silakan hubungi Administrator Utama.";
    }
}

define('LICENSE_ST', $LICENSE_ST);
define('LICENSE_MSG', $LICENSE_MSG);

// Performance Optimization: Add missing indexes for faster searching and reporting
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_customer ON invoices(customer_id)"); } catch(Exception $e) {}
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status)"); } catch(Exception $e) {}
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_due ON invoices(due_date)"); } catch(Exception $e) {}
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_payments_invoice ON payments(invoice_id)"); } catch(Exception $e) {}
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_type ON customers(type)"); } catch(Exception $e) {}
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_area ON customers(area)"); } catch(Exception $e) {}
try { $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_code ON customers(customer_code)"); } catch(Exception $e) {}

// Insert default users if not exists
$stmt = $db->query("SELECT COUNT(*) FROM users");
if ($stmt->fetchColumn() == 0) {
    // Password for all is 'admin123' using password_hash
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (username, password, role, name) VALUES (?, ?, ?, ?)");
    $stmt->execute(['admin', $hash, 'admin', 'Administrator']);
    $stmt->execute(['tagih', $hash, 'collector', 'Petugas Tagih']);
    $stmt->execute(['mitra', $hash, 'partner', 'Mitra Partner']);
}
?>
