<?php
$action = $_GET['action'] ?? 'list';
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'guest';

// Fetch current user templates and global settings
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$me = $db->query("SELECT wa_template, wa_template_paid FROM users WHERE id = $u_id AND tenant_id = $tenant_id")->fetch();
$settings = $db->query("SELECT company_name, wa_template, wa_template_paid, site_url, bank_account FROM settings WHERE tenant_id = $tenant_id")->fetch();
if (!$settings) $settings = ['company_name' => 'ISP', 'site_url' => get_app_url()];

$base_url = !empty($settings['site_url']) ? $settings['site_url'] : get_app_url();
$wa_tpl = !empty($me['wa_template']) ? $me['wa_template'] : ($settings['wa_template'] ?? "Halo {nama}, tagihan internet Anda {tagihan} ({bulan}) jatuh tempo pada {jatuh_tempo}. Hubungi admin untuk info pembayaran.");
$wa_tpl_paid = !empty($me['wa_template_paid']) ? $me['wa_template_paid'] : ($settings['wa_template_paid'] ?? "Halo {nama}, terima kasih. Pembayaran {total_bayar} ({bulan}) telah diterima dan status {status_pembayaran}. Sisa Tunggakan: {sisa_tunggakan}. Cek nota: {link_tagihan}");

// Success Modal for Admin Invoices (After marking paid)
$success_data = null;
if (isset($_GET['msg']) && $_GET['msg'] === 'bulk_paid' && isset($_GET['cust_id'])) {
    $sid = intval($_GET['cust_id']);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $success_data = $db->query("SELECT id, name, contact, customer_code, package_name, monthly_fee FROM customers WHERE id = $sid AND tenant_id = $tenant_id")->fetch();
    if ($success_data) {
        $wa_num_paid = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $success_data['contact']));
        $months_paid = intval($_GET['months'] ?? 1);
        $total_paid = floatval($_GET['total'] ?? 0);
        $total_display = 'Rp ' . number_format($total_paid, 0, ',', '.');
        $tunggakan_val = $db->query("SELECT COALESCE(SUM(amount - discount), 0) FROM invoices WHERE customer_id = $sid AND status = 'Belum Lunas' AND tenant_id = $tenant_id")->fetchColumn() ?: 0;
        $tunggakan_display = 'Rp ' . number_format($tunggakan_val, 0, ',', '.');
        $status_wa = ($tunggakan_val > 0) ? "LUNAS SEBAGIAN" : "LUNAS SEPENUHNYA";
        
        $portal_link = $base_url . "/index.php?page=customer_portal&code=" . ($success_data['customer_code'] ?: $success_data['id']);
        $nota_link = $portal_link . "&action=print&id=" . intval($_GET['last_id'] ?? 0);
        
        $receipt_msg = parse_wa_template($wa_tpl_paid, [
            'name' => $success_data['name'],
            'id_cust' => ($success_data['customer_code'] ?: $success_data['id']),
            'tagihan' => $success_data['monthly_fee'],
            'package' => ($success_data['package_name'] ?: '-'),
            'period' => $months_paid . ' Bulan',
            'tunggakan' => $tunggakan_val,
            'payment_time' => date('d/m/Y H:i') . ' WIB',
            'admin_name' => $_SESSION['user_name'],
            'company_name' => $settings['company_name'],
            'portal_link' => $portal_link,
            'nota_link' => $nota_link,
            'payment_status' => $status_wa,
            'sisa_tunggakan' => $tunggakan_val, // or should it be $tunggakan_val? In this context it is the same.
            'total_paid' => $total_paid
        ]);
        $success_data['wa_text'] = $receipt_msg;
        $success_data['wa_link'] = "https://api.whatsapp.com/send?phone=$wa_num_paid&text=" . urlencode($receipt_msg);
    }
}
?>

<?php if($success_data): ?>
<div class="glass-panel" style="margin-bottom:20px; border-left:4px solid var(--success); padding:20px; background:rgba(16,185,129,0.1);">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h3 style="margin:0; color:var(--success); font-size:16px;"><i class="fas fa-check-circle"></i> Berhasil!</h3>
            <p style="margin:0; font-size:12px; color:var(--text-secondary);">Invoice <strong><?= htmlspecialchars($success_data['name']) ?></strong> lunas.</p>
        </div>
        <button onclick="sendWAGateway('<?= $wa_num_paid ?>', <?= htmlspecialchars(json_encode($success_data['wa_text'])) ?>, '<?= $success_data['wa_link'] ?>', this)" class="btn btn-sm btn-success"><i class="fab fa-whatsapp"></i> WA Kuitansi</button>
    </div>
</div>
<?php endif; ?>
<?php
if ($action === 'create_itemized' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id']);
    $due_date = $_POST['due_date'];
    $descriptions = $_POST['item_desc'];
    $amounts = $_POST['item_amount'];
    
    $discount = floatval($_POST['invoice_discount'] ?? 0);
    $total_amount = array_sum($amounts);
    $created_at = date('Y-m-d H:i:s');
    
    // Multi-Tenancy Ownership Check
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $scope_check = "";
    if ($_SESSION['user_role'] === 'partner') {
        $scope_check = " AND created_by = $u_id";
    } elseif ($_SESSION['user_role'] === 'collector') {
        $scope_check = " AND collector_id = $u_id";
    }
    $check_c = $db->query("SELECT id FROM customers WHERE id = $customer_id AND tenant_id = $tenant_id $scope_check")->fetchColumn();
    if (!$check_c) { header("Location: index.php?page=admin_invoices&msg=forbidden"); exit; }
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, discount, due_date, created_at, tenant_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$customer_id, $total_amount, $discount, $due_date, $created_at, $tenant_id]);
    $invoice_id = $db->lastInsertId();
    
    $stmt_item = $db->prepare("INSERT INTO invoice_items (invoice_id, description, amount) VALUES (?, ?, ?)");
    foreach ($descriptions as $i => $desc) {
        if (!empty(trim($desc)) && $amounts[$i] > 0) {
            $stmt_item->execute([$invoice_id, $desc, $amounts[$i]]);
        }
    }
    
    header("Location: index.php?page=admin_customers&action=details&id=$customer_id&msg=invoice_itemized_created");
    exit;
}

if ($action === 'create_auto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id']);
    $amount = floatval($_POST['amount']);
    
    // Get customer's billing date
    $c = $db->query("SELECT billing_date FROM customers WHERE id = $customer_id")->fetch();
    $b_day = str_pad($c['billing_date'] ?? '10', 2, '0', STR_PAD_LEFT);
    
    // Prepaid Logic: Set to Next Month (Bulan Depan)
    $next_month_period = date('Y-m', strtotime('+1 month'));
    $due_date = $next_month_period . '-' . $b_day;
    $created_at = date('Y-m-d H:i:s');
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    // [NEW] Duplicate Check: Prevent creating another invoice for the same customer and month
    $existing = $db->query("SELECT id FROM invoices WHERE customer_id = $customer_id AND strftime('%Y-%m', due_date) = " . $db->quote($next_month_period) . " AND tenant_id = $tenant_id")->fetchColumn();
    if ($existing) {
        header("Location: index.php?page=admin_customers&action=details&id=$customer_id&msg=invoice_exists");
        exit;
    }

    // Multi-Tenancy Ownership Check
    $scope_check = "";
    if ($_SESSION['user_role'] === 'partner') {
        $scope_check = " AND created_by = $u_id";
    } elseif ($_SESSION['user_role'] === 'collector') {
        $scope_check = " AND collector_id = $u_id";
    }
    $check_c = $db->query("SELECT id FROM customers WHERE id = $customer_id AND tenant_id = $tenant_id $scope_check")->fetchColumn();
    if (!$check_c) { header("Location: index.php?page=admin_invoices&msg=forbidden"); exit; }
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at, status, discount, tenant_id) VALUES (?, ?, ?, ?, 'Belum Lunas', 0, ?)");
    $stmt->execute([$customer_id, $amount, $due_date, $created_at, $tenant_id]);
    
    header("Location: index.php?page=admin_customers&action=details&id=$customer_id&msg=invoice_created");
    exit;
}

if ($action === 'delete') {
    $id = intval($_GET['id']);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $invoice = $db->query("SELECT * FROM invoices WHERE id = $id AND tenant_id = $tenant_id")->fetch();
    
    if ($invoice) {
        $u_id = $_SESSION['user_id'];
        $u_role = $_SESSION['user_role'];
        
        $is_allowed = true;
        if ($u_role === 'partner' || $u_role === 'collector') {
            $scope_check = ($u_role === 'partner') ? "created_by = $u_id" : "collector_id = $u_id";
            $c_owner = $db->query("SELECT id FROM customers WHERE id = " . $invoice['customer_id'] . " AND $scope_check")->fetchColumn();
            if (!$c_owner) $is_allowed = false;
        }
        
        if ($is_allowed) {
            // Cascade delete manual
            $db->exec("DELETE FROM payments WHERE invoice_id = $id AND tenant_id = $tenant_id");
            $db->exec("DELETE FROM invoice_items WHERE invoice_id = $id");
            $db->exec("DELETE FROM invoices WHERE id = $id AND tenant_id = $tenant_id");
        } else {
            header("Location: index.php?page=admin_invoices&msg=forbidden");
            exit;
        }
    }
    $ref = $_GET['ref'] ?? '';
    if ($ref === 'customer_details' && isset($_GET['cust_id'])) {
        header("Location: index.php?page=admin_customers&action=details&id=" . intval($_GET['cust_id']) . "&msg=deleted");
    } else {
        header("Location: index.php?page=admin_invoices&msg=deleted");
    }
    exit;
}

if ($action === 'edit_post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $amount = $_POST['amount'];
    $discount = $_POST['discount'] ?? 0;
    $due_date = $_POST['due_date'];
    
    $u_id = $_SESSION['user_id'];
    $u_role = $_SESSION['user_role'];
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $scope_check = "";
    if ($_SESSION['user_role'] === 'partner') {
        $scope_check = " AND (SELECT created_by FROM customers WHERE id = invoices.customer_id) = $u_id";
    } elseif ($_SESSION['user_role'] === 'collector') {
        $scope_check = " AND (SELECT collector_id FROM customers WHERE id = invoices.customer_id) = $u_id";
    }
    
    $check_i = $db->query("SELECT id FROM invoices WHERE id = $id AND tenant_id = $tenant_id $scope_check")->fetchColumn();
    
    if ($check_i) {
        $db->prepare("UPDATE invoices SET amount=?, discount=?, due_date=? WHERE id=? AND tenant_id=?")->execute([$amount, $discount, $due_date, $id, $tenant_id]);
    } else {
        header("Location: index.php?page=admin_invoices&msg=forbidden");
        exit;
    }
    
    $ref = $_POST['ref'] ?? '';
    if ($ref === 'customer_details' && isset($_POST['cust_id'])) {
        header("Location: index.php?page=admin_customers&action=details&id=" . intval($_POST['cust_id']) . "&msg=updated");
    } else {
        header("Location: index.php?page=admin_invoices&msg=updated");
    }
    exit;
}

if ($action === 'mark_paid') {
    if ($id > 0) {
        $tenant_id_check = $_SESSION['tenant_id'] ?? 1;
        $stmt = $db->prepare("SELECT amount, discount FROM invoices WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenant_id_check]);
        $inv = $stmt->fetch();
    }
    
    if ($inv) {
        // Multi-Tenancy Authorization Check
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $scope_check = "";
        if ($_SESSION['user_role'] === 'partner') {
            $scope_check = " AND (SELECT created_by FROM customers WHERE id = invoices.customer_id) = $u_id";
        } elseif ($_SESSION['user_role'] === 'collector') {
            $scope_check = " AND (SELECT collector_id FROM customers WHERE id = invoices.customer_id) = $u_id";
        }
        
        $check_i = $db->query("SELECT id FROM invoices WHERE id = $id AND tenant_id = $tenant_id $scope_check")->fetchColumn();
        if (!$check_i) { header("Location: index.php?page=admin_invoices&msg=forbidden"); exit; }

        $net_amount = $inv['amount'] - ($inv['discount'] ?? 0);
        $receiver_id = $_SESSION['user_id'];
        $payment_date = date('Y-m-d H:i:s');
        
        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        $db->prepare("UPDATE invoices SET status = 'Lunas' WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
        $db->prepare("INSERT INTO payments (invoice_id, amount, received_by, payment_date, tenant_id) VALUES (?, ?, ?, ?, ?)")->execute([$id, $net_amount, $receiver_id, $payment_date, $tenant_id]);
    }
    
    $ref = $_GET['ref'] ?? '';
    if ($ref === 'customer_details' && isset($_GET['cust_id'])) {
        header("Location: index.php?page=admin_customers&action=details&id=" . intval($_GET['cust_id']) . "&msg=paid");
    } else {
        header("Location: index.php?page=admin_invoices&action=list&msg=paid");
    }
    exit;
}

if ($action === 'mark_paid_bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id']);
    $num_months = intval($_POST['num_months']);
    $receiver_id = $_SESSION['user_id'];
    $payment_date = date('Y-m-d H:i:s');
    $total_paid_accum = 0;
    
    // Multi-Tenancy Ownership Check
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $scope_check = "";
    if ($_SESSION['user_role'] === 'partner') {
        $scope_check = " AND created_by = $receiver_id";
    } elseif ($_SESSION['user_role'] === 'collector') {
        $scope_check = " AND collector_id = $receiver_id";
    }
    
    $check_c = $db->query("SELECT id FROM customers WHERE id = $customer_id AND tenant_id = $tenant_id $scope_check")->fetchColumn();
    if (!$check_c) { header("Location: index.php?page=admin_invoices&msg=forbidden"); exit; }
    
    // Fetch oldest N unpaid invoices
    $unpaid = $db->query("SELECT id, amount, discount FROM invoices WHERE customer_id = $customer_id AND status = 'Belum Lunas' AND tenant_id = $tenant_id ORDER BY due_date ASC LIMIT $num_months")->fetchAll();
    
    $last_id = 0;
    foreach ($unpaid as $inv) {
        $net_amount = $inv['amount'] - ($inv['discount'] ?? 0);
        $db->prepare("UPDATE invoices SET status = 'Lunas' WHERE id = ? AND tenant_id = ?")->execute([$inv['id'], $tenant_id]);
        $db->prepare("INSERT INTO payments (invoice_id, amount, received_by, payment_date, tenant_id) VALUES (?, ?, ?, ?, ?)")->execute([$inv['id'], $net_amount, $receiver_id, $payment_date, $tenant_id]);
        $last_id = $inv['id'];
        $total_paid_accum += $net_amount;
    }
    
    $ref = $_SERVER['HTTP_REFERER'] ?? 'index.php?page=admin_invoices';
    $base_redirect = ($_SESSION['user_role'] === 'collector') ? "index.php?page=collector" : $ref;
    
    $redirect = $base_redirect . (strpos($base_redirect, '?') !== false ? '&' : '?') . "msg=bulk_paid&cust_id=$customer_id&months=$num_months&total=$total_paid_accum&last_id=$last_id";
    header("Location: $redirect");
    exit;
}

if ($action === 'unpay') {
    $id = intval($_GET['id']);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    // Fetch invoice along with customer ownership info
    $inv = $db->query("SELECT i.*, c.created_by, c.collector_id FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.id = $id AND i.tenant_id = $tenant_id")->fetch();
    
    if ($inv && $inv['status'] === 'Lunas') {
        $u_id = $_SESSION['user_id'];
        $u_role = $_SESSION['user_role'];
        
        // Multi-Tenancy Check: Since query already filters by tenant_id, we just need to confirm if it belongs to this tenant
        if ($inv) {
            $is_allowed = true;
            if ($u_role === 'partner' && $inv['created_by'] != $u_id) {
                $is_allowed = false;
            } elseif ($u_role === 'collector' && $inv['collector_id'] != $u_id) {
                $is_allowed = false;
            }
            
            if (!$is_allowed) {
                header("Location: index.php?page=admin_invoices&msg=forbidden");
                exit;
            }
            
            $db->prepare("DELETE FROM payments WHERE invoice_id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
            $db->prepare("UPDATE invoices SET status = 'Belum Lunas' WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
            $msg_type = "unpay_success";
        } else {
            $msg_type = "forbidden";
        }
    }
    // Redirect back with message
    $ref = $_SERVER['HTTP_REFERER'] ?? 'index.php?page=admin_invoices';
    $redirect = $ref . (strpos($ref, '?') !== false ? '&' : '?') . "msg=$msg_type";
    header("Location: $redirect");
    exit;
}

if ($action === 'create_auto_bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $due_month = $_POST['due_month']; // format 2023-10
    $due_date = $_POST['due_date'];   // format 2023-10-10
    
    $u_id = $_SESSION['user_id'];
    $u_role = $_SESSION['user_role'];
    $filter_type = $_POST['filter_type'] ?? 'customer';
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    // Scope Filter: Strict tenant isolation
    $scope_sql = " AND tenant_id = $tenant_id";
        if ($u_role === 'collector') {
            $scope_sql .= " AND collector_id = $u_id";
        } elseif ($u_role === 'partner') {
            $scope_sql .= " AND created_by = $u_id";
        } else {
            // Admin: Exclude Partner-managed customers from mass billing
            // Dynamic logic to identify partners in this tenant
            $p_ids = $db->query("SELECT id FROM users WHERE role = 'partner' AND tenant_id = $tenant_id")->fetchAll(PDO::FETCH_COLUMN);
            $p_list = !empty($p_ids) ? implode(',', $p_ids) : '0';
            $scope_sql .= " AND (created_by NOT IN ($p_list) OR created_by = 0 OR created_by IS NULL) ";
        }
    $type_sql = " AND type = " . $db->quote($filter_type);

    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    // Fetch all customers that don't have an invoice for this month
    $customers = $db->query("
        SELECT id, monthly_fee FROM customers 
        WHERE tenant_id = $tenant_id $type_sql $scope_sql
        AND id NOT IN (
            SELECT customer_id FROM invoices 
            WHERE tenant_id = $tenant_id AND (strftime('%Y-%m', due_date) = " . $db->quote($due_month) . "
            OR strftime('%Y-%m', created_at) = " . $db->quote($due_month) . ")
        )
    ")->fetchAll();
    
    $count = 0;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, status, due_date, created_at, discount, tenant_id) VALUES (?, ?, 'Belum Lunas', ?, CURRENT_TIMESTAMP, 0, ?)");
        foreach ($customers as $c) {
            $stmt->execute([$c['id'], $c['monthly_fee'], $due_date, $tenant_id]);
            $count++;
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: index.php?page=admin_invoices&msg=bulk_error&err=" . urlencode($e->getMessage()));
        exit;
    }
    
    header("Location: index.php?page=admin_invoices&filter_type=$filter_type&msg=bulk_created&count=$count");
    exit;
}

if ($action === 'print') {
    $id = intval($_GET['id']);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stmt = $db->prepare("
        SELECT i.*, c.name, c.address, c.contact, c.package_name, c.type, c.id as customer_id, c.created_by, c.collector_id
        FROM invoices i 
        JOIN customers c ON i.customer_id = c.id 
        WHERE i.id = ? AND i.tenant_id = ?
    ");
    $stmt->execute([$id, $tenant_id]);
    $invoice = $stmt->fetch();

    // Security Cross-Check: User can only see invoices belonging to their tenant
    // $invoice already filtered by tenant_id in query above.
    // Additional role-based checks for partners/collectors:
    if ($_SESSION['user_role'] === 'partner' || $_SESSION['user_role'] === 'collector') {
        $u_id = $_SESSION['user_id'];
        $u_role = $_SESSION['user_role'];
        
        $is_allowed = false;
        if ($u_role === 'partner') {
            $partner_cid = $db->query("SELECT customer_id FROM users WHERE id = $u_id")->fetchColumn() ?: 0;
            if ($invoice['created_by'] == $u_id || $invoice['customer_id'] == $partner_cid) $is_allowed = true;
        } elseif ($u_role === 'collector') {
            if ($invoice['collector_id'] == $u_id) $is_allowed = true;
        }
        
        if (!$is_allowed) {
            echo "<div class='glass-panel' style='padding:40px; text-align:center;'>
                    <h1 style='color:#ef4444;'>Akses Ditolak</h1>
                    <p>Anda hanya diperbolehkan mencetak nota untuk pelanggan Anda sendiri atau tagihan untuk Anda sendiri.</p>
                  </div>";
            exit;
        }
    }

    if ($invoice) {
        // Fetch invoice line-items (for external/quick invoices with uraian)
        $invoice_items = [];
        try {
            $stmt_items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
            $stmt_items->execute([$id]);
            $invoice_items = $stmt_items->fetchAll();
        } catch (Exception $e) { $invoice_items = []; }

        // Calculate tunggakan (outstanding arrears from OTHER unpaid invoices)
        $tunggakan = 0;
        $tunggakan_bulan = 0;
        try {
            $cid = intval($invoice['customer_id'] ?? 0);
            if ($cid > 0) {
                $arrears = $db->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(amount - COALESCE(discount,0)), 0) as total FROM invoices WHERE customer_id = ? AND id != ? AND status = 'Belum Lunas' AND tenant_id = ?");
                $arrears->execute([$cid, $id, $tenant_id]);
                $ar = $arrears->fetch();
                $tunggakan = floatval($ar['total'] ?? 0);
                $tunggakan_bulan = intval($ar['cnt'] ?? 0);
            }
        } catch (Exception $e) {}

        // Fetch payment info if invoice is paid
        $payment_info = null;
        $bulan_bayar = '';
        if (($invoice['status'] ?? '') === 'Lunas') {
            try {
                $pstmt = $db->prepare("SELECT payment_date, payment_method, notes FROM payments WHERE invoice_id = ? ORDER BY payment_date DESC LIMIT 1");
                $pstmt->execute([$id]);
                $payment_info = $pstmt->fetch();
            } catch (Exception $e) {}
            $bulan_bayar = 'Pembayaran ' . (!empty($invoice['created_at']) ? date('F Y', strtotime($invoice['created_at'])) : '-');
        }
    }

    require __DIR__ . '/../print.php';
    exit;
}

// Partners are allowed to view the list (scoped to their own customers)
/*
if ($action === 'list' && ($_SESSION['user_role'] ?? '') === 'partner') {
    header("Location: index.php?page=partner");
    exit;
}
*/
?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'unpay_success'): ?>
<div style="padding:12px 20px; background:rgba(16,185,129,0.15); border:1px solid rgba(16,185,129,0.4); border-radius:10px; margin-bottom:15px; color:var(--success);">
    <i class="fas fa-undo"></i> Pembayaran berhasil dibatalkan. Tagihan kembali menjadi <strong>Belum Lunas</strong>.
</div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
<?php
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $filter_status = $_GET['filter_status'] ?? '';
    $filter_type = $_GET['filter_type'] ?? '';
    
    $date_where = '';
    if ($date_from && $date_to) {
        $date_where = " AND i.due_date BETWEEN " . $db->quote($date_from) . " AND " . $db->quote($date_to);
    } elseif ($date_from) {
        $date_where = " AND i.due_date >= " . $db->quote($date_from);
    } elseif ($date_to) {
        $date_where = " AND i.due_date <= " . $db->quote($date_to);
    }
    
    $status_where = '';
    if ($filter_status === 'lunas') $status_where = " AND i.status = 'Lunas'";
    elseif ($filter_status === 'belum') $status_where = " AND i.status = 'Belum Lunas'";

    $type_where = '';
    if ($filter_type) {
        $type_where = " AND c.type = " . $db->quote($filter_type);
    }

    // Collector filter
    $filter_collector = $_GET['filter_collector'] ?? '';
    $collector_where = '';
    if ($filter_collector) {
        $collector_where = " AND c.collector_id = " . intval($filter_collector);
    }
    
    // Scoping Logic (Multi-tenancy/Silo)
    $u_id = $_SESSION['user_id'];
    $u_role = $_SESSION['user_role'];
    
    // Partner-specific view mode (Tab selection)
    $view_mode = $_GET['view_mode'] ?? 'customers'; 

    // Scoping Logic for Invoices (Multi-tenancy & Hierarchical Isolation)
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $scope_where = " AND c.tenant_id = $tenant_id";
    
    // Dynamic logic to identify partners in this tenant (to exclude their customers from Admin view)
    $partner_ids = $db->query("SELECT id FROM users WHERE role = 'partner' AND tenant_id = $tenant_id")->fetchAll(PDO::FETCH_COLUMN);
    $partner_list = !empty($partner_ids) ? implode(',', $partner_ids) : '0';

    if ($u_role === 'collector') {
        $scope_where .= " AND (c.collector_id = $u_id) ";
    } elseif ($u_role === 'partner') {
        $partner_cid = $db->query("SELECT customer_id FROM users WHERE id = $u_id AND tenant_id = $tenant_id")->fetchColumn() ?: 0;
        if ($view_mode === 'isp_bill') {
            // View ONLY own B2B bill
            $scope_where .= " AND c.id = $partner_cid";
        } else {
            // View ONLY own customers
            $scope_where .= " AND c.created_by = $u_id";
        }
    } else {
        // Admin: Exclude Partner-managed customers
        $scope_where .= " AND (c.created_by NOT IN ($partner_list) OR c.created_by = 0 OR c.created_by IS NULL) ";
    }

    $collectors = $db->query("SELECT id, name FROM users WHERE role = 'collector' AND tenant_id = $tenant_id ORDER BY name ASC")->fetchAll();
?>
<div class="glass-panel" style="padding: 24px;">
    <!-- Partner Tabs -->
    <?php if ($u_role === 'partner'): ?>
    <?php 
        $tenant_id_p = $_SESSION['tenant_id'] ?? 1;
        $partner_cid = $db->query("SELECT customer_id FROM users WHERE id = $u_id AND tenant_id = $tenant_id_p")->fetchColumn() ?: 0;
        $unpaid_personal = $db->query("SELECT COUNT(*) FROM invoices WHERE customer_id = $partner_cid AND status = 'Belum Lunas' AND tenant_id = $tenant_id_p")->fetchColumn();
    ?>
    <div style="display:flex; gap:10px; margin-bottom:20px; border-bottom:1px solid var(--glass-border); padding-bottom:10px;">
        <a href="index.php?page=admin_invoices&view_mode=customers" style="text-decoration:none; padding:8px 16px; border-radius:8px; font-size:14px; font-weight:700; transition:all 0.3s; <?= $view_mode === 'customers' ? 'background:var(--primary); color:white;' : 'color:var(--text-secondary);' ?>">
            <i class="fas fa-users"></i> Tagihan Pelanggan
        </a>
        <a href="index.php?page=admin_invoices&view_mode=isp_bill" style="text-decoration:none; padding:8px 16px; border-radius:8px; font-size:14px; font-weight:700; transition:all 0.3s; position:relative; <?= $view_mode === 'isp_bill' ? 'background:var(--primary); color:white;' : 'color:var(--text-secondary);' ?>">
            <i class="fas fa-building"></i> Kewajiban Ke ISP Induk
            <?php if ($unpaid_personal > 0): ?>
                <span style="position:absolute; top:-5px; right:-5px; background:var(--danger); color:white; font-size:10px; padding:2px 6px; border-radius:50px; box-shadow:0 0 10px rgba(244,63,94,0.4);"><?= $unpaid_personal ?></span>
            <?php endif; ?>
        </a>
    </div>
    <?php endif; ?>

    <!-- Unified Header -->
    <div class="grid-header">
        <div>
            <h3 style="font-size:20px; font-weight:800; margin:0; display:flex; align-items:center; gap:12px;">
                <i class="fas fa-file-invoice-dollar text-primary"></i> 
                <?php 
                    if ($view_mode === 'isp_bill') echo 'Nota Kewajiban Ke ISP';
                    elseif ($filter_status === 'belum') echo 'Manajemen Tunggakan';
                    else echo 'Daftar Tagihan';
                ?>
            </h3>
            <div style="font-size:12px; color:var(--text-secondary); margin-top:4px; opacity:0.8;">
                <?php 
                    if ($view_mode === 'isp_bill') echo 'Daftar tagihan atau biaya langganan Mitra ke ISP Pusat';
                    elseif ($filter_type === 'partner') echo 'Kemitraan & B2B';
                    else echo 'Layanan Retail / Rumahan';
                ?> • 
                <span style="color:var(--primary);"><?= date('m/Y') ?></span>
            </div>
        </div>
        <div class="grid-actions">
            <div class="btn-group">
                <?php if ($u_role !== 'collector' && $view_mode !== 'isp_bill'): ?>
                <button class="btn btn-primary btn-sm" onclick="showBulkInvoiceModal()">
                    <i class="fas fa-magic"></i> <span>Tagih Masal</span>
                </button>
                <button class="btn btn-info btn-sm" onclick="showManualInvoiceModal()">
                    <i class="fas fa-plus"></i> <span>Manual</span>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
    // Calculate current view statistics
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stat_q = "SELECT 
        COUNT(*) as total, 
        SUM(CASE WHEN i.status='Lunas' THEN 1 ELSE 0 END) as lunas,
        SUM(CASE WHEN i.status='Belum Lunas' THEN 1 ELSE 0 END) as belum,
        COALESCE(SUM(CASE WHEN i.status='Lunas' THEN (i.amount - i.discount) ELSE 0 END), 0) as amt_lunas,
        COALESCE(SUM(CASE WHEN i.status='Belum Lunas' THEN (i.amount - i.discount) ELSE 0 END), 0) as amt_belum
        FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.tenant_id = $tenant_id $date_where $status_where $collector_where $scope_where $type_where";
    $stats = $db->query($stat_q)->fetch();
    ?>

    <!-- Integrated Stats Strip -->
    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:25px; background:rgba(255,255,255,0.02); padding:15px; border-radius:15px; border:1px solid var(--glass-border);">
        <div style="padding-left:10px; border-left:3px solid var(--primary);">
            <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">Total Tagihan</div>
            <div style="font-size:18px; font-weight:800;"><?= number_format($stats['total'], 0, ',', '.') ?></div>
        </div>
        <div style="padding-left:10px; border-left:3px solid var(--success);">
            <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">Terbayar (Lunas)</div>
            <div style="font-size:18px; font-weight:800; color:var(--success);">Rp <?= number_format($stats['amt_lunas'], 0, ',', '.') ?></div>
        </div>
        <div style="padding-left:10px; border-left:3px solid var(--danger);">
            <div style="font-size:10px; font-weight:800; color:var(--text-secondary); text-transform:uppercase;">Piutang (Belum Lunas)</div>
            <div style="font-size:18px; font-weight:800; color:var(--danger);">Rp <?= number_format($stats['amt_belum'], 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- Integrated Filter Bar -->
    <div style="padding:20px; margin-bottom:25px; background:rgba(var(--primary-rgb), 0.05); border-radius:12px; border:1px solid rgba(var(--primary-rgb), 0.1);">
    <form method="GET" class="grid-filters">
        <input type="hidden" name="page" value="admin_invoices">
        <input type="hidden" name="filter_type" value="<?= htmlspecialchars($filter_type) ?>">
        
        <div class="filter-group">
            <label><i class="fas fa-calendar-alt"></i> Periode Jatuh Tempo</label>
            <div style="display:flex; align-items:center; gap:8px;">
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>" style="padding:8px 10px; font-size:12px;">
                <span style="color:var(--text-secondary); font-size:12px; opacity:0.5;">sampai dengan</span>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>" style="padding:8px 10px; font-size:12px;">
            </div>
        </div>

        <div class="filter-group">
            <label><i class="fas fa-info-circle"></i> Status</label>
            <select name="filter_status" class="form-control" style="padding:8px 12px; font-size:13px;">
                <option value="">Semua Status</option>
                <option value="lunas" <?= $filter_status === 'lunas' ? 'selected' : '' ?>>Lunas</option>
                <option value="belum" <?= $filter_status === 'belum' ? 'selected' : '' ?>>Belum Lunas</option>
            </select>
        </div>

        <?php if ($u_role === 'admin'): ?>
        <div class="filter-group">
            <label><i class="fas fa-user-tie"></i> Collector</label>
            <select name="filter_collector" class="form-control" style="padding:8px 12px; font-size:13px;">
                <option value="">Semua Collector</option>
                <?php foreach($collectors as $coll): ?>
                    <option value="<?= $coll['id'] ?>" <?= $filter_collector == $coll['id'] ? 'selected' : '' ?>><?= htmlspecialchars($coll['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="grid-actions" style="margin-top:auto;">
            <div class="btn-group" style="width:100%;">
                <button type="submit" class="btn btn-primary btn-sm" style="flex:1;"><i class="fas fa-filter"></i> Apply</button>
                <?php if($date_from || $date_to || $filter_status || $filter_collector): ?>
                    <a href="index.php?page=admin_invoices&filter_type=<?= $filter_type ?>" class="btn btn-ghost btn-sm" style="flex:0; padding:0 15px;"><i class="fas fa-sync"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </form>
    </div>
    <div class="scroll-container" style="padding:0; overflow-y:auto; max-height:70vh; border-radius:15px; border:1px solid var(--glass-border); background:rgba(255,255,255,0.01);">

    <!-- INVOICE LIST -->
    <?php
        // Paginasi
        $items_per_page = 50;
        $current_page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
        $offset = ($current_page - 1) * $items_per_page;

        // NEW: Check if we should group by customer (only for Unpaid view)
        $is_grouped = ($filter_status === 'belum');

        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        // Hitung total baris untuk filter ini
        if ($is_grouped) {
            $count_q = "SELECT COUNT(DISTINCT c.id) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.tenant_id = $tenant_id $date_where $status_where $collector_where $scope_where $type_where";
        } else {
            $count_q = "SELECT COUNT(*) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.tenant_id = $tenant_id $date_where $status_where $collector_where $scope_where $type_where";
        }
        $total_rows = $db->query($count_q)->fetchColumn();
        $total_pages = ceil($total_rows / $items_per_page);

        $tenant_id = $_SESSION['tenant_id'] ?? 1;
        if ($is_grouped) {
            $invoices = $db->query("
                SELECT 
                    MAX(i.id) as id, 
                    i.customer_id, 
                    i.status,
                    SUM(i.amount) as amount, 
                    SUM(i.discount) as discount,
                    MIN(i.due_date) as due_date, 
                    COUNT(i.id) as months_owed,
                    c.id as cust_id, c.customer_code, c.name as customer_name, c.type as customer_type, c.contact, c.package_name, c.monthly_fee, c.created_by, c.collector_id,
                    0 as item_count
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                WHERE i.tenant_id = $tenant_id $date_where $status_where $collector_where $scope_where $type_where
                GROUP BY c.id
                ORDER BY due_date ASC
                LIMIT $items_per_page OFFSET $offset
            ")->fetchAll();
        } else {
            $invoices = $db->query("
                SELECT i.*, c.id as cust_id, c.customer_code, c.name as customer_name, c.type as customer_type, c.contact, c.package_name, c.monthly_fee, c.created_by, c.collector_id,
                (SELECT COUNT(*) FROM invoice_items WHERE invoice_id = i.id) as item_count
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                WHERE i.tenant_id = $tenant_id $date_where $status_where $collector_where $scope_where $type_where
                ORDER BY i.id DESC
                LIMIT $items_per_page OFFSET $offset
            ")->fetchAll();
        }
    ?>

        <!-- Mobile Card View (Hidden on Desktop) -->
        <div class="invoices-mobile-container" style="display:none;">
            <div style="margin-bottom:10px; display:flex; gap:10px; align-items:center;">
                 <input type="checkbox" id="checkAll_mobile"> <label for="checkAll_mobile" style="font-size:13px; color:var(--text-secondary);">Pilih Semua</label>
            </div>
            <?php 
            // Pre-fetch all unpaid invoices for these customers to avoid N+1 query problem
            $cust_ids = array_filter(array_unique(array_column($invoices, 'customer_id')));
            $all_unpaid_data = [];
            if(!empty($cust_ids)) {
                $ids_str = implode(',', $cust_ids);
                $unpaid_list = $db->query("SELECT id, customer_id, amount FROM invoices WHERE status = 'Belum Lunas' AND customer_id IN ($ids_str)")->fetchAll();
                foreach($unpaid_list as $up) $all_unpaid_data[$up['customer_id']][] = $up;
            }
            
            // Pre-fetch payment data (date and receiver) to avoid N+1 query
            $inv_ids = array_column($invoices, 'id');
            $payment_info = [];
            if(!empty($inv_ids)) {
                $inv_ids_str = implode(',', $inv_ids);
                $pay_list = $db->query("
                    SELECT p.invoice_id, p.payment_date, u.name as admin_name 
                    FROM payments p 
                    LEFT JOIN users u ON p.received_by = u.id
                    WHERE p.invoice_id IN ($inv_ids_str)
                ")->fetchAll();
                foreach($pay_list as $pl) {
                    $payment_info[$pl['invoice_id']] = [
                        'date' => $pl['payment_date'],
                        'admin' => $pl['admin_name'] ?: 'System'
                    ];
                }
            }

            foreach($invoices as $inv): 
                // Security context for actions
                // Security context for actions (Already pre-fetched in main query)
                $check_owner = $inv['created_by'] ?? 0;
                $can_manage_item = ($u_role === 'admin') ? ($check_owner == $u_id || $check_owner == 0 || $check_owner === NULL) : ($check_owner == $u_id);

                $wa_number = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $inv['contact']));
                // (WA Template Logic)
                $mon_id = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                $inv_month = $mon_id[intval(date('m', strtotime($inv['due_date']))) - 1] . ' ' . date('Y', strtotime($inv['due_date']));
                // Use unique customer_code if available, otherwise fallback to padded seq id
                $cust_id_display = $inv['customer_code'] ?: str_pad($inv['cust_id'] ?? 0, 5, "0", STR_PAD_LEFT);
                $package_display = $inv['package_name'] ?? '-';
                $nominal_display = 'Rp ' . number_format($inv['amount'], 0, ',', '.');
                
                if ($inv['status'] == 'Lunas') {
                    $pay_meta = $payment_info[$inv['id']] ?? null;
                    $realtime_bayar = $pay_meta ? date('Y-m-d H:i:s', strtotime($pay_meta['date'])) : '-';
                    $admin_bayar = $pay_meta['admin'] ?? '-';
                    
                    // Calculate remaining arrears after this payment
                    $tunggakan_remain = 0;
                    $rem_invoices = $all_unpaid_data[$inv['customer_id']] ?? [];
                    foreach($rem_invoices as $rem) {
                        if($rem['id'] != $inv['id']) $tunggakan_remain += $rem['amount'];
                    }
                    $t_remain_display = $tunggakan_remain > 0 ? 'Rp ' . number_format($tunggakan_remain, 0, ',', '.') : 'LUNAS SELURUHNYA';

                    $msg = parse_wa_template($wa_tpl_paid, [
                        'name' => $inv['customer_name'],
                        'id_cust' => $cust_id_display,
                        'package' => $package_display,
                        'period' => $inv_month,
                        'tagihan' => $inv['amount'],
                        'tunggakan' => $tunggakan_remain,
                        'admin_name' => $admin_bayar,
                        'portal_link' => $portal_link,
                        'payment_time' => $realtime_bayar,
                        'total_paid' => $inv['amount'], // or whatever was paid
                        'sisa_tunggakan' => $tunggakan_remain,
                        'status_pembayaran' => 'LUNAS'
                    ]);
                } else {
                    // Calculate previous arrears
                    $tunggakan_prev = 0;
                    $up_list = $all_unpaid_data[$inv['customer_id']] ?? [];
                    foreach($up_list as $up) {
                        if($up['id'] < $inv['id']) $tunggakan_prev += $up['amount'];
                    }
                    $t_prev_display = $tunggakan_prev > 0 ? 'Rp ' . number_format($tunggakan_prev, 0, ',', '.') : 'Rp 0';
                    $total_harus = $inv['amount'] + $tunggakan_prev;
                    $total_harus_display = 'Rp ' . number_format($total_harus, 0, ',', '.');

                    $msg = parse_wa_template($wa_tpl, [
                        'name' => $inv['customer_name'],
                        'id_cust' => $cust_id_display,
                        'package' => $package_display,
                        'period' => $inv_month,
                        'tagihan' => $inv['amount'],
                        'due_date' => date('d/m/Y', strtotime($inv['due_date'])),
                        'rekening' => trim($settings['bank_account']),
                        'tunggakan' => $tunggakan_prev,
                        'total_payment' => $total_harus,
                        'portal_link' => $portal_link
                    ]);
                }
                $wa_text = urlencode($msg);
            ?>
        <div class="glass-panel" style="padding:18px; margin-bottom:15px; border-left:6px solid <?= $inv['status'] == 'Lunas' ? 'var(--success)' : 'var(--danger)' ?>; border-radius:18px; position:relative; overflow:hidden;">
            <?php if($inv['status'] != 'Lunas' && $view_mode !== 'isp_bill'): ?>
                <div style="position:absolute; top:12px; right:12px;">
                    <input type="checkbox" class="cb-invoice cb-mobile" data-phone="<?= htmlspecialchars($wa_number) ?>" data-msg="<?= htmlspecialchars($msg) ?>" data-name="<?= htmlspecialchars($inv['customer_name']) ?>" style="width:22px; height:22px; accent-color:var(--primary);">
                </div>
            <?php endif; ?>

            <div style="margin-bottom:15px;">
                <div style="font-weight:800; font-size:16px; color:var(--text-primary); margin-bottom:4px; padding-right:30px;"><?= htmlspecialchars($inv['customer_name']) ?></div>
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <span class="badge" style="background:var(--nav-active-bg); color:var(--primary); border:1px solid var(--glass-border); font-size:10px; font-weight:700; border-radius:6px;"><?= $package_display ?></span>
                    <span style="font-size:11px; color:var(--text-secondary); opacity:0.8;">#<?= $cust_id_display ?></span>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:18px; padding:12px; background:rgba(255,255,255,0.03); border-radius:12px;">
                <div>
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Periode</div>
                    <div style="font-size:13px; font-weight:600; color:var(--text-primary);"><?= $inv_month ?></div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:10px; color:var(--text-secondary); text-transform:uppercase; font-weight:700;">Total Tagihan</div>
                    <div style="font-size:14px; font-weight:800; color:var(--stat-value-color);">Rp <?= number_format($inv['amount'], 0, ',', '.') ?></div>
                </div>
            </div>
            
            <div style="display:flex; gap:8px;">
                <?php if($inv['status'] != 'Lunas'): ?>
                    <?php if($view_mode !== 'isp_bill'): ?>
                        <?php if($is_grouped && $inv['months_owed'] > 1): ?>
                            <button onclick="showBulkPayModal(<?= $inv['cust_id'] ?>, '<?= addslashes($inv['customer_name']) ?>', <?= $inv['months_owed'] ?>, <?= $inv['amount'] / $inv['months_owed'] ?>, <?= $inv['amount'] ?>)" class="btn btn-success" style="flex:1; font-weight:800; font-size:12px; border-radius:10px;">
                                BAYAR (<?= $inv['months_owed'] ?>)
                            </button>
                        <?php else: ?>
                            <a href="index.php?page=admin_invoices&action=mark_paid&id=<?= $inv['id'] ?>" class="btn btn-success" style="flex:1; font-weight:800; font-size:12px; border-radius:10px;" onclick="return confirm('Tandai tagihan sudah dibayar?')">
                                BAYAR
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="flex:1; background:rgba(var(--primary-rgb), 0.1); color:var(--primary); font-size:11px; font-weight:700; border-radius:10px; display:flex; align-items:center; justify-content:center; border:1px solid var(--glass-border);">
                            <i class="fas fa-info-circle" style="margin-right:5px;"></i> MENUNGGU KONFIRMASI ADMIN
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="flex:1; display:flex; gap:5px;">
                        <div class="btn btn-ghost" style="flex:1; border-color:var(--success); color:var(--success); opacity:0.8; font-size:10px; pointer-events:none; padding:0; display:flex; align-items:center; justify-content:center;">
                            <i class="fas fa-check-circle"></i> LUNAS
                        </div>
                        <?php 
                            $inv_meta = $db->query("SELECT created_by, collector_id FROM customers WHERE id = " . intval($inv['customer_id']))->fetch();
                            $check_owner_mob = $inv_meta['created_by'];
                            $check_coll_mob = $inv_meta['collector_id'];
                            
                            $can_unpay_mob = false;
                            if ($u_role === 'admin') $can_unpay_mob = true;
                            elseif ($u_role === 'partner' && $check_owner_mob == $u_id) $can_unpay_mob = true;
                            elseif ($u_role === 'collector' && $check_coll_mob == $u_id) $can_unpay_mob = true;

                            if($can_unpay_mob): 
                        ?>
                            <a href="index.php?page=admin_invoices&action=unpay&id=<?= $inv['id'] ?>" class="btn btn-ghost" style="width:36px; color:var(--danger); border-color:rgba(239, 68, 68, 0.2); background:rgba(239, 68, 68, 0.05); padding:0; display:flex; align-items:center; justify-content:center;" onclick="return confirm('Batalkan status lunas?')">
                                <i class="fas fa-undo" style="font-size:12px;"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <a href="index.php?page=admin_invoices&action=print&id=<?= $inv['id'] ?>" target="_blank" class="btn btn-ghost" style="width:44px; height:40px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0;">
                    <i class="fas fa-print" style="font-size:16px;"></i>
                </a>
                
                <?php if($inv['status'] != 'Lunas' && $wa_number): ?>
                    <button onclick="sendWAGateway('<?= $wa_number ?>', <?= htmlspecialchars(json_encode($msg)) ?>, 'https://api.whatsapp.com/send?phone=<?= $wa_number ?>&text=<?= $wa_text ?>', this)" class="btn btn-ghost" style="width:44px; height:40px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; color:#25D366;">
                        <i class="fab fa-whatsapp" style="font-size:18px;"></i>
                    </button>
                <?php endif; ?>
                
                <?php if($can_manage_item): ?>
                    <button onclick="InvoicesPage.showEditInvoice(<?= $inv['id'] ?>, <?= $inv['amount'] ?>, <?= $inv['discount'] ?? 0 ?>, '<?= $inv['due_date'] ?>')" class="btn btn-ghost btn-edit-invoice" data-inv-id="<?= $inv['id'] ?>" data-inv-amount="<?= $inv['amount'] ?>" data-inv-discount="<?= $inv['discount'] ?? 0 ?>" data-inv-date="<?= $inv['due_date'] ?>" style="width:40px; height:40px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; color:var(--warning);">
                        <i class="fas fa-edit" style="font-size:15px;"></i>
                    </button>
                    <a href="index.php?page=admin_invoices&action=delete&id=<?= $inv['id'] ?>" class="btn btn-ghost" style="width:40px; height:40px; display:flex; align-items:center; justify-content:center; border-radius:10px; padding:0; color:var(--danger);" onclick="return confirm('Hapus tagihan ini?')">
                        <i class="fas fa-trash" style="font-size:15px;"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Desktop Table (Hidden on Mobile) -->
    <div class="table-container invoices-desktop-table">
        <table>
            <thead>
                <tr>
                    <th style="width:40px;"><input type="checkbox" id="checkAll"></th>
                    <th>Pelanggan</th>
                    <th>Jatuh Tempo</th>
                    <th>Nominal</th>
                    <th>Potongan</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="invoiceTableBody">
                <?php foreach($invoices as $inv):
                    $wa_number = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $inv['contact']));
                    $mon_id = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                    $inv_month = $mon_id[intval(date('m', strtotime($inv['due_date']))) - 1] . ' ' . date('Y', strtotime($inv['due_date']));
                    $cust_id_display = $inv['customer_code'] ?: str_pad($inv['cust_id'] ?? 0, 5, "0", STR_PAD_LEFT);
                    $package_display = $inv['package_name'] ?: '-';
                    $nominal_display = 'Rp ' . number_format($inv['amount'], 0, ',', '.');
                    
                    if ($inv['status'] == 'Lunas') {
                        $pay_meta = $payment_info[$inv['id']] ?? null;
                        $realtime_bayar = $pay_meta ? date('d/m/Y H:i', strtotime($pay_meta['date'])) : '-';
                        $admin_bayar = $pay_meta['admin'] ?? '-';
                        
                        // Calculate remaining arrears
                        $tunggakan_remain = 0;
                        $rem_invoices = $all_unpaid_data[$inv['customer_id']] ?? [];
                        foreach($rem_invoices as $rem) {
                            if($rem['id'] != $inv['id']) $tunggakan_remain += $rem['amount'];
                        }
                        $t_remain_display = $tunggakan_remain > 0 ? 'Rp ' . number_format($tunggakan_remain, 0, ',', '.') : 'LUNAS SELURUHNYA';

                        $portal_link = $base_url . "/index.php?page=customer_portal&code=" . $cust_id_display;
                        $msg = str_replace(
                            [
                                '{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', 
                                '{tunggakan}', '{waktu_bayar}', '{admin}', '{link_tagihan}', '{rekening}', 
                                '{nominal}', '{status_pembayaran}', '{sisa_tunggakan}', '{total_bayar}'
                            ], 
                            [
                                $inv['customer_name'], 
                                '*' . $cust_id_display . '*', 
                                $package_display, 
                                $inv_month, 
                                '*' . $nominal_display . '*', 
                                '*' . $t_remain_display . '*', 
                                '*' . $realtime_bayar . '*',
                                '*' . $admin_bayar . '*', 
                                $portal_link,
                                '*' . trim($settings['bank_account'] ?? '') . '*',
                                '*' . $nominal_display . '*',
                                '*LUNAS*',
                                '*' . $t_remain_display . '*',
                                '*' . $nominal_display . '*'
                            ], 
                            $wa_tpl_paid
                        );
                        
                        if(strpos($msg, '{tunggakan}') === false && strpos($msg, 'Tunggakan') === false) {
                            $msg .= "\n*Sisa Tunggakan : $t_remain_display*";
                        }

                        // Final emphasis on LUNAS
                        $msg = str_ireplace('LUNAS', '*LUNAS*', $msg);
                        $msg = str_replace('**', '*', $msg); // Clean up potential double bolding

                        if(strpos($msg, '{waktu_bayar}') !== false) {
                            $msg = str_replace('{waktu_bayar}', '*' . $realtime_bayar . '*', $msg);
                        } elseif(strpos($msg, 'Waktu Lunas') === false) {
                            $msg .= "\n\n*Informasi Sistem:*\n- Waktu Lunas: *$realtime_bayar*\n- Petugas: *$admin_bayar*";
                        }
                    } else {
                        // Calculate previous arrears
                        $tunggakan_prev = 0;
                        $up_list = $all_unpaid_data[$inv['customer_id']] ?? [];
                        foreach($up_list as $up) {
                            if($up['id'] < $inv['id']) $tunggakan_prev += $up['amount'];
                        }
                        $t_prev_display = $tunggakan_prev > 0 ? 'Rp ' . number_format($tunggakan_prev, 0, ',', '.') : 'Rp 0';
                        $total_harus = $inv['amount'] + $tunggakan_prev;
                        $total_harus_display = 'Rp ' . number_format($total_harus, 0, ',', '.');

                        $portal_link = $base_url . "/index.php?page=customer_portal&code=" . $cust_id_display;
                        $msg = str_replace(
                            ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{tunggakan}', '{total_harus}', '{link_tagihan}'], 
                            [$inv['customer_name'], '*' . $cust_id_display . '*', $package_display, $inv_month, '*' . $nominal_display . '*', '*' . date('d/m/Y', strtotime($inv['due_date'])) . '*', '*' . trim($settings['bank_account'] ?? '') . '*', '*' . $t_prev_display . '*', '*' . $total_harus_display . '*', $portal_link], 
                            $wa_tpl
                        );

                        // Add breakdown if not in template
                        if(strpos($msg, '{total_harus}') === false && strpos($msg, 'TOTAL') === false) {
                            $msg .= "\n\n*Rincian:*";
                            $msg .= "\n- Tagihan: $nominal_display";
                            if($tunggakan_prev > 0) $msg .= "\n- Tunggakan: $t_prev_display";
                            $msg .= "\n-------------------";
                            $msg .= "\n*TOTAL: $total_harus_display*";
                        }
                    }
                    $wa_text = urlencode($msg);
                ?>
                <tr>
                    <td style="vertical-align: middle; text-align: center; padding-left:20px;">
                        <?php if($inv['status'] != 'Lunas' && $wa_number && $view_mode !== 'isp_bill'): ?>
                            <input type="checkbox" class="cb-invoice cb-desktop" data-phone="<?= htmlspecialchars($wa_number) ?>" data-msg="<?= htmlspecialchars($msg) ?>" data-name="<?= htmlspecialchars($inv['customer_name']) ?>" style="width:18px; height:18px; cursor:pointer; accent-color:var(--primary);">
                        <?php endif; ?>
                    </td>
                    <td style="padding:15px 12px; vertical-align: middle;">
                        <div style="font-weight:700; color:var(--text-primary); font-size:15px; line-height:1.2;"><?= htmlspecialchars($inv['customer_name']) ?></div>
                        <div style="margin-top: 4px; display:flex; align-items:center; gap:8px;">
                            <?php if($is_grouped): ?>
                                <span class="badge" style="background:rgba(239, 68, 68, 0.1); color:#ef4444; border:1px solid rgba(239, 68, 68, 0.2); font-size:10px; border-radius:4px; padding:1px 6px; font-weight:700;">
                                    <i class="fas fa-clock"></i> <?= $inv['months_owed'] ?> Bln
                                </span>
                            <?php else: ?>
                                <span style="font-size:11px; color:var(--text-secondary); font-family:monospace; opacity:0.7;">INV-<?= str_pad($inv['id'], 5, "0", STR_PAD_LEFT) ?></span>
                            <?php endif; ?>
                            <span style="font-size:11px; color:var(--text-secondary); opacity:0.7;"><i class="fas fa-box" style="font-size:9px;"></i> <?= $package_display ?></span>
                        </div>
                    </td>
                    <td style="vertical-align: middle; font-size:13px; color:var(--text-secondary);">
                        <div style="font-weight:600; color:var(--text-primary);"><?= date('d/m/Y', strtotime($inv['due_date'])) ?></div>
                        <div style="font-size:10px; opacity:0.6;"><?= $is_grouped ? 'Awal Periode' : 'Jatuh Tempo' ?></div>
                    </td>
                    <td style="vertical-align: middle;">
                        <div style="font-weight:800; color:var(--stat-value-color); font-size:15px;">Rp <?= number_format($inv['amount'], 0, ',', '.') ?></div>
                    </td>
                    <td style="vertical-align: middle; color:var(--danger); font-size:12px; font-weight:600;">
                        <?= $inv['discount'] > 0 ? '-Rp ' . number_format($inv['discount'], 0, ',', '.') : '<span style="opacity:0.3;">—</span>' ?>
                    </td>
                    <td style="vertical-align: middle;">
                        <?php if($inv['status'] == 'Lunas'): ?>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span class="badge badge-glow-success" style="background:rgba(16, 185, 129, 0.15); color:#10b981; border:1px solid rgba(16, 185, 129, 0.3); padding:4px 12px; border-radius:50px; font-size:10px; font-weight:700;">
                                    <i class="fas fa-check-circle"></i> LUNAS
                                </span>
                                <?php 
                                    $check_owner_desk = $inv['created_by'] ?? 0;
                                    $check_coll_desk = $inv['collector_id'] ?? 0;
                                    
                                    $can_unpay_desk = false;
                                    if ($u_role === 'admin') $can_unpay_desk = true;
                                    elseif ($u_role === 'partner' && $check_owner_desk == $u_id) $can_unpay_desk = true;
                                    elseif ($u_role === 'collector' && $check_coll_desk == $u_id) $can_unpay_desk = true;

                                    if($can_unpay_desk): 
                                ?>
                                    <a href="index.php?page=admin_invoices&action=unpay&id=<?= $inv['id'] ?>" class="btn btn-xs btn-ghost" style="color:var(--danger); opacity:0.6; padding:2px 6px; font-size:9px;" title="Batalkan Pembayaran" onclick="return confirm('Yakin ingin membatalkan pembayaran ini?')">
                                        <i class="fas fa-undo"></i> Batalkan
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="badge badge-glow-danger" style="background:rgba(239, 68, 68, 0.15); color:#ef4444; border:1px solid rgba(239, 68, 68, 0.3); padding:4px 12px; border-radius:50px; font-size:10px; font-weight:700;">
                                <i class="fas fa-exclamation-circle"></i> TERHUTANG
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align: middle; padding-right:20px;">
                        <div style="display:flex; gap:6px; justify-content:flex-end;">
                            <?php if($inv['status'] != 'Lunas'): ?>
                                <?php if($view_mode !== 'isp_bill'): ?>
                                    <?php if($is_grouped && $inv['months_owed'] > 1): ?>
                                        <button onclick="showBulkPayModal(<?= $inv['cust_id'] ?>, '<?= addslashes($inv['customer_name']) ?>', <?= $inv['months_owed'] ?>, <?= $inv['amount'] / $inv['months_owed'] ?>, <?= $inv['amount'] ?>)" class="btn btn-sm btn-success" style="font-weight:800; padding:6px 14px; font-size:11px;">
                                            BAYAR
                                        </button>
                                    <?php else: ?>
                                        <a href="index.php?page=admin_invoices&action=mark_paid&id=<?= $inv['id'] ?>" class="btn btn-sm btn-success" style="font-weight:800; padding:6px 14px; font-size:11px;" onclick="return confirm('Tandai sudah dibayar?')">
                                            BAYAR
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="font-size:11px; font-weight:700; color:var(--text-secondary); opacity:0.6;"><i class="fas fa-lock"></i> Konfirmasi Admin</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                             
                            <?php if($inv['status'] != 'Lunas' && $wa_number): ?>
                                <button onclick="sendWAGateway('<?= $wa_number ?>', <?= htmlspecialchars(json_encode($msg)) ?>, 'https://api.whatsapp.com/send?phone=<?= $wa_number ?>&text=<?= $wa_text ?>', this)" class="btn btn-sm btn-ghost" title="Kirim WA" style="width:34px; height:34px; display:flex; align-items:center; justify-content:center; padding:0; background:rgba(37, 211, 102, 0.05); color:#25D366;"><i class="fab fa-whatsapp" style="font-size:15px;"></i></button>
                            <?php endif; ?>

                            <a href="index.php?page=admin_invoices&action=print&id=<?= $inv['id'] ?>" target="_blank" class="btn btn-sm btn-ghost" title="Print Nota" style="width:34px; height:34px; display:flex; align-items:center; justify-content:center; padding:0; background:rgba(255,255,255,0.05);"><i class="fas fa-print" style="font-size:13px;"></i></a>
                            
                            <?php 
                                $check_owner = $db->query("SELECT created_by FROM customers WHERE id = " . intval($inv['customer_id']))->fetchColumn();
                                $can_manage = ($u_role === 'admin') ? ($check_owner == $u_id || $check_owner == 0 || $check_owner === NULL) : ($check_owner == $u_id);
                                if($can_manage): 
                            ?>
                                <button onclick="InvoicesPage.showEditInvoice(<?= $inv['id'] ?>, <?= $inv['amount'] ?>, <?= $inv['discount'] ?? 0 ?>, '<?= $inv['due_date'] ?>')" class="btn btn-sm btn-ghost btn-edit-invoice" title="Edit" data-inv-id="<?= $inv['id'] ?>" data-inv-amount="<?= $inv['amount'] ?>" data-inv-discount="<?= $inv['discount'] ?? 0 ?>" data-inv-date="<?= $inv['due_date'] ?>" style="width:34px; height:34px; display:flex; align-items:center; justify-content:center; padding:0; background:rgba(245, 158, 11, 0.05); color:var(--warning);"><i class="fas fa-edit" style="font-size:13px;"></i></button>
                                <a href="index.php?page=admin_invoices&action=delete&id=<?= $inv['id'] ?>" class="btn btn-sm btn-ghost" title="Hapus" style="width:34px; height:34px; display:flex; align-items:center; justify-content:center; padding:0; background:rgba(239, 68, 68, 0.05); color:var(--danger);" onclick="return confirm('Hapus tagihan ini permanent?')"><i class="fas fa-trash" style="font-size:13px;"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Navigation -->
        <?php if($total_pages > 1): ?>
        <div style="display:flex; justify-content:center; gap:8px; margin:24px 0; flex-wrap:wrap;">
            <?php 
            $params = $_GET; 
            unset($params['p']); 
            $query_str = http_build_query($params);
            $base_url = "index.php?" . $query_str . "&p=";
            ?>
            
            <?php if($current_page > 1): ?>
                <a href="<?= $base_url . ($current_page - 1) ?>" class="btn btn-sm btn-ghost">&laquo;</a>
            <?php endif; ?>

            <?php 
            $start_p = max(1, $current_page - 2);
            $end_p = min($total_pages, $current_page + 2);
            for($i = $start_p; $i <= $end_p; $i++): 
            ?>
                <a href="<?= $base_url . $i ?>" class="btn btn-sm <?= $i == $current_page ? 'btn-primary' : 'btn-ghost' ?>" style="min-width:35px;"><?= $i ?></a>
            <?php endfor; ?>

            <?php if($current_page < $total_pages): ?>
                <a href="<?= $base_url . ($current_page + 1) ?>" class="btn btn-sm btn-ghost">&raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Floating Floating Broadcast Bar (Appears when items are selected) -->
<div id="floatingBroadcastBar" class="floating-broadcast-bar">
    <div style="display:flex; align-items:center; gap:12px;">
        <div style="background:rgba(255,255,255,0.2); width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
            <i class="fab fa-whatsapp" style="font-size:20px;"></i>
        </div>
        <div>
            <div id="waSelectedCountFloating" style="font-size:16px; font-weight:800;">0 Terpilih</div>
            <div id="waProgressTextFloating" style="font-size:11px; opacity:0.9;">Pesan tagihan siap dikirim</div>
        </div>
    </div>
    <div style="display:flex; gap:10px;">
        <button onclick="startMassWaWeb()" id="btnMassWa" class="btn" style="background:white; color:#10b981; font-weight:800; border-radius:50px; padding:8px 25px; border:none; font-size:13px; box-shadow:0 4px 15px rgba(0,0,0,0.1);">
            <i class="fas fa-paper-plane"></i> KIRIM SEKARANG
        </button>
        <button onclick="uncheckAllInvoices()" class="btn btn-ghost" style="color:white; border-color:rgba(255,255,255,0.3);"><i class="fas fa-times"></i></button>
    </div>
</div>

<style>
@media (max-width: 768px) {
    .invoices-desktop-table { display: none !important; }
    .invoices-mobile-container { display: block !important; }
}
</style>

<script>
    function updateWaSelectedCount() {
        let selectedItems = document.querySelectorAll('.cb-invoice:checked');
        let count = selectedItems.length;
        
        let floatingBar = document.getElementById('floatingBroadcastBar');
        let countDisplay = document.getElementById('waSelectedCountFloating');
        
        if(count > 0) {
            floatingBar.classList.add('active');
            countDisplay.innerText = count + ' Pelanggan Terpilih';
        } else {
            floatingBar.classList.remove('active');
        }
    }

    function uncheckAllInvoices() {
        document.querySelectorAll('.cb-invoice').forEach(cb => cb.checked = false);
        document.getElementById('checkAll').checked = false;
        if(document.getElementById('checkAll_mobile')) document.getElementById('checkAll_mobile').checked = false;
        updateWaSelectedCount();
    }

    document.querySelectorAll('.cb-invoice').forEach(cb => {
        cb.addEventListener('change', updateWaSelectedCount);
    });

    document.getElementById('checkAll').addEventListener('change', function() {
        document.querySelectorAll('.cb-desktop').forEach(cb => cb.checked = this.checked);
        updateWaSelectedCount();
    });
    if(document.getElementById('checkAll_mobile')) {
        document.getElementById('checkAll_mobile').addEventListener('change', function() {
            document.querySelectorAll('.cb-mobile').forEach(cb => cb.checked = this.checked);
            updateWaSelectedCount();
        });
    }

    // Function to send message via Gateway (Standardized to use Global WAApiProxy)
    async function sendWAGateway(phone, message, fallback, btn) {
        if (!btn) {
            // Background sends (e.g. from startMassWaWeb)
            try {
                const r = await fetch(WAApiProxy + 'send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ cid: WAGatewayCID, phone, message })
                });
                return await r.json();
            } catch (e) { return { error: true, message: e.toString() }; }
        }

        const old = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        try {
            const r = await fetch(WAApiProxy + 'send', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ cid: WAGatewayCID, phone, message })
            });
            const data = await r.json();
            if (data.error) throw new Error(data.message);
            
            btn.classList.add('btn-success');
            btn.innerHTML = '<i class="fas fa-check"></i>';
            setTimeout(() => { btn.innerHTML = old; btn.disabled = false; btn.classList.remove('btn-success'); }, 2000);
        } catch (e) {
            console.error('Gateway failed:', e);
            if (fallback) window.open(fallback, '_blank');
            else alert('Gagal mengirim: ' + e.message);
            btn.innerHTML = old; btn.disabled = false;
        }
    }

    async function startMassWaWeb() {
        let checkboxes = document.querySelectorAll('.cb-invoice:checked');
        if(checkboxes.length === 0) {
            alert('Pilih tagihan terlebih dahulu!');
            return;
        }

        if(!confirm('Kirim pesan otomatis ke ' + checkboxes.length + ' pelanggan?\n(Delay 10 detik per pesan)')) return;

        let btn = document.getElementById('btnMassWa');
        let progText = document.getElementById('waProgressTextFloating');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
        
        for(let i=0; i<checkboxes.length; i++) {
            let cb = checkboxes[i];
            let phone = cb.getAttribute('data-phone');
            let msg = cb.getAttribute('data-msg');
            let name = cb.getAttribute('data-name');
            
            progText.innerHTML = `Mengirim: <strong>${name}</strong> (${i+1}/${checkboxes.length})...`;
            
            const result = await sendWAGateway(phone, msg, null, null);
            
            if (result && !result.error) {
                cb.closest('.glass-panel')?.style.setProperty('border-color', '#25D366');
                cb.closest('tr')?.style.setProperty('background', 'rgba(37, 211, 102, 0.1)');
                cb.checked = false;
            } else {
                progText.innerHTML = `<span style="color:#ef4444;">Gagal mengirim ke ${name}. Mengalihkan ke manual...</span>`;
                window.open(`https://api.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(msg)}`, '_blank');
            }
            
            if (i < checkboxes.length - 1) {
                for(let c = 10; c > 0; c--) {
                    progText.innerHTML = `Jeda keamanan: <strong>${c} detik</strong> sebelum kirim berikutnya...`;
                    await new Promise(r => setTimeout(r, 1000));
                }
            }
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> KIRIM SEKARANG';
        progText.innerHTML = '<span style="color:#ffffff; font-weight:800;">Selesai! Seluruh pesan berhasil diproses.</span>';
        updateWaSelectedCount();
    }

    function showBulkInvoiceModal() {
        document.getElementById('bulkInvoiceModal').style.display = 'flex';
    }
    function hideBulkInvoiceModal() {
        document.getElementById('bulkInvoiceModal').style.display = 'none';
    }
    function showManualInvoiceModal() {
        document.getElementById('manualInvoiceModal').style.display = 'flex';
    }
    function hideManualInvoiceModal() {
        document.getElementById('manualInvoiceModal').style.display = 'none';
    }

    if (!window.InvoicesPage) window.InvoicesPage = {};
    (function(ns){
        ns.showEditInvoice = function(id, amount, discount, date) {
            if (window.location.protocol === 'https:') {
                const q = document.getElementById('qrcode'); if(q) q.innerHTML = '<div style="background:rgba(245,158,11,0.1); padding:15px; border-radius:10px; border:1px solid #f59e0b; color:#f59e0b; font-size:11px; text-align:left;">' +
                    '<h6 style="margin:0 0 5px; color:#f59e0b;"><i class="fas fa-shield-alt"></i> BLOKIR HTTPS</h6>' +
                    'Izin diperlukan:<br>1. Klik ikon <b>Gembok</b> di URL bar<br>2. Pilih <b>Site Settings</b><br>3. Cari <b>Insecure Content</b><br>4. Ubah ke <b>Allow</b><br>5. Refresh (F5)' +
                    '</div>';
            } else {
                const q = document.getElementById('qrcode'); if(q) q.innerHTML = '<div style="color:#ef4444; font-size:12px; font-weight:700;"><i class="fas fa-exclamation-triangle"></i> GATEWAY OFFLINE<br><span style="font-weight:400; opacity:0.7;">Harap nyalakan node server.js</span></div>';
            }
            const elId = document.getElementById('editInvId'); if(elId) elId.value = id;
            const am = document.getElementById('editInvAmount'); if(am) am.value = amount;
            const disc = document.getElementById('editInvDiscount'); if(disc) disc.value = discount;
            const dt = document.getElementById('editInvDate'); if(dt) dt.value = date;
            const title = document.getElementById('editTitle'); if(title) title.innerText = 'Edit INV-' + String(id).padStart(5, '0');
            const modal = document.getElementById('editInvoiceModal'); if(modal) modal.style.display = 'flex';
        };
        ns.hideEditInvoice = function(){ const modal = document.getElementById('editInvoiceModal'); if(modal) modal.style.display = 'none'; };
    })(window.InvoicesPage);

    let currentMonthlyFee = 0;
    function showBulkPayModal(custId, custName, totalMonths, monthlyFee, totalAmt) {
        currentMonthlyFee = monthlyFee;
        document.getElementById('bulkCustId').value = custId;
        document.getElementById('bulkCustName').innerText = custName;
        document.getElementById('bulkTotalMonths').innerText = totalMonths;
        document.getElementById('bulkMonthInput').max = totalMonths;
        document.getElementById('bulkMonthInput').value = totalMonths;
        
        updateBulkTotal();
        document.getElementById('bulkPayModal').style.display = 'flex';
    }

    function hideBulkPayModal() {
        document.getElementById('bulkPayModal').style.display = 'none';
    }

    function updateBulkTotal() {
        const months = parseInt(document.getElementById('bulkMonthInput').value) || 1;
        const total = months * currentMonthlyFee;
        const formatted = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(total).replace('IDR', 'Rp');
        document.getElementById('bulkTotalDisplay').innerText = formatted;
    }

    function addManualItem() {
        const container = document.getElementById('itemizedList');
        const row = document.createElement('div');
        row.style.display = 'grid';
        row.style.gridTemplateColumns = '1fr 140px 40px';
        row.style.gap = '10px';
        row.style.marginBottom = '10px';
        row.innerHTML = `
            <input type="text" name="item_desc[]" class="form-control" placeholder="Deskripsi" required>
            <input type="number" name="item_amount[]" class="form-control" placeholder="Rp" required style="font-weight:700;">
            <button type="button" class="btn btn-ghost" onclick="this.parentElement.remove()" style="color:var(--danger); padding:0;"><i class="fas fa-times"></i></button>
        `;
        container.appendChild(row);
    }
</script>

<!-- Modal Bulk Pay Arrears -->
<div id="bulkPayModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px; border-top:4px solid var(--success);">
        <h3 style="margin-bottom:10px; font-weight:800;"><i class="fas fa-layer-group text-success"></i> Pelunasan Tunggakan</h3>
        <p style="font-size:14px; color:var(--text-secondary); margin-bottom:20px;">Bayar sebagian atau seluruh tunggakan untuk <strong><span id="bulkCustName"></span></strong>.</p>
        
        <form action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST">
            <input type="hidden" name="customer_id" id="bulkCustId">
            
            <div class="form-group mb-3">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Berapa Bulan?</label>
                <div style="display:flex; align-items:center; gap:12px;">
                    <input type="number" name="num_months" id="bulkMonthInput" class="form-control" value="1" min="1" oninput="updateBulkTotal()" style="font-size:22px; font-weight:800; text-align:center; height:50px;">
                    <div style="font-weight:600; color:var(--text-secondary);">Dari <span id="bulkTotalMonths"></span> Bln</div>
                </div>
            </div>

            <div style="padding:15px; background:rgba(16, 185, 129, 0.05); border:1px solid rgba(16, 185, 129, 0.2); border-radius:12px; margin-bottom:20px;">
                <div style="font-size:11px; color:var(--text-secondary); font-weight:700; margin-bottom:4px;">TOTAL BAYAR:</div>
                <div style="font-size:24px; font-weight:800; color:var(--success);" id="bulkTotalDisplay">Rp 0</div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-ghost" onclick="hideBulkPayModal()">Batal</button>
                <button type="submit" class="btn btn-success" style="font-weight:800; padding:10px 25px;">PROSES PEMBAYARAN</button>
            </div>
        </form>
    </div>
</div>
<div id="editInvoiceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px; border-top:4px solid var(--warning);">
        <h3 id="editTitle" style="margin-bottom:20px; font-weight:800;"><i class="fas fa-edit text-warning"></i> Edit Tagihan</h3>
        <form action="index.php?page=admin_invoices&action=edit_post" method="POST">
            <input type="hidden" name="id" id="editInvId">
            <div class="form-group mb-3">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Nominal Tagihan (Rp)</label>
                <input type="number" name="amount" id="editInvAmount" class="form-control" required style="font-size:18px; font-weight:700; height:45px;">
            </div>
            <div class="form-group mb-3">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Potongan / Restitusi (Rp)</label>
                <input type="number" name="discount" id="editInvDiscount" class="form-control" value="0" style="color:var(--danger); font-weight:700;">
            </div>
            <div class="form-group mb-4">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Jatuh Tempo</label>
                <input type="date" name="due_date" id="editInvDate" class="form-control" required>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-ghost" onclick="InvoicesPage.hideEditInvoice()">Batal</button>
                <button type="submit" class="btn btn-warning" style="font-weight:800; padding:10px 25px;">SIMPAN PERUBAHAN</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Bulk Create -->
<div id="bulkInvoiceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:450px; padding:28px; margin:20px; border-top:4px solid var(--primary);">
        <h3 style="margin-bottom:15px; font-weight:800;"><i class="fas fa-magic text-primary"></i> Tagih Masal (<?= $filter_type === 'partner' ? 'Mitra' : 'Retail' ?>)</h3>
        <p style="font-size:13px; color:var(--text-secondary); line-height:1.6; margin-bottom:24px; background:rgba(var(--primary-rgb), 0.05); padding:12px; border-radius:10px;">
            Fitur ini akan membuat tagihan otomatis untuk <strong>SEMUA <?= $filter_type === 'partner' ? 'Mitra' : 'Pelanggan' ?> aktif</strong> yang belum memiliki tagihan pada bulan yang dipilih.
        </p>
        <form action="index.php?page=admin_invoices&action=create_auto_bulk" method="POST">
            <input type="hidden" name="filter_type" value="<?= htmlspecialchars($filter_type ?: 'customer') ?>">
            <div class="form-group mb-3">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">UNTUK BULAN / PERIODE</label>
                <input type="month" name="due_month" class="form-control" value="<?= date('Y-m') ?>" required style="height:45px; font-size:16px; font-weight:700;">
            </div>
            <div class="form-group mb-4">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">TANGGAL JATUH TEMPO</label>
                <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-20') ?>" required style="height:45px;">
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn btn-ghost" onclick="hideBulkInvoiceModal()">Batal</button>
                <button type="submit" class="btn btn-primary" style="font-weight:800; padding:10px 25px;">MULAI PROSES TAGIH</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Manual Create -->
<div id="manualInvoiceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); backdrop-filter:blur(5px); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:500px; padding:28px; margin:20px; border-top:4px solid var(--primary);">
        <h3 style="margin-bottom:20px; font-weight:800;"><i class="fas fa-plus text-primary"></i> Tagihan Manual</h3>
        <form action="index.php?page=admin_invoices&action=create_itemized" method="POST">
            <div class="form-group mb-4">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Pilih Pelanggan (<?= strtoupper($filter_type ?: 'customer') ?>)</label>
                <select name="customer_id" class="form-control" required style="height:45px; font-weight:600;">
                    <option value="">- Cari Nama Pelanggan -</option>
                    <?php 
                        $cust_list = $db->query("SELECT id, name, type FROM customers c WHERE type = " . $db->quote($filter_type ?: 'customer') . " $scope_where ORDER BY name ASC")->fetchAll();
                        foreach($cust_list as $cl): 
                    ?>
                        <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-4">
                <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase;">Tanggal Jatuh Tempo</label>
                <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d') ?>" required style="height:45px;">
            </div>
            
            <label style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; margin-bottom:10px; display:block;">Rincian Item</label>
            <div id="itemizedList">
                <div style="display:grid; grid-template-columns: 1fr 140px 40px; gap:10px; margin-bottom:10px;">
                    <input type="text" name="item_desc[]" class="form-control" placeholder="Deskripsi (ex: Paket Internet)" required>
                    <input type="number" name="item_amount[]" class="form-control" placeholder="Rp" required style="font-weight:700;">
                    <div style="width:40px;"></div>
                </div>
            </div>
            
            <button type="button" onclick="addManualItem()" class="btn btn-ghost btn-sm" style="margin-top:10px; font-size:11px;"><i class="fas fa-plus"></i> Tambah Baris Item</button>
            
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:30px;">
                <button type="button" class="btn btn-ghost" onclick="hideManualInvoiceModal()">Batal</button>
                <button type="submit" class="btn btn-primary" style="font-weight:800; padding:10px 30px;">SIMPAN TAGIHAN</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>
