<?php
$db = new PDO('sqlite:' . __DIR__ . '/../database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try { 
    $db->exec('ALTER TABLE settings ADD COLUMN wa_template TEXT'); 
    
    // Set default value if we just added it
    $default_template = "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}. Mohon segera melakukan pembayaran. Terima kasih.";
    $stmt = $db->prepare("UPDATE settings SET wa_template=? WHERE id=1");
    $stmt->execute([$default_template]);
} catch(Exception $e){}

echo "Migrasi template WA berhasil.";
?>
