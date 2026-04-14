<?php
/**
 * License verification helper
 * - verify_license(PDO $db): returns ['status'=>..., 'msg'=>..., 'expiry'=>null|string]
 */

function verify_license(PDO $db) {
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $stmt = $db->prepare("SELECT license_key, license_expiry, installation_date FROM settings WHERE tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) $row = [];

    $key = $row['license_key'] ?? '';
    $expiry_db = $row['license_expiry'] ?? '';
    $install_date = ($row['installation_date'] ?? null) ?: date('Y-m-d');

    $MASTER = getenv('MASTER_KEY') ?: 'EB-ULTIMATE-2026';
    $SALT = getenv('EINVABILL_SALT') ?: 'EINVABILL_SECRET';

    $result = ['status' => 'EXPIRED', 'msg' => 'No valid license', 'expiry' => null];

    // Unlimited master key
    if (!empty($key) && $key === $MASTER) {
        $result['status'] = 'UNLIMITED';
        $result['msg'] = 'Unlimited access (master key).';
        return $result;
    }

    // Expirable license format: EXP-YYYYMMDD-CRC
    if (!empty($key) && preg_match('/^EXP-(\d{8})-([A-Z0-9]{4})$/', $key, $m)) {
        $date_str = $m[1];
        $crc = $m[2];
        $expected = strtoupper(substr(md5($date_str . $SALT), 0, 4));
        if ($crc !== $expected) {
            $result['status'] = 'INVALID';
            $result['msg'] = 'Kode lisensi tidak valid (checksum mismatch).';
            return $result;
        }
        $expiry = substr($date_str,0,4) . '-' . substr($date_str,4,2) . '-' . substr($date_str,6,2);
        $result['expiry'] = $expiry;
        if (strtotime($expiry) >= strtotime(date('Y-m-d'))) {
            $result['status'] = 'ACTIVE';
            $result['msg'] = 'License active until ' . $expiry;
        } else {
            $result['status'] = 'EXPIRED';
            $result['msg'] = 'License expired on ' . $expiry;
        }
        return $result;
    }

    // Trial fallback: 7-day trial from installation_date
    $days_since_install = (strtotime(date('Y-m-d')) - strtotime($install_date)) / 86400;
    if ($days_since_install <= 7) {
        $remaining = 7 - floor($days_since_install);
        $result['status'] = 'TRIAL';
        $result['msg'] = "Masa Percobaan (Trial) sisa $remaining hari.";
        return $result;
    }

    // Default: expired
    $result['status'] = 'EXPIRED';
    $result['msg'] = 'Masa percobaan/lisensi telah habis.';
    return $result;
}

?>
