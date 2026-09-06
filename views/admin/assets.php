<?php
// Handle Asset Actions
$action = $_GET['action'] ?? 'list';
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'admin';
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$scope_where = " AND (a.tenant_id = $tenant_id) ";

function inferAssetCategory($name) {
    $text = strtolower(trim((string)($name ?? '')));
    if ($text === '') return 'Peralatan Kantor';
    if (preg_match('/laptop|komputer|pc|server|router|switch|monitor|printer|scanner|notebook|tablet|wifi|network|internet|access point|access-point/i', $text)) return 'Komputer & IT';
    if (preg_match('/mobil|motor|truck|kendaraan|vehicle/i', $text)) return 'Kendaraan';
    if (preg_match('/meja|kursi|lemari|rak|sofa|furniture|kabinet/i', $text)) return 'Furniture';
    if (preg_match('/gedung|bangunan|ruang|kantor|rumah|building/i', $text)) return 'Bangunan';
    if (preg_match('/printer|fax|stapler|scanner|projector|alat tulis|office/i', $text)) return 'Peralatan Kantor';
    return 'Lainnya';
}

function extractAssetCode($description) {
    if (!is_string($description)) return '';
    if (preg_match('/Kode:\s*([^|\n]+)/i', $description, $m)) {
        return trim($m[1]);
    }
    return '';
}

function generateAssetCode($db, $tenant_id, $category) {
    $prefixMap = [
        'Peralatan Kantor' => 'PK',
        'Komputer & IT' => 'IT',
        'Kendaraan' => 'KD',
        'Furniture' => 'FR',
        'Bangunan' => 'BG',
        'Lainnya' => 'LN'
    ];
    $prefix = $prefixMap[$category] ?? 'LN';
    $stmt = $db->prepare("SELECT description FROM infrastructure_assets WHERE tenant_id = ? ORDER BY id DESC");
    $stmt->execute([$tenant_id]);
    $latest = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $code = extractAssetCode($row['description'] ?? '');
        if (preg_match('/' . preg_quote($prefix) . '-?(\d{3,})$/i', $code, $m)) {
            $num = intval($m[1]);
            if ($num > $latest) $latest = $num;
        }
    }
    return $prefix . '-' . str_pad($latest + 1, 4, '0', STR_PAD_LEFT);
}

if (($action ?? 'list') === 'print') {
    $assets = $db->query("SELECT a.*, p.name as parent_name FROM infrastructure_assets a LEFT JOIN infrastructure_assets p ON a.parent_id = p.id WHERE 1=1 $scope_where ORDER BY a.type DESC, a.name ASC")->fetchAll();
    $company_name = $_SESSION['company_name'] ?? 'Perusahaan';
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Daftar Aset</title><style>body{font-family:Arial,sans-serif;padding:24px;color:#111}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{border:1px solid #ddd;padding:8px;font-size:12px;text-align:left}th{background:#f5f5f5}h2,h3{margin:0 0 8px} .meta{font-size:12px;color:#666;margin-bottom:12px}</style></head><body>';
    echo '<h2>Daftar Aset</h2>';
    echo '<div class="meta">Periode: ' . date('d-m-Y') . '</div>';
    echo '<div class="meta">Perusahaan: ' . htmlspecialchars($company_name) . '</div>';
    echo '<table><thead><tr><th>No</th><th>Nama Aset</th><th>Kategori</th><th>Merk / Vendor</th><th>Nilai Perolehan</th><th>Status</th><th>Tanggal Perolehan</th></tr></thead><tbody>';
    $no = 1;
    foreach ($assets as $a) {
        $description = $a['description'] ?? '';
        $codeMatch = [];
        preg_match('/Kode:\s*([^|]+)/i', $description, $codeMatch);
        $code = isset($codeMatch[1]) ? trim($codeMatch[1]) : '';
        $nameDisplay = htmlspecialchars($a['name'] ?? '');
        if ($code !== '') {
            $nameDisplay .= ' <span style="color:#666;">(' . htmlspecialchars($code) . ')</span>';
        }
        echo '<tr>';
        echo '<td>' . $no++ . '</td>';
        echo '<td>' . $nameDisplay . '</td>';
        echo '<td>' . htmlspecialchars($a['type'] ?: 'Lainnya') . '</td>';
        echo '<td>' . htmlspecialchars($a['brand'] ?: '-') . '</td>';
        echo '<td>Rp ' . number_format((float)($a['price'] ?? 0), 0, ',', '.') . '</td>';
        echo '<td>' . htmlspecialchars($a['status'] ?: '-') . '</td>';
        echo '<td>' . htmlspecialchars($a['installation_date'] ?: '-') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<script>window.print();</script></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add' || $action === 'edit') {
        $name = $_POST['name'];
        $type = inferAssetCategory($name);
        if (!empty($_POST['type'])) {
            $type = $_POST['type'];
        }
        $parent_id = $_POST['parent_id'] ?? 0;
        $lat = $_POST['lat'] ?? '';
        $lng = $_POST['lng'] ?? '';
        $total_ports = $_POST['total_ports'] ?? 8;
        $brand = $_POST['brand'] ?? '';
        $description = $_POST['description'] ?? '';
        $price = $_POST['price'] ?? 0;
        $status = $_POST['status'] ?? 'Deployed';
        $installation_date = $_POST['installation_date'] ?? date('Y-m-d');

        if ($action === 'add') {
            $tenant_id = $_SESSION['tenant_id'] ?? 1;
            $asset_code = trim($_POST['asset_code'] ?? '');
            if ($asset_code === '') {
                $asset_code = generateAssetCode($db, $tenant_id, $type);
            }
            $description = trim($_POST['description'] ?? '');
            $useful_life_years = max(1, intval($_POST['useful_life_years'] ?? 5));
            $description_parts = [];
            if ($asset_code !== '') {
                $description_parts[] = 'Kode: ' . $asset_code;
            }
            if ($useful_life_years > 0) {
                $description_parts[] = 'Masa manfaat: ' . $useful_life_years . ' tahun';
            }
            if ($description !== '') {
                $description_parts[] = $description;
            }
            $description = implode(' | ', $description_parts);
            $stmt = $db->prepare("INSERT INTO infrastructure_assets (name, type, parent_id, lat, lng, total_ports, brand, description, price, status, installation_date, created_by, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $type, $parent_id, $lat, $lng, $total_ports, $brand, $description, $price, $status, $installation_date, $u_id, $tenant_id]);
            $success = "Aset berhasil ditambahkan.";
        } else {
            $id = intval($_POST['id'] ?? 0);
            $tenant_id = $_SESSION['tenant_id'] ?? 1;
            // Ownership Check
            $check = $db->query("SELECT tenant_id FROM infrastructure_assets WHERE id = $id")->fetchColumn();
            $is_owner = ($u_role === 'admin') ? ($check == $tenant_id) : (/* restricted */ false);
            if ($is_owner) {
                $asset_code = trim($_POST['asset_code'] ?? '');
                if ($asset_code === '') {
                    $asset_code = extractAssetCode($db->query("SELECT description FROM infrastructure_assets WHERE id = $id LIMIT 1")->fetchColumn() ?: '');
                }
                $description = trim($_POST['description'] ?? '');
                $useful_life_years = max(1, intval($_POST['useful_life_years'] ?? 5));
                $description_parts = [];
                if ($asset_code !== '') {
                    $description_parts[] = 'Kode: ' . $asset_code;
                }
                if ($useful_life_years > 0) {
                    $description_parts[] = 'Masa manfaat: ' . $useful_life_years . ' tahun';
                }
                if ($description !== '') {
                    $description_parts[] = $description;
                }
                $description = implode(' | ', $description_parts);
                $stmt = $db->prepare("UPDATE infrastructure_assets SET name=?, type=?, parent_id=?, lat=?, lng=?, total_ports=?, brand=?, description=?, price=?, status=?, installation_date=? WHERE id=? AND tenant_id=?");
                $stmt->execute([$name, $type, $parent_id, $lat, $lng, $total_ports, $brand, $description, $price, $status, $installation_date, $id, $tenant_id]);
                $success = "Aset berhasil diperbarui.";
            }
        }
    }

    // Create invoice for asset sale
    if ($action === 'invoice_create') {
        $asset_id = intval($_POST['asset_id'] ?? 0);
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $recipient_name = trim($_POST['recipient_name'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $due_date = $_POST['due_date'] ?? date('Y-m-d');
        $description = trim($_POST['description'] ?? 'Pembelian Perangkat');
        $billing_address = trim($_POST['billing_address'] ?? '');
        $billing_phone = trim($_POST['billing_phone'] ?? '');
        $billing_email = trim($_POST['billing_email'] ?? '');

        if ($customer_id > 0) {
            try {
                $tenant_id = $_SESSION['tenant_id'] ?? 1;
                $cust_stmt = $db->prepare("SELECT name, address, contact, package_name, monthly_fee FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1");
                $cust_stmt->execute([$customer_id, $tenant_id]);
                $customer = $cust_stmt->fetch(PDO::FETCH_ASSOC);
                if ($customer) {
                    if ($recipient_name === '') $recipient_name = trim($customer['name'] ?? '');
                    if ($billing_address === '') $billing_address = trim($customer['address'] ?? '');
                    if ($billing_phone === '') $billing_phone = trim($customer['contact'] ?? '');
                    if ($billing_email === '') {
                        try {
                            $cust_cols = $db->query("PRAGMA table_info(customers)")->fetchAll(PDO::FETCH_COLUMN, 1);
                            if (is_array($cust_cols) && in_array('email', $cust_cols)) {
                                $email_val = $db->prepare("SELECT email FROM customers WHERE id = ? AND tenant_id = ? LIMIT 1");
                                $email_val->execute([$customer_id, $tenant_id]);
                                $billing_email = trim((string)$email_val->fetchColumn());
                            }
                        } catch (Exception $e) {}
                    }
                    $base_amount = floatval($customer['monthly_fee'] ?? 0);
                    $customer_tax_total = compute_customer_invoice_total_from_amount($base_amount, $customer['ppn_active'] ?? 0, $customer['bhp_active'] ?? 0, $customer['uso_active'] ?? 0)['total'];
                    if ($amount <= 0) $amount = $customer_tax_total;
                    if (empty($_POST['item_desc'])) {
                        $_POST['item_desc'] = [trim($customer['package_name'] ?? 'Tagihan Layanan') ?: 'Tagihan Layanan'];
                        $_POST['item_qty'] = [1];
                        $_POST['item_unit'] = [$amount > 0 ? $amount : $base_amount];
                        $_POST['item_amount'] = [floatval($_POST['item_unit'][0] ?? $amount)];
                    }
                }
            } catch (Exception $e) {}
        }

        // Ownership / permission: only admin or creator can issue invoice
        $u_id = $_SESSION['user_id'];
        $u_role = $_SESSION['user_role'] ?? 'guest';

        if ($u_role === 'admin' || $u_role === 'partner') {
            $created_at = date('Y-m-d H:i:s');
            $issued_by_id = $u_id;
            $issued_by_name = $_SESSION['user_name'] ?? '';

            // If no customer selected but recipient name provided, create a temporary customer record so invoice history can reference it
            if ($customer_id <= 0 && !empty($recipient_name)) {
                try {
                    // Create a temporary customer record
                    $tenant_id = $_SESSION['tenant_id'] ?? 1;
                    $stmt_c = $db->prepare("INSERT INTO customers (customer_code, name, address, contact, type, created_by, registration_date, tenant_id) VALUES (?, ?, ?, ?, 'note', ?, datetime('now'), ?)");
                    $cust_code = null;
                    $stmt_c->execute([$cust_code, $recipient_name, $billing_address, $billing_phone, 0, $tenant_id]);
                    $customer_id = $db->lastInsertId();
                } catch (Exception $e) {
                    // fallback: leave customer_id as 0
                    $customer_id = 0;
                }
            }

            // Ensure invoices table has extended columns (auto-migrate if needed)
            try {
                $existing = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN, 1);
            } catch (Exception $e) { $existing = []; }

            $ensure_cols = [
                'billing_address' => 'TEXT',
                'billing_phone' => 'TEXT',
                'billing_email' => 'TEXT',
                'issued_by_id' => 'INTEGER DEFAULT 0',
                'issued_by_name' => 'TEXT',
                'payment_instructions' => 'TEXT',
                'created_via' => 'TEXT'
            ];
            foreach ($ensure_cols as $col => $def) {
                if (!in_array($col, $existing)) {
                    try { $db->exec("ALTER TABLE invoices ADD COLUMN $col $def"); } catch (Exception $e) {}
                }
            }

            // Refresh columns list
            try {
                $cols = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN, 1);
            } catch (Exception $e) { $cols = []; }
            $has_extra_cols = is_array($cols) && (in_array('billing_address', $cols) || in_array('issued_by_name', $cols));

            // check if invoices table has 'created_via' column
            $has_created_via = false;
            try {
                $inv_cols = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN, 1);
                $has_created_via = is_array($inv_cols) && in_array('created_via', $inv_cols);
            } catch (Exception $e) { $has_created_via = false; }

            $tenant_id = $_SESSION['tenant_id'] ?? 1;
            if ($has_extra_cols) {
                if ($has_created_via) {
                    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at, status, discount, billing_address, billing_phone, billing_email, issued_by_id, issued_by_name, created_via, tenant_id) VALUES (?, ?, ?, ?, 'Belum Lunas', 0, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$customer_id, $amount, $due_date, $created_at, $billing_address, $billing_phone, $billing_email, $issued_by_id, $issued_by_name, ($_POST['created_via'] ?? ''), $tenant_id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at, status, discount, billing_address, billing_phone, billing_email, issued_by_id, issued_by_name, tenant_id) VALUES (?, ?, ?, ?, 'Belum Lunas', 0, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$customer_id, $amount, $due_date, $created_at, $billing_address, $billing_phone, $billing_email, $issued_by_id, $issued_by_name, $tenant_id]);
                }
            } else {
                // Fallback to legacy insert (DB without new columns)
                if ($has_created_via) {
                    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at, status, discount, created_via, tenant_id) VALUES (?, ?, ?, ?, 'Belum Lunas', 0, ?, ?)");
                    $stmt->execute([$customer_id, $amount, $due_date, $created_at, ($_POST['created_via'] ?? ''), $tenant_id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at, status, discount, tenant_id) VALUES (?, ?, ?, ?, 'Belum Lunas', 0, ?)");
                    $stmt->execute([$customer_id, $amount, $due_date, $created_at, $tenant_id]);
                }
            }
            $invoice_id = $db->lastInsertId();

            // Ensure invoice_items has qty/unit columns (auto-migrate if needed)
            try {
                $item_cols = $db->query("PRAGMA table_info(invoice_items)")->fetchAll(PDO::FETCH_COLUMN, 1);
            } catch (Exception $e) { $item_cols = []; }
            $ensure_item_cols = [ 'qty' => 'INTEGER DEFAULT 1', 'unit_price' => 'REAL DEFAULT 0' ];
            foreach ($ensure_item_cols as $col => $def) {
                if (!in_array($col, $item_cols)) {
                    try { $db->exec("ALTER TABLE invoice_items ADD COLUMN $col $def"); } catch (Exception $e) {}
                }
            }

            try {
                $item_cols = $db->query("PRAGMA table_info(invoice_items)")->fetchAll(PDO::FETCH_COLUMN, 1);
            } catch (Exception $e) { $item_cols = []; }

            $has_qty = is_array($item_cols) && (in_array('qty', $item_cols) || in_array('quantity', $item_cols));
            $has_unit = is_array($item_cols) && (in_array('unit_price', $item_cols) || in_array('unit', $item_cols));

            if ($has_qty && $has_unit) {
                $stmt_item = $db->prepare("INSERT INTO invoice_items (invoice_id, description, amount, qty, unit_price) VALUES (?, ?, ?, ?, ?)");
            } else {
                $stmt_item = $db->prepare("INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)");
            }

            if (!empty($_POST['item_desc']) && is_array($_POST['item_desc'])) {
                $descs = $_POST['item_desc'];
                $amounts = $_POST['item_amount'] ?? array_fill(0, count($descs), 0);
                $qtys = $_POST['item_qty'] ?? array_fill(0, count($descs), 1);
                $units = $_POST['item_unit'] ?? array_fill(0, count($descs), 0);
                foreach ($descs as $i => $d) {
                    $d = trim($d);
                    $a = floatval($amounts[$i] ?? 0);
                    $q = intval($qtys[$i] ?? 1);
                    $u = floatval($units[$i] ?? 0);
                    if ($d !== '' && $a >= 0) {
                        if ($has_qty && $has_unit) {
                            $stmt_item->execute([$invoice_id, $d, $a, $q, $u]);
                        } else {
                            // If DB doesn't have qty/unit columns, embed qty and unit into description for print clarity
                            $desc_extra = $d;
                            if ($q > 1 || $u > 0) {
                                $desc_extra .= ' - ' . $q . ' x Rp ' . number_format($u, 0, ',', '.');
                            }
                            $stmt_item->execute([$invoice_id, $desc_extra, $a]);
                        }
                    }
                }
            } else {
                // single fallback
                if ($has_qty && $has_unit) {
                    $single_qty = intval($_POST['item_qty'][0] ?? 1);
                    $single_unit = floatval($_POST['item_unit'][0] ?? $amount);
                    $db->prepare("INSERT INTO invoice_items (invoice_id, description, amount, qty, unit_price) VALUES (?, ?, ?, ?, ?)")->execute([$invoice_id, $description, $amount, $single_qty, $single_unit]);
                } else {
                    $desc_extra = $description;
                    $sq = intval($_POST['item_qty'][0] ?? 0);
                    $su = floatval($_POST['item_unit'][0] ?? 0);
                    if ($sq > 1 || $su > 0) $desc_extra .= ' - ' . $sq . ' x Rp ' . number_format($su, 0, ',', '.');
                    $stmt_item->execute([$invoice_id, $desc_extra, $amount]);
                }
            }

            // Save payment_instructions if provided and column exists
            $payment_instructions = trim($_POST['payment_instructions'] ?? '');
            if ($payment_instructions) {
                try {
                    $cols = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN, 1);
                    if (is_array($cols) && in_array('payment_instructions', $cols)) {
                        $db->prepare("UPDATE invoices SET payment_instructions = ? WHERE id = ?")->execute([$payment_instructions, $invoice_id]);
                    }
                } catch (Exception $e) {}
            }

            // Optionally mark asset as sold (set status to 'Sold') if requested
            if (isset($_POST['mark_sold']) && intval($_POST['mark_sold']) === 1 && $asset_id > 0) {
                try {
                    $db->prepare("UPDATE infrastructure_assets SET status = 'Sold' WHERE id = ?")->execute([$asset_id]);
                } catch (Exception $e) {}
            }

            header("Location: index.php?page=admin_invoices&action=print&id=$invoice_id");
            exit;
        } else {
            header("Location: index.php?page=admin_assets&msg=forbidden");
            exit;
        }
    }

    // Update existing quick invoice (edit form posts here)
    if ($action === 'invoice_update') {
        $invoice_id = intval($_POST['invoice_id'] ?? 0);
        if ($invoice_id <= 0) { header("Location: index.php?page=admin_create_invoice&msg=invalid"); exit; }

        // fetch invoice and verify it's a quick invoice
        try {
            $inv = $db->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
            $inv->execute([$invoice_id]);
            $invoice = $inv->fetch();
        } catch (Exception $e) { $invoice = null; }

        if (!$invoice) { header("Location: index.php?page=admin_create_invoice&msg=notfound"); exit; }

        // check created_via if column exists — allow editing for 'quick' and 'external' types
        try {
            $cols = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN,1);
        } catch (Exception $e) { $cols = []; }
        if (is_array($cols) && in_array('created_via', $cols)) {
            $cv = $invoice['created_via'] ?? '';
            if ($cv !== 'quick' && $cv !== 'external' && $cv !== '') {
                header("Location: index.php?page=admin_create_invoice&msg=not_quick"); exit;
            }
        }

        // permission: only admin or issuer can edit
        $u_id = $_SESSION['user_id']; $u_role = $_SESSION['user_role'] ?? 'guest';
        $can_edit = false;
        if ($u_role === 'admin' || $u_role === 'partner') $can_edit = true;
        if (!$can_edit) { header("Location: index.php?page=admin_create_invoice&msg=forbidden"); exit; }

        // collect header fields
        $billing_address = trim($_POST['billing_address'] ?? '');
        $billing_phone = trim($_POST['billing_phone'] ?? '');
        $billing_email = trim($_POST['billing_email'] ?? '');
        $payment_instructions = trim($_POST['payment_instructions'] ?? '');
        $due_date = $_POST['due_date'] ?? $invoice['due_date'];
        $status = $_POST['status'] ?? $invoice['status'];

        // compute total from posted items
        $total_amount = 0;
        $descs = $_POST['item_desc'] ?? [];
        $amounts = $_POST['item_amount'] ?? [];
        foreach ($descs as $i => $d) {
            $a = floatval($amounts[$i] ?? 0);
            $total_amount += $a;
        }

        // update invoice header (only if columns exist)
        try {
            $cols = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN,1);
        } catch (Exception $e) { $cols = []; }
        $has_payment_instr = is_array($cols) && in_array('payment_instructions', $cols);
        $has_billing_cols = is_array($cols) && in_array('billing_address', $cols);

        if ($has_billing_cols) {
            if ($has_payment_instr) {
                $db->prepare("UPDATE invoices SET amount = ?, due_date = ?, billing_address = ?, billing_phone = ?, billing_email = ?, payment_instructions = ?, status = ? WHERE id = ?")->execute([$total_amount, $due_date, $billing_address, $billing_phone, $billing_email, $payment_instructions, $status, $invoice_id]);
            } else {
                $db->prepare("UPDATE invoices SET amount = ?, due_date = ?, billing_address = ?, billing_phone = ?, billing_email = ?, status = ? WHERE id = ?")->execute([$total_amount, $due_date, $billing_address, $billing_phone, $billing_email, $status, $invoice_id]);
            }
        } else {
            // minimal update
            $db->prepare("UPDATE invoices SET amount = ?, due_date = ?, status = ? WHERE id = ?")->execute([$total_amount, $due_date, $status, $invoice_id]);
        }

        // Replace invoice items: delete existing then insert posted
        try { $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$invoice_id]); } catch (Exception $e) {}

        // Ensure invoice_items has qty/unit columns (like in create)
        try {
            $item_cols = $db->query("PRAGMA table_info(invoice_items)" )->fetchAll(PDO::FETCH_COLUMN,1);
        } catch (Exception $e) { $item_cols = []; }
        $ensure_item_cols = [ 'qty' => 'INTEGER DEFAULT 1', 'unit_price' => 'REAL DEFAULT 0' ];
        foreach ($ensure_item_cols as $col => $def) {
            if (!in_array($col, $item_cols)) {
                try { $db->exec("ALTER TABLE invoice_items ADD COLUMN $col $def"); } catch (Exception $e) {}
            }
        }
        try { $item_cols = $db->query("PRAGMA table_info(invoice_items)")->fetchAll(PDO::FETCH_COLUMN,1); } catch (Exception $e) { $item_cols = []; }
        $has_qty = is_array($item_cols) && in_array('qty', $item_cols);
        $has_unit = is_array($item_cols) && in_array('unit_price', $item_cols);

        if ($has_qty && $has_unit) {
            $stmt_item = $db->prepare("INSERT INTO invoice_items (invoice_id, description, amount, qty, unit_price) VALUES (?, ?, ?, ?, ?)");
        } else {
            $stmt_item = $db->prepare("INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)");
        }

        $qtys = $_POST['item_qty'] ?? [];
        $units = $_POST['item_unit'] ?? [];
        foreach ($descs as $i => $d) {
            $d = trim($d);
            $a = floatval($amounts[$i] ?? 0);
            $q = intval($qtys[$i] ?? 1);
            $u = floatval($units[$i] ?? 0);
            if ($d === '') continue;
            if ($has_qty && $has_unit) {
                $stmt_item->execute([$invoice_id, $d, $a, $q, $u]);
            } else {
                $desc_extra = $d;
                if ($q > 1 || $u > 0) $desc_extra .= ' - ' . $q . ' x Rp ' . number_format($u, 0, ',', '.');
                $stmt_item->execute([$invoice_id, $desc_extra, $a]);
            }
        }

        header("Location: index.php?page=admin_create_invoice&msg=updated");
        exit;
    }
}

if ($action === 'delete') {
    $id = intval($_GET['id'] ?? 0);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    // Ownership Check
    $check = $db->query("SELECT tenant_id FROM infrastructure_assets WHERE id = $id")->fetchColumn();
    $is_owner = ($u_role === 'admin') ? ($check == $tenant_id) : (/* restricted */ false);
    
    if ($is_owner) {
        $db->prepare("DELETE FROM infrastructure_assets WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
    }
    header("Location: index.php?page=admin_assets");
    exit;
}

// Allow marking quick invoices as paid from the quick-invoice UI and return there
if ($action === 'invoice_mark_paid') {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $db->prepare("SELECT amount, discount FROM invoices WHERE id = ?");
            $stmt->execute([$id]);
            $inv = $stmt->fetch();
        } catch (Exception $e) { $inv = null; }

        if ($inv) {
            $net_amount = floatval($inv['amount']) - floatval($inv['discount'] ?? 0);
            $receiver_id = $_SESSION['user_id'];
            $tenant_id = $_SESSION['tenant_id'] ?? 1;
            $payment_date = date('Y-m-d H:i:s');
            try {
                $db->prepare("UPDATE invoices SET status = 'Lunas' WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
                $db->prepare("INSERT INTO payments (invoice_id, amount, received_by, payment_date, tenant_id) VALUES (?, ?, ?, ?, ?)")->execute([$id, $net_amount, $receiver_id, $payment_date, $tenant_id]);
            } catch (Exception $e) {}
        }
    }
    header("Location: index.php?page=admin_create_invoice&msg=paid");
    exit;
}

// Delete quick invoice and return to quick-invoice page
if ($action === 'invoice_delete_quick') {
    $id = intval($_GET['id'] ?? 0);
    if ($id > 0) {
        try {
            // remove payments, items, invoice
            $tenant_id = $_SESSION['tenant_id'] ?? 1;
            $db->prepare("DELETE FROM payments WHERE invoice_id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
            $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM invoices WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
        } catch (Exception $e) {
            // ignore errors but continue
        }
    }
    header("Location: index.php?page=admin_create_invoice&msg=deleted");
    exit;
}

// Fetch Basic Stats for non-PHP blocks
$stats_raw = $db->query("SELECT type, COUNT(*) as count FROM infrastructure_assets a WHERE 1=1 $scope_where GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR);

// Recursive Function to Build Network Tree
function buildNetworkTree($db, $parentId = 0, $scope_where = "") {
    if ($parentId == 0) {
        $stmt = $db->prepare("SELECT a.*, (SELECT COUNT(*) FROM customers WHERE odp_id = a.id) as cust_count FROM infrastructure_assets a WHERE parent_id = 0 $scope_where ORDER BY type ASC, name ASC");
    } else {
        $stmt = $db->prepare("SELECT a.*, (SELECT COUNT(*) FROM customers WHERE odp_id = a.id) as cust_count FROM infrastructure_assets a WHERE parent_id = ? $scope_where ORDER BY type ASC, name ASC");
    }
    
    if ($parentId == 0) $stmt->execute();
    else $stmt->execute([$parentId]);
    
    $assets = $stmt->fetchAll();
    $tree = [];
    
    foreach ($assets as $asset) {
        $children = buildNetworkTree($db, $asset['id'], $scope_where);
        
        // Calculate Total Active Downstream (Recursive)
        $total_child_usage = 0;
        foreach($children as $child) {
            $total_child_usage += $child['total_active_downstream'];
        }
        
        $asset['children'] = $children;
        $asset['total_active_downstream'] = $asset['cust_count'] + $total_child_usage;
        $tree[] = $asset;
    }
    return $tree;
}

// Enhanced Stats Calculation
$total_investment = $db->query("SELECT SUM(price) FROM infrastructure_assets a WHERE 1=1 $scope_where")->fetchColumn() ?: 0;
$total_ports_capacity = $db->query("SELECT SUM(total_ports) FROM infrastructure_assets a WHERE 1=1 $scope_where")->fetchColumn() ?: 0;
$used_by_customers = $db->query("SELECT COUNT(*) FROM customers c WHERE odp_id > 0 AND tenant_id = $tenant_id")->fetchColumn() ?: 0;
$used_by_child_assets = $db->query("SELECT COUNT(*) FROM infrastructure_assets a WHERE parent_id > 0 $scope_where")->fetchColumn() ?: 0;
$total_ports_used = $used_by_customers + $used_by_child_assets;
$idle_ports = $total_ports_capacity - $total_ports_used;
$utilization_pct = ($total_ports_capacity > 0) ? ($total_ports_used / $total_ports_capacity) * 100 : 0;
$active_assets = $db->query("SELECT COUNT(*) FROM infrastructure_assets a WHERE 1=1 $scope_where AND lower(COALESCE(status, '')) IN ('aktif', 'deployed', 'siap pakai', 'ready', 'active')")->fetchColumn() ?: 0;
?>

<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Register aset perusahaan</h2>
    </div>
    <div class="compact-toolbar flex flex-wrap gap-2">
        <button class="ui-btn ui-btn-outline" onclick="window.open('index.php?page=admin_assets&action=print','_blank')"><i class="fas fa-print"></i> Export / cetak</button>
        <button class="ui-btn ui-btn-primary" onclick="showAssetModal()"><i class="fas fa-plus"></i> Tambah aset</button>
    </div>
</div>

<div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Total nilai perolehan</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums">Rp <?= number_format($total_investment, 0, ',', '.') ?></div>
        <div class="text-xs text-muted-foreground">Total nilai aset perusahaan tercatat</div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Jumlah aset tercatat</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= array_sum($stats_raw) ?> <span class="text-sm font-normal text-muted-foreground">unit</span></div>
        <div class="text-xs text-muted-foreground">Terdaftar dalam register aset</div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Status aktif</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums text-signal"><?= $active_assets ?> <span class="text-sm font-normal text-muted-foreground">unit</span></div>
        <div class="text-xs text-muted-foreground">Aset yang siap dipakai / aktif</div>
    </div>
    <div class="ui-card p-4">
        <div class="text-xs font-medium text-muted-foreground">Kondisi lainnya</div>
        <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= max(0, array_sum($stats_raw) - $active_assets) ?> <span class="text-sm font-normal text-muted-foreground">unit</span></div>
        <div class="text-xs text-muted-foreground">Dalam perbaikan / rusak / tidak aktif</div>
    </div>
</div>

<div class="view-toggle mb-5 flex w-fit flex-wrap gap-1 rounded-md bg-muted p-1">
    <button class="<?= ($_GET['view']??'table') == 'table' ? 'rounded-sm bg-card px-3 py-1.5 text-sm font-semibold text-foreground shadow-card border-0' : 'rounded-sm bg-transparent px-3 py-1.5 text-sm font-medium text-muted-foreground hover:text-foreground border-0' ?>" onclick="location.href='index.php?page=admin_assets&view=table'"<?= ($_GET['view']??'table') == 'table' ? ' aria-current="page"' : '' ?>>
        <i class="fas fa-table"></i> Daftar
    </button>
    <button class="<?= ($_GET['view']??'') == 'tree' ? 'rounded-sm bg-card px-3 py-1.5 text-sm font-semibold text-foreground shadow-card border-0' : 'rounded-sm bg-transparent px-3 py-1.5 text-sm font-medium text-muted-foreground hover:text-foreground border-0' ?>" onclick="location.href='index.php?page=admin_assets&view=tree'"<?= ($_GET['view']??'') == 'tree' ? ' aria-current="page"' : '' ?>>
        <i class="fas fa-network-wired"></i> Topologi
    </button>
</div>

<?php if(($_GET['view']??'table') === 'table'): ?>
<section class="ui-card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold">Aset</th>
                    <th class="px-4 py-2.5 font-semibold">Kategori</th>
                    <th class="px-4 py-2.5 text-right font-semibold">Nilai perolehan</th>
                    <th class="px-4 py-2.5 text-right font-semibold">Penyusutan</th>
                    <th class="px-4 py-2.5 text-right font-semibold">Nilai buku</th>
                    <th class="px-4 py-2.5 font-semibold">Status</th>
                    <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
                </tr>
            </thead>
            <tbody>
        <?php
        $assets = $db->query("SELECT a.*, p.name as parent_name FROM infrastructure_assets a LEFT JOIN infrastructure_assets p ON a.parent_id = p.id WHERE 1=1 $scope_where ORDER BY a.type DESC, a.name ASC")->fetchAll();
        foreach($assets as $a):
            $useful_match = [];
            preg_match('/Masa manfaat:\s*(\d+)/i', $a['description'] ?? '', $useful_match);
            $useful_life_years = isset($useful_match[1]) ? max(1, intval($useful_match[1])) : 5;
            $depreciation_per_year = $a['price'] > 0 ? ($a['price'] / $useful_life_years) : 0;
            $years_used = 0;
            if (!empty($a['installation_date'])) {
                $purchase_date = new DateTime($a['installation_date']);
                $today = new DateTime(date('Y-m-d'));
                $interval = $today->diff($purchase_date);
                $years_used = $interval->y + ($interval->m / 12) + ($interval->d / 365);
            }
            $depreciation_amount = min($a['price'], $depreciation_per_year * floor($years_used));
            $book_value = max(0, $a['price'] - $depreciation_amount);
        ?>
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3">
                        <div class="font-semibold text-foreground"><?= htmlspecialchars($a['name']) ?></div>
                        <div class="text-xs text-muted-foreground"><?= htmlspecialchars($a['description'] ? trim(strip_tags($a['description'])) : 'Keterangan belum diisi') ?></div>
                        <div class="text-xs text-muted-foreground"><?= htmlspecialchars($a['brand'] ?: 'Vendor belum diisi') ?></div>
                    </td>
                    <td class="px-4 py-3"><span class="ui-badge ui-badge-muted"><?= htmlspecialchars($a['type'] ?: 'Umum') ?></span></td>
                    <td class="px-4 py-3 text-right font-semibold tabular-nums">Rp <?= number_format($a['price'], 0, ',', '.') ?></td>
                    <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">Rp <?= number_format($depreciation_amount, 0, ',', '.') ?></td>
                    <td class="px-4 py-3 text-right tabular-nums">Rp <?= number_format($book_value, 0, ',', '.') ?></td>
                    <td class="px-4 py-3"><span class="ui-badge"><?= htmlspecialchars($a['status'] ?: '-') ?></span></td>
                    <td class="px-4 py-3 text-right">
                        <div class="compact-action-icons inline-flex flex-wrap justify-end gap-1">
                            <button class="ui-btn ui-btn-sm ui-btn-outline" onclick='editAsset(<?= json_encode($a) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                            <button class="ui-btn ui-btn-sm ui-btn-outline" onclick='showInvoiceModal(<?= json_encode($a) ?>)' title="Buat Nota / Cetak"><i class="fas fa-receipt"></i></button>
                            <a data-method="post" href="index.php?page=admin_assets&action=delete&id=<?= $a['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus" onclick="return confirm('Hapus aset ini?')"><i class="fas fa-trash"></i></a>
                        </div>
                    </td>
                </tr>
        <?php endforeach; ?>
        <?php if(empty($assets)): ?>
                <tr class="border-t border-solid border-border"><td colspan="7" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada aset terdaftar.</td></tr>
        <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php else: ?>
<!-- Network Topology Tree View -->
<div class="ui-card p-4 sm:p-5">
    <div class="network-tree-container">
        <?php
        $tree = buildNetworkTree($db, 0, $scope_where);

        if (!function_exists('getCustomersForAsset')) {
            function getCustomersForAsset($db, $assetId) {
                $tenant_id = $_SESSION['tenant_id'] ?? 1;
                $stmt = $db->prepare("SELECT name, customer_code FROM customers WHERE odp_id = ? AND tenant_id = ? ORDER BY name ASC");
                $stmt->execute([$assetId, $tenant_id]);
                return $stmt->fetchAll();
            }
        }

        if (!function_exists('renderTreeItem')) {
            function renderTreeItem($db, $item, $level = 0) {
            $usage_pct = ($item['total_ports'] > 0) ? ($item['total_active_downstream'] / $item['total_ports']) * 100 : 0;
            $color = 'var(--primary)';
            if($item['type'] == 'ODC') $color = '#a855f7';
            if($item['type'] == 'ODP') $color = '#ec4899';
            if($item['type'] == 'Router') $color = '#f59e0b';

            $icon = 'fa-server';
            if($item['type'] == 'ODC') $icon = 'fa-boxes-stacked';
            if($item['type'] == 'ODP') $icon = 'fa-plug-circle-bolt';
            if($item['type'] == 'Router') $icon = 'fa-router';

            echo '<div class="tree-item relative mb-3 border-l border-solid border-border pl-5" style="margin-left:' . ($level * 24) . 'px;">';
            if($level > 0) {
                echo '<div class="absolute left-0 top-8 h-px w-5 bg-border"></div>';
            }

            echo '<div class="ui-card flex flex-wrap items-center justify-between gap-4 px-4 py-3">';

            echo '<div class="min-w-0">';
            echo '<div class="flex flex-wrap items-center gap-2 text-sm font-bold text-foreground">' . htmlspecialchars($item['name']) . ' <span class="ui-badge ui-badge-muted">' . $item['type'] . '</span></div>';
            echo '<div class="mt-0.5 text-xs text-muted-foreground">' . $item['total_active_downstream'] . ' total jalur aktif</div>';
            echo '</div>';

            echo '<div class="flex items-center gap-5">';
            echo '<div class="w-[120px]">';
            echo '<div class="mb-1 flex justify-between text-[11px] text-muted-foreground">';
            echo '<span>Utilisasi port</span>';
            echo '<span class="font-semibold tabular-nums ' . ($usage_pct > 85 ? 'text-danger' : 'text-foreground') . '">' . round($usage_pct) . '%</span>';
            echo '</div>';
            echo '<div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">';
            echo '<div class="h-full ' . ($usage_pct > 85 ? 'bg-danger' : 'bg-signal') . '" style="width:' . $usage_pct . '%;"></div>';
            echo '</div>';
            echo '</div>';

            echo '<div class="flex gap-1">';
            if($item['lat'] && $item['lng']) {
                echo '<a href="index.php?page=admin_map&lat=' . $item['lat'] . '&lng=' . $item['lng'] . '" class="ui-btn ui-btn-sm ui-btn-outline" title="Lihat di Peta"><i class="fas fa-location-dot"></i></a>';
            }
            echo '<button class="ui-btn ui-btn-sm ui-btn-outline" title="Edit" onclick=\'editAsset(' . json_encode($item) . ')\'><i class="fas fa-edit"></i></button>';
            echo '</div>';
            echo '</div>';

            echo '</div>'; // end glass-panel

            // List Customers if it's an ODP or has customers
            $customers = getCustomersForAsset($db, $item['id']);
            if(!empty($customers)) {
                echo '<div class="mb-3 ml-4 rounded-b-lg border border-t-0 border-solid border-border bg-muted px-4 py-2 text-xs">';
                echo '<div class="mb-1 font-semibold text-muted-foreground">Pelanggan terhubung:</div>';
                foreach($customers as $c) {
                    echo '<div class="mr-4 inline-block text-foreground">' . htmlspecialchars($c['name']) . ' (' . $c['customer_code'] . ')</div>';
                }
                echo '</div>';
            }

            if(!empty($item['children'])) {
                foreach($item['children'] as $child) {
                    renderTreeItem($db, $child, $level + 1);
                }
            }
            echo '</div>'; // end tree-item
        }
    }

    foreach($tree as $root) renderTreeItem($db, $root);

        if(empty($tree)) {
            echo '<div class="px-5 py-10 text-center text-sm text-muted-foreground">';
            echo 'Belum ada infrastruktur terdaftar atau periksa filter Parent.';
            echo '</div>';
        }
        ?>
    </div>
</div>
<?php endif; ?>

<!-- Asset Modal -->
<div id="assetModal" class="modal fixed inset-0 z-[1001] overflow-y-auto bg-black/50 p-4 sm:p-6" style="display:none;">
    <div class="ui-card mx-auto w-full max-w-2xl p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 id="modalTitle" class="m-0 text-lg font-bold">Tambah aset baru</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="closeAssetModal()" aria-label="Tutup">✕</button>
        </div>
        <form method="POST" id="assetForm">
<?= csrf_field() ?>
            <input type="hidden" name="id" id="asset_id">
            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama aset</span>
                    <input type="text" name="name" id="asset_name" class="form-control" required placeholder="Contoh: Laptop Administrasi">
                    <span class="mt-1 block text-[11px] text-muted-foreground">Kategori dan kode akan dibuat otomatis berdasarkan nama aset.</span>
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Kode aset / nomor asset</span>
                        <input type="text" name="asset_code" id="asset_code" class="form-control" placeholder="Contoh: A-001">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Kategori</span>
                        <select name="type" id="asset_type" class="form-control" required>
                            <option value="Peralatan Kantor">Peralatan Kantor</option>
                            <option value="Komputer & IT">Komputer & IT</option>
                            <option value="Kendaraan">Kendaraan</option>
                            <option value="Furniture">Furniture</option>
                            <option value="Bangunan">Bangunan</option>
                            <option value="Lainnya">Lainnya</option>
                        </select>
                    </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Merk / vendor</span>
                        <input type="text" name="brand" id="asset_brand" class="form-control" placeholder="Contoh: Lenovo / PT. Mitra Sejahtera">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Nilai perolehan (Rp)</span>
                        <input type="number" name="price" id="asset_price" class="form-control" value="0">
                    </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal perolehan</span>
                        <input type="date" name="installation_date" id="asset_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Masa manfaat (tahun)</span>
                        <input type="number" name="useful_life_years" id="asset_useful_life" class="form-control" min="1" value="5">
                    </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Status / kondisi</span>
                        <select name="status" id="asset_status" class="form-control">
                            <option value="Aktif">Aktif</option>
                            <option value="Siap Pakai">Siap Pakai</option>
                            <option value="Perbaikan">Perbaikan</option>
                            <option value="Rusak">Rusak</option>
                            <option value="Dijual">Dijual</option>
                        </select>
                    </label>
                </div>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Aset induk (opsional)</span>
                    <select name="parent_id" id="asset_parent" class="form-control">
                        <option value="0">Tidak ada</option>
                        <?php
                        $parents = $db->query("SELECT a.id, a.name, a.type FROM infrastructure_assets a WHERE a.type != 'ODP' $scope_where ORDER BY a.type DESC")->fetchAll();
                        foreach($parents as $p) echo "<option value='{$p['id']}'>{$p['type']} - {$p['name']}</option>";
                        ?>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Keterangan / lokasi</span>
                    <textarea name="description" id="asset_description" class="form-control" rows="3" placeholder="Contoh: Ruang Administrasi, cabang Jakarta, catatan pemakaian"></textarea>
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Latitude (opsional)</span>
                        <input type="text" name="lat" id="asset_lat" class="form-control">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Longitude (opsional)</span>
                        <input type="text" name="lng" id="asset_lng" class="form-control">
                    </label>
                </div>
            </div>
            <div class="form-actions-row mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="closeAssetModal()">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary" id="saveBtn">Simpan aset</button>
            </div>
        </form>
    </div>
</div>

<!-- Invoice Modal -->
<div id="invoiceModal" class="modal fixed inset-0 z-[1002] overflow-y-auto bg-black/50 p-4 sm:p-6" style="display:none;">
    <div class="ui-card mx-auto w-full max-w-lg p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Buat nota penjualan aset</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="closeInvoiceModal()" aria-label="Tutup">✕</button>
        </div>
        <form method="POST" id="invoiceForm" action="index.php?page=admin_assets&action=invoice_create">
<?= csrf_field() ?>
            <input type="hidden" name="asset_id" id="inv_asset_id">
            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Pilih mitra / pelanggan (untuk menagih)</span>
                    <select name="customer_id" id="inv_customer" class="form-control" required>
                        <option value="">-- Pilih Mitra / Pelanggan --</option>
                        <?php
                            $tenant_id = $_SESSION['tenant_id'] ?? 1;
                            $partners = $db->query("SELECT id, name FROM customers WHERE type = 'partner' AND tenant_id = $tenant_id ORDER BY name ASC")->fetchAll();
                            foreach($partners as $p) echo "<option value='" . intval($p['id']) . "'>" . htmlspecialchars($p['name']) . "</option>";
                        ?>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Jumlah (Rp)</span>
                    <input type="number" name="amount" id="inv_amount" class="form-control" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Deskripsi / item</span>
                    <input type="text" name="description" id="inv_description" class="form-control" placeholder="Contoh: Pembelian Router XYZ">
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat penagihan (opsional)</span>
                    <input type="text" name="billing_address" id="inv_billing_address" class="form-control" placeholder="Alamat untuk dicantumkan di invoice">
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">No. HP / telepon</span>
                        <input type="text" name="billing_phone" id="inv_billing_phone" class="form-control" placeholder="Contoh: 08123456789">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Email</span>
                        <input type="email" name="billing_email" id="inv_billing_email" class="form-control" placeholder="email@example.com">
                    </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal jatuh tempo</span>
                        <input type="date" name="due_date" id="inv_due_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </label>
                    <label class="flex h-10 items-center gap-2 text-sm">
                        <input type="checkbox" name="mark_sold" id="inv_mark_sold" value="1" class="h-4 w-4"> Tandai terjual
                    </label>
                </div>
            </div>
            <div class="form-actions-row mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="closeInvoiceModal()">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Buat & cetak</button>
            </div>
        </form>
    </div>
</div>

<script>
function autoCategorizeAsset(name) {
    const text = (name || '').toLowerCase();
    let category = 'Lainnya';
    if (/laptop|komputer|pc|server|router|switch|monitor|printer|scanner|notebook|tablet|wifi|network|internet|access/.test(text)) {
        category = 'Komputer & IT';
    } else if (/mobil|motor|truck|kendaraan|vehicle/.test(text)) {
        category = 'Kendaraan';
    } else if (/meja|kursi|lemari|rak|sofa|furniture|kabinet/.test(text)) {
        category = 'Furniture';
    } else if (/gedung|bangunan|ruang|kantor|rumah|building/.test(text)) {
        category = 'Bangunan';
    } else if (/printer|fax|stapler|scanner|projector|alat tulis|office/.test(text)) {
        category = 'Peralatan Kantor';
    }
    const typeSelect = document.getElementById('asset_type');
    if (typeSelect) {
        typeSelect.value = category;
    }
}

function showAssetModal() {
    document.getElementById('assetForm').action = 'index.php?page=admin_assets&action=add';
    document.getElementById('modalTitle').innerText = 'Tambah Aset Baru';
    document.getElementById('asset_id').value = '';
    document.getElementById('assetForm').reset();
    document.getElementById('asset_type').value = 'Peralatan Kantor';
    document.getElementById('asset_status').value = 'Aktif';
    document.getElementById('asset_date').value = '<?= date('Y-m-d') ?>';
    document.getElementById('asset_useful_life').value = '5';
    document.getElementById('assetModal').style.display = 'block';
}
function closeAssetModal() {
    document.getElementById('assetModal').style.display = 'none';
}
 
function showInvoiceModal(asset) {
    try {
        document.getElementById('inv_asset_id').value = asset.id || '';
        document.getElementById('inv_amount').value = asset.price ? parseFloat(asset.price) : 0;
        document.getElementById('inv_description').value = 'Pembelian: ' + (asset.name || 'Perangkat');
        document.getElementById('inv_due_date').value = '<?= date('Y-m-d') ?>';
        document.getElementById('inv_mark_sold').checked = false;
        document.getElementById('invoiceModal').style.display = 'block';
    } catch(e) { console.error(e); alert('Gagal membuka modal invoice'); }
}

function closeInvoiceModal() {
    document.getElementById('invoiceModal').style.display = 'none';
}
document.getElementById('asset_name').addEventListener('input', function() {
    autoCategorizeAsset(this.value);
});

function editAsset(a) {
    const description = (a.description || '').toString();
    const codeMatch = description.match(/Kode:\s*([^|]+)/i);
    const usefulMatch = description.match(/Masa manfaat:\s*(\d+)/i);
    const cleanDescription = description
        .replace(/Kode:\s*[^|]+/i, '')
        .replace(/Masa manfaat:\s*\d+\s*tahun/i, '')
        .replace(/^\s*\|\s*/, '')
        .replace(/\|\s*$/g, '')
        .trim();

    document.getElementById('assetForm').action = 'index.php?page=admin_assets&action=edit';
    document.getElementById('modalTitle').innerText = 'Edit Aset';
    document.getElementById('asset_id').value = a.id;
    document.getElementById('asset_name').value = a.name || '';
    document.getElementById('asset_code').value = codeMatch ? codeMatch[1].trim() : '';
    document.getElementById('asset_type').value = a.type || 'Peralatan Kantor';
    document.getElementById('asset_parent').value = a.parent_id || 0;
    document.getElementById('asset_brand').value = a.brand || '';
    document.getElementById('asset_lat').value = a.lat || '';
    document.getElementById('asset_lng').value = a.lng || '';
    document.getElementById('asset_price').value = a.price || 0;
    document.getElementById('asset_status').value = a.status || 'Aktif';
    document.getElementById('asset_date').value = a.installation_date || '<?= date('Y-m-d') ?>';
    document.getElementById('asset_useful_life').value = usefulMatch ? usefulMatch[1] : '5';
    document.getElementById('asset_description').value = cleanDescription;
    document.getElementById('assetModal').style.display = 'block';
}
</script>
