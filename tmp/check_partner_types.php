<?php
$db = new PDO('sqlite:' . __DIR__ . '/../database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== DISTINCT TYPES IN CUSTOMERS ===\n";
$types = $db->query("SELECT type, COUNT(*) as cnt FROM customers GROUP BY type")->fetchAll(PDO::FETCH_ASSOC);
foreach($types as $t) echo "  type='{$t['type']}' count={$t['cnt']}\n";

echo "\n=== SAMPLE PARTNER ROWS (type='partner') ===\n";
$partners = $db->query("SELECT id, name, type FROM customers WHERE type='partner' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
if (empty($partners)) echo "  (none found)\n";
foreach($partners as $p) echo "  id={$p['id']} name={$p['name']}\n";

echo "\n=== SAMPLE CUSTOMER ROWS (type='customer') ===\n";
$custs = $db->query("SELECT id, name, type FROM customers WHERE type='customer' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
foreach($custs as $c) echo "  id={$c['id']} name={$c['name']}\n";
