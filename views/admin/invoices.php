<?php
$action = $_GET['action'] ?? 'list';
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'guest';
$id = intval($_GET['id'] ?? 0);

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
<div class="ui-card mb-5 flex flex-col gap-3 p-4 text-sm sm:flex-row sm:items-center sm:justify-between">
    <div>
        <span class="font-semibold text-signal">Berhasil.</span> Invoice <strong><?= htmlspecialchars($success_data['name']) ?></strong> lunas.
    </div>
    <button onclick="sendWAGateway('<?= $wa_num_paid ?>', <?= htmlspecialchars(json_encode($success_data['wa_text'])) ?>, '<?= $success_data['wa_link'] ?>', this)" class="ui-btn ui-btn-sm ui-btn-wa w-full sm:w-auto"><i class="fab fa-whatsapp"></i> Kirim kuitansi WhatsApp</button>
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
    } elseif ($ref === 'partner_collection') {
        header("Location: index.php?page=partner_collection&msg=deleted");
    } else {
        header("Location: index.php?page=admin_invoices&msg=deleted");
    }
    exit;
}

if ($action === 'edit_post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
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
        cash_tag_payment($db, (int)$tenant_id, (int)$db->lastInsertId(), cash_posted_account($db, (int)$tenant_id));
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
    $pay_account = cash_posted_account($db, (int)$tenant_id);
    foreach ($unpaid as $inv) {
        $net_amount = $inv['amount'] - ($inv['discount'] ?? 0);
        $db->prepare("UPDATE invoices SET status = 'Lunas' WHERE id = ? AND tenant_id = ?")->execute([$inv['id'], $tenant_id]);
        $db->prepare("INSERT INTO payments (invoice_id, amount, received_by, payment_date, tenant_id) VALUES (?, ?, ?, ?, ?)")->execute([$inv['id'], $net_amount, $receiver_id, $payment_date, $tenant_id]);
        cash_tag_payment($db, (int)$tenant_id, (int)$db->lastInsertId(), $pay_account);
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
            echo "<div class='ui-card p-10 text-center'>
                    <h2 class='m-0 text-xl font-bold text-danger'>Akses ditolak</h2>
                    <p class='mt-2 mb-0 text-sm text-muted-foreground'>Anda hanya diperbolehkan mencetak nota untuk pelanggan Anda sendiri atau tagihan untuk Anda sendiri.</p>
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
<div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Dibatalkan.</span> Pembayaran berhasil dibatalkan. Tagihan kembali menjadi <strong>Belum lunas</strong>.</div>
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
    
    // Exclude admin_manual invoices (separate billing system)
    $admin_manual_where = " AND (i.created_via IS NULL OR i.created_via NOT IN ('admin_manual', 'quick', 'external')) ";
    
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
<div>
    <!-- Partner tabs -->
    <?php if ($u_role === 'partner'): ?>
    <?php
        $tenant_id_p = $_SESSION['tenant_id'] ?? 1;
        $partner_cid = $db->query("SELECT customer_id FROM users WHERE id = $u_id AND tenant_id = $tenant_id_p")->fetchColumn() ?: 0;
        $unpaid_personal = $db->query("SELECT COUNT(*) FROM invoices WHERE customer_id = $partner_cid AND status = 'Belum Lunas' AND tenant_id = $tenant_id_p")->fetchColumn();
    ?>
    <div class="mb-5 flex w-fit flex-wrap gap-1 rounded-md bg-muted p-1">
        <a href="index.php?page=admin_invoices&view_mode=customers" class="rounded-sm px-3 py-1.5 text-sm no-underline <?= $view_mode === 'customers' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>"<?= $view_mode === 'customers' ? ' aria-current="page"' : '' ?>>Tagihan pelanggan</a>
        <a href="index.php?page=admin_invoices&view_mode=isp_bill" class="inline-flex items-center gap-2 rounded-sm px-3 py-1.5 text-sm no-underline <?= $view_mode === 'isp_bill' ? 'bg-card font-semibold text-foreground shadow-card' : 'font-medium text-muted-foreground hover:text-foreground' ?>"<?= $view_mode === 'isp_bill' ? ' aria-current="page"' : '' ?>>
            Kewajiban ke ISP induk
            <?php if ($unpaid_personal > 0): ?>
                <span class="ui-badge ui-badge-danger"><?= $unpaid_personal ?></span>
            <?php endif; ?>
        </a>
    </div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">
                <?php
                    if ($view_mode === 'isp_bill') echo 'Nota kewajiban ke ISP';
                    elseif ($filter_status === 'belum') echo 'Manajemen tunggakan';
                    else echo 'Daftar tagihan';
                ?>
            </h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">
                <?php
                    if ($view_mode === 'isp_bill') echo 'Daftar tagihan atau biaya langganan mitra ke ISP pusat';
                    elseif ($filter_type === 'partner') echo 'Kemitraan dan B2B';
                    else echo 'Layanan retail / rumahan';
                ?>, periode <?= date('m/Y') ?>.
            </p>
        </div>
        <?php if ($u_role !== 'collector' && $view_mode !== 'isp_bill'): ?>
        <div class="flex w-full flex-wrap gap-2 sm:w-auto">
            <button type="button" class="ui-btn ui-btn-outline w-full sm:w-auto" onclick="showManualInvoiceModal()"><i class="fas fa-plus"></i> Manual</button>
            <button type="button" class="ui-btn ui-btn-primary w-full sm:w-auto" onclick="showBulkInvoiceModal()">Tagih masal</button>
        </div>
        <?php endif; ?>
    </div>

    <div class="mb-5 flex w-fit flex-wrap gap-1 rounded-md bg-muted p-1">
        <a href="index.php?page=admin_customers" class="rounded-sm px-3 py-1.5 text-sm font-medium text-muted-foreground no-underline hover:text-foreground">Pelanggan</a>
        <a href="index.php?page=admin_invoices" class="rounded-sm bg-card px-3 py-1.5 text-sm font-semibold text-foreground no-underline shadow-card" aria-current="page">Tagihan</a>
        <a href="index.php?page=admin_reports" class="rounded-sm px-3 py-1.5 text-sm font-medium text-muted-foreground no-underline hover:text-foreground">Laporan</a>
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

    <!-- Stat tiles -->
    <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="ui-card p-4">
            <div class="text-xs font-medium text-muted-foreground">Total tagihan</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= number_format($stats['total'], 0, ',', '.') ?></div>
        </div>
        <div class="ui-card p-4">
            <div class="text-xs font-medium text-muted-foreground">Terbayar (lunas)</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums text-signal">Rp <?= number_format($stats['amt_lunas'], 0, ',', '.') ?></div>
        </div>
        <div class="ui-card p-4">
            <div class="text-xs font-medium text-muted-foreground">Piutang (belum lunas)</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums text-danger">Rp <?= number_format($stats['amt_belum'], 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="ui-card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_180px_180px_auto] lg:items-end">
        <input type="hidden" name="page" value="admin_invoices">
        <input type="hidden" name="filter_type" value="<?= htmlspecialchars($filter_type) ?>">

        <div class="block sm:col-span-2 lg:col-span-1">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Periode jatuh tempo</span>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                <span class="shrink-0 text-xs text-muted-foreground">sampai</span>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
            </div>
        </div>

        <label class="block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Status</span>
            <select name="filter_status" class="form-control">
                <option value="">Semua status</option>
                <option value="lunas" <?= $filter_status === 'lunas' ? 'selected' : '' ?>>Lunas</option>
                <option value="belum" <?= $filter_status === 'belum' ? 'selected' : '' ?>>Belum lunas</option>
            </select>
        </label>

        <?php if ($u_role === 'admin'): ?>
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Collector</span>
            <select name="filter_collector" class="form-control">
                <option value="">Semua collector</option>
                <?php foreach($collectors as $coll): ?>
                    <option value="<?= $coll['id'] ?>" <?= $filter_collector == $coll['id'] ? 'selected' : '' ?>><?= htmlspecialchars($coll['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php else: ?>
        <div class="hidden lg:block"></div>
        <?php endif; ?>

        <div class="flex gap-2">
            <button type="submit" class="ui-btn ui-btn-primary w-full sm:w-auto">Terapkan</button>
            <?php if($date_from || $date_to || $filter_status || $filter_collector): ?>
                <a href="index.php?page=admin_invoices&filter_type=<?= $filter_type ?>" class="ui-btn ui-btn-outline">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <section class="ui-card overflow-hidden">

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
            $count_q = "SELECT COUNT(DISTINCT c.id) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.tenant_id = $tenant_id $date_where $status_where $collector_where $scope_where $type_where $admin_manual_where";
        } else {
            $count_q = "SELECT COUNT(*) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE i.tenant_id = $tenant_id $date_where $status_where $collector_where $scope_where $type_where $admin_manual_where";
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
               WHERE i.tenant_id = $tenant_id $date_where $status_where $collector_where $scope_where $type_where $admin_manual_where
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
               WHERE i.tenant_id = $tenant_id $date_where $status_where $collector_where $scope_where $type_where $admin_manual_where
                ORDER BY i.id DESC
                LIMIT $items_per_page OFFSET $offset
            ")->fetchAll();
        }
    ?>

        <!-- Mobile card view (hidden on desktop) -->
        <div class="invoices-mobile-container p-3 md:hidden">
            <div class="mb-3 flex items-center gap-2 px-1">
                <input type="checkbox" id="checkAll_mobile" class="h-4 w-4 accent-primary"> <label for="checkAll_mobile" class="text-sm text-muted-foreground">Pilih semua</label>
            </div>
            <?php if (empty($invoices)): ?>
                <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada tagihan untuk filter ini.</div>
            <?php endif; ?>
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
        <div class="glass-panel ui-card relative mb-3 p-4">
            <?php if($inv['status'] != 'Lunas' && $view_mode !== 'isp_bill'): ?>
                <div class="absolute right-3 top-3">
                    <input type="checkbox" class="cb-invoice cb-mobile h-5 w-5 accent-primary" data-phone="<?= htmlspecialchars($wa_number) ?>" data-msg="<?= htmlspecialchars($msg) ?>" data-name="<?= htmlspecialchars($inv['customer_name']) ?>">
                </div>
            <?php endif; ?>

            <div class="pr-8">
                <div class="text-sm font-semibold"><?= htmlspecialchars($inv['customer_name']) ?></div>
                <div class="mt-0.5 text-xs text-muted-foreground"><?= $package_display ?> · #<?= $cust_id_display ?></div>
                <div class="mt-1">
                    <?= render_wa_status_badge($db, $inv['id']) ?>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3 rounded-md bg-muted p-3">
                <div>
                    <div class="text-xs text-muted-foreground">Periode</div>
                    <div class="text-sm font-medium"><?= $inv_month ?></div>
                </div>
                <div class="text-right">
                    <div class="text-xs text-muted-foreground">Total tagihan</div>
                    <div class="text-sm font-bold tabular-nums">Rp <?= number_format($inv['amount'], 0, ',', '.') ?></div>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <?php if($inv['status'] != 'Lunas'): ?>
                    <?php if($view_mode !== 'isp_bill'): ?>
                        <?php if($is_grouped && $inv['months_owed'] > 1): ?>
                            <button type="button" onclick="showBulkPayModal(<?= $inv['cust_id'] ?>, '<?= addslashes($inv['customer_name']) ?>', <?= $inv['months_owed'] ?>, <?= $inv['amount'] / $inv['months_owed'] ?>, <?= $inv['amount'] ?>)" class="ui-btn ui-btn-sm ui-btn-primary flex-1">
                                Bayar (<?= $inv['months_owed'] ?>)
                            </button>
                        <?php else: ?>
                            <a data-method="post" href="index.php?page=admin_invoices&action=mark_paid&id=<?= $inv['id'] ?>" class="ui-btn ui-btn-sm ui-btn-primary flex-1" onclick="return confirm('Tandai tagihan sudah dibayar?')">
                                Bayar
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="ui-badge ui-badge-accent flex-1 justify-center">Menunggu konfirmasi admin</span>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="flex flex-1 items-center gap-2">
                        <span class="ui-badge ui-badge-signal">Lunas</span>
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
                            <a data-method="post" href="index.php?page=admin_invoices&action=unpay&id=<?= $inv['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Batalkan pembayaran" onclick="return confirm('Batalkan status lunas?')">
                                <i class="fas fa-undo"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <a href="index.php?page=admin_invoices&action=print&id=<?= $inv['id'] ?>" target="_blank" class="ui-btn ui-btn-sm ui-btn-outline" title="Cetak nota">
                    <i class="fas fa-print"></i>
                </a>

                <?php if($inv['status'] != 'Lunas' && $wa_number): ?>
                    <button type="button" onclick="sendWAGateway('<?= $wa_number ?>', <?= htmlspecialchars(json_encode($msg)) ?>, 'https://api.whatsapp.com/send?phone=<?= $wa_number ?>&text=<?= $wa_text ?>', this)" class="ui-btn ui-btn-sm ui-btn-wa" title="Kirim WhatsApp">
                        <i class="fab fa-whatsapp"></i>
                    </button>
                <?php endif; ?>

                <?php if($can_manage_item): ?>
                    <button type="button" onclick="InvoicesPage.showEditInvoice(<?= $inv['id'] ?>, <?= $inv['amount'] ?>, <?= $inv['discount'] ?? 0 ?>, '<?= $inv['due_date'] ?>')" class="ui-btn ui-btn-sm ui-btn-outline btn-edit-invoice" title="Edit" data-inv-id="<?= $inv['id'] ?>" data-inv-amount="<?= $inv['amount'] ?>" data-inv-discount="<?= $inv['discount'] ?? 0 ?>" data-inv-date="<?= $inv['due_date'] ?>">
                        <i class="fas fa-edit"></i>
                    </button>
                    <a data-method="post" href="index.php?page=admin_invoices&action=delete&id=<?= $inv['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus" onclick="return confirm('Hapus tagihan ini?')">
                        <i class="fas fa-trash"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Desktop table (hidden on mobile) -->
    <div class="invoices-desktop-table hidden overflow-x-auto md:block">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="w-10 px-4 py-2.5 font-semibold sm:px-5"><input type="checkbox" id="checkAll" class="h-4 w-4 accent-primary"></th>
                    <th class="px-3 py-2.5 font-semibold">Pelanggan</th>
                    <th class="px-3 py-2.5 font-semibold">Jatuh tempo</th>
                    <th class="px-3 py-2.5 text-right font-semibold">Nominal</th>
                    <th class="px-3 py-2.5 text-right font-semibold">Potongan</th>
                    <th class="px-3 py-2.5 font-semibold">Status</th>
                    <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
                </tr>
            </thead>
            <tbody id="invoiceTableBody">
                <?php if (empty($invoices)): ?>
                <tr class="border-t border-solid border-border"><td colspan="7" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada tagihan untuk filter ini.</td></tr>
                <?php endif; ?>
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
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 text-center sm:px-5">
                        <?php if($inv['status'] != 'Lunas' && $wa_number && $view_mode !== 'isp_bill'): ?>
                            <input type="checkbox" class="cb-invoice cb-desktop h-4 w-4 cursor-pointer accent-primary" data-phone="<?= htmlspecialchars($wa_number) ?>" data-msg="<?= htmlspecialchars($msg) ?>" data-name="<?= htmlspecialchars($inv['customer_name']) ?>">
                        <?php endif; ?>
                    </td>
                    <td class="px-3 py-3">
                        <div class="text-sm font-semibold"><?= htmlspecialchars($inv['customer_name']) ?></div>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            <?php if($is_grouped): ?>
                                <span class="ui-badge ui-badge-danger"><?= $inv['months_owed'] ?> bln</span>
                            <?php else: ?>
                                <span class="tabular-nums">INV-<?= str_pad($inv['id'], 5, "0", STR_PAD_LEFT) ?></span>
                            <?php endif; ?>
                            <span><?= $package_display ?></span>
                        </div>
                        <div class="mt-0.5">
                            <?= render_wa_status_badge($db, $inv['id']) ?>
                        </div>
                    </td>
                    <td class="px-3 py-3 whitespace-nowrap">
                        <div class="text-sm font-medium tabular-nums"><?= date('d/m/Y', strtotime($inv['due_date'])) ?></div>
                        <div class="text-xs text-muted-foreground"><?= $is_grouped ? 'Awal periode' : 'Jatuh tempo' ?></div>
                    </td>
                    <td class="px-3 py-3 text-right font-bold tabular-nums whitespace-nowrap">Rp <?= number_format($inv['amount'], 0, ',', '.') ?></td>
                    <td class="px-3 py-3 text-right text-xs font-medium tabular-nums whitespace-nowrap text-danger">
                        <?= $inv['discount'] > 0 ? '-Rp ' . number_format($inv['discount'], 0, ',', '.') : '<span class="text-muted-foreground/70">—</span>' ?>
                    </td>
                    <td class="px-3 py-3">
                        <?php if($inv['status'] == 'Lunas'): ?>
                            <div class="flex items-center gap-2">
                                <span class="ui-badge ui-badge-signal">Lunas</span>
                                <?php
                                    $check_owner_desk = $inv['created_by'] ?? 0;
                                    $check_coll_desk = $inv['collector_id'] ?? 0;

                                    $can_unpay_desk = false;
                                    if ($u_role === 'admin') $can_unpay_desk = true;
                                    elseif ($u_role === 'partner' && $check_owner_desk == $u_id) $can_unpay_desk = true;
                                    elseif ($u_role === 'collector' && $check_coll_desk == $u_id) $can_unpay_desk = true;

                                    if($can_unpay_desk):
                                ?>
                                    <a data-method="post" href="index.php?page=admin_invoices&action=unpay&id=<?= $inv['id'] ?>" class="text-xs text-danger no-underline hover:underline whitespace-nowrap" title="Batalkan pembayaran" onclick="return confirm('Yakin ingin membatalkan pembayaran ini?')">
                                        <i class="fas fa-undo"></i> Batalkan
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <span class="ui-badge ui-badge-danger">Belum lunas</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 sm:px-5">
                        <div class="invoice-desktop-actions flex items-center justify-end gap-1.5">
                            <?php if($inv['status'] != 'Lunas'): ?>
                                <?php if($view_mode !== 'isp_bill'): ?>
                                    <?php if($is_grouped && $inv['months_owed'] > 1): ?>
                                        <button type="button" onclick="showBulkPayModal(<?= $inv['cust_id'] ?>, '<?= addslashes($inv['customer_name']) ?>', <?= $inv['months_owed'] ?>, <?= $inv['amount'] / $inv['months_owed'] ?>, <?= $inv['amount'] ?>)" class="ui-btn ui-btn-sm ui-btn-primary" title="Tandai lunas">
                                            Bayar
                                        </button>
                                    <?php else: ?>
                                        <a data-method="post" href="index.php?page=admin_invoices&action=mark_paid&id=<?= $inv['id'] ?>" class="ui-btn ui-btn-sm ui-btn-primary" title="Tandai lunas" onclick="return confirm('Tandai sudah dibayar?')">
                                            Bayar
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="ui-badge ui-badge-accent">Konfirmasi admin</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if($inv['status'] != 'Lunas' && $wa_number): ?>
                                <button type="button" onclick="sendWAGateway('<?= $wa_number ?>', <?= htmlspecialchars(json_encode($msg)) ?>, 'https://api.whatsapp.com/send?phone=<?= $wa_number ?>&text=<?= $wa_text ?>', this)" class="ui-btn ui-btn-sm ui-btn-wa" title="Kirim WhatsApp"><i class="fab fa-whatsapp"></i></button>
                            <?php endif; ?>

                            <a href="index.php?page=admin_invoices&action=print&id=<?= $inv['id'] ?>" target="_blank" class="ui-btn ui-btn-sm ui-btn-outline" title="Cetak nota"><i class="fas fa-print"></i></a>

                            <?php
                                $check_owner = $db->query("SELECT created_by FROM customers WHERE id = " . intval($inv['customer_id']))->fetchColumn();
                                $can_manage = ($u_role === 'admin') ? ($check_owner == $u_id || $check_owner == 0 || $check_owner === NULL) : ($check_owner == $u_id);
                                if($can_manage):
                            ?>
                                <button type="button" onclick="InvoicesPage.showEditInvoice(<?= $inv['id'] ?>, <?= $inv['amount'] ?>, <?= $inv['discount'] ?? 0 ?>, '<?= $inv['due_date'] ?>')" class="ui-btn ui-btn-sm ui-btn-outline btn-edit-invoice" title="Edit" data-inv-id="<?= $inv['id'] ?>" data-inv-amount="<?= $inv['amount'] ?>" data-inv-discount="<?= $inv['discount'] ?? 0 ?>" data-inv-date="<?= $inv['due_date'] ?>"><i class="fas fa-edit"></i></button>
                                <a data-method="post" href="index.php?page=admin_invoices&action=delete&id=<?= $inv['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus" onclick="return confirm('Hapus tagihan ini permanent?')"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
        <div class="flex flex-wrap items-center justify-center gap-1.5 border-t border-solid border-border px-4 py-3">
            <?php
            $params = $_GET;
            unset($params['p']);
            $query_str = http_build_query($params);
            $base_url = "index.php?" . $query_str . "&p=";
            ?>

            <?php if($current_page > 1): ?>
                <a href="<?= $base_url . ($current_page - 1) ?>" class="ui-btn ui-btn-sm ui-btn-outline" aria-label="Sebelumnya">&laquo;</a>
            <?php endif; ?>

            <?php
            $start_p = max(1, $current_page - 2);
            $end_p = min($total_pages, $current_page + 2);
            for($i = $start_p; $i <= $end_p; $i++):
            ?>
                <a href="<?= $base_url . $i ?>" class="ui-btn ui-btn-sm <?= $i == $current_page ? 'ui-btn-primary' : 'ui-btn-outline' ?>"<?= $i == $current_page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
            <?php endfor; ?>

            <?php if($current_page < $total_pages): ?>
                <a href="<?= $base_url . ($current_page + 1) ?>" class="ui-btn ui-btn-sm ui-btn-outline" aria-label="Berikutnya">&raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
</div>

<!-- Floating broadcast bar (appears when items are selected) -->
<div id="floatingBroadcastBar" class="floating-broadcast-bar !rounded-md !border-0 !bg-primary !shadow-lift !backdrop-blur-none">
    <div class="flex items-center gap-3">
        <i class="fab fa-whatsapp text-xl"></i>
        <div>
            <div id="waSelectedCountFloating" class="text-sm font-bold">0 terpilih</div>
            <div id="waProgressTextFloating" class="text-xs text-white/70">Pesan tagihan siap dikirim</div>
        </div>
    </div>
    <div class="flex gap-2">
        <button type="button" onclick="startMassWaWeb()" id="btnMassWa" class="ui-btn ui-btn-sm ui-btn-wa">
            <i class="fas fa-paper-plane"></i> Kirim sekarang
        </button>
        <button type="button" onclick="uncheckAllInvoices()" class="ui-btn ui-btn-sm border-white/10 bg-white/10 text-white hover:bg-white/10" aria-label="Batal pilih"><i class="fas fa-times"></i></button>
    </div>
</div>

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
<div id="bulkPayModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <div>
                <h3 class="m-0 text-lg font-bold">Pelunasan tunggakan</h3>
                <p class="m-0 mt-1 text-sm text-muted-foreground">Bayar sebagian atau seluruh tunggakan untuk <strong><span id="bulkCustName"></span></strong>.</p>
            </div>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="hideBulkPayModal()" aria-label="Tutup">✕</button>
        </div>

        <form action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="customer_id" id="bulkCustId">

            <?php if (($_SESSION['user_role'] ?? '') === 'admin'): $pay_accounts = cash_company_accounts($db, (int)($_SESSION['tenant_id'] ?? 1)); $pay_last = intval($_SESSION['cash_last_account'] ?? 0); ?>
            <label class="mb-4 block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Uang masuk ke</span>
                <select name="account_id" class="form-control">
                    <?php foreach ($pay_accounts as $pa): ?><option value="<?= intval($pa['id']) ?>" <?= ($pay_last ? $pay_last === intval($pa['id']) : $pa['is_default']) ? 'selected' : '' ?>><?= htmlspecialchars($pa['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <?php endif; ?>

            <label class="mb-4 block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Berapa bulan?</span>
                <div class="flex items-center gap-3">
                    <input type="number" name="num_months" id="bulkMonthInput" class="form-control text-center text-lg font-bold tabular-nums" value="1" min="1" oninput="updateBulkTotal()">
                    <span class="shrink-0 text-sm text-muted-foreground">dari <span id="bulkTotalMonths"></span> bulan</span>
                </div>
            </label>

            <div class="mb-5 rounded-md bg-muted p-4">
                <div class="text-xs font-medium text-muted-foreground">Total bayar</div>
                <div class="mt-1 text-2xl font-extrabold tabular-nums text-signal" id="bulkTotalDisplay">Rp 0</div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="hideBulkPayModal()">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Proses pembayaran</button>
            </div>
        </form>
    </div>
</div>
<div id="editInvoiceModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 id="editTitle" class="m-0 text-lg font-bold">Edit tagihan</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="InvoicesPage.hideEditInvoice()" aria-label="Tutup">✕</button>
        </div>
        <form action="index.php?page=admin_invoices&action=edit_post" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="id" id="editInvId">
            <label class="mb-4 block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Nominal tagihan (Rp)</span>
                <input type="number" name="amount" id="editInvAmount" class="form-control font-semibold tabular-nums" required>
            </label>
            <label class="mb-4 block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Potongan / restitusi (Rp)</span>
                <input type="number" name="discount" id="editInvDiscount" class="form-control tabular-nums" value="0">
            </label>
            <label class="mb-4 block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Jatuh tempo</span>
                <input type="date" name="due_date" id="editInvDate" class="form-control" required>
            </label>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="InvoicesPage.hideEditInvoice()">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Simpan perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal bulk create -->
<div id="bulkInvoiceModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <div>
                <h3 class="m-0 text-lg font-bold">Tagih masal (<?= $filter_type === 'partner' ? 'mitra' : 'retail' ?>)</h3>
                <p class="m-0 mt-1 text-sm text-muted-foreground">Membuat tagihan otomatis untuk <strong>semua <?= $filter_type === 'partner' ? 'mitra' : 'pelanggan' ?> aktif</strong> yang belum memiliki tagihan pada bulan yang dipilih.</p>
            </div>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="hideBulkInvoiceModal()" aria-label="Tutup">✕</button>
        </div>
        <form action="index.php?page=admin_invoices&action=create_auto_bulk" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="filter_type" value="<?= htmlspecialchars($filter_type ?: 'customer') ?>">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Untuk bulan / periode</span>
                    <input type="month" name="due_month" class="form-control" value="<?= date('Y-m') ?>" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal jatuh tempo</span>
                    <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-20') ?>" required>
                </label>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="hideBulkInvoiceModal()">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Mulai proses tagih</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal manual create -->
<div id="manualInvoiceModal" class="fixed inset-0 z-[1000] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-lg p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 class="m-0 text-lg font-bold">Tagihan manual</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="hideManualInvoiceModal()" aria-label="Tutup">✕</button>
        </div>
        <form action="index.php?page=admin_invoices&action=create_itemized" method="POST">
<?= csrf_field() ?>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Pilih pelanggan (<?= htmlspecialchars($filter_type ?: 'customer') ?>)</span>
                    <select name="customer_id" class="form-control" required>
                        <option value="">- Cari nama pelanggan -</option>
                        <?php
                            $cust_list = $db->query("SELECT id, name, type FROM customers c WHERE type = " . $db->quote($filter_type ?: 'customer') . " $scope_where ORDER BY name ASC")->fetchAll();
                            foreach($cust_list as $cl):
                        ?>
                            <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal jatuh tempo</span>
                    <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </label>
            </div>

            <div class="mb-1 mt-4 text-xs font-medium text-muted-foreground">Rincian item</div>
            <div id="itemizedList">
                <div class="mb-2.5 grid grid-cols-[1fr_140px_40px] gap-2.5">
                    <input type="text" name="item_desc[]" class="form-control" placeholder="Deskripsi (mis. paket internet)" required>
                    <input type="number" name="item_amount[]" class="form-control tabular-nums" placeholder="Rp" required>
                    <div class="w-10"></div>
                </div>
            </div>

            <button type="button" onclick="addManualItem()" class="ui-btn ui-btn-sm ui-btn-outline mt-1"><i class="fas fa-plus"></i> Tambah baris item</button>

            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="hideManualInvoiceModal()">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Simpan tagihan</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>
