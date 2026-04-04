<?php
// Tampilkan log aktivitas
$limit = 300;
try {
    $query = "
        SELECT l.*, u.name as user_name, u.role as user_role 
        FROM activity_logs l 
        LEFT JOIN users u ON l.user_id = u.id 
        ORDER BY l.id DESC 
        LIMIT $limit
    ";
    $logs = $db->query($query)->fetchAll();
} catch (Exception $e) {
    // Jika belum migrasi SQLite
    $logs = [];
}
?>

<div class="glass-panel" style="padding: 24px;">
    <h3 style="font-size:20px; margin-bottom:10px;"><i class="fas fa-history text-primary"></i> Rekam Jejak Sistem</h3>
    <p style="color:var(--text-secondary); margin-bottom:20px;">Menampilkan hingga <?= $limit ?> log aktivitas terakhir (Siapa yang melakukan pengubahan/penghapusan data).</p>
    
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width:140px;">Waktu Eksekusi</th>
                    <th style="width:150px;">Oleh</th>
                    <th style="width:120px;">Kategori</th>
                    <th>Detail Aktivitas</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($logs) == 0): ?>
                <tr>
                    <td colspan="4" style="text-align:center; padding:30px; color:var(--text-secondary);"><i class="fas fa-sleep text-secondary fa-2x"></i><br><br>Belum ada rekaman aktivitas, atau Database belum dimigrasi (Jalankan migrate7.php).</td>
                </tr>
                <?php endif; ?>
                
                <?php foreach($logs as $log): 
                    $actionClass = 'badge-primary'; // default style
                    $lowAct = strtolower($log['action']);
                    if(strpos($lowAct, 'hapus') !== false) { 
                        $actionClass = 'badge-danger'; 
                    } elseif(strpos($lowAct, 'edit') !== false || strpos($lowAct, 'ubah') !== false) { 
                        $actionClass = 'badge-warning'; 
                    } elseif(strpos($lowAct, 'lunas') !== false || strpos($lowAct, 'bayar') !== false) { 
                        $actionClass = 'badge-success'; 
                    }
                ?>
                <tr>
                    <td style="white-space:nowrap; font-size:13px; color:var(--text-secondary);"><?= date('d/m/Y', strtotime($log['created_at'])) ?><br><strong style="color:white;"><?= date('H:i:s', strtotime($log['created_at'])) ?></strong></td>
                    <td style="white-space:nowrap;">
                        <span style="font-weight:600; color:var(--text-primary);"><i class="fas fa-user text-secondary" style="margin-right:5px;"></i> <?= htmlspecialchars($log['user_name'] ?: 'Unknown') ?></span><br>
                        <span style="font-size:11px; padding:2px 6px; background:rgba(255,255,255,0.1); border-radius:4px; margin-top:4px; display:inline-block;"><?= strtoupper($log['user_role']) ?></span>
                    </td>
                    <td><span class="badge <?= $actionClass ?>"><?= htmlspecialchars($log['action']) ?></span></td>
                    <td style="line-height:1.5; color:#f8fafc;"><?= htmlspecialchars($log['description']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
