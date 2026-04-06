<?php
require_once __DIR__ . '/routeros_api.class.php';

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'partner'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$router_id = isset($_GET['router_id']) ? intval($_GET['router_id']) : 0;
if ($router_id == 0) {
    echo json_encode(['error' => 'Router ID not provided']);
    exit;
}

$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'];

$router = $db->query("SELECT * FROM routers WHERE id = $router_id")->fetch();

if(!$router) {
    echo json_encode(['error' => 'Router not found in database']);
    exit;
}

// Ownership Check
$is_owner = ($u_role === 'admin') ? ($router['created_by'] == 0 || $router['created_by'] === NULL) : ($router['created_by'] == $u_id);
if (!$is_owner) {
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

$api = new RouterosAPI();
$api->debug = false;
$api->port = empty($router['port']) ? 8728 : $router['port'];
$api->timeout = 2; // short timeout for ajax

if ($api->connect($router['host'], $router['username'], $router['password'])) {
    $action = $_GET['action'] ?? 'traffic';

    if ($action === 'interfaces') {
        $interfaces = $api->comm('/interface/print');
        $api->disconnect();
        echo json_encode($interfaces);
        exit;
    } 
    elseif ($action === 'pppoe_secrets') {
        $secrets = $api->comm('/ppp/secret/print');
        $api->disconnect();
        echo json_encode($secrets);
        exit;
    }
    elseif ($action === 'pppoe_active') {
        $active = $api->comm('/ppp/active/print');
        $api->disconnect();
        echo json_encode($active);
        exit;
    }
    elseif ($action === 'traffic') {
        $interface = $_GET['interface'] ?? 'ether1';
        $traffic = $api->comm('/interface/monitor-traffic', array(
            'interface' => $interface,
            'once' => ''
        ));
        $api->disconnect();

        if (isset($traffic[0])) {
            echo json_encode([
                'rx' => isset($traffic[0]['rx-bits-per-second']) ? (int)$traffic[0]['rx-bits-per-second'] : 0,
                'tx' => isset($traffic[0]['tx-bits-per-second']) ? (int)$traffic[0]['tx-bits-per-second'] : 0
            ]);
        } else {
            echo json_encode(['rx' => 0, 'tx' => 0, 'error' => 'Interface not found']);
        }
        exit;
    }
} else {
    echo json_encode(['error' => 'Could not connect to router']);
}
?>
