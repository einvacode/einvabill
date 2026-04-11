<?php
require_once __DIR__ . '/app/init.php';

// Update admin password to 123456
$hash = password_hash('123456', PASSWORD_DEFAULT);
$stmt = $db->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
$stmt->execute([$hash]);

echo "Password untuk user 'admin' telah diupdate ke '123456'.\n";
?>