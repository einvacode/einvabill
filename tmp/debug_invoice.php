<?php
// Temporary debug script: fetch invoice, customer, items, payments
// Usage (CLI): php tmp/debug_invoice.php 54
// Usage (Web): /tmp/debug_invoice.php?id=54

if (php_sapi_name() === 'cli') {
    // Minimal server vars for init.php
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SERVER_PORT'] = 80;
}

require_once __DIR__ . '/../app/init.php';

$id = 0;
if (php_sapi_name() === 'cli') {
    $id = isset($argv[1]) ? intval($argv[1]) : 54;
} else {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 54;
}

header('Content-Type: application/json; charset=utf-8');

$out = ['requested_id' => $id, 'ok' => false, 'errors' => [], 'invoice' => null, 'customer' => null, 'items' => [], 'payments' => []];

try {
    $stmt = $db->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $inv = $stmt->fetch();
    if (!$inv) {
        $out['errors'][] = "Invoice not found";
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $out['invoice'] = $inv;

    if (!empty($inv['customer_id'])) {
        $stmtc = $db->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
        $stmtc->execute([$inv['customer_id']]);
        $cust = $stmtc->fetch();
        if ($cust) $out['customer'] = $cust;
        else $out['errors'][] = "Customer id {$inv['customer_id']} not found";
    } else {
        $out['errors'][] = "Invoice has no customer_id";
    }

    $stmt_it = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $stmt_it->execute([$id]);
    $out['items'] = $stmt_it->fetchAll();

    $stmt_p = $db->prepare("SELECT * FROM payments WHERE invoice_id = ? ORDER BY payment_date ASC");
    $stmt_p->execute([$id]);
    $out['payments'] = $stmt_p->fetchAll();

    $out['ok'] = true;
} catch (Exception $e) {
    $out['errors'][] = $e->getMessage();
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

?>