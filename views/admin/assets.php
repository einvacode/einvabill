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
            $id = $_POST['id'];
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
                    if ($amount <= 0) $amount = floatval($customer['monthly_fee'] ?? 0);
                    if (empty($_POST['item_desc'])) {
                        $_POST['item_desc'] = [trim($customer['package_name'] ?? 'Tagihan Layanan') ?: 'Tagihan Layanan'];
                        $_POST['item_qty'] = [1];
                        $_POST['item_unit'] = [$amount > 0 ? $amount : floatval($customer['monthly_fee'] ?? 0)];
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
    $id = $_GET['id'];
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

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:30px;">
    <div class="glass-panel" style="padding:20px; border-left:4px solid var(--primary); display:flex; flex-direction:column; justify-content:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Total Nilai Perolehan</div>
        <div style="font-size:24px; font-weight:800; color:var(--text-primary);">Rp <?= number_format($total_investment, 0, ',', '.') ?></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Total nilai aset perusahaan tercatat</div>
    </div>
    <div class="glass-panel" style="padding:20px; border-left:4px solid var(--success); display:flex; flex-direction:column; justify-content:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Jumlah Aset Tercatat</div>
        <div style="font-size:24px; font-weight:800; color:var(--success);"><?= array_sum($stats_raw) ?> <span style="font-size:14px;">Unit</span></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Terdaftar dalam register aset</div>
    </div>
    <div class="glass-panel" style="padding:24px; border-left:4px solid #f59e0b; display:flex; flex-direction:column; justify-content:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Status Aktif</div>
        <div style="font-size:24px; font-weight:800; color:#f59e0b;"><?= $active_assets ?> <span style="font-size:14px; color:var(--text-secondary); font-weight:normal;">Unit</span></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Aset yang siap dipakai / aktif</div>
    </div>
    <div class="glass-panel" style="padding:20px; border-left:4px solid #a855f7; display:flex; flex-direction:column; justify-content:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Kondisi Lainnya</div>
        <div style="font-size:24px; font-weight:800; color:#a855f7;"><?= max(0, array_sum($stats_raw) - $active_assets) ?> <span style="font-size:14px; color:var(--text-secondary); font-weight:normal;">Unit</span></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Dalam perbaikan / rusak / tidak aktif</div>
    </div>
</div>

<div class="glass-panel" style="padding:25px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap:wrap; gap:15px;">
        <h3 style="margin:0;"><i class="fas fa-boxes text-primary"></i> Register Aset Perusahaan</h3>
        <div class="compact-toolbar" style="display:flex; gap:10px;">
            <div class="view-toggle" style="background:rgba(255,255,255,0.05); padding:4px; border-radius:10px; display:flex;">
                <button class="btn btn-sm <?= ($_GET['view']??'table') == 'table' ? 'btn-primary' : 'btn-ghost' ?>" onclick="location.href='index.php?page=admin_assets&view=table'">
                    <i class="fas fa-table"></i> Daftar
                </button>
                <button class="btn btn-sm <?= ($_GET['view']??'') == 'tree' ? 'btn-primary' : 'btn-ghost' ?>" onclick="location.href='index.php?page=admin_assets&view=tree'">
                    <i class="fas fa-network-wired"></i> Topologi
                </button>
            </div>
            <button class="btn btn-ghost" onclick="window.open('index.php?page=admin_assets&action=print','_blank')"><i class="fas fa-print"></i> Export / Cetak</button>
            <button class="btn btn-primary" onclick="showAssetModal()"><i class="fas fa-plus"></i> Tambah Aset</button>
        </div>
    </div>

    <?php if(($_GET['view']??'table') === 'table'): ?>
    <div style="max-height:520px; overflow-y:auto; padding-right:6px; display:grid; gap:12px;">
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
        <div class="glass-panel" style="padding:16px 18px; border-left:4px solid var(--primary); display:grid; grid-template-columns: 1.6fr 1fr auto; gap:16px; align-items:center; border-radius:14px;">
            <div>
                <div style="font-weight:800; font-size:16px; margin-bottom:6px;"><?= htmlspecialchars($a['name']) ?></div>
                <div style="font-size:12px; color:var(--text-secondary); margin-bottom:6px; line-height:1.5;"><?= htmlspecialchars($a['description'] ? trim(strip_tags($a['description'])) : 'Keterangan belum diisi') ?></div>
                <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:8px;">
                    <span class="badge" style="background:var(--primary); color:white;"><?= htmlspecialchars($a['type'] ?: 'Umum') ?></span>
                    <span class="badge" style="background:rgba(255,255,255,0.08); color:var(--text-primary);"><?= htmlspecialchars($a['brand'] ?: 'Vendor belum diisi') ?></span>
                </div>
            </div>
            <div>
                <div style="font-weight:700; color:var(--success); margin-bottom:4px;">Rp <?= number_format($a['price'], 0, ',', '.') ?></div>
                <div style="font-size:12px; color:#f59e0b; margin-bottom:2px;">Penyusutan: Rp <?= number_format($depreciation_amount, 0, ',', '.') ?></div>
                <div style="font-size:12px; color:var(--text-secondary);">Nilai buku: Rp <?= number_format($book_value, 0, ',', '.') ?></div>
                <div style="font-size:12px; color:var(--text-secondary); margin-top:6px; display:inline-block; padding:4px 8px; border-radius:999px; background:rgba(255,255,255,0.06);">Status: <?= htmlspecialchars($a['status'] ?: '-') ?></div>
            </div>
            <div class="compact-action-icons" style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
                <button class="btn btn-sm btn-warning" onclick='editAsset(<?= json_encode($a) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-sm btn-primary" onclick='showInvoiceModal(<?= json_encode($a) ?>)' title="Buat Nota / Cetak"><i class="fas fa-receipt"></i></button>
                <a href="index.php?page=admin_assets&action=delete&id=<?= $a['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus aset ini?')"><i class="fas fa-trash"></i></a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($assets)): ?>
            <div style="text-align:center; padding:50px; color:var(--text-secondary);"><i class="fas fa-info-circle"></i> Belum ada aset terdaftar.</div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <!-- Network Topology Tree View -->
    <div class="network-tree-container" style="padding:10px 0;">
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

            echo '<div class="tree-item" style="margin-left:' . ($level * 35) . 'px; border-left: 2px solid rgba(255,255,255,0.05); padding-left: 25px; position:relative; margin-bottom:15px;">';
            if($level > 0) {
                echo '<div style="position:absolute; left:0; top:35px; width:25px; height:2px; background:rgba(255,255,255,0.05);"></div>';
            }
            
            echo '<div class="glass-panel" style="padding:15px 20px; display:flex; justify-content:space-between; align-items:center; border-left:4px solid ' . $color . '; min-height:80px; transition:all 0.2s;">';
            
            echo '<div style="display:flex; align-items:center; gap:20px;">';
            echo '<div style="width:48px; height:48px; background:' . $color . '15; color:' . $color . '; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:20px;"><i class="fas ' . $icon . '"></i></div>';
            echo '<div>';
            echo '<div style="font-weight:700; font-size:16px; color:var(--text-primary);">' . htmlspecialchars($item['name']) . ' <span style="font-size:11px; opacity:0.5; font-weight:normal; margin-left:8px; text-transform:uppercase;">' . $item['type'] . '</span></div>';
            echo '<div style="font-size:12px; color:var(--text-secondary); margin-top:4px;"><i class="fas fa-network-wired" style="font-size:10px; margin-right:5px;"></i> ' . $item['total_active_downstream'] . ' Total Jalur Aktif</div>';
            echo '</div>';
            echo '</div>';
            
            echo '<div style="display:flex; align-items:center; gap:25px;">';
            echo '<div style="text-align:right; width:120px;">';
            echo '<div style="display:flex; justify-content:space-between; font-size:10px; color:var(--text-secondary); margin-bottom:6px;">';
            echo '<span>Utilisasi Port</span>';
            echo '<span style="font-weight:800; color:' . ($usage_pct > 85 ? 'var(--danger)' : 'var(--text-primary)') . '">' . round($usage_pct) . '%</span>';
            echo '</div>';
            echo '<div style="width:100%; height:8px; background:rgba(255,255,255,0.05); border-radius:4px; overflow:hidden;">';
            echo '<div style="width:' . $usage_pct . '%; height:100%; background:' . ($usage_pct > 85 ? 'var(--danger)' : 'var(--success)') . '; box-shadow: 0 0 10px ' . ($usage_pct > 85 ? 'var(--danger)' : 'var(--success)') . '44;"></div>';
            echo '</div>';
            echo '</div>';
            
            echo '<div style="display:flex; gap:8px;">';
            if($item['lat'] && $item['lng']) {
                echo '<a href="index.php?page=admin_map&lat=' . $item['lat'] . '&lng=' . $item['lng'] . '" class="btn btn-sm btn-ghost" title="Lihat di Peta" style="color:#06b6d4;"><i class="fas fa-location-dot"></i></a>';
            }
            echo '<button class="btn btn-sm btn-ghost" style="color:var(--text-secondary);" onclick=\'editAsset(' . json_encode($item) . ')\'><i class="fas fa-edit"></i></button>';
            echo '</div>';
            echo '</div>';
            
            echo '</div>'; // end glass-panel

            // List Customers if it's an ODP or has customers
            $customers = getCustomersForAsset($db, $item['id']);
            if(!empty($customers)) {
                echo '<div style="margin-left: 68px; margin-top: -10px; margin-bottom: 20px; font-size: 11px; padding: 10px 15px; background: rgba(255,255,255,0.03); border-radius: 0 0 12px 12px; border: 1px solid rgba(255,255,255,0.05); border-top:none;">';
                echo '<div style="color:var(--text-secondary); margin-bottom:5px; font-weight:700;"><i class="fas fa-users-viewfinder"></i> PELANGGAN TERHUBUNG:</div>';
                foreach($customers as $c) {
                    echo '<div style="display:inline-block; margin-right:15px; color:var(--text-primary);"><i class="fas fa-user" style="font-size:9px; opacity:0.5;"></i> ' . htmlspecialchars($c['name']) . ' (' . $c['customer_code'] . ')</div>';
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
            echo '<div style="text-align:center; padding:80px; color:var(--text-secondary); opacity:0.6;">';
            echo '<i class="fas fa-network-wired" style="font-size:60px; margin-bottom:20px; display:block; opacity:0.1;"></i> Belum ada infrastruktur terdaftar atau periksa filter Parent.';
            echo '</div>';
        }
        ?>
    </div>
    <?php endif; ?>
</div>

<!-- Asset Modal -->
<div id="assetModal" class="modal" style="display:none; position:fixed; z-index:1001; left:0; top:0; width:100%; height:100%; overflow-y:auto; padding:24px 0; background:rgba(0,0,0,0.8); backdrop-filter:blur(10px);">
    <div class="glass-panel" style="width:90%; max-width:620px; margin:0 auto; padding:30px; max-height:calc(100vh - 48px); overflow-y:auto;">
        <h3 id="modalTitle" style="margin-bottom:20px;">Tambah Aset Baru</h3>
        <form method="POST" id="assetForm" style="display:flex; flex-direction:column; gap:0;">
            <input type="hidden" name="id" id="asset_id">
            <div style="max-height:calc(100vh - 220px); overflow-y:auto; padding-right:6px;">
            <div class="form-group">
                <label>Nama Aset</label>
                <input type="text" name="name" id="asset_name" class="form-control" required placeholder="Contoh: Laptop Administrasi">
                <div style="font-size:11px; color:var(--text-secondary); margin-top:4px;">Kategori dan kode akan dibuat otomatis berdasarkan nama aset.</div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Kode Aset / Nomor Asset</label>
                    <input type="text" name="asset_code" id="asset_code" class="form-control" placeholder="Contoh: A-001">
                </div>
                <div class="form-group">
                    <label>Kategori</label>
                    <select name="type" id="asset_type" class="form-control" required>
                        <option value="Peralatan Kantor">Peralatan Kantor</option>
                        <option value="Komputer & IT">Komputer & IT</option>
                        <option value="Kendaraan">Kendaraan</option>
                        <option value="Furniture">Furniture</option>
                        <option value="Bangunan">Bangunan</option>
                        <option value="Lainnya">Lainnya</option>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Merk / Vendor</label>
                    <input type="text" name="brand" id="asset_brand" class="form-control" placeholder="Contoh: Lenovo / PT. Mitra Sejahtera">
                </div>
                <div class="form-group">
                    <label>Nilai Perolehan (Rp)</label>
                    <input type="number" name="price" id="asset_price" class="form-control" value="0">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Tanggal Perolehan</label>
                    <input type="date" name="installation_date" id="asset_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Masa Manfaat (tahun)</label>
                    <input type="number" name="useful_life_years" id="asset_useful_life" class="form-control" min="1" value="5">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Status / Kondisi</label>
                    <select name="status" id="asset_status" class="form-control">
                        <option value="Aktif">Aktif</option>
                        <option value="Siap Pakai">Siap Pakai</option>
                        <option value="Perbaikan">Perbaikan</option>
                        <option value="Rusak">Rusak</option>
                        <option value="Dijual">Dijual</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Aset Induk (opsional)</label>
                <select name="parent_id" id="asset_parent" class="form-control">
                    <option value="0">Tidak ada</option>
                    <?php 
                    $parents = $db->query("SELECT a.id, a.name, a.type FROM infrastructure_assets a WHERE a.type != 'ODP' $scope_where ORDER BY a.type DESC")->fetchAll();
                    foreach($parents as $p) echo "<option value='{$p['id']}'>{$p['type']} - {$p['name']}</option>";
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label>Keterangan / Lokasi</label>
                <textarea name="description" id="asset_description" class="form-control" rows="3" placeholder="Contoh: Ruang Administrasi, cabang Jakarta, catatan pemakaian"></textarea>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Latitude (opsional)</label>
                    <input type="text" name="lat" id="asset_lat" class="form-control">
                </div>
                <div class="form-group">
                    <label>Longitude (opsional)</label>
                    <input type="text" name="lng" id="asset_lng" class="form-control">
                </div>
            </div>
            </div>
            <div class="form-actions-row" style="margin-top:20px; position:sticky; bottom:0; background:linear-gradient(180deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.08) 35%, rgba(255,255,255,0.12) 100%); backdrop-filter:blur(8px); padding-top:14px;">
                <button type="button" class="btn btn-ghost" onclick="closeAssetModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="saveBtn">Simpan Aset</button>
            </div>
        </form>
    </div>
</div>

<!-- Invoice Modal -->
<div id="invoiceModal" class="modal" style="display:none; position:fixed; z-index:1002; left:0; top:0; width:100%; height:100%; overflow-y:auto; padding:24px 0; background:rgba(0,0,0,0.8); backdrop-filter:blur(10px);">
    <div class="glass-panel" style="width:90%; max-width:520px; margin:0 auto; padding:20px; max-height:calc(100vh - 48px); overflow-y:auto;">
        <h3 style="margin-bottom:10px;"><i class="fas fa-receipt"></i> Buat Nota Penjualan Aset</h3>
        <form method="POST" id="invoiceForm" action="index.php?page=admin_assets&action=invoice_create">
            <input type="hidden" name="asset_id" id="inv_asset_id">
            <div class="form-group">
                <label>Pilih Mitra / Pelanggan (Untuk menagih)</label>
                <select name="customer_id" id="inv_customer" class="form-control" required>
                    <option value="">-- Pilih Mitra / Pelanggan --</option>
                    <?php
                        $tenant_id = $_SESSION['tenant_id'] ?? 1;
                        $partners = $db->query("SELECT id, name FROM customers WHERE type = 'partner' AND tenant_id = $tenant_id ORDER BY name ASC")->fetchAll();
                        foreach($partners as $p) echo "<option value='" . intval($p['id']) . "'>" . htmlspecialchars($p['name']) . "</option>";
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label>Jumlah (Rp)</label>
                <input type="number" name="amount" id="inv_amount" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Deskripsi / Item</label>
                <input type="text" name="description" id="inv_description" class="form-control" placeholder="Contoh: Pembelian Router XYZ">
            </div>
            <div class="form-group">
                <label>Alamat Penagihan (opsional)</label>
                <input type="text" name="billing_address" id="inv_billing_address" class="form-control" placeholder="Alamat untuk dicantumkan di invoice">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div class="form-group">
                    <label>No. HP / Telepon</label>
                    <input type="text" name="billing_phone" id="inv_billing_phone" class="form-control" placeholder="Contoh: 08123456789">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="billing_email" id="inv_billing_email" class="form-control" placeholder="email@example.com">
                </div>
            </div>
            <div style="display:flex; gap:10px;">
                <div class="form-group" style="flex:1;">
                    <label>Tanggal Jatuh Tempo</label>
                    <input type="date" name="due_date" id="inv_due_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group" style="width:140px; display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-size:13px;">Tandai Terjual</label>
                    <input type="checkbox" name="mark_sold" id="inv_mark_sold" value="1" style="width:20px; height:20px;">
                </div>
            </div>
            <div class="form-actions-row" style="margin-top:16px;">
                <button type="button" class="btn btn-ghost" onclick="closeInvoiceModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Buat & Cetak</button>
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
