<?php
// CLI helper to normalize temporary customers (set created_by = 0)
require_once __DIR__ . '/../app/init.php';
try {
    $count = $db->exec("UPDATE customers SET created_by = 0 WHERE type IN ('note','temp') AND (created_by IS NULL OR created_by <> 0)");
    echo "Updated: " . intval($count) . PHP_EOL;
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
