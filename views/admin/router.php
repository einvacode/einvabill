<?php
require_once __DIR__ . '/../../app/routeros_api.class.php';

$action = $_GET['action'] ?? 'list';

if ($action === 'save_router' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $name = $_POST['name'];
    $host = $_POST['host'];
    $port = $_POST['port'] ?: 8728;
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // Auto migrasi jika SQLite error karena table routers belum ada (safety fallback)
    $db->exec("CREATE TABLE IF NOT EXISTS routers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, host TEXT, port INTEGER, username TEXT, password TEXT)");

    if ($id) {
        $db->prepare("UPDATE routers SET name=?, host=?, port=?, username=?, password=? WHERE id=?")->execute([$name, $host, $port, $username, $password, $id]);
    } else {
        $db->prepare("INSERT INTO routers (name, host, port, username, password) VALUES (?, ?, ?, ?, ?)")->execute([$name, $host, $port, $username, $password]);
    }
    header("Location: index.php?page=admin_router");
    exit;
}

if ($action === 'delete_router') {
    $id = intval($_GET['id']);
    $db->exec("DELETE FROM routers WHERE id=$id");
    header("Location: index.php?page=admin_router");
    exit;
}

// Helper formatting inside view
if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $precision = 2) { 
        $units = array('B', 'KB', 'MB', 'GB', 'TB'); 
        $bytes = max($bytes, 0); 
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
        $pow = min($pow, count($units) - 1); 
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow]; 
    }
}
?>

<?php if ($action === 'list'): ?>
<?php 
    try {
        $routers = $db->query("SELECT * FROM routers")->fetchAll();
    } catch(Exception $e) { $routers = []; } 
?>
<div class="glass-panel" style="padding:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3 style="margin:0;"><i class="fas fa-network-wired"></i> Master Multi-Router</h3>
        <button class="btn btn-primary" onclick="document.getElementById('addRouterModal').style.display='flex'"><i class="fas fa-plus"></i> Tambah Router</button>
    </div>
    
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Label Perangkat</th>
                    <th>IP / Host</th>
                    <th>Status API</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($routers as $rt): 
                    $api = new RouterosAPI();
                    $api->debug = false;
                    $api->port = $rt['port'];
                    $api->timeout = 1;
                    $isCon = @$api->connect($rt['host'], $rt['username'], $rt['password']);
                    if($isCon) $api->disconnect();
                ?>
                <tr>
                    <td><strong style="color:var(--primary);"><?= htmlspecialchars($rt['name']) ?></strong></td>
                    <td style="font-family:monospace;"><?= htmlspecialchars($rt['host']) ?>:<?= htmlspecialchars($rt['port']) ?></td>
                    <td>
                        <?php if($isCon): ?>
                            <span class="badge badge-success"><i class="fas fa-check-circle"></i> Connected</span>
                        <?php else: ?>
                            <span class="badge badge-danger" style="background:rgba(239,68,68,0.2); color:#ef4444;"><i class="fas fa-times-circle"></i> Disconnected</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="index.php?page=admin_router&action=view&id=<?= $rt['id'] ?>" class="btn btn-sm btn-primary">Dashboard</a>
                        <a href="#" onclick="editRouter(<?= htmlspecialchars(json_encode($rt)) ?>)" class="btn btn-sm" style="background:#f59e0b; color:white;">Edit</a>
                        <a href="index.php?page=admin_router&action=delete_router&id=<?= $rt['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus router ini permanen?')">Hapus</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($routers) == 0): ?>
                    <tr><td colspan="4" style="text-align:center; padding:30px;">Belum ada perangkat Router yang dimasukkan. <br><div style="font-size:12px; margin-top:5px; color:#64748b;">(Jalankan app/migrate8.php jika sebelumnya sudah pernah instal)</div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Router -->
<div id="addRouterModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:9999;">
    <div class="glass-panel" style="width:100%; max-width:400px; padding:24px;">
        <h3 id="modalTitle" style="margin-bottom:20px;">Tambah Router Baru</h3>
        <form action="index.php?page=admin_router&action=save_router" method="POST">
            <input type="hidden" name="id" id="rt_id">
            <div class="form-group">
                <label>Nama Label (Misal: Mikrotik Pusat Server)</label>
                <input type="text" name="name" id="rt_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Alamat IP / Host API</label>
                <input type="text" name="host" id="rt_host" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Port API (Default: 8728)</label>
                <input type="number" name="port" id="rt_port" class="form-control" value="8728" required>
            </div>
            <div class="form-group">
                <label>Username Router</label>
                <input type="text" name="username" id="rt_user" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Password Router</label>
                <input type="password" name="password" id="rt_pass" class="form-control">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('addRouterModal').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Router</button>
            </div>
        </form>
    </div>
</div>

<script>
function editRouter(rt) {
    document.getElementById('rt_id').value = rt.id;
    document.getElementById('rt_name').value = rt.name;
    document.getElementById('rt_host').value = rt.host;
    document.getElementById('rt_port').value = rt.port;
    document.getElementById('rt_user').value = rt.username;
    document.getElementById('rt_pass').value = rt.password; // Note: For real environment, pulling plaintext DB password isn't secure, but for localhost it's fine.
    
    document.getElementById('modalTitle').innerText = 'Edit Router';
    document.getElementById('addRouterModal').style.display = 'flex';
}
</script>

<?php endif; ?>

<?php if ($action === 'view'): ?>
<?php
    $id = intval($_GET['id']);
    $router = $db->query("SELECT * FROM routers WHERE id = $id")->fetch();

    $connected = false;
    $api = new RouterosAPI();
    $api->debug = false;
    $api->port = empty($router['port']) ? 8728 : $router['port'];

    $resources = [];
    $active_ppp = [];
    $all_secrets = [];
    $interfaces = [];
    $error_msg = "";

    if ($api->connect($router['host'], $router['username'], $router['password'])) {
        $connected = true;
        $res = $api->comm('/system/resource/print');
        $resources = $res[0] ?? [];
        $active_ppp = $api->comm('/ppp/active/print');
        $all_secrets = $api->comm('/ppp/secret/print');
        $interfaces = $api->comm('/interface/print');
        $api->disconnect();
    } else {
        $error_msg = "Koneksi ke Router '{$router['name']}' gagal.";
    }
?>
<div class="router-dashboard">
    <div style="margin-bottom:20px;">
        <a href="index.php?page=admin_router" class="btn btn-sm btn-ghost"><i class="fas fa-arrow-left"></i> Kembali ke List Router</a>
    </div>

    <?php if(!$connected): ?>
        <div class="glass-panel" style="padding: 24px; max-width:600px; margin:0 auto; text-align:center;">
            <i class="fas fa-exclamation-triangle" style="font-size: 40px; color: var(--danger-color); margin-bottom:15px;"></i>
            <h3>Koneksi Router Gagal</h3>
            <p style="color:var(--text-secondary); margin-bottom:20px;"><?= htmlspecialchars($error_msg) ?></p>
        </div>
    <?php else: ?>
        <div style="margin-bottom:20px;">
            <h2 style="margin:0; font-size:24px;">Monitoring: <?= htmlspecialchars($router['name']) ?></h2>
            <div style="color:var(--text-secondary); font-family:monospace;"><?= htmlspecialchars($router['host']) ?></div>
        </div>
        
        <div class="metrics-grid">
            <div class="metric-card glass-panel text-center">
                <div class="metric-title">CPU Load</div>
                <div class="metric-value"><?= htmlspecialchars($resources['cpu-load'] ?? 0) ?>%</div>
                <div class="metric-icon"><i class="fas fa-microchip"></i></div>
            </div>
            <div class="metric-card glass-panel text-center">
                <div class="metric-title">Uptime</div>
                <div class="metric-value" style="font-size: 20px;"><?= htmlspecialchars($resources['uptime'] ?? '-') ?></div>
                <div class="metric-icon"><i class="fas fa-clock"></i></div>
            </div>
            <div class="metric-card glass-panel text-center">
                <div class="metric-title">Sisa Memori</div>
                <div class="metric-value"><?= formatBytes($resources['free-memory'] ?? 0) ?></div>
                <div class="metric-icon"><i class="fas fa-memory"></i></div>
            </div>
            <div class="metric-card glass-panel text-center">
                <div class="metric-title">Koneksi Aktif PPPoE</div>
                <div class="metric-value"><?= count($active_ppp) ?></div>
                <div class="metric-icon"><i class="fas fa-network-wired"></i></div>
            </div>
        </div>

        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:20px;">
            <div class="glass-panel" style="flex:1; min-width:400px; padding: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                    <h3 style="margin:0;"><i class="fas fa-chart-line"></i> Live Traffic</h3>
                    <select id="interfaceSelect" class="form-control" style="width: auto; display:inline-block;" onchange="changeInterface()">
                        <?php foreach($interfaces as $iface): ?>
                            <option value="<?= htmlspecialchars($iface['name']) ?>"><?= htmlspecialchars($iface['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="position: relative; height: 300px; width: 100%;">
                    <canvas id="trafficChart"></canvas>
                </div>
            </div>
        </div>

        <?php
            // Cross-reference secrets with active connections
            $active_names = [];
            foreach($active_ppp as $act) {
                $active_names[$act['name']] = $act;
            }
        ?>
        <div class="glass-panel" style="margin-top:20px; padding:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                <h3 style="margin:0;"><i class="fas fa-users-cog"></i> Registri PPPoE Secrets — <span style="color:var(--primary);"><?= count($all_secrets) ?></span> Total | <span style="color:var(--success);"><?= count($active_ppp) ?></span> Online</h3>
                <div style="display:flex; gap:10px;">
                    <span class="badge badge-success" style="font-size:13px; padding:6px 14px;"><i class="fas fa-circle" style="font-size:8px;"></i> Online <?= count($active_ppp) ?></span>
                    <span class="badge" style="background:rgba(239,68,68,0.15); color:#ef4444; border:1px solid rgba(239,68,68,0.4); font-size:13px; padding:6px 14px;"><i class="fas fa-circle" style="font-size:8px;"></i> Offline <?= count($all_secrets) - count($active_ppp) ?></span>
                </div>
            </div>
            <div style="overflow-x:auto;">
                <table class="table" style="width:100%">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>PPPoE Name / Secret</th>
                            <th>Profile</th>
                            <th style="text-align:center;">Status</th>
                            <th>IP Address</th>
                            <th>Uptime</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($all_secrets) > 0): ?>
                            <?php $no=1; foreach($all_secrets as $secret): 
                                $name = $secret['name'] ?? '';
                                $isOnline = isset($active_names[$name]);
                                $activeData = $isOnline ? $active_names[$name] : null;
                            ?>
                                <tr style="<?= $isOnline ? 'background:rgba(16,185,129,0.04);' : '' ?>">
                                    <td><?= $no++ ?></td>
                                    <td style="font-weight:bold; color:<?= $isOnline ? 'var(--success)' : 'var(--text-secondary)' ?>;">
                                        <?php if($isOnline): ?>
                                            <i class="fas fa-signal" style="margin-right:5px;"></i>
                                        <?php else: ?>
                                            <i class="fas fa-user" style="margin-right:5px; opacity:0.4;"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($name) ?>
                                    </td>
                                    <td>
                                        <span style="font-family:monospace; background:rgba(0,0,0,0.2); padding:2px 8px; border-radius:4px; font-size:12px;">
                                            <?= htmlspecialchars($secret['profile'] ?? '-') ?>
                                        </span>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if($isOnline): ?>
                                            <span class="badge badge-success" style="animation: pulse 2s infinite;">
                                                <i class="fas fa-check-circle"></i> Online
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background:rgba(239,68,68,0.15); color:#ef4444; border:1px solid rgba(239,68,68,0.4);">
                                                <i class="fas fa-times-circle"></i> Offline
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-family:monospace; font-size:13px;">
                                        <?= $isOnline ? htmlspecialchars($activeData['address'] ?? '-') : '<span style="opacity:0.3;">—</span>' ?>
                                    </td>
                                    <td style="font-size:13px;">
                                        <?= $isOnline ? htmlspecialchars($activeData['uptime'] ?? '-') : '<span style="opacity:0.3;">—</span>' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center; padding:20px;">Tidak ada PPPoE Secret terdaftar di router ini.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <style>
            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.7; }
            }
        </style>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            let trafficChart;
            let currentInterface = document.getElementById('interfaceSelect') ? document.getElementById('interfaceSelect').value : null;
            const maxPoints = 20;
            const routerId = <?= $id ?>;
            
            // Empty data array
            let chartLabels = Array(maxPoints).fill('');
            let rxData = Array(maxPoints).fill(0);
            let txData = Array(maxPoints).fill(0);

            function initChart() {
                if(!document.getElementById('trafficChart')) return;
                const ctx = document.getElementById('trafficChart').getContext('2d');
                trafficChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartLabels,
                        datasets: [
                            {
                                label: 'Rx (Download)',
                                data: rxData,
                                borderColor: 'rgba(52, 152, 219, 1)',
                                backgroundColor: 'rgba(52, 152, 219, 0.1)',
                                borderWidth: 2, fill: true, tension: 0.4
                            },
                            {
                                label: 'Tx (Upload)',
                                data: txData,
                                borderColor: 'rgba(46, 204, 113, 1)',
                                backgroundColor: 'rgba(46, 204, 113, 0.1)',
                                borderWidth: 2, fill: true, tension: 0.4
                            }
                        ]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        animation: { duration: 0 },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        if (value >= 1000000000) return (value / 1000000000).toFixed(1) + ' Gbps';
                                        if (value >= 1000000) return (value / 1000000).toFixed(1) + ' Mbps';
                                        if (value >= 1000) return (value / 1000).toFixed(1) + ' kbps';
                                        return value + ' bps';
                                    }
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let value = context.raw;
                                        if (value >= 1000000000) return context.dataset.label + ': ' + (value / 1000000000).toFixed(1) + ' Gbps';
                                        if (value >= 1000000) return context.dataset.label + ': ' + (value / 1000000).toFixed(1) + ' Mbps';
                                        if (value >= 1000) return context.dataset.label + ': ' + (value / 1000).toFixed(1) + ' kbps';
                                        return context.dataset.label + ': ' + value + ' bps';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            function changeInterface() {
                if(!document.getElementById('interfaceSelect')) return;
                currentInterface = document.getElementById('interfaceSelect').value;
                chartLabels = Array(maxPoints).fill('');
                rxData = Array(maxPoints).fill(0);
                txData = Array(maxPoints).fill(0);
                trafficChart.data.labels = chartLabels;
                trafficChart.data.datasets[0].data = rxData;
                trafficChart.data.datasets[1].data = txData;
                trafficChart.update();
            }

            function updateTrafficData() {
                if(!currentInterface) return;
                fetch(`index.php?page=router_data&router_id=${routerId}&action=traffic&interface=${encodeURIComponent(currentInterface)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (!data.error) {
                            const rx = data.rx;
                            const tx = data.tx;
                            const timeLabel = new Date().toLocaleTimeString('id-ID', { hour12: false, hour: "numeric", minute: "numeric", second: "numeric" });

                            chartLabels.push(timeLabel);
                            chartLabels.shift();

                            rxData.push(rx);
                            rxData.shift();

                            txData.push(tx);
                            txData.shift();

                            trafficChart.update();
                        }
                    })
                    .catch(err => console.error('Traffic update error:', err));
            }

            document.addEventListener("DOMContentLoaded", function() {
                initChart();
                if(currentInterface) {
                    setInterval(updateTrafficData, 2000);
                }
            });
        </script>
    <?php endif; ?>
</div>
<?php endif; ?>
