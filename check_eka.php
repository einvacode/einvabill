<?php
$db = new PDO('sqlite:database.db');
$stmt = $db->prepare("SELECT id, username, name, role, area, customer_id FROM users WHERE username = 'eka' OR name LIKE '%eka%'");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents('eka_info.json', json_encode($users, JSON_PRETTY_PRINT));
