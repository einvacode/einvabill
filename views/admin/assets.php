<?php
// Handle Asset Actions
$action = $_GET['action'] ?? 'list';
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'admin';
$scope_where = ($u_role === 'admin') ? " AND (a.created_by = $u_id OR a.created_by = 0 OR a.created_by IS NULL) " : " AND (a.created_by = $u_id) ";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add' || $action === 'edit') {
        $name = $_POST['name'];
        $type = $_POST['type'];
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
            $stmt = $db->prepare("INSERT INTO infrastructure_assets (name, type, parent_id, lat, lng, total_ports, brand, description, price, status, installation_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $type, $parent_id, $lat, $lng, $total_ports, $brand, $description, $price, $status, $installation_date, $u_id]);
            $success = "Aset berhasil ditambahkan.";
        } else {
            $id = $_POST['id'];
            // Ownership Check
            $check = $db->query("SELECT created_by FROM infrastructure_assets WHERE id = $id")->fetchColumn();
            $is_owner = ($u_role === 'admin') ? ($check == $u_id || $check == 0 || $check === NULL) : ($check == $u_id);
            if ($is_owner) {
                $stmt = $db->prepare("UPDATE infrastructure_assets SET name=?, type=?, parent_id=?, lat=?, lng=?, total_ports=?, brand=?, description=?, price=?, status=?, installation_date=? WHERE id=?");
                $stmt->execute([$name, $type, $parent_id, $lat, $lng, $total_ports, $brand, $description, $price, $status, $installation_date, $id]);
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
                    // Create a temporary customer record but do NOT attribute it to the current user/partner
                    // to avoid it appearing in partner/mitra lists. Use created_by = 0 (system).
                    $stmt_c = $db->prepare("INSERT INTO customers (customer_code, name, address, contact, type, created_by, registration_date) VALUES (?, ?, ?, ?, 'note', ?, datetime('now'))");
                    $cust_code = null;
                    $stmt_c->execute([$cust_code, $recipient_name, $billing_address, $billing_phone, 0]);
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

            if ($has_extra_cols) {
                if ($has_created_via) {
                    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at, status, discount, billing_address, billing_phone, billing_email, issued_by_id, issued_by_name, created_via) VALUES (?, ?, ?, ?, 'Belum Lunas', 0, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$customer_id, $amount, $due_date, $created_at, $billing_address, $billing_phone, $billing_email, $issued_by_id, $issued_by_name, ($_POST['created_via'] ?? '')]);
                } else {
                    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at, status, discount, billing_address, billing_phone, billing_email, issued_by_id, issued_by_name) VALUES (?, ?, ?, ?, 'Belum Lunas', 0, ?, ?, ?, ?, ?)");
                    $stmt->execute([$customer_id, $amount, $due_date, $created_at, $billing_address, $billing_phone, $billing_email, $issued_by_id, $issued_by_name]);
                }
            } else {
                // Fallback to legacy insert (DB without new columns)
                if ($has_created_via) {
                    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at, status, discount, created_via) VALUES (?, ?, ?, ?, 'Belum Lunas', 0, ?)");
                    $stmt->execute([$customer_id, $amount, $due_date, $created_at, ($_POST['created_via'] ?? '')]);
                } else {
                    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at, status, discount) VALUES (?, ?, ?, ?, 'Belum Lunas', 0)");
                    $stmt->execute([$customer_id, $amount, $due_date, $created_at]);
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
    // Ownership Check
    $check = $db->query("SELECT created_by FROM infrastructure_assets WHERE id = $id")->fetchColumn();
    $is_owner = ($u_role === 'admin') ? ($check == $u_id || $check == 0 || $check === NULL) : ($check == $u_id);
    
    if ($is_owner) {
        $db->prepare("DELETE FROM infrastructure_assets WHERE id = ?")->execute([$id]);
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
            $payment_date = date('Y-m-d H:i:s');
            try {
                $db->prepare("UPDATE invoices SET status = 'Lunas' WHERE id = ?")->execute([$id]);
                $db->prepare("INSERT INTO payments (invoice_id, amount, received_by, payment_date) VALUES (?, ?, ?, ?)")->execute([$id, $net_amount, $receiver_id, $payment_date]);
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
            $db->prepare("DELETE FROM payments WHERE invoice_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM invoices WHERE id = ?")->execute([$id]);
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
$used_by_customers = $db->query("SELECT COUNT(*) FROM customers c WHERE odp_id > 0 AND (SELECT created_by FROM infrastructure_assets WHERE id = c.odp_id) = " . ($u_role === 'admin' ? "0" : $u_id))->fetchColumn() ?: 0;
$used_by_child_assets = $db->query("SELECT COUNT(*) FROM infrastructure_assets a WHERE parent_id > 0 $scope_where")->fetchColumn() ?: 0;
$total_ports_used = $used_by_customers + $used_by_child_assets;
$idle_ports = $total_ports_capacity - $total_ports_used;
$utilization_pct = ($total_ports_capacity > 0) ? ($total_ports_used / $total_ports_capacity) * 100 : 0;
?>

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:30px;">
    <div class="glass-panel" style="padding:20px; border-left:4px solid var(--primary); display:flex; flex-direction:column; justify-content:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Total Valuasi Aset</div>
        <div style="font-size:24px; font-weight:800; color:var(--text-primary);">Rp <?= number_format($total_investment, 0, ',', '.') ?></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Total belanja infrastruktur</div>
    </div>
    <div class="glass-panel" style="padding:20px; border-left:4px solid var(--success); display:flex; flex-direction:column; justify-content:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Utilisasi Port Global</div>
        <div style="font-size:24px; font-weight:800; color:var(--success);"><?= round($utilization_pct, 1) ?><span style="font-size:14px;">%</span></div>
        <div style="width:100%; height:4px; background:rgba(255,255,255,0.05); border-radius:10px; margin-top:10px; overflow:hidden;">
            <div style="width:<?= $utilization_pct ?>%; height:100%; background:var(--success);"></div>
        </div>
    </div>
    <div class="glass-panel" style="padding:24px; border-left:4px solid #f59e0b; display:flex; flex-direction:column; justify-content:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Kapasitas PORT Idle</div>
        <div style="font-size:24px; font-weight:800; color:#f59e0b;"><?= $idle_ports ?> <span style="font-size:14px; color:var(--text-secondary); font-weight:normal;">Port</span></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">Siap digunakan pelanggan baru</div>
    </div>
    <div class="glass-panel" style="padding:20px; border-left:4px solid #a855f7; display:flex; flex-direction:column; justify-content:center;">
        <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Total Perangkat</div>
        <div style="font-size:24px; font-weight:800; color:#a855f7;"><?= array_sum($stats_raw) ?> <span style="font-size:14px; color:var(--text-secondary); font-weight:normal;">Unit</span></div>
        <div style="font-size:11px; color:var(--text-secondary); margin-top:5px;">OLT, ODC, ODP & Lainnya</div>
    </div>
</div>

<div class="glass-panel" style="padding:25px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; flex-wrap:wrap; gap:15px;">
        <h3 style="margin:0;"><i class="fas fa-boxes text-primary"></i> Operasi Infrastruktur Jaringan</h3>
        <div style="display:flex; gap:10px;">
            <div class="view-toggle" style="background:rgba(255,255,255,0.05); padding:4px; border-radius:10px; display:flex;">
                <button class="btn btn-sm <?= ($_GET['view']??'table') == 'table' ? 'btn-primary' : 'btn-ghost' ?>" onclick="location.href='index.php?page=admin_assets&view=table'">
                    <i class="fas fa-table"></i> Daftar
                </button>
                <button class="btn btn-sm <?= ($_GET['view']??'') == 'tree' ? 'btn-primary' : 'btn-ghost' ?>" onclick="location.href='index.php?page=admin_assets&view=tree'">
                    <i class="fas fa-network-wired"></i> Topologi
                </button>
            </div>
            <button class="btn btn-primary" onclick="showAssetModal()"><i class="fas fa-plus"></i> Tambah Aset</button>
        </div>
    </div>

    <?php if(($_GET['view']??'table') === 'table'): ?>
    <div class="table-container shadow-sm">
        <table>
            <thead>
                <tr>
                    <th>Nama Aset</th>
                    <th>Tipe / Status</th>
                    <th>Induk (Uplink)</th>
                    <th>Kapasitas</th>
                    <th>Nilai Aset (Rp)</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $assets = $db->query("SELECT a.*, p.name as parent_name FROM infrastructure_assets a LEFT JOIN infrastructure_assets p ON a.parent_id = p.id WHERE 1=1 $scope_where ORDER BY a.type DESC, a.name ASC")->fetchAll();
                foreach($assets as $a):
                    // Hitung Penggunaan Port Fisik (Hanya 1 Tingkat / Direct)
                    $usage_cust = $db->prepare("SELECT COUNT(*) FROM customers WHERE odp_id = ?");
                    $usage_cust->execute([$a['id']]);
                    $direct_cust_count = $usage_cust->fetchColumn();
                    
                    $usage_child = $db->prepare("SELECT COUNT(*) FROM infrastructure_assets WHERE parent_id = ?");
                    $usage_child->execute([$a['id']]);
                    $direct_asset_count = $usage_child->fetchColumn();
                    
                    $current_usage = $direct_cust_count + $direct_asset_count;
                    $usage_pct = ($a['total_ports'] > 0) ? ($current_usage / $a['total_ports']) * 100 : 0;
                ?>
                <tr>
                    <td>
                        <div style="font-weight:700;"><?= htmlspecialchars($a['name']) ?></div>
                        <div style="font-size:11px; color:var(--text-secondary);"><?= htmlspecialchars($a['brand'] ?: 'Generic') ?></div>
                    </td>
                    <td>
                        <?php 
                        $bgColor = 'var(--primary)';
                        if($a['type'] == 'ODC') $bgColor = '#a855f7';
                        if($a['type'] == 'ODP') $bgColor = '#ec4899';
                        if($a['type'] == 'Router') $bgColor = '#f59e0b';
                        if($a['type'] == 'Switch') $bgColor = '#6366f1';
                        if($a['type'] == 'Wireless') $bgColor = '#06b6d4';
                        if($a['type'] == 'Server') $bgColor = '#4b5563';
                        if($a['type'] == 'ONU') $bgColor = '#10b981';
                        ?>
                        <span class="badge" style="background:<?= $bgColor ?>; color:white;">
                            <?= $a['type'] ?>
                        </span>
                    </td>
                    <td style="font-size:13px;"><?= htmlspecialchars($a['parent_name'] ?: 'ROOT') ?></td>
                    <td>
                        <div style="width:100px; height:8px; background:rgba(255,255,255,0.1); border-radius:4px; margin-bottom:5px; overflow:hidden;">
                            <div style="width:<?= $usage_pct ?>%; height:100%; background:<?= $usage_pct > 90 ? 'var(--danger)' : 'var(--success)' ?>;"></div>
                        </div>
                        <div style="font-size:11px; font-weight:700;">Penggunaan: <?= $current_usage ?> / <?= $a['total_ports'] ?> Port</div>
                        <div style="font-size:10px; color:var(--text-secondary); margin-top:2px;">
                            <i class="fas fa-link"></i> <?= $direct_asset_count ?> Cabang, <?= $direct_cust_count ?> Pelanggan
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:700; color:var(--success);">Rp <?= number_format($a['price'], 0, ',', '.') ?></div>
                        <div style="font-size:10px; color:var(--text-secondary);"><?= $a['installation_date'] ? 'Pasang: '.$a['installation_date'] : '-' ?></div>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-warning" onclick='editAsset(<?= json_encode($a) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-sm btn-primary" onclick='showInvoiceModal(<?= json_encode($a) ?>)' title="Buat Nota / Cetak"><i class="fas fa-receipt"></i></button>
                        <a href="index.php?page=admin_assets&action=delete&id=<?= $a['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus aset ini?')"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($assets)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:50px; color:var(--text-secondary);"><i class="fas fa-info-circle"></i> Belum ada aset terdaftar.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <!-- Network Topology Tree View -->
    <div class="network-tree-container" style="padding:10px 0;">
        <?php
        $tree = buildNetworkTree($db, 0, $scope_where);

        if (!function_exists('getCustomersForAsset')) {
            function getCustomersForAsset($db, $assetId) {
                $stmt = $db->prepare("SELECT name, customer_code FROM customers WHERE odp_id = ? ORDER BY name ASC");
                $stmt->execute([$assetId]);
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
<div id="assetModal" class="modal" style="display:none; position:fixed; z-index:1001; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.8); backdrop-filter:blur(10px);">
    <div class="glass-panel" style="width:90%; max-width:500px; margin:5% auto; padding:30px;">
        <h3 id="modalTitle" style="margin-bottom:20px;">Tambah Aset Baru</h3>
        <form method="POST" id="assetForm">
            <input type="hidden" name="id" id="asset_id">
            <div class="form-group">
                <label>Nama Perangkat (Contoh: ODP-JL-MAWAR-01)</label>
                <input type="text" name="name" id="asset_name" class="form-control" required>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Tipe</label>
                    <select name="type" id="asset_type" class="form-control" required>
                        <option value="OLT">OLT (Pusat)</option>
                        <option value="ODC">ODC (Cabinet)</option>
                        <option value="ODP">ODP (Pelanggan)</option>
                        <option value="Router">Router (MikroTik/Lainnya)</option>
                        <option value="Switch">Switch (L2/L3)</option>
                        <option value="Wireless">Wireless (AP/Radio)</option>
                        <option value="Server">Server</option>
                        <option value="ONU">ONU (Modem Pelanggan)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Uplink (Parent)</label>
                    <select name="parent_id" id="asset_parent" class="form-control">
                        <option value="0">TIDAK ADA / ROOT</option>
                        <?php 
                        $parents = $db->query("SELECT a.id, a.name, a.type FROM infrastructure_assets a WHERE a.type != 'ODP' $scope_where ORDER BY a.type DESC")->fetchAll();
                        foreach($parents as $p) echo "<option value='{$p['id']}'>{$p['type']} - {$p['name']}</option>";
                        ?>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Total Port</label>
                    <input type="number" name="total_ports" id="asset_ports" class="form-control" value="8">
                </div>
                <div class="form-group">
                    <label>Brand/Merk</label>
                    <input type="text" name="brand" id="asset_brand" class="form-control" placeholder="ZTE / Huawei">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Latitude</label>
                    <input type="text" name="lat" id="asset_lat" class="form-control">
                </div>
                <div class="form-group">
                    <label>Longitude</label>
                    <input type="text" name="lng" id="asset_lng" class="form-control">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="form-group">
                    <label>Harga Beli (Rp)</label>
                    <input type="number" name="price" id="asset_price" class="form-control" value="0">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="asset_status" class="form-control">
                        <option value="Deployed">Terpasang (Deployed)</option>
                        <option value="Stock">Gudang (Stock)</option>
                        <option value="Repair">Rusak (Repair)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Tanggal Pemasangan</label>
                <input type="date" name="installation_date" id="asset_date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-ghost" onclick="closeAssetModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="saveBtn">Simpan Aset</button>
            </div>
        </form>
    </div>
</div>

<!-- Invoice Modal -->
<div id="invoiceModal" class="modal" style="display:none; position:fixed; z-index:1002; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.8); backdrop-filter:blur(10px);">
    <div class="glass-panel" style="width:90%; max-width:520px; margin:5% auto; padding:20px;">
        <h3 style="margin-bottom:10px;"><i class="fas fa-receipt"></i> Buat Nota Penjualan Aset</h3>
        <form method="POST" id="invoiceForm" action="index.php?page=admin_assets&action=invoice_create">
            <input type="hidden" name="asset_id" id="inv_asset_id">
            <div class="form-group">
                <label>Pilih Mitra / Pelanggan (Untuk menagih)</label>
                <select name="customer_id" id="inv_customer" class="form-control" required>
                    <option value="">-- Pilih Mitra / Pelanggan --</option>
                    <?php
                        $partners = $db->query("SELECT id, name FROM customers WHERE type = 'partner' ORDER BY name ASC")->fetchAll();
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
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px;">
                <button type="button" class="btn btn-ghost" onclick="closeInvoiceModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Buat & Cetak</button>
            </div>
        </form>
    </div>
</div>

<script>
function showAssetModal() {
// ...
// ...
    document.getElementById('assetForm').action = 'index.php?page=admin_assets&action=add';
    document.getElementById('modalTitle').innerText = 'Tambah Aset Baru';
    document.getElementById('asset_id').value = '';
    document.getElementById('assetForm').reset();
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
function editAsset(a) {
    document.getElementById('assetForm').action = 'index.php?page=admin_assets&action=edit';
    document.getElementById('modalTitle').innerText = 'Edit Aset';
    document.getElementById('asset_id').value = a.id;
    document.getElementById('asset_name').value = a.name;
    document.getElementById('asset_type').value = a.type;
    document.getElementById('asset_parent').value = a.parent_id;
    document.getElementById('asset_ports').value = a.total_ports;
    document.getElementById('asset_brand').value = a.brand;
    document.getElementById('asset_lat').value = a.lat;
    document.getElementById('asset_lng').value = a.lng;
    document.getElementById('asset_price').value = a.price;
    document.getElementById('asset_status').value = a.status;
    document.getElementById('asset_date').value = a.installation_date;
    document.getElementById('assetModal').style.display = 'block';
}
</script>
