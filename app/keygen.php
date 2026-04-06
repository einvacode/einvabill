<?php
/**
 * EINVABILL LICENSE KEY GENERATOR
 * Keep this script private. Use it to generate keys for your customers.
 */

require_once __DIR__ . '/init.php';

// Authentication Check: Only allow if logged in as Admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die("Akses Ditolak.");
}

$salt = "EINVABILL_SECRET";

function generate_annual_key($expiry_date, $salt) {
    $date_str = str_replace('-', '', $expiry_date); // YYYYMMDD
    $crc = strtoupper(substr(md5($date_str . $salt), 0, 4));
    return "EXP-" . $date_str . "-" . $crc;
}

$master_key = "AG-ULTIMATE-2026";
$results = [];

if (isset($_POST['gen_annual'])) {
    $date = $_POST['expiry_date'];
    $results[] = [
        'type' => '1 Tahun (' . $date . ')',
        'key' => generate_annual_key($date, $salt)
    ];
}

?>

<div class="glass-panel" style="padding: 30px; max-width: 600px; margin: 40px auto;">
    <h2 style="margin-bottom: 20px;"><i class="fas fa-magic"></i> Generator Kode Lisensi</h2>
    
    <div style="background: rgba(59, 130, 246, 0.1); padding: 15px; border-radius: 12px; margin-bottom: 25px;">
        <strong>Kunci Master (Unlimited):</strong><br>
        <code style="font-size: 18px; color: var(--primary);"><?= $master_key ?></code>
        <div style="font-size: 11px; color: var(--text-secondary); margin-top: 5px;">* Gunakan ini khusus untuk Anda sendiri / Administrator Utama.</div>
    </div>

    <form method="POST" style="background: rgba(0,0,0,0.2); padding: 20px; border-radius: 12px; border: 1px solid var(--glass-border);">
        <h4 style="margin-bottom: 15px;">Buat Lisensi 1 Tahun</h4>
        <div class="form-group">
            <label>Tanggal Kadaluarsa</label>
            <input type="date" name="expiry_date" class="form-control" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required>
        </div>
        <button type="submit" name="gen_annual" class="btn btn-primary" style="width: 100%;">GENERASI KODE BARU</button>
    </form>

    <?php if(!empty($results)): ?>
        <div style="margin-top: 30px; border-top: 1px solid var(--glass-border); padding-top: 20px;">
            <h4 style="margin-bottom: 15px;">Hasil Generasi:</h4>
            <?php foreach($results as $res): ?>
                <div style="padding: 15px; background: rgba(16, 185, 129, 0.1); border: 1px solid var(--success); border-radius: 12px; margin-bottom: 10px;">
                    <div style="font-size: 12px; color: var(--success); margin-bottom: 5px;">Tipe: <?= $res['type'] ?></div>
                    <code style="font-size: 20px; letter-spacing: 1px; font-weight: 700; color: #fff;"><?= $res['key'] ?></code>
                    <div style="font-size: 11px; margin-top: 5px; color: var(--text-secondary);">Salin kode di atas untuk diberikan ke klien.</div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <div style="margin-top: 30px; text-align: center;">
        <a href="index.php" class="btn btn-ghost btn-sm">Kembali ke Dashboard</a>
    </div>
</div>
