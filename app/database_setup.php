<?php
/**
 * Database Setup & Migration Script
 * This script handles all table creation, seed data, and schema migrations.
 * It should only be called when dev/admin wants to update the schema
 * or when the application detects a version mismatch.
 */

function run_database_setup($db) {
    // 1. Create Tables
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT NOT NULL,
            name TEXT NOT NULL,
            area TEXT,
            customer_id INTEGER,
            brand_name TEXT,
            brand_logo TEXT,
            brand_qris TEXT,
            brand_address TEXT,
            brand_contact TEXT,
            brand_bank TEXT,
            brand_rekening TEXT,
            wa_template TEXT,
            wa_template_paid TEXT
        );

        CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_code TEXT,
            name TEXT NOT NULL,
            address TEXT,
            contact TEXT,
            package_name TEXT,
            monthly_fee REAL,
            ip_address TEXT,
            type TEXT DEFAULT 'customer',
            registration_date TEXT,
            billing_date INTEGER,
            router_id INTEGER DEFAULT 0,
            pppoe_name TEXT,
            area TEXT,
            created_by INTEGER DEFAULT 0,
            lat TEXT,
            lng TEXT,
            odp_id INTEGER DEFAULT 0,
            odp_port INTEGER,
            path_json TEXT
        );

        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            due_date TEXT NOT NULL,
            status TEXT DEFAULT 'Belum Lunas',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            discount REAL DEFAULT 0,
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
            site_url TEXT DEFAULT 'http://fibernodeinternet.com',
            license_key TEXT,
            license_expiry TEXT,
            license_type TEXT,
            installation_date TEXT,
            acs_url TEXT,
            acs_user TEXT,
            acs_pass TEXT,
            landing_hero_title TEXT,
            landing_hero_text TEXT,
            landing_about_us TEXT,
            db_version INTEGER DEFAULT 0
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

        CREATE TABLE IF NOT EXISTS infrastructure_assets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT NOT NULL,
            parent_id INTEGER DEFAULT 0,
            lat TEXT,
            lng TEXT,
            total_ports INTEGER DEFAULT 8,
            brand TEXT,
            description TEXT,
            price REAL DEFAULT 0,
            status TEXT DEFAULT 'Deployed',
            installation_date TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER DEFAULT 0,
            path_json TEXT
        );

        CREATE TABLE IF NOT EXISTS banners (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            content TEXT,
            image_path TEXT,
            target_role TEXT DEFAULT 'all',
            is_active INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS packages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            fee REAL NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS areas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT NOT NULL,
            amount REAL NOT NULL,
            description TEXT,
            date TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER DEFAULT 0
        );
    ");

    // 2. Incremental Schema Migrations (Safety check for existing databases)
    $cols_to_add = [
        'customers' => ['router_id' => 'INTEGER DEFAULT 0', 'pppoe_name' => 'TEXT', 'customer_code' => 'TEXT', 'area' => 'TEXT', 'created_by' => 'INTEGER DEFAULT 0', 'lat' => 'TEXT', 'lng' => 'TEXT', 'odp_id' => 'INTEGER DEFAULT 0', 'odp_port' => 'INTEGER', 'path_json' => 'TEXT', 'collector_id' => 'INTEGER DEFAULT 0'],
        'users' => ['area' => 'TEXT', 'customer_id' => 'INTEGER', 'brand_name' => 'TEXT', 'brand_logo' => 'TEXT', 'brand_qris' => 'TEXT', 'brand_address' => 'TEXT', 'brand_contact' => 'TEXT', 'brand_bank' => 'TEXT', 'brand_rekening' => 'TEXT', 'wa_template' => 'TEXT', 'wa_template_paid' => 'TEXT'],
        'invoices' => ['discount' => 'REAL DEFAULT 0'],
        'routers' => ['created_by' => 'INTEGER DEFAULT 0'],
        'packages' => ['created_by' => 'INTEGER DEFAULT 0'],
        'expenses' => ['created_by' => 'INTEGER DEFAULT 0'],
        'infrastructure_assets' => ['price' => 'REAL DEFAULT 0', 'status' => "TEXT DEFAULT 'Deployed'", 'installation_date' => 'TEXT', 'created_by' => 'INTEGER DEFAULT 0', 'path_json' => 'TEXT'],
        'invoices' => [
            'discount' => 'REAL DEFAULT 0',
            'issued_by_id' => 'INTEGER DEFAULT 0',
            'issued_by_name' => "TEXT",
            'billing_address' => 'TEXT',
            'billing_phone' => 'TEXT',
            'billing_email' => 'TEXT'
        ],
        // Mark invoices created via quick standalone invoice tool
        'invoices_meta' => [
            'created_via' => "TEXT"
        ],
        'invoices_extra' => [
            'payment_instructions' => 'TEXT'
        ],
        'settings' => ['license_key' => 'TEXT', 'license_expiry' => 'TEXT', 'license_type' => 'TEXT', 'installation_date' => 'TEXT', 'site_url' => "TEXT DEFAULT 'http://fibernodeinternet.com'", 'acs_url' => 'TEXT', 'acs_user' => 'TEXT', 'acs_pass' => 'TEXT', 'landing_hero_title' => 'TEXT', 'landing_hero_text' => 'TEXT', 'landing_about_us' => 'TEXT', 'db_version' => 'INTEGER DEFAULT 0']
    ];

    foreach ($cols_to_add as $table => $cols) {
        foreach ($cols as $col => $def) {
            try { $db->exec("ALTER TABLE $table ADD COLUMN $col $def"); } catch (Exception $e) {}
        }
    }

    // 3. Performance Indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_customer ON invoices(customer_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_due ON invoices(due_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_payments_invoice ON payments(invoice_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_type ON customers(type)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_area ON customers(area)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_code ON customers(customer_code)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_reg_date ON customers(registration_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_payments_date ON payments(payment_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_created_by ON customers(created_by)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_collector ON customers(collector_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses(date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_created ON invoices(created_at)");

    // 4. Seed Data
    // Default Settings
    $check_settings = $db->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($check_settings == 0) {
        $db->exec("INSERT INTO settings (id, company_name, company_tagline, company_address, wa_template, landing_hero_title, landing_hero_text, db_version) 
                  VALUES (1, 'EinvaBill ISP', 'Internet Cepat & Layanan Prima', 'Alamat Perusahaan Anda', 'Halo {nama}, tagihan Anda sebesar {tagihan} sudah terbit.', 'Koneksi Super Cepat & Stabil', 'Solusi internet dan IT untuk kebutuhan personal dan korporasi.', 20)");
    } else {
        $db->exec("UPDATE settings SET db_version = 20 WHERE id = 1");
    }

    // Default Users
    $check_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($check_users == 0) {
        $hash = password_hash('123456', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, role, name) VALUES (?, ?, ?, ?)");
        $stmt->execute(['admin', $hash, 'admin', 'Administrator']);
        $stmt->execute(['tagih', $hash, 'collector', 'Petugas Tagih']);
        $stmt->execute(['mitra', $hash, 'partner', 'Mitra Partner']);
    }

    // Landing Packages
    $check_landing = $db->query("SELECT COUNT(*) FROM landing_packages")->fetchColumn();
    if ($check_landing == 0) {
        $stmt_pkg = $db->prepare("INSERT INTO landing_packages (name, speed, price, features, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt_pkg->execute(['Paket Basic', '10 Mbps', 150000, 'Koneksi Stabil, Uptime 99%, Dukungan 24/7', 1]);
        $stmt_pkg->execute(['Paket Family', '20 Mbps', 250000, 'Koneksi Cepat, Gratis Router WiFi, Dukungan 24/7', 2]);
        $stmt_pkg->execute(['Paket Pro', '50 Mbps', 450000, 'High Speed Fiber, Prioritas Traffic, Dukungan VVIP', 3]);
    }

    // 5. Cleanup / Normalization tasks (Only run during setup)
    // Area normalization
    try {
        $existing_areas = $db->query("SELECT DISTINCT area FROM customers WHERE area IS NOT NULL AND area != ''")->fetchAll(PDO::FETCH_COLUMN);
        $stmt_ins = $db->prepare("INSERT OR IGNORE INTO areas (name) VALUES (?)");
        foreach($existing_areas as $a_name) { $stmt_ins->execute([trim($a_name)]); }
    } catch(Exception $e) {}

    // Customer Code generation
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

    // Performance housekeeping
    $db->exec("ANALYZE;");
    $db->exec("VACUUM;");
}
