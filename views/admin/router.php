<?php
require_once __DIR__ . '/../../app/routeros_api.class.php';

$action = $_GET['action'] ?? 'list';
$u_id = $_SESSION['user_id'];
$u_role = $_SESSION['user_role'] ?? 'admin';

if ($action === 'save_router' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : null;
    $name = $_POST['name'];
    $host = $_POST['host'];
    $port = $_POST['port'] ?: 8728;
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    if ($id) {
        // Ownership Check
        $check = $db->query("SELECT tenant_id FROM routers WHERE id = $id")->fetchColumn();
        $is_owner = ($u_role === 'admin') ? ($check == $tenant_id) : (/* restricted */ false);
        if ($is_owner) {
            $db->prepare("UPDATE routers SET name=?, host=?, port=?, username=?, password=? WHERE id=? AND tenant_id=?")->execute([$name, $host, $port, $username, $password, $id, $tenant_id]);
        }
    } else {
        $db->prepare("INSERT INTO routers (name, host, port, username, password, created_by, tenant_id) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$name, $host, $port, $username, $password, $u_id, $tenant_id]);
    }
    header("Location: index.php?page=admin_router");
    exit;
}

if ($action === 'delete_router') {
    $id = intval($_GET['id']);
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    // Ownership Check
    $check = $db->query("SELECT tenant_id FROM routers WHERE id = $id")->fetchColumn();
    $is_owner = ($u_role === 'admin') ? ($check == $tenant_id) : (/* restricted */ false);
    if ($is_owner) {
        $db->exec("DELETE FROM routers WHERE id=$id AND tenant_id=$tenant_id");
    }
    header("Location: index.php?page=admin_router");
    exit;
}

// Scoping Logic
$tenant_id = $_SESSION['tenant_id'] ?? 1;
$scope_where = "WHERE tenant_id = $tenant_id";

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
        $routers = $db->query("SELECT * FROM routers $scope_where")->fetchAll();
    } catch(Exception $e) { $routers = []; } 
?>
<div class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h2 class="m-0 text-xl font-bold sm:text-2xl">Manajemen router</h2>
    </div>
    <button class="ui-btn ui-btn-primary w-full sm:w-auto" onclick="document.getElementById('addRouterModal').style.display='flex'"><i class="fas fa-plus"></i> Tambah router</button>
</div>

<section class="ui-card overflow-hidden">
    <div class="table-container overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                    <th class="px-4 py-2.5 font-semibold">Label perangkat</th>
                    <th class="px-4 py-2.5 font-semibold">IP / host</th>
                    <th class="px-4 py-2.5 font-semibold">Status API</th>
                    <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
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
                <tr class="border-t border-solid border-border">
                    <td class="px-4 py-3 font-semibold"><?= htmlspecialchars($rt['name']) ?></td>
                    <td class="px-4 py-3 font-mono text-xs"><?= htmlspecialchars($rt['host']) ?>:<?= htmlspecialchars($rt['port']) ?></td>
                    <td class="px-4 py-3">
                        <?php if($isCon): ?>
                            <span class="ui-badge ui-badge-signal">Connected</span>
                        <?php else: ?>
                            <span class="ui-badge ui-badge-danger">Disconnected</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="inline-flex flex-wrap justify-end gap-1">
                            <a href="index.php?page=admin_router&action=view&id=<?= $rt['id'] ?>" class="ui-btn ui-btn-sm ui-btn-primary">Dashboard</a>
                            <a href="#" onclick="editRouter(<?= htmlspecialchars(json_encode($rt)) ?>)" class="ui-btn ui-btn-sm ui-btn-outline">Edit</a>
                            <a data-method="post" href="index.php?page=admin_router&action=delete_router&id=<?= $rt['id'] ?>" class="ui-btn ui-btn-sm ui-btn-outline text-danger" onclick="return confirm('Hapus router ini permanen?')">Hapus</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(count($routers) == 0): ?>
                    <tr class="border-t border-solid border-border"><td colspan="4" class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada perangkat router yang dimasukkan. <br><div class="mt-1 text-xs">(Jalankan app/migrate8.php jika sebelumnya sudah pernah instal)</div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Modal Router -->
<div id="addRouterModal" class="fixed inset-0 z-[9999] items-center justify-center bg-black/50 p-4" style="display:none;">
    <div class="ui-card w-full max-w-md p-5 sm:p-6">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h3 id="modalTitle" class="m-0 text-lg font-bold">Tambah router baru</h3>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="document.getElementById('addRouterModal').style.display='none'" aria-label="Tutup">✕</button>
        </div>
        <form action="index.php?page=admin_router&action=save_router" method="POST">
<?= csrf_field() ?>
            <input type="hidden" name="id" id="rt_id">
            <div class="grid gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama label (misal: Mikrotik Pusat Server)</span>
                    <input type="text" name="name" id="rt_name" class="form-control" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat IP / host API</span>
                    <input type="text" name="host" id="rt_host" class="form-control" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Port API (default: 8728)</span>
                    <input type="number" name="port" id="rt_port" class="form-control" value="8728" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Username router</span>
                    <input type="text" name="username" id="rt_user" class="form-control" required>
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Password router</span>
                    <input type="password" name="password" id="rt_pass" class="form-control">
                </label>
            </div>
            <div class="form-actions-row mt-6 flex justify-end gap-2">
                <button type="button" class="ui-btn ui-btn-outline" onclick="document.getElementById('addRouterModal').style.display='none'">Batal</button>
                <button type="submit" class="ui-btn ui-btn-primary">Simpan router</button>
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
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $router = $db->query("SELECT * FROM routers WHERE id = $id AND tenant_id = $tenant_id")->fetch();
    
    if (!$router) {
        header("Location: index.php?page=admin_router");
        exit;
    }

    // Ownership Check (already implicitly limited by SELECT)
    $is_owner = ($router) ? true : false;
    if (!$is_owner) {
        header("Location: index.php?page=admin_router");
        exit;
    }

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
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Monitoring: <?= htmlspecialchars($router['name']) ?></h2>
            <?php if($connected): ?><p class="m-0 mt-1 font-mono text-sm text-muted-foreground"><?= htmlspecialchars($router['host']) ?></p><?php endif; ?>
        </div>
        <a href="index.php?page=admin_router" class="ui-btn ui-btn-outline"><i class="fas fa-arrow-left"></i> Kembali ke list router</a>
    </div>

    <?php if(!$connected): ?>
        <div class="ui-card mx-auto max-w-xl border-danger/40 p-5 text-sm sm:p-6">
            <span class="font-semibold text-danger">Koneksi router gagal.</span> <?= htmlspecialchars($error_msg) ?>
        </div>
    <?php else: ?>
        <div class="metrics-grid mb-5 grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div class="metric-card ui-card p-4">
                <div class="metric-title text-xs font-medium text-muted-foreground">CPU load</div>
                <div class="metric-value mt-1 text-2xl font-extrabold tabular-nums"><?= htmlspecialchars($resources['cpu-load'] ?? 0) ?>%</div>
            </div>
            <div class="metric-card ui-card p-4">
                <div class="metric-title text-xs font-medium text-muted-foreground">Uptime</div>
                <div class="metric-value mt-1 text-xl font-extrabold tabular-nums"><?= htmlspecialchars($resources['uptime'] ?? '-') ?></div>
            </div>
            <div class="metric-card ui-card p-4">
                <div class="metric-title text-xs font-medium text-muted-foreground">Sisa memori</div>
                <div class="metric-value mt-1 text-2xl font-extrabold tabular-nums"><?= formatBytes($resources['free-memory'] ?? 0) ?></div>
            </div>
            <div class="metric-card ui-card p-4">
                <div class="metric-title text-xs font-medium text-muted-foreground">Koneksi aktif PPPoE</div>
                <div class="metric-value mt-1 text-2xl font-extrabold tabular-nums"><?= count($active_ppp) ?></div>
            </div>
        </div>

        <section class="ui-card mb-5 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
                <h3 class="m-0 text-[15px] font-bold">Live traffic</h3>
                <select id="interfaceSelect" class="form-control w-auto" onchange="changeInterface()">
                    <?php foreach($interfaces as $iface): ?>
                        <option value="<?= htmlspecialchars($iface['name']) ?>"><?= htmlspecialchars($iface['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="p-4 sm:p-5">
                <div style="position: relative; height: 300px; width: 100%;">
                    <canvas id="trafficChart"></canvas>
                </div>
            </div>
        </section>

        <?php
            // Cross-reference secrets with active connections
            $active_names = [];
            foreach($active_ppp as $act) {
                $active_names[$act['name']] = $act;
            }
        ?>
        <section class="ui-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
                <div>
                    <h3 class="m-0 text-[15px] font-bold">Registri PPPoE secrets</h3>
                    <p class="m-0 text-xs text-muted-foreground"><span class="tabular-nums"><?= count($all_secrets) ?></span> total | <span class="tabular-nums"><?= count($active_ppp) ?></span> online</p>
                </div>
                <div class="flex gap-2">
                    <span class="ui-badge ui-badge-signal">Online <?= count($active_ppp) ?></span>
                    <span class="ui-badge ui-badge-muted">Offline <?= count($all_secrets) - count($active_ppp) ?></span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="table w-full border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                            <th class="px-4 py-2.5 font-semibold">No</th>
                            <th class="px-4 py-2.5 font-semibold">PPPoE name / secret</th>
                            <th class="px-4 py-2.5 font-semibold">Profile</th>
                            <th class="px-4 py-2.5 text-center font-semibold">Status</th>
                            <th class="px-4 py-2.5 font-semibold">IP address</th>
                            <th class="px-4 py-2.5 font-semibold">Uptime</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($all_secrets) > 0): ?>
                            <?php $no=1; foreach($all_secrets as $secret):
                                $name = $secret['name'] ?? '';
                                $isOnline = isset($active_names[$name]);
                                $activeData = $isOnline ? $active_names[$name] : null;
                            ?>
                                <tr class="border-t border-solid border-border">
                                    <td class="px-4 py-3 tabular-nums text-muted-foreground"><?= $no++ ?></td>
                                    <td class="px-4 py-3 font-semibold <?= $isOnline ? 'text-foreground' : 'text-muted-foreground' ?>">
                                        <?= htmlspecialchars($name) ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-md bg-muted px-2 py-0.5 font-mono text-xs">
                                            <?= htmlspecialchars($secret['profile'] ?? '-') ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if($isOnline): ?>
                                            <span class="ui-badge ui-badge-signal">Online</span>
                                        <?php else: ?>
                                            <span class="ui-badge ui-badge-muted">Offline</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs">
                                        <?= $isOnline ? htmlspecialchars($activeData['address'] ?? '-') : '<span class="text-muted-foreground">—</span>' ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs tabular-nums">
                                        <?= $isOnline ? htmlspecialchars($activeData['uptime'] ?? '-') : '<span class="text-muted-foreground">—</span>' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="border-t border-solid border-border"><td colspan="6" class="px-5 py-10 text-center text-sm text-muted-foreground">Tidak ada PPPoE Secret terdaftar di router ini.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

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
