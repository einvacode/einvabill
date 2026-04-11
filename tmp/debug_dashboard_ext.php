<?php
require_once __DIR__ . '/../app/init.php';

$u_id = 1; // assume admin context for debug

// build partner list
$partner_user_ids = $db->query("SELECT id FROM users WHERE role = 'partner'")->fetchAll(PDO::FETCH_COLUMN);
$partner_list_str = !empty($partner_user_ids) ? implode(',', $partner_user_ids) : '0';

$c_scope = " AND (c.created_by NOT IN ($partner_list_str) OR c.created_by = 0 OR c.created_by IS NULL) ";

try {
    $q = $db->prepare("SELECT COUNT(*) as ext_count, COALESCE(SUM(i.amount - i.discount),0) as ext_total FROM invoices i JOIN customers c ON i.customer_id = c.id WHERE (i.created_via = 'external' OR (i.created_via IS NOT NULL AND i.created_via <> '') OR c.type IN ('note','temp')) $c_scope");
    $q->execute();
    $r = $q->fetch();
    echo "Debug Dashboard External Stats:\n";
    echo "partner_list_str = $partner_list_str\n";
    echo "c_scope = $c_scope\n";
    echo "ext_count = " . ($r['ext_count'] ?? 0) . "\n";
    echo "ext_total = " . ($r['ext_total'] ?? 0) . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
