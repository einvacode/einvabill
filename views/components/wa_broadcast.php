<?php
// Menerima parameter $collector_area dari file induk (jika ada), bila kosong berati global (Admin)
$area_filter = "";
if (isset($collector_area) && !empty(trim($collector_area))) {
    $area_filter = " AND c.area = " . $db->quote(trim($collector_area));
}

// Role-based scope filter
$u_role = $_SESSION['user_role'] ?? 'admin';
$u_id = $_SESSION['user_id'] ?? 0;
$scope_where = ($u_role === 'admin') ? " AND (c.created_by = 0 OR c.created_by IS NULL) " : " AND (c.created_by = $u_id) ";

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
      $scope_where
      $area_filter
    GROUP BY c.id
    ORDER BY nearest_due ASC
";
$targets = $db->query($query_wa)->fetchAll();

// Setting WA dari database (Pusat)
$stg = $db->query("SELECT wa_template, bank_account, site_url FROM settings WHERE id=1")->fetch();

// Priority: Use Partner's custom template and bank if available
if ($u_role === 'partner') {
    $p_stg = $db->query("SELECT wa_template, brand_bank, brand_rekening FROM users WHERE id = $u_id")->fetch();
    $wa_tpl = (!empty($p_stg['wa_template'])) ? $p_stg['wa_template'] : ($stg['wa_template'] ?: "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}. Transfer ke {rekening}");
    $rekening_tpl = (!empty($p_stg['brand_bank'])) ? $p_stg['brand_bank'] . " " . $p_stg['brand_rekening'] : $stg['bank_account'];
} else {
    $wa_tpl = $stg['wa_template'] ?: "Halo {nama}, tagihan internet Anda sebesar {tagihan} jatuh tempo pada {jatuh_tempo}. Transfer ke {rekening}";
    $rekening_tpl = $stg['bank_account'];
}

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

    $base_url = !empty($stg['site_url']) ? $stg['site_url'] : get_app_url();
    $portal_link = $base_url . "/index.php?page=customer_portal&code=" . $cust_id_display;
    $msg = str_replace(
        ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}', '{rekening}', '{tunggakan}', '{total_harus}', '{link_tagihan}'], 
        [$t['name'], $cust_id_display, $package_display, $inv_month, $tagihan_display, date('d M Y', strtotime($t['nearest_due'])), $rekening_tpl, $tunggakan_display, $total_harus_display, $portal_link], 
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
            <p style="font-size:13px; color:var(--text-secondary); margin-top:5px; line-height:1.4;">Mengirim tagihan H-3 jatuh tempo secara massal ke WA Web.</p>
            
            <div style="margin-top:10px; display:flex; align-items:center; gap:8px;">
                <label class="switch" style="position:relative; display:inline-block; width:34px; height:20px;">
                    <input type="checkbox" id="manualMode" style="opacity:0; width:0; height:0;">
                    <span style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background-color:#ccc; transition:.4s; border-radius:34px;"></span>
                </label>
                <span style="font-size:12px; color:var(--text-primary); font-weight:600;">Mode Konfirmasi Manual (Lebih Akurat)</span>
            </div>
        </div>
        <?php if(count($broadcast_data) > 0): ?>
            <button id="btnStartBroadcast" class="btn btn-sm" style="background:#25D366; color:white; border:none; padding:12px 24px; font-weight:700; box-shadow: 0 4px 14px rgba(37,211,102,0.3);" onclick="startBroadcast()">
                <i class="fas fa-paper-plane"></i> Mulai (<?= count($broadcast_data) ?> Antrean)
            </button>
        <?php endif; ?>
    </div>
    
    <style>
        #manualMode:checked + span { background-color: #25D366; }
        #manualMode:checked + span:before { transform: translateX(14px); }
        .switch span:before { position:absolute; content:""; height:14px; width:14px; left:3px; bottom:3px; background-color:white; transition:.4s; border-radius:50%; }
        .btn-confirm { background:#3b82f6; color:white; border:none; padding:8px 15px; border-radius:8px; cursor:pointer; font-size:12px; font-weight:700; }
        .btn-confirm:hover { background:#2563eb; }
    </style>
    
    <?php if(count($broadcast_data) == 0): ?>
        <div style="padding:15px; background:rgba(37, 211, 102, 0.05); color:var(--success); border-radius:12px; text-align:center; border: 1px dashed rgba(37, 211, 102, 0.3);">
            Tidak ada tagihan mendesak. Semua terpantau aman!
        </div>
    <?php else: ?>
        <div id="broadcastStatusArea" style="display:none; padding:15px; background:var(--bg-color); border-radius:12px; margin-bottom:15px; border: 1px solid var(--glass-border);">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div style="font-size:13px;"><strong id="statusLabel" style="color:var(--warning)">STATUS:</strong> <span id="broadcastStatusText" style="color:var(--text-primary);">Menyiapkan antrean...</span></div>
                <div id="manualActionArea" style="display:none;">
                    <button class="btn-confirm" onclick="confirmSent()"><i class="fas fa-check"></i> Sudah Terkirim & Lanjut</button>
                </div>
            </div>
            <div style="width:100%; background:var(--progress-bg); height:6px; border-radius:10px; overflow:hidden; margin-top:12px;">
                <div id="broadcastProgressBar" style="width:0%; height:100%; background:#25D366; transition: width 0.3s ease;"></div>
            </div>
        </div>
        
        <div class="table-container" style="max-height: 250px; overflow-y:auto; border:1px solid var(--glass-border);">
            <table style="width:100%; margin:0;">
                <?php foreach($broadcast_data as $idx => $bd): ?>
                <tr id="bc_row_<?= $idx ?>" style="transition:all 0.3s;">
                    <td style="padding:12px; width:40px; text-align:center;"><i id="bc_icon_<?= $idx ?>" class="fas fa-clock" style="color:var(--text-secondary);"></i></td>
                    <td style="padding:12px;"><strong style="color:var(--text-primary);"><?= $bd['name'] ?></strong></td>
                    <td style="padding:12px; font-family:monospace; color:var(--text-secondary); text-align:right; font-size:12px;"><?= '+' . $bd['phone'] ?></td>
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
let isWaitingConfirmation = false;

function startBroadcast() {
    if(isBroadcasting) return;
    let testPop = window.open('about:blank', '_blank');
    if(!testPop || testPop.closed) {
        alert("Pop-up diblokir! Izinkan pop-up di browser Anda agar tab WA Web bisa terbuka otomatis.");
        return;
    }
    testPop.close();

    const modeText = document.getElementById('manualMode').checked ? "(Mode Terpandu)" : "(Mode Otomatis)";
    if(!confirm("Mulai pengiriman ke " + broadcastData.length + " kontak? " + modeText)) return;
    
    isBroadcasting = true;
    let btn = document.getElementById('btnStartBroadcast');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Berjalan...';
    btn.disabled = true;
    btn.style.opacity = '0.7';
    document.getElementById('manualMode').disabled = true;
    
    document.getElementById('broadcastStatusArea').style.display = 'block';
    executeNextBroadcast();
}

function confirmSent() {
    isWaitingConfirmation = false;
    document.getElementById('manualActionArea').style.display = 'none';
    
    // Process mark success for current
    let iconId = `bc_icon_${broadcastIndex}`;
    let rowId = `bc_row_${broadcastIndex}`;
    document.getElementById(iconId).className = "fas fa-check-circle text-success";
    document.getElementById(rowId).style.background = "rgba(16, 185, 129, 0.1)";
    
    broadcastIndex++;
    updateProgress();
    executeNextBroadcast();
}

function updateProgress() {
    let progress = (broadcastIndex / broadcastData.length) * 100;
    document.getElementById('broadcastProgressBar').style.width = progress + '%';
}

function executeNextBroadcast() {
    if(broadcastIndex >= broadcastData.length) {
        document.getElementById('statusLabel').innerText = "SELESAI:";
        document.getElementById('statusLabel').style.color = "var(--success)";
        document.getElementById('broadcastStatusText').innerText = "Antrean selesai! Pastikan Anda sudah klik SEND di setiap tab WhatsApp.";
        document.getElementById('btnStartBroadcast').innerHTML = 'Selesai';
        isBroadcasting = false;
        document.getElementById('broadcastProgressBar').style.width = '100%';
        return;
    }
    
    let isManual = document.getElementById('manualMode').checked;
    let target = broadcastData[broadcastIndex];
    let iconId = `bc_icon_${broadcastIndex}`;
    
    document.getElementById(iconId).className = "fas fa-sync fa-spin text-warning";
    document.getElementById('broadcastStatusText').innerText = `Membuka Pesan: ${target.name}...`;
    
    let waUrl = `https://web.whatsapp.com/send?phone=${target.phone}&text=${target.text}`;
    window.open(waUrl, '_blank');
    
    if(isManual) {
        // MANUAL MODE: Wait for user interaction
        isWaitingConfirmation = true;
        document.getElementById('statusLabel').innerText = "VERIFIKASI:";
        document.getElementById('statusLabel').style.color = "#3b82f6";
        document.getElementById('broadcastStatusText').innerHTML = `Menunggu Anda klik SEND di WA Web & konfirmasi di sini: <strong>${target.name}</strong>`;
        document.getElementById('manualActionArea').style.display = 'block';
    } else {
        // AUTOMATIC MODE: Just a visual delay
        setTimeout(() => {
            document.getElementById(iconId).className = "fas fa-check-circle text-success";
            document.getElementById(`bc_row_${broadcastIndex}`).style.background = "rgba(16, 185, 129, 0.1)";
            
            broadcastIndex++;
            updateProgress();
            
            if (broadcastIndex < broadcastData.length) {
                let cooldown = 10;
                let cooldownInterval = setInterval(() => {
                    document.getElementById('statusLabel').innerText = "JEDA:";
                    document.getElementById('statusLabel').style.color = "var(--warning)";
                    document.getElementById('broadcastStatusText').innerHTML = `Berikutnya dlm <strong>${cooldown}s</strong>... (Total: ${broadcastIndex}/${broadcastData.length})`;
                    cooldown--;
                    if (cooldown < 0) {
                        clearInterval(cooldownInterval);
                        executeNextBroadcast();
                    }
                }, 1000);
            } else executeNextBroadcast();
        }, 3000); // 3s delay for opening visual before moving to cooldown
    }
}
</script>
