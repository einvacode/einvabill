<?php
$action = $_GET['action'] ?? 'list';

if ($action === 'create_itemized' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id']);
    $due_date = $_POST['due_date'];
    $descriptions = $_POST['item_desc'];
    $amounts = $_POST['item_amount'];
    
    $total_amount = array_sum($amounts);
    $created_at = date('Y-m-d H:i:s');
    
    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$customer_id, $total_amount, $due_date, $created_at]);
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
    
    $stmt = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at, status) VALUES (?, ?, ?, ?, 'Belum Lunas')");
    $stmt->execute([$customer_id, $amount, $due_date, $created_at]);
    
    header("Location: index.php?page=admin_customers&action=details&id=$customer_id&msg=invoice_created");
    exit;
}

if ($action === 'delete') {
    $id = intval($_GET['id']);
    $invoice = $db->query("SELECT * FROM invoices WHERE id = $id")->fetch();
    if ($invoice && $_SESSION['user_role'] === 'admin') {
        // Cascade delete manual
        $db->exec("DELETE FROM payments WHERE invoice_id = $id");
        $db->exec("DELETE FROM invoice_items WHERE invoice_id = $id");
        $db->exec("DELETE FROM invoices WHERE id = $id");
    }
    header("Location: index.php?page=admin_invoices");
    exit;
}

if ($action === 'edit_post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $amount = $_POST['amount'];
    $due_date = $_POST['due_date'];
    
    if ($_SESSION['user_role'] === 'admin') {
        $db->prepare("UPDATE invoices SET amount=?, due_date=? WHERE id=?")->execute([$amount, $due_date, $id]);
    }
    header("Location: index.php?page=admin_invoices");
    exit;
}

if ($action === 'mark_paid') {
    $id = $_GET['id'];
    $amount = $db->query("SELECT amount FROM invoices WHERE id = $id")->fetchColumn();
    $receiver_id = $_SESSION['user_id'];
    $payment_date = date('Y-m-d H:i:s');
    
    $db->prepare("UPDATE invoices SET status = 'Lunas' WHERE id = ?")->execute([$id]);
    $db->prepare("INSERT INTO payments (invoice_id, amount, received_by, payment_date) VALUES (?, ?, ?, ?)")->execute([$id, $amount, $receiver_id, $payment_date]);
    
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

if ($action === 'mark_paid_bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = intval($_POST['customer_id']);
    $num_months = intval($_POST['num_months']);
    $receiver_id = $_SESSION['user_id'];
    $payment_date = date('Y-m-d H:i:s');
    
    // Fetch oldest N unpaid invoices
    $unpaid = $db->query("SELECT id, amount FROM invoices WHERE customer_id = $customer_id AND status = 'Belum Lunas' ORDER BY due_date ASC LIMIT $num_months")->fetchAll();
    
    foreach ($unpaid as $inv) {
        $db->prepare("UPDATE invoices SET status = 'Lunas' WHERE id = ?")->execute([$inv['id']]);
        $db->prepare("INSERT INTO payments (invoice_id, amount, received_by, payment_date) VALUES (?, ?, ?, ?)")->execute([$inv['id'], $inv['amount'], $receiver_id, $payment_date]);
    }
    
    header("Location: index.php?page=admin_invoices&msg=bulk_paid");
    exit;
}

if ($action === 'print') {
    $id = $_GET['id'];
    $invoice = $db->query("
        SELECT i.*, c.name, c.address, c.contact, c.package_name, c.type 
        FROM invoices i 
        JOIN customers c ON i.customer_id = c.id 
        WHERE i.id = $id
    ")->fetch();
    require __DIR__ . '/../print.php';
    exit;
}

?>

<?php if ($action === 'list'): ?>
<?php
    // Date range filter
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $filter_status = $_GET['filter_status'] ?? '';
    
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

    // Collector filter
    $filter_collector = $_GET['filter_collector'] ?? '';
    $collector_where = '';
    if ($filter_collector) {
        $collector_where = " AND c.collector_id = " . intval($filter_collector);
    }
    
    $collectors = $db->query("SELECT id, name FROM users WHERE role = 'collector' ORDER BY name ASC")->fetchAll();
?>
<div class="glass-panel" style="padding: 24px;">
    <?php if($filter_status === 'belum'): ?>
        <h3 style="font-size:20px; margin-bottom:20px;"><i class="fas fa-user-clock" style="color:#f43f5e;"></i> User Tunggakan (Aggregated)</h3>
    <?php else: ?>
        <h3 style="font-size:20px; margin-bottom:20px;"><i class="fas fa-file-invoice-dollar text-primary"></i> Daftar Tagihan</h3>
    <?php endif; ?>
    
    <!-- Filter Rentang Tanggal -->
    <div style="padding:15px; background:var(--hover-bg); border-radius:12px; margin-bottom:20px; border-left:4px solid var(--primary);">
        <form method="GET" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <input type="hidden" name="page" value="admin_invoices">
            <div>
                <label style="font-size:12px; color:var(--text-secondary); display:block; margin-bottom:4px;">Dari Tanggal</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>" style="padding:8px 12px; font-size:13px;">
            </div>
            <div>
                <label style="font-size:12px; color:var(--text-secondary); display:block; margin-bottom:4px;">Sampai Tanggal</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>" style="padding:8px 12px; font-size:13px;">
            </div>
            <div>
                <label style="font-size:12px; color:var(--text-secondary); display:block; margin-bottom:4px;">Status</label>
                <select name="filter_status" class="form-control" style="padding:8px 12px; font-size:13px;">
                    <option value="">Semua Status</option>
                    <option value="lunas" <?= $filter_status === 'lunas' ? 'selected' : '' ?>>Lunas</option>
                    <option value="belum" <?= $filter_status === 'belum' ? 'selected' : '' ?>>Belum Lunas</option>
                </select>
            </div>
            <div>
                <label style="font-size:12px; color:var(--text-secondary); display:block; margin-bottom:4px;">Collector</label>
                <select name="filter_collector" class="form-control" style="padding:8px 12px; font-size:13px;">
                    <option value="">Semua Collector</option>
                    <?php foreach($collectors as $coll): ?>
                        <option value="<?= $coll['id'] ?>" <?= $filter_collector == $coll['id'] ? 'selected' : '' ?>><?= htmlspecialchars($coll['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" style="padding:8px 16px; height:fit-content;"><i class="fas fa-filter"></i> Filter</button>
            <?php if($date_from || $date_to || $filter_status || $filter_collector): ?>
                <a href="index.php?page=admin_invoices" class="btn btn-sm btn-ghost" style="padding:8px 16px; height:fit-content;"><i class="fas fa-times"></i> Reset</a>
            <?php endif; ?>
        </form>
        
        <?php if($date_from || $date_to): 
            // Ringkasan untuk rentang yang dipilih
            $sum_q = "SELECT COUNT(*) as total, 
                SUM(CASE WHEN i.status='Lunas' THEN 1 ELSE 0 END) as lunas,
                SUM(CASE WHEN i.status='Belum Lunas' THEN 1 ELSE 0 END) as belum,
                COALESCE(SUM(CASE WHEN i.status='Lunas' THEN i.amount ELSE 0 END),0) as total_lunas,
                SUM(CASE WHEN i.status='Belum Lunas' THEN i.amount ELSE 0 END) as total_belum
                FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE 1=1 $date_where $status_where $collector_where";
            $summary = $db->query($sum_q)->fetch();
        ?>
        <div style="display:flex; gap:20px; margin-top:12px; padding-top:12px; border-top:1px solid var(--glass-border); flex-wrap:wrap;">
            <div style="font-size:13px;">📊 Total: <strong><?= $summary['total'] ?></strong> tagihan</div>
            <div style="font-size:13px; color:var(--success);">✅ Lunas: <strong><?= $summary['lunas'] ?></strong> (Rp <?= number_format($summary['total_lunas'], 0, ',', '.') ?>)</div>
            <div style="font-size:13px; color:var(--danger);">🔴 Belum: <strong><?= $summary['belum'] ?></strong> (Rp <?= number_format($summary['total_belum'], 0, ',', '.') ?>)</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- INVOICE LIST -->
    <?php
        $settings = $db->query("SELECT wa_template, wa_template_paid, bank_account FROM settings WHERE id=1")->fetch();
        $wa_tpl = $settings['wa_template'] ?: "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}. Transfer ke {rekening}";
        $wa_tpl_paid = $settings['wa_template_paid'] ?: "Halo {nama}, terima kasih. Pembayaran {tagihan} sudah lunas.";
        
        // Paginasi
        $items_per_page = 50;
        $current_page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
        $offset = ($current_page - 1) * $items_per_page;

        // NEW: Check if we should group by customer (only for Unpaid view)
        $is_grouped = ($filter_status === 'belum');

        // Hitung total row untuk filter ini
        if ($is_grouped) {
            $count_q = "SELECT COUNT(DISTINCT c.id) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE 1=1 $date_where $status_where $collector_where";
        } else {
            $count_q = "SELECT COUNT(*) FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE 1=1 $date_where $status_where $collector_where";
        }
        $total_rows = $db->query($count_q)->fetchColumn();
        $total_pages = ceil($total_rows / $items_per_page);

        if ($is_grouped) {
            $invoices = $db->query("
                SELECT 
                    MAX(i.id) as id, 
                    i.customer_id, 
                    i.status,
                    SUM(i.amount) as amount, 
                    MIN(i.due_date) as due_date, 
                    COUNT(i.id) as months_owed,
                    c.id as cust_id, c.customer_code, c.name as customer_name, c.type as customer_type, c.contact, c.package_name, c.monthly_fee,
                    0 as item_count
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                WHERE 1=1 $date_where $status_where $collector_where
                GROUP BY c.id
                ORDER BY due_date ASC
                LIMIT $items_per_page OFFSET $offset
            ")->fetchAll();
        } else {
            $invoices = $db->query("
                SELECT i.*, c.id as cust_id, c.customer_code, c.name as customer_name, c.type as customer_type, c.contact, c.package_name, c.monthly_fee,
                (SELECT COUNT(*) FROM invoice_items WHERE invoice_id = i.id) as item_count
                FROM invoices i
                JOIN customers c ON i.customer_id = c.id
                WHERE 1=1 $date_where $status_where $collector_where
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
            
            // Pre-fetch payment dates to avoid N+1 query
            $inv_ids = array_column($invoices, 'id');
            $payment_dates = [];
            if(!empty($inv_ids)) {
                $inv_ids_str = implode(',', $inv_ids);
                $pay_list = $db->query("SELECT invoice_id, payment_date FROM payments WHERE invoice_id IN ($inv_ids_str)")->fetchAll();
                foreach($pay_list as $pl) $payment_dates[$pl['invoice_id']] = $pl['payment_date'];
            }

            foreach($invoices as $inv): 
                $wa_number = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $inv['contact']));
                // (WA Template Logic)
                $mon_id = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                $inv_month = $mon_id[intval(date('m', strtotime($inv['due_date']))) - 1] . ' ' . date('Y', strtotime($inv['due_date']));
                // Use unique customer_code if available, otherwise fallback to padded seq id
                $cust_id_display = $inv['customer_code'] ?: str_pad($inv['cust_id'] ?? 0, 5, "0", STR_PAD_LEFT);
                $package_display = $inv['package_name'] ?? '-';
                $nominal_display = 'Rp ' . number_format($inv['amount'], 0, ',', '.');
                
                if ($inv['status'] == 'Lunas') {
                    $payment_date = $payment_dates[$inv['id']] ?? null;
                    $realtime_bayar = $payment_date ? date('Y-m-d H:i:s', strtotime($payment_date)) : '-';
                    
                    // Calculate remaining arrears after this payment
                    $tunggakan_remain = 0;
                    $rem_invoices = $all_unpaid_data[$inv['customer_id']] ?? [];
                    foreach($rem_invoices as $rem) {
                        if($rem['id'] != $inv['id']) $tunggakan_remain += $rem['amount'];
                    }
                    $t_remain_display = $tunggakan_remain > 0 ? 'Rp ' . number_format($tunggakan_remain, 0, ',', '.') : 'LUNAS SELURUHNYA';

                    $msg = str_replace(
                        ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{tunggakan}'], 
                        [$inv['customer_name'], $cust_id_display, $package_display, $inv_month, $nominal_display, $t_remain_display], 
                        $wa_tpl_paid
                    );
                    
                    if(strpos($msg, '{tunggakan}') === false && strpos($msg, 'Tunggakan') === false) {
                        $msg .= "\n*Sisa Tunggakan : $t_remain_display*";
                    }

                    if(strpos($msg, 'Waktu Lunas') === false) $msg .= "\n\n*Informasi Sistem:*\n- Waktu Lunas: $realtime_bayar";
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

                    $msg = str_replace(
                        ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{tunggakan}', '{total_harus}'], 
                        [$inv['customer_name'], $cust_id_display, $package_display, $inv_month, $nominal_display, date('d M Y', strtotime($inv['due_date'])), $settings['bank_account'], $t_prev_display, $total_harus_display], 
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
        <div class="glass-panel" style="padding:16px; margin-bottom:12px; border-left:4px solid <?= $inv['status'] == 'Lunas' ? 'var(--success)' : 'var(--danger)' ?>;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                <div style="display:flex; gap:10px; align-items:center;">
                    <?php if($inv['status'] != 'Lunas' && $wa_number): ?>
                        <input type="checkbox" class="cb-invoice cb-mobile" data-phone="<?= htmlspecialchars($wa_number) ?>" data-msg="<?= htmlspecialchars($msg) ?>" data-name="<?= htmlspecialchars($inv['customer_name']) ?>">
                    <?php endif; ?>
                    <div>
                        <div style="font-weight:700; font-size:15px;"><?= htmlspecialchars($inv['customer_name']) ?></div>
                        <?php if($inv['customer_type'] === 'partner'): ?>
                            <span style="font-size:9px; background:#a855f7; color:white; padding:1px 5px; border-radius:3px; font-weight:700;">MITRA</span>
                        <?php else: ?>
                            <span style="font-size:9px; background:#3b82f6; color:white; padding:1px 5px; border-radius:3px; font-weight:700;">PERSONAL</span>
                        <?php endif; ?>
                        <div style="font-size:12px; color:var(--text-secondary);">INV-<?= str_pad($inv['id'], 5, "0", STR_PAD_LEFT) ?> • <?= date('d M Y', strtotime($inv['due_date'])) ?></div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-weight:800; color:var(--stat-value-color); font-size:16px;">Rp <?= number_format($inv['amount'], 0, ',', '.') ?></div>
                    <span class="badge <?= $inv['status'] == 'Lunas' ? 'badge-success' : 'badge-danger' ?>" style="font-size:10px;"><?= $inv['status'] ?></span>
                </div>
            </div>
            
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php if($inv['status'] != 'Lunas'): ?>
                    <?php if($is_grouped && $inv['months_owed'] > 1): ?>
                        <button onclick="showBulkPayModal(<?= $inv['cust_id'] ?>, '<?= addslashes($inv['customer_name']) ?>', <?= $inv['months_owed'] ?>, <?= $inv['amount'] / $inv['months_owed'] ?>, <?= $inv['amount'] ?>)" class="btn btn-sm btn-success" style="flex:1; min-width:100px; font-weight:700;">
                            <i class="fas fa-layer-group"></i> Bayar
                        </button>
                    <?php else: ?>
                        <a href="index.php?page=admin_invoices&action=mark_paid&id=<?= $inv['id'] ?>" class="btn btn-sm btn-success" style="flex:1; min-width:100px; font-weight:700;" onclick="return confirm('Tandai sudah dibayar?')">
                            <i class="fas fa-check-circle"></i> Bayar
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if($wa_number): ?>
                    <a href="https://api.whatsapp.com/send?phone=<?= $wa_number ?>&text=<?= $wa_text ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:white;">
                        <i class="fab fa-whatsapp"></i> WA
                    </a>
                <?php endif; ?>

                <a href="index.php?page=admin_invoices&action=print&id=<?= $inv['id'] ?>" target="_blank" class="btn btn-sm btn-ghost">
                    <i class="fas fa-print"></i>
                </a>
                
                <?php if($_SESSION['user_role'] === 'admin'): ?>
                    <button onclick="showEditInvoice(<?= $inv['id'] ?>, <?= $inv['amount'] ?>, '<?= $inv['due_date'] ?>')" class="btn btn-sm btn-ghost" style="color:var(--warning);">
                        <i class="fas fa-edit"></i>
                    </button>
                    <a href="index.php?page=admin_invoices&action=delete&id=<?= $inv['id'] ?>" class="btn btn-sm btn-ghost" style="color:var(--danger);" onclick="return confirm('Hapus permanently?')">
                        <i class="fas fa-trash"></i>
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
                        $payment_date = $payment_dates[$inv['id']] ?? null;
                        $realtime_bayar = $payment_date ? date('Y-m-d H:i:s', strtotime($payment_date)) : '-';
                        
                        // Calculate remaining arrears
                        $tunggakan_remain = 0;
                        $rem_invoices = $all_unpaid_data[$inv['customer_id']] ?? [];
                        foreach($rem_invoices as $rem) {
                            if($rem['id'] != $inv['id']) $tunggakan_remain += $rem['amount'];
                        }
                        $t_remain_display = $tunggakan_remain > 0 ? 'Rp ' . number_format($tunggakan_remain, 0, ',', '.') : 'LUNAS SELURUHNYA';

                        $msg = str_replace(
                            ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{tunggakan}'], 
                            [$inv['customer_name'], $cust_id_display, $package_display, $inv_month, $nominal_display, $t_remain_display], 
                            $wa_tpl_paid
                        );
                        
                        if(strpos($msg, '{tunggakan}') === false && strpos($msg, 'Tunggakan') === false) {
                            $msg .= "\n*Sisa Tunggakan : $t_remain_display*";
                        }

                        if(strpos($msg, 'Waktu Lunas') === false) $msg .= "\n\n*Informasi Sistem:*\n- Waktu Lunas: $realtime_bayar";
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

                        $msg = str_replace(
                            ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{tunggakan}', '{total_harus}'], 
                            [$inv['customer_name'], $cust_id_display, $package_display, $inv_month, $nominal_display, date('d M Y', strtotime($inv['due_date'])), $settings['bank_account'], $t_prev_display, $total_harus_display], 
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
                    <td style="vertical-align: middle; text-align: center;">
                        <?php if($inv['status'] != 'Lunas' && $wa_number): ?>
                            <input type="checkbox" class="cb-invoice cb-desktop" data-phone="<?= htmlspecialchars($wa_number) ?>" data-msg="<?= htmlspecialchars($msg) ?>" data-name="<?= htmlspecialchars($inv['customer_name']) ?>" style="transform:scale(1.2); cursor:pointer;">
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align: middle;">
                        <div style="font-weight:700; color:var(--text-primary); font-size:14px;"><?= htmlspecialchars($inv['customer_name']) ?></div>
                        <div style="margin-top: 4px;">
                            <?php if($is_grouped): ?>
                                <span class="badge" style="background:rgba(59,130,246,0.08); color:var(--primary); border:1px solid rgba(59,130,246,0.3); font-size:10px; border-radius:20px; padding:2px 10px; display:inline-block; font-weight:600;">
                                    <i class="fas fa-history" style="font-size:9px; margin-right:3px;"></i> <?= $inv['months_owed'] ?> Bulan Tunggakan
                                </span>
                            <?php else: ?>
                                <span style="font-size:11px; color:var(--text-secondary); font-family:monospace;">INV-<?= str_pad($inv['id'], 5, "0", STR_PAD_LEFT) ?></span>
                                <?php if($inv['item_count'] > 0): ?>
                                    <span title="Memiliki Rincian Item (Add-ons)" style="color:var(--primary); margin-left:5px; font-size:10px;"><i class="fas fa-list-ul"></i> Itemized</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td style="vertical-align: middle;"><?= date('d M Y', strtotime($inv['due_date'])) ?> <?= $is_grouped ? '<br><small style="color:var(--text-secondary);">Mulai Periode</small>' : '' ?></td>
                    <td style="vertical-align: middle; font-weight:800; color:var(--stat-value-color);">Rp <?= number_format($inv['amount'], 0, ',', '.') ?></td>
                    <td style="vertical-align: middle;">
                        <?php if($inv['status'] == 'Lunas'): ?>
                            <span class="badge badge-success" style="padding:4px 10px; border-radius:10px;"><i class="fas fa-check"></i> Lunas</span>
                        <?php else: ?>
                            <span class="badge badge-danger" style="padding:4px 10px; border-radius:10px;">Belum Lunas</span>
                        <?php endif; ?>
                    </td>
                    <td style="vertical-align: middle; white-space:nowrap;">
                        <?php if($inv['status'] != 'Lunas'): ?>
                            <?php if($is_grouped && $inv['months_owed'] > 1): ?>
                                <button onclick="showBulkPayModal(<?= $inv['cust_id'] ?>, '<?= addslashes($inv['customer_name']) ?>', <?= $inv['months_owed'] ?>, <?= $inv['amount'] / $inv['months_owed'] ?>, <?= $inv['amount'] ?>)" class="btn btn-sm btn-success" style="font-weight:700;">
                                    <i class="fas fa-layer-group"></i> Bayar
                                </button>
                            <?php else: ?>
                                <a href="index.php?page=admin_invoices&action=mark_paid&id=<?= $inv['id'] ?>" class="btn btn-sm btn-success" style="font-weight:700;" onclick="return confirm('Tandai sudah dibayar?')">
                                    <i class="fas fa-check-circle"></i> Bayar
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if($wa_number): ?>
                            <a href="https://api.whatsapp.com/send?phone=<?= $wa_number ?>&text=<?= $wa_text ?>" target="_blank" class="btn btn-sm" style="background:#25D366; color:white;" title="Kirim WA"><i class="fab fa-whatsapp"></i> WA</a>
                        <?php endif; ?>
                        
                        <a href="index.php?page=admin_invoices&action=print&id=<?= $inv['id'] ?>" target="_blank" class="btn btn-sm btn-ghost" title="Thermal"><i class="fas fa-print"></i></a>
                        <a href="index.php?page=admin_invoices&action=print&id=<?= $inv['id'] ?>&format=a4" target="_blank" class="btn btn-sm" style="background:var(--primary); color:white;" title="A4"><i class="fas fa-file-pdf"></i></a>
                        
                        <?php if(isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
                            <a href="#" onclick="showEditInvoice(<?= $inv['id'] ?>, <?= $inv['amount'] ?>, '<?= $inv['due_date'] ?>')" class="btn btn-sm" style="background:var(--warning); color:white;" title="Edit"><i class="fas fa-edit"></i></a>
                            <a href="index.php?page=admin_invoices&action=delete&id=<?= $inv['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('PERINGATAN: Hapus tagihan ini secara permanen dari database?')" title="Hapus"><i class="fas fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Navigation -->
        <?php if($total_pages > 1): ?>
        <div style="display:flex; justify-content:center; gap:8px; margin-top:24px; flex-wrap:wrap;">
            <?php 
            $params = $_GET; 
            unset($params['p']); 
            $query_str = http_build_query($params);
            $base_url = "index.php?" . $query_str . "&p=";
            ?>
            
            <?php if($current_page > 1): ?>
                <a href="<?= $base_url . ($current_page - 1) ?>" class="btn btn-sm btn-ghost">&laquo; Prev</a>
            <?php endif; ?>

            <?php 
            $start_p = max(1, $current_page - 2);
            $end_p = min($total_pages, $current_page + 2);
            if($start_p > 1) echo '<span style="padding:5px 10px; color:var(--text-secondary);">...</span>';
            for($i = $start_p; $i <= $end_p; $i++): 
            ?>
                <a href="<?= $base_url . $i ?>" class="btn btn-sm <?= $i == $current_page ? 'btn-primary' : 'btn-ghost' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if($end_p < $total_pages) echo '<span style="padding:5px 10px; color:var(--text-secondary);">...</span>'; ?>

            <?php if($current_page < $total_pages): ?>
                <a href="<?= $base_url . ($current_page + 1) ?>" class="btn btn-sm btn-ghost">Next &raquo;</a>
            <?php endif; ?>
            
            <div style="width:100%; text-align:center; margin-top:8px; font-size:12px; color:var(--text-secondary);">
                Menampilkan <?= count($invoices) ?> dari <?= $total_rows ?> tagihan (Halaman <?= $current_page ?> dari <?= $total_pages ?>)
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
    <!-- BROADCAST SECTION -->
    <div class="glass-panel" style="margin-top:20px; padding:20px; background:rgba(37, 211, 102, 0.08); border-radius:12px; border:1.5px solid rgba(37,211,102,0.3); display:flex; flex-wrap:wrap; gap:15px; justify-content:space-between; align-items:center;">
        <div style="flex:1; min-width:250px;">
            <h4 style="color:#25D366; margin:0; font-size:18px;"><i class="fab fa-whatsapp"></i> Broadcast Massal (Tagihan)</h4>
            <div style="font-size:13px; color:var(--text-secondary); margin-top:5px; line-height:1.4;">Jalankan pengiriman pesan otomatis ke pelanggan terpilih. Sistem akan membuka tab baru setiap 10 detik.</div>
            <div id="waProgressText" style="font-size:13px; font-weight:bold; color:var(--warning); margin-top:8px;"></div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="https://web.whatsapp.com" target="_blank" class="btn btn-ghost btn-sm" style="border-color:#25D366; color:var(--text-primary);"><i class="fas fa-qrcode"></i> 1. Scan Login</a>
            <button onclick="startMassWaWeb()" id="btnMassWa" class="btn btn-sm" style="background:#25D366; color:white; font-weight:bold;"><i class="fas fa-paper-plane"></i> 2. Kirim Terpilih</button>
        </div>
    </div>
</div>

<style>
@media (max-width: 768px) {
    .invoices-desktop-table { display: none !important; }
    .invoices-mobile-container { display: block !important; }
}
</style>

<script>
    document.getElementById('checkAll').addEventListener('change', function() {
        document.querySelectorAll('.cb-desktop').forEach(cb => cb.checked = this.checked);
    });
    document.getElementById('checkAll_mobile').addEventListener('change', function() {
        document.querySelectorAll('.cb-mobile').forEach(cb => cb.checked = this.checked);
    });

    async function startMassWaWeb() {
        let checkboxes = document.querySelectorAll('.cb-invoice:checked');
        if(checkboxes.length === 0) {
            alert('Pilih tagihan terlebih dahulu!');
            return;
        }

        let testPop = window.open('about:blank', '_blank');
        if(!testPop || testPop.closed) {
            alert('Mohon IZINKAN POP-UP pada browser Anda!');
            return;
        }
        testPop.close();

        if(!confirm('Kirim pesan ke ' + checkboxes.length + ' pelanggan?')) return;

        let btn = document.getElementById('btnMassWa');
        let progText = document.getElementById('waProgressText');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
        
        for(let i=0; i<checkboxes.length; i++) {
            let cb = checkboxes[i];
            let phone = cb.getAttribute('data-phone');
            let msg = cb.getAttribute('data-msg');
            let name = cb.getAttribute('data-name');
            
            progText.innerHTML = `Membuka tab: ${name} (${i+1}/${checkboxes.length})...`;
            
            let waUrl = `https://web.whatsapp.com/send?phone=${phone}&text=${msg}`;
            window.open(waUrl, '_blank');
            cb.closest('.glass-panel')?.style.setProperty('border-color', '#25D366');
            cb.closest('tr')?.style.setProperty('background', 'rgba(37, 211, 102, 0.1)');
            
            if (i < checkboxes.length - 1) {
                for(let c = 10; c > 0; c--) {
                    progText.innerHTML = `Tab berikutnya dalam: <strong>${c} detik</strong>.`;
                    await new Promise(r => setTimeout(r, 1000));
                }
            }
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> 2. Kirim Terpilih';
        progText.innerHTML = '<span style="color:#25D366;">Selesai! Scan tab yang terbuka dan klik kirim.</span>';
    }

    function showEditInvoice(id, amount, date) {
        document.getElementById('editInvId').value = id;
        document.getElementById('editInvAmount').value = amount;
        document.getElementById('editInvDate').value = date;
        document.getElementById('editTitle').innerText = 'Edit INV-' + String(id).padStart(5, '0');
        document.getElementById('editInvoiceModal').style.display = 'flex';
    }

    function hideEditInvoice() {
        document.getElementById('editInvoiceModal').style.display = 'none';
    }

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
</script>

<!-- Modal Edit Invoice -->
<div id="editInvoiceModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px;">
        <h3 id="editTitle" style="margin-bottom:20px;">Edit Tagihan</h3>
        <form action="index.php?page=admin_invoices&action=edit_post" method="POST">
            <input type="hidden" name="id" id="editInvId">
            <div class="form-group">
                <label>Nominal Tagihan (Rp)</label>
                <input type="number" name="amount" id="editInvAmount" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Jatuh Tempo</label>
                <input type="date" name="due_date" id="editInvDate" class="form-control" required>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-ghost" onclick="hideEditInvoice()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Bulk Pay Arrears -->
<div id="bulkPayModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px; margin:20px; border-top:4px solid var(--success);">
        <h3 style="margin-bottom:10px;"><i class="fas fa-layer-group text-success"></i> Pelunasan Tunggakan</h3>
        <p style="font-size:14px; color:var(--text-secondary); margin-bottom:20px;">Bayar sebagian atau seluruh tunggakan untuk <strong><span id="bulkCustName"></span></strong>.</p>
        
        <form action="index.php?page=admin_invoices&action=mark_paid_bulk" method="POST">
            <input type="hidden" name="customer_id" id="bulkCustId">
            
            <div class="form-group">
                <label>Berapa Bulan yang Dibayar?</label>
                <div style="display:flex; align-items:center; gap:12px;">
                    <input type="number" name="num_months" id="bulkMonthInput" class="form-control" value="1" min="1" oninput="updateBulkTotal()" style="font-size:20px; font-weight:800; text-align:center;">
                    <div style="font-weight:600; color:var(--text-secondary);">Dari <span id="bulkTotalMonths"></span> Bulan</div>
                </div>
            </div>

            <div style="padding:15px; background:var(--nav-active-bg); border-radius:12px; margin-bottom:20px;">
                <div style="font-size:12px; color:var(--text-secondary); margin-bottom:4px;">TOTAL YANG HARUS DIBAYAR:</div>
                <div style="font-size:22px; font-weight:800; color:var(--success);" id="bulkTotalDisplay">Rp 0</div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-ghost" onclick="hideBulkPayModal()">Batal</button>
                <button type="submit" class="btn btn-success" style="padding-left:25px; padding-right:25px;">
                    <i class="fas fa-check-circle"></i> PROSES BAYAR
                </button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>
