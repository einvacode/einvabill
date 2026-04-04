<?php
$db = new PDO('sqlite:' . __DIR__ . '/../database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try { 
    $db->exec('ALTER TABLE settings ADD COLUMN wa_template_paid TEXT'); 
    $db->exec('ALTER TABLE settings ADD COLUMN bank_account TEXT'); 

    // Set default values
    $default_paid_template = "Halo {nama}, terima kasih! Pembayaran internet Anda sebesar {tagihan} telah LUNAS. Terima kasih atas kepercayaan Anda.";
    $default_bank = "BCA: 1234567890 a.n. PT RTRW NET\nMandiri: 0987654321 a.n. PT RTRW NET";
    
    $stmt = $db->prepare("UPDATE settings SET wa_template_paid=?, bank_account=? WHERE id=1");
    $stmt->execute([$default_paid_template, $default_bank]);
} catch(Exception $e){}

echo "Migrasi template lunas dan info bank berhasil.";
?>
