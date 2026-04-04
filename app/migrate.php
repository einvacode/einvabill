<?php
$db = new PDO('sqlite:' . __DIR__ . '/../database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try { $db->exec('ALTER TABLE customers ADD COLUMN registration_date TEXT'); } catch(Exception $e){}
try { $db->exec('ALTER TABLE customers ADD COLUMN billing_date INTEGER'); } catch(Exception $e){}
try { $db->exec('ALTER TABLE settings ADD COLUMN company_logo TEXT'); } catch(Exception $e){}

echo "Semua kolom baru berhasil ditambahkan.";
?>
