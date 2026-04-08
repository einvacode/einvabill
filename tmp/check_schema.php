<?php
$db = new PDO('sqlite:database.sqlite');
$res = $db->query("PRAGMA table_info(users)");
$cols = $res->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($cols, JSON_PRETTY_PRINT);
?>
