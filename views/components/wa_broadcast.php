<?php
// Menerima parameter $collector_area dari file induk (jika ada), bila kosong berati global (Admin)
$area_filter = "";
if (isset($collector_area) && !empty(trim($collector_area))) {
    $area_filter = " AND c.area = " . $db->quote(trim($collector_area));
}

// Query H-3 Jatuh Tempo (Grouped by Customer)
$query_wa = "
    SELECT 
        c.id as cust_id, c.customer_code, c.name, c.contact, c.package_name,
        SUM(i.amount) as total_current_amount,
        MIN(i.due_date) as nearest_due
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' 
      AND i.due_date <= date('now', '+3 days') 
      $area_filter
    GROUP BY c.id
    ORDER BY nearest_due ASC
";
$targets = $db->query($query_wa)->fetchAll();

// Setting WA dari database
$stg = $db->query("SELECT wa_template, bank_account FROM settings WHERE id=1")->fetch();
$wa_tpl = $stg['wa_template'] ?: "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}. Transfer ke {rekening}";

// Bangun JS Array
$broadcast_data = [];
$mon_id = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// Pre-fetch all unpaid invoices for these customers to calculate total debt including older arrears
$cust_ids = array_filter(array_unique(array_column($targets, 'cust_id')));
$all_unpaid_totals = [];
if(!empty($cust_ids)) {
    $ids_str = implode(',', $cust_ids);
    $unpaid_list = $db->query("SELECT customer_id, SUM(amount) as total FROM invoices WHERE status = 'Belum Lunas' AND customer_id IN ($ids_str) GROUP BY customer_id")->fetchAll();
    foreach($unpaid_list as $up) $all_unpaid_totals[$up['customer_id']] = $up['total'];
}

foreach($targets as $t) {
    if(empty($t['contact'])) continue;
    
    // Perbaiki nomor telepon berawalan 0 atau 62
    $raw_phone = preg_replace('/[^0-9]/', '', $t['contact']);
    if(substr($raw_phone, 0, 1) == '0') {
        $wa_number = '62' . substr($raw_phone, 1);
    } else {
        $wa_number = $raw_phone;
    }
    
    if(empty($wa_number) || strlen($wa_number) < 9) continue;
    
    $inv_month = $mon_id[intval(date('m', strtotime($t['nearest_due']))) - 1] . ' ' . date('Y', strtotime($t['nearest_due']));
    $cust_id_display = $t['customer_code'] ?: str_pad($t['cust_id'], 5, "0", STR_PAD_LEFT);
    $package_display = $t['package_name'] ?: '-';
    
    // Financial Breakdown
    $tagihan_ini = $t['total_current_amount']; // Unpaid in H-3 window
    $total_debt = $all_unpaid_totals[$t['cust_id']] ?? $tagihan_ini;
    $tunggakan_prev = $total_debt - $tagihan_ini;
    
    $tagihan_display = 'Rp ' . number_format($tagihan_ini, 0, ',', '.');
    $tunggakan_display = $tunggakan_prev > 0 ? 'Rp ' . number_format($tunggakan_prev, 0, ',', '.') : 'Rp 0';
    $total_harus_display = 'Rp ' . number_format($total_debt, 0, ',', '.');

    $msg = str_replace(
        ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{tunggakan}', '{total_harus}'], 
        [$t['name'], $cust_id_display, $package_display, $inv_month, $tagihan_display, date('d M Y', strtotime($t['nearest_due'])), $stg['bank_account'], $tunggakan_display, $total_harus_display], 
        $wa_tpl
    );
    
    // Add auto-breakdown if total_harus not explicitly in template
    if(strpos($msg, '{total_harus}') === false && strpos($msg, 'TOTAL') === false && $tunggakan_prev > 0) {
        $msg .= "\n\n*Rincian Tagihan:*";
        $msg .= "\n- Tagihan: $tagihan_display";
        $msg .= "\n- Tunggakan: $tunggakan_display";
        $msg .= "\n-------------------";
        $msg .= "\n*TOTAL: $total_harus_display*";
    }
    
    $broadcast_data[] = [
        'name' => htmlspecialchars($t['name']),
        'phone' => htmlspecialchars($wa_number),
        'text' => urlencode($msg)
    ];
}
?>

<div class="glass-panel" style="padding: 24px; margin-bottom: 30px; border-left: 5px solid #25D366; background: var(--hover-bg);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom:15px;">
        <div style="flex:1; min-width:300px;">
            <h3 style="font-size:18px; color:#25D366; margin:0;"><i class="fab fa-whatsapp"></i> Broadcast Pengingat WhatsApp</h3>
            <p style="font-size:13px; color:var(--text-secondary); margin-top:5px; line-height:1.4;">Mengirim tagihan H-3 jatuh tempo secara massal ke WA Web. (Jeda otomatis 10 detik).</p>
        </div>
        <?php if(count($broadcast_data) > 0): ?>
            <button id="btnStartBroadcast" class="btn btn-sm" style="background:#25D366; color:white; border:none; padding:12px 24px; font-weight:700; box-shadow: 0 4px 14px rgba(37,211,102,0.3);" onclick="startBroadcast()">
                <i class="fas fa-paper-plane"></i> Mulai (<?= count($broadcast_data) ?> Antrean)
            </button>
        <?php endif; ?>
    </div>
    
    <?php if(count($broadcast_data) == 0): ?>
        <div style="padding:15px; background:rgba(37, 211, 102, 0.05); color:var(--success); border-radius:12px; text-align:center; border: 1px dashed rgba(37, 211, 102, 0.3);">
            <i class="fas fa-check-double" style="font-size:24px; margin-bottom:8px; display:block;"></i>
            Tidak ada tagihan mendesak. Semua terpantau aman!
        </div>
    <?php else: ?>
        <div id="broadcastStatusArea" style="display:none; padding:15px; background:var(--bg-color); border-radius:12px; margin-bottom:15px; border-left: 3px solid var(--warning);">
            <div style="font-size:13px; margin-bottom:10px;"><strong style="color:var(--warning)">Status:</strong> <span id="broadcastStatusText" style="color:var(--text-primary);">Menyiapkan antrean...</span></div>
            <div style="width:100%; background:var(--progress-bg); height:6px; border-radius:10px; overflow:hidden;">
                <div id="broadcastProgressBar" style="width:0%; height:100%; background:#25D366; transition: width 0.3s ease;"></div>
            </div>
        </div>
        
        <div class="table-container" style="max-height: 250px; overflow-y:auto; border:1px solid var(--glass-border);">
            <table style="width:100%; margin:0;">
                <?php foreach($broadcast_data as $idx => $bd): ?>
                <tr id="bc_row_<?= $idx ?>">
                    <td style="padding:12px; width:40px; text-align:center;"><i id="bc_icon_<?= $idx ?>" class="fas fa-clock" style="color:var(--text-secondary);"></i></td>
                    <td style="padding:12px;"><strong style="color:var(--text-primary);"><?= $bd['name'] ?></strong></td>
                    <td style="padding:12px; font-family:monospace; color:var(--text-secondary); text-align:right;"><?= '+' . $bd['phone'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
const broadcastData = <?= json_encode($broadcast_data) ?>;
let broadcastIndex = 0;
let isBroadcasting = false;

function startBroadcast() {
    if(isBroadcasting) return;
    let testPop = window.open('about:blank', '_blank');
    if(!testPop || testPop.closed) {
        alert("Pop-up diblokir! Izinkan pop-up di browser Anda agar tab WA Web bisa terbuka otomatis.");
        return;
    }
    testPop.close();

    if(!confirm("Mulai pengiriman ke " + broadcastData.length + " kontak? Tab baru akan terbuka tiap 10 detik.")) return;
    
    isBroadcasting = true;
    let btn = document.getElementById('btnStartBroadcast');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Berjalan...';
    btn.disabled = true;
    btn.style.opacity = '0.7';
    
    document.getElementById('broadcastStatusArea').style.display = 'block';
    executeNextBroadcast();
}

function executeNextBroadcast() {
    if(broadcastIndex >= broadcastData.length) {
        document.getElementById('broadcastStatusText').innerText = "Selesai! Semua pesan telah diluncurkan.";
        document.getElementById('btnStartBroadcast').innerHTML = '<i class="fas fa-check"></i> Berhasil';
        isBroadcasting = false;
        document.getElementById('broadcastProgressBar').style.width = '100%';
        return;
    }
    
    let target = broadcastData[broadcastIndex];
    let rowId = `bc_row_${broadcastIndex}`;
    let iconId = `bc_icon_${broadcastIndex}`;
    
    document.getElementById(iconId).className = "fas fa-sync fa-spin text-warning";
    document.getElementById('broadcastStatusText').innerText = `Membuka tab: ${target.name}...`;
    
    let waUrl = `https://web.whatsapp.com/send?phone=${target.phone}&text=${target.text}`;
    window.open(waUrl, '_blank');
    
    setTimeout(() => {
        document.getElementById(iconId).className = "fas fa-check-circle text-success";
        document.getElementById(rowId).style.background = "rgba(16, 185, 129, 0.1)";
        
        broadcastIndex++;
        let progress = (broadcastIndex / broadcastData.length) * 100;
        document.getElementById('broadcastProgressBar').style.width = progress + '%';
        
        if (broadcastIndex < broadcastData.length) {
            let cooldown = 10;
            let cooldownInterval = setInterval(() => {
                document.getElementById('broadcastStatusText').innerHTML = `Berikutnya dlm <strong>${cooldown}s</strong>... (Total: ${broadcastIndex}/${broadcastData.length})`;
                cooldown--;
                if (cooldown < 0) {
                    clearInterval(cooldownInterval);
                    executeNextBroadcast();
                }
            }, 1000);
        } else executeNextBroadcast();
    }, 1500);
}
</script>
