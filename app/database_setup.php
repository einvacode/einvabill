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
            path_json TEXT,
            ppn_active INTEGER DEFAULT 0,
            bhp_active INTEGER DEFAULT 0,
            uso_active INTEGER DEFAULT 0
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

        CREATE TABLE IF NOT EXISTS auto_invoice_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER DEFAULT 1,
            report_json TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(tenant_id) REFERENCES settings(id)
        );

        CREATE TABLE IF NOT EXISTS wa_message_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            customer_id INTEGER NOT NULL,
            customer_name TEXT,
            phone_number TEXT,
            message_type TEXT DEFAULT 'reminder',
            status TEXT DEFAULT 'sent',
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            sent_by INTEGER,
            notes TEXT,
            tenant_id INTEGER DEFAULT 1,
            FOREIGN KEY(invoice_id) REFERENCES invoices(id),
            FOREIGN KEY(customer_id) REFERENCES customers(id),
            FOREIGN KEY(sent_by) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS invoice_item_catalog (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER DEFAULT 1,
            description TEXT NOT NULL,
            unit_price REAL DEFAULT 0,
            created_at TEXT
        );

        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT,
            ip TEXT,
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // 2. Incremental Schema Migrations (Safety check for existing databases)
    $cols_to_add = [
        'customers' => ['router_id' => 'INTEGER DEFAULT 0', 'pppoe_name' => 'TEXT', 'customer_code' => 'TEXT', 'area' => 'TEXT', 'created_by' => 'INTEGER DEFAULT 0', 'lat' => 'TEXT', 'lng' => 'TEXT', 'odp_id' => 'INTEGER DEFAULT 0', 'odp_port' => 'INTEGER', 'path_json' => 'TEXT', 'collector_id' => 'INTEGER DEFAULT 0', 'tenant_id' => 'INTEGER DEFAULT 1', 'ppn_active' => 'INTEGER DEFAULT 0', 'bhp_active' => 'INTEGER DEFAULT 0', 'uso_active' => 'INTEGER DEFAULT 0', 'email' => 'TEXT', 'company_name' => 'TEXT', 'npwp' => 'TEXT'],
        'users' => ['area' => 'TEXT', 'customer_id' => 'INTEGER', 'brand_name' => 'TEXT', 'brand_logo' => 'TEXT', 'brand_qris' => 'TEXT', 'brand_address' => 'TEXT', 'brand_contact' => 'TEXT', 'brand_bank' => 'TEXT', 'brand_rekening' => 'TEXT', 'wa_template' => 'TEXT', 'wa_template_paid' => 'TEXT', 'tenant_id' => 'INTEGER DEFAULT 1', 'must_change_password' => 'INTEGER DEFAULT 0'],
        'invoices' => ['discount' => 'REAL DEFAULT 0', 'tenant_id' => 'INTEGER DEFAULT 1', 'billing_address' => 'TEXT', 'billing_phone' => 'TEXT', 'billing_email' => 'TEXT', 'issued_by_id' => 'INTEGER', 'issued_by_name' => 'TEXT', 'payment_instructions' => 'TEXT', 'created_via' => 'TEXT', 'billing_company' => 'TEXT', 'billing_npwp' => 'TEXT'],
        'routers' => ['created_by' => 'INTEGER DEFAULT 0', 'tenant_id' => 'INTEGER DEFAULT 1'],
        'packages' => ['created_by' => 'INTEGER DEFAULT 0', 'tenant_id' => 'INTEGER DEFAULT 1'],
        'expenses' => ['created_by' => 'INTEGER DEFAULT 0', 'tenant_id' => 'INTEGER DEFAULT 1'],
        'infrastructure_assets' => ['price' => 'REAL DEFAULT 0', 'status' => "TEXT DEFAULT 'Deployed'", 'installation_date' => 'TEXT', 'created_by' => 'INTEGER DEFAULT 0', 'path_json' => 'TEXT', 'tenant_id' => 'INTEGER DEFAULT 1'],
        'payments' => ['tenant_id' => 'INTEGER DEFAULT 1'],
        'areas' => ['tenant_id' => 'INTEGER DEFAULT 1'],
        'banners' => ['tenant_id' => 'INTEGER DEFAULT 1'],
        'landing_packages' => ['tenant_id' => 'INTEGER DEFAULT 1'],
        'landing_logos' => ['tenant_id' => 'INTEGER DEFAULT 1'],
        'settings' => ['license_key' => 'TEXT', 'license_expiry' => 'TEXT', 'license_type' => 'TEXT', 'installation_date' => 'TEXT', 'site_url' => "TEXT DEFAULT 'http://fibernodeinternet.com'", 'acs_url' => 'TEXT', 'acs_user' => 'TEXT', 'acs_pass' => 'TEXT', 'landing_hero_title' => 'TEXT', 'landing_hero_text' => 'TEXT', 'landing_about_us' => 'TEXT', 'db_version' => 'INTEGER DEFAULT 0', 'tenant_id' => 'INTEGER DEFAULT 1', 'debug_mode' => 'INTEGER DEFAULT 0', 'company_qris' => 'TEXT', 'company_contact' => 'TEXT']
    ];

    foreach ($cols_to_add as $table => $cols) {
        foreach ($cols as $col => $def) {
            try { $db->exec("ALTER TABLE $table ADD COLUMN $col $def"); } catch (Exception $e) {}
        }
    }

    // Repair: before v25, init.php selected settings.debug_mode, which no
    // migration ever created. The failed SELECT made init.php believe the
    // tenant had no settings row and insert a fresh "Perusahaan Baru" row
    // on every request. Keep the oldest row per tenant, drop the rest.
    try {
        $db->exec("DELETE FROM settings WHERE company_name = 'Perusahaan Baru'
                   AND id NOT IN (SELECT MIN(id) FROM settings GROUP BY tenant_id)");
    } catch (Exception $e) {}

    // Seed the saved-item catalog from line items already used on invoices
    // (one row per description per tenant); idempotent.
    try {
        $db->exec("INSERT INTO invoice_item_catalog (tenant_id, description, unit_price, created_at)
                   SELECT i.tenant_id, TRIM(ii.description),
                          MAX(CASE WHEN ii.unit_price > 0 THEN ii.unit_price
                                   WHEN ii.qty > 0 THEN ROUND(ii.amount / ii.qty) ELSE ii.amount END),
                          datetime('now')
                   FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id
                   WHERE ii.description IS NOT NULL AND TRIM(ii.description) <> ''
                     AND NOT EXISTS (SELECT 1 FROM invoice_item_catalog c
                                     WHERE c.tenant_id = i.tenant_id AND LOWER(c.description) = LOWER(TRIM(ii.description)))
                   GROUP BY i.tenant_id, LOWER(TRIM(ii.description))");
    } catch (Exception $e) {}

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
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_tenant ON customers(tenant_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_tenant ON users(tenant_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_tenant_status_due ON invoices(tenant_id, status, due_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_tenant_collector ON customers(tenant_id, collector_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_tenant_created ON customers(tenant_id, created_by)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_payments_tenant_date ON payments(tenant_id, payment_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_settings_tenant ON settings(tenant_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_tenant_type ON customers(tenant_id, type)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_tenant_name ON customers(tenant_id, name)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_tenant_code ON customers(tenant_id, customer_code)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_tenant_regdate ON customers(tenant_id, registration_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_tenant_billdate ON customers(tenant_id, billing_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_tenant_customer_due ON invoices(tenant_id, customer_id, due_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_tenant_customer_status ON invoices(tenant_id, customer_id, status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_payments_tenant_invoice_date ON payments(tenant_id, invoice_id, payment_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_users_tenant_role ON users(tenant_id, role)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_invoice_items_invoice ON invoice_items(invoice_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_wa_message_logs_invoice ON wa_message_logs(invoice_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_wa_message_logs_customer ON wa_message_logs(customer_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_wa_message_logs_tenant_sent ON wa_message_logs(tenant_id, sent_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_wa_message_logs_status ON wa_message_logs(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_time ON login_attempts(attempted_at)");

    // 4. Seed Data
    // Default Settings
    $check_settings = $db->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($check_settings == 0) {
        $db->exec("INSERT INTO settings (id, company_name, company_tagline, company_address, wa_template, landing_hero_title, landing_hero_text, db_version) 
                  VALUES (1, 'EinvaBill ISP', 'Internet Cepat & Layanan Prima', 'Alamat Perusahaan Anda', 'Halo {nama}, tagihan Anda sebesar {tagihan} sudah terbit.', 'Koneksi Super Cepat & Stabil', 'Solusi internet dan IT untuk kebutuhan personal dan korporasi.', 27)");
    } else {
        $db->exec("UPDATE settings SET db_version = 27 WHERE id = 1");
    }

    // Default Users
    $check_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($check_users == 0) {
        $hash = password_hash('123456', PASSWORD_DEFAULT);
        // Seeded accounts must set their own password on first login.
        $stmt = $db->prepare("INSERT INTO users (username, password, role, name, must_change_password) VALUES (?, ?, ?, ?, 1)");
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
