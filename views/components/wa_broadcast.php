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
// Exclude admin_manual invoices (separate billing system)
$query_wa = "
    SELECT 
        c.id as cust_id, c.customer_code, c.name, c.contact, c.package_name,
        SUM(i.amount) as total_current_amount,
        MIN(i.due_date) as nearest_due,
        MIN(i.id) as first_invoice_id
    FROM invoices i 
    JOIN customers c ON i.customer_id = c.id 
    WHERE i.status = 'Belum Lunas' 
      AND i.due_date <= date('now', '+3 days') 
      AND (i.created_via IS NULL OR i.created_via NOT IN ('admin_manual', 'quick', 'external'))
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
        'text' => urlencode($msg),
        'customer_id' => $t['cust_id'],
        'invoice_id' => $t['first_invoice_id']
    ];
}
?>

<section class="ui-card mb-6 p-4 sm:p-5">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h3 class="m-0 text-[15px] font-bold">Broadcast pengingat WhatsApp</h3>
            <p class="m-0 mt-1 text-xs text-muted-foreground">Mengirim tagihan H-3 jatuh tempo secara massal ke WA Web.</p>

            <label class="switch mt-3 inline-flex cursor-pointer items-center gap-2">
                <input type="checkbox" id="manualMode" class="peer sr-only">
                <span class="relative inline-block h-5 w-9 rounded-full bg-muted transition-colors before:absolute before:bottom-[3px] before:left-[3px] before:h-3.5 before:w-3.5 before:rounded-full before:bg-white before:shadow before:transition-transform before:content-[''] peer-checked:bg-primary peer-checked:before:translate-x-4"></span>
                <span class="text-xs font-medium text-foreground">Mode konfirmasi manual (lebih akurat)</span>
            </label>
        </div>
        <?php if(count($broadcast_data) > 0): ?>
            <button id="btnStartBroadcast" class="ui-btn ui-btn-wa w-full sm:w-auto" onclick="startBroadcast()">
                <i class="fas fa-paper-plane"></i> Mulai (<?= count($broadcast_data) ?> antrean)
            </button>
        <?php endif; ?>
    </div>

    <?php if(count($broadcast_data) == 0): ?>
        <div class="rounded-lg border border-solid border-border px-5 py-8 text-center text-sm text-muted-foreground">
            Tidak ada tagihan mendesak. Semua terpantau aman.
        </div>
    <?php else: ?>
        <div id="broadcastStatusArea" class="mb-4 rounded-lg border border-solid border-border bg-muted p-4" style="display:none;">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="text-[13px]"><strong id="statusLabel" class="text-accent-ink">STATUS:</strong> <span id="broadcastStatusText" class="text-foreground">Menyiapkan antrean...</span></div>
                <div id="manualActionArea" style="display:none;">
                    <button class="ui-btn ui-btn-sm ui-btn-primary" onclick="confirmSent()"><i class="fas fa-check"></i> Sudah terkirim, lanjut</button>
                </div>
            </div>
            <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-border">
                <div id="broadcastProgressBar" class="h-full bg-signal transition-[width] duration-300" style="width:0%;"></div>
            </div>
        </div>

        <div class="table-container max-h-64 overflow-y-auto rounded-lg border border-solid border-border">
            <table class="w-full border-collapse text-sm" style="margin:0;">
                <?php foreach($broadcast_data as $idx => $bd): ?>
                <tr id="bc_row_<?= $idx ?>" class="border-t border-solid border-border first:border-t-0">
                    <td class="w-10 px-3 py-2.5 text-center"><i id="bc_icon_<?= $idx ?>" class="fas fa-clock text-muted-foreground"></i></td>
                    <td class="px-3 py-2.5 font-semibold text-foreground"><?= $bd['name'] ?></td>
                    <td class="px-3 py-2.5 text-right font-mono text-xs tabular-nums text-muted-foreground"><?= '+' . $bd['phone'] ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

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
    
    // Log WA message to database
    let target = broadcastData[broadcastIndex];
    if (target.customer_id && target.invoice_id) {
        fetch('api_log_wa_message.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                invoice_id: target.invoice_id,
                customer_id: target.customer_id,
                customer_name: target.name,
                phone_number: target.phone,
                message_type: 'reminder',
                status: 'sent'
            })
        }).catch(err => console.log('WA log error:', err));
    }
    
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
        // MANUAL MODE: Open Web WA tab and wait for user interaction
        let waUrl = `https://web.whatsapp.com/send?phone=${target.phone}&text=${target.text}`;
        window.open(waUrl, '_blank');
        
        isWaitingConfirmation = true;
        document.getElementById('statusLabel').innerText = "VERIFIKASI:";
        document.getElementById('statusLabel').style.color = "#3b82f6";
        document.getElementById('broadcastStatusText').innerHTML = `Menunggu Anda klik SEND di WA Web & konfirmasi di sini: <strong>${target.name}</strong>`;
        document.getElementById('manualActionArea').style.display = 'block';
    } else {
        // AUTOMATIC MODE: Use Automated Gateway
        document.getElementById('statusLabel').innerText = "MENGIRIM:";
        document.getElementById('statusLabel').style.color = "#25D366";
        document.getElementById('broadcastStatusText').innerHTML = `Mengirim pesan otomatis ke: <strong>${target.name}</strong>...`;
        
        sendWAGateway(target.phone, decodeURIComponent(target.text), null, null).then(result => {
            document.getElementById(iconId).className = "fas fa-check-circle text-success";
            document.getElementById(`bc_row_${broadcastIndex}`).style.background = "rgba(16, 185, 129, 0.1)";
            
            // Log WA message to database
            if (target.customer_id && target.invoice_id) {
                fetch('api_log_wa_message.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        invoice_id: target.invoice_id,
                        customer_id: target.customer_id,
                        customer_name: target.name,
                        phone_number: target.phone,
                        message_type: 'reminder',
                        status: 'sent'
                    })
                }).catch(err => console.log('WA log error:', err));
            }
            
            broadcastIndex++;
            updateProgress();
            
            if (broadcastIndex < broadcastData.length) {
                let cooldown = 10;
                let cooldownInterval = setInterval(() => {
                    document.getElementById('statusLabel').innerText = "JEDA:";
                    document.getElementById('statusLabel').style.color = "var(--warning)";
                    document.getElementById('broadcastStatusText').innerHTML = `Berhenti sejekak (Anti-Blokir)... Berikutnya dlm <strong>${cooldown}s</strong> (Progress: ${broadcastIndex}/${broadcastData.length})`;
                    cooldown--;
                    if (cooldown < 0) {
                        clearInterval(cooldownInterval);
                        executeNextBroadcast();
                    }
                }, 1000);
            } else executeNextBroadcast();
        });
    }
}
</script>
