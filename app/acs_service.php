<?php
/**
 * GenieACS API Service for EinvaBill
 */

function get_acs_device($pppoe_name) {
    global $db;
    $settings = $db->query("SELECT acs_url, acs_user, acs_pass FROM settings WHERE id=1")->fetch();
    
    if (empty($settings['acs_url'])) return ['error' => 'ACS URL belum dikonfigurasi.'];
    
    $url = rtrim($settings['acs_url'], '/') . '/devices/?query=' . urlencode(json_encode([
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username' => $pppoe_name
    ]));
    
    // Fallback search for some ONTs that use different paths
    // In production, you might want to query multiple paths or use a custom tag
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    if (!empty($settings['acs_user'])) {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $settings['acs_user'] . ":" . $settings['acs_pass']);
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code != 200) {
        return ['error' => "Gagal terhubung ke ACS (HTTP $http_code)."];
    }
    
    $data = json_decode($response, true);
    if (empty($data)) return ['error' => "Perangkat tidak ditemukan di ACS."];
    
    return $data[0]; // Return the first matching device
}

/**
 * Helper to extract specific parameters from GenieACS device object
 */
function get_acs_param($device, $path) {
    if (!isset($device)) return null;
    $parts = explode('.', $path);
    $current = $device;
    foreach ($parts as $p) {
        if (isset($current[$p])) {
            $current = $current[$p];
        } else {
            return null;
        }
    }
    return $current['_value'] ?? null;
}
