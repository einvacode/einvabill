<?php
/**
 * WhatsApp Gateway Diagnostic Tool
 */
require_once 'app/init.php';

$cid = ($_SESSION["user_role"] === "admin") ? "admin" : "u_" . ($_SESSION["user_id"] ?? "guest");
$node_url = "http://127.0.0.1:3000/status?cid=" . $cid;

// 1. Test Server -> Node
$ch = curl_init($node_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$resp = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

$server_node = [
    'success' => ($http_code === 200),
    'code' => $http_code,
    'resp' => json_decode($resp, true),
    'error' => $err
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>WhatsApp Diagnostic</title>
    <style>
        body { font-family: sans-serif; background: #f8fafc; padding: 40px; color: #334155; }
        .card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); margin-bottom: 20px; }
        .status { padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; }
        .success { background: #dcfce7; color: #166534; }
        .fail { background: #fee2e2; color: #991b1b; }
        pre { background: #1e293b; color: #e2e8f0; padding: 15px; border-radius: 8px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>WhatsApp Gateway Diagnostic</h1>
    
    <div class="card">
        <h3>1. Server-Side Connection (PHP -> Node.js)</h3>
        <p>Testing: <code><?= $node_url ?></code></p>
        <?php if($server_node['success']): ?>
            <span class="status success">SUCCESS</span>
            <p>Node.js is responding correctly.</p>
        <?php else: ?>
            <span class="status fail">FAILED</span>
            <p>Error: <?= $server_node['error'] ?: "HTTP $http_code" ?></p>
        <?php endif; ?>
        <pre><?= json_encode($server_node, JSON_PRETTY_PRINT) ?></pre>
    </div>

    <div class="card">
        <h3>2. Browser-Side Connection (JS -> Proxy -> Node.js)</h3>
        <p>Click the button to test if your browser can reach the gateway via the proxy.</p>
        <button onclick="testProxy()">Test Proxy Connection</button>
        <div id="proxy-result" style="margin-top:20px;"></div>
    </div>

    <script>
    async function testProxy() {
        const out = document.getElementById('proxy-result');
        out.innerHTML = "Testing...";
        try {
            const resp = await fetch('wa_proxy.php?path=status&cid=<?= $cid ?>');
            const data = await resp.json();
            out.innerHTML = `<span class="status ${data.connected ? 'success' : 'fail'}">${data.connected ? 'CONNECTED' : 'DISCONNECTED'}</span>
            <pre>${JSON.stringify(data, null, 2)}</pre>`;
        } catch (e) {
            out.innerHTML = `<span class="status fail">BROWSER ERROR</span><pre>${e.message}</pre>`;
        }
    }
    </script>
</body>
</html>
