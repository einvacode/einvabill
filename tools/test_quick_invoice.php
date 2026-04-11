<?php
// CLI test: create a quick invoice with items, mark paid, verify DB
require __DIR__ . '/../app/init.php';
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from CLI.\n";
    exit(1);
}

function e($msg) { echo $msg . "\n"; }

try {
    // use a dedicated test customer name so it's easy to find
    $testName = 'TEST_QUICK_' . time();
    $billing_address = 'Jl. Test 1';
    $billing_phone = '081200000000';
    $billing_email = 'test@example.local';

    // create customer (type note) to mimic quick recipient
    $stmt = $db->prepare("INSERT INTO customers (customer_code, name, address, contact, type, created_by, registration_date) VALUES (?, ?, ?, ?, 'note', 0, datetime('now'))");
    $stmt->execute([null, $testName, $billing_address, $billing_phone]);
    $cust_id = $db->lastInsertId();
    e("Created test customer id=$cust_id");

    // create invoice
    $created_at = date('Y-m-d H:i:s');
    $due_date = date('Y-m-d');
    $created_via = 'quick_test';
    $amount = 0;

    // Prepare columns presence
    $cols = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN,1);
    $has_created_via = is_array($cols) && in_array('created_via', $cols);

    if ($has_created_via) {
        $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at, status, discount, created_via, billing_address, billing_phone, billing_email, issued_by_name) VALUES (?, ?, ?, ?, 'Belum Lunas', 0, ?, ?, ?, ?, ?)")->execute([$cust_id, 0, $due_date, $created_at, $created_via, $billing_address, $billing_phone, $billing_email, 'CLI Test']);
    } else {
        $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, created_at, status, discount) VALUES (?, ?, ?, ?, 'Belum Lunas', 0)")->execute([$cust_id, 0, $due_date, $created_at]);
    }
    $inv_id = $db->lastInsertId();
    e("Created invoice id=$inv_id");

    // create items (ensure columns exist for qty/unit)
    $item_cols = $db->query("PRAGMA table_info(invoice_items)")->fetchAll(PDO::FETCH_COLUMN,1);
    if (!in_array('qty', $item_cols)) { try { $db->exec("ALTER TABLE invoice_items ADD COLUMN qty INTEGER DEFAULT 1"); } catch(Exception $e) {} }
    if (!in_array('unit_price', $item_cols)) { try { $db->exec("ALTER TABLE invoice_items ADD COLUMN unit_price REAL DEFAULT 0"); } catch(Exception $e) {} }

    $items = [
        ['desc' => 'Test Router', 'qty' => 1, 'unit' => 500000],
        ['desc' => 'Instalasi', 'qty' => 1, 'unit' => 75000]
    ];
    $total = 0;
    $stmt_item = $db->prepare("INSERT INTO invoice_items (invoice_id, description, amount, qty, unit_price) VALUES (?, ?, ?, ?, ?)");
    foreach ($items as $it) {
        $line = $it['qty'] * $it['unit'];
        $stmt_item->execute([$inv_id, $it['desc'], $line, $it['qty'], $it['unit']]);
        $total += $line;
    }

    // update invoice amount
    $db->prepare("UPDATE invoices SET amount = ? WHERE id = ?")->execute([$total, $inv_id]);
    e("Inserted items, invoice total set to: $total");

    // Verify items
    $rows = $db->query("SELECT description, amount, qty, unit_price FROM invoice_items WHERE invoice_id = " . intval($inv_id))->fetchAll();
    e("Invoice items:\n" . json_encode($rows));

    // Mark as paid (simulate invoice_mark_paid)
    $net_amount = $total;
    $db->prepare("UPDATE invoices SET status = 'Lunas' WHERE id = ?")->execute([$inv_id]);
    $db->prepare("INSERT INTO payments (invoice_id, amount, received_by, payment_date) VALUES (?, ?, ?, ?)")->execute([$inv_id, $net_amount, 0, date('Y-m-d H:i:s')]);
    e("Marked invoice paid and inserted payments record for amount: $net_amount");

    // Verify payment
    $pay = $db->query("SELECT id, amount, payment_date FROM payments WHERE invoice_id = " . intval($inv_id))->fetchAll();
    e("Payments for invoice: \n" . json_encode($pay));

    // Cleanup: delete test rows to avoid polluting db (optional)
    // Comment next lines if you want to keep records
    $db->prepare("DELETE FROM payments WHERE invoice_id = ?")->execute([$inv_id]);
    $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$inv_id]);
    $db->prepare("DELETE FROM invoices WHERE id = ?")->execute([$inv_id]);
    $db->prepare("DELETE FROM customers WHERE id = ?")->execute([$cust_id]);
    e("Cleanup done. Test completed successfully.");

} catch (Exception $e) {
    e("Error: " . $e->getMessage());
    exit(1);
}

exit(0);
