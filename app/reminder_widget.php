<?php
/**
 * Reminder Widget Component
 * Display invoices approaching due date with WhatsApp send capability
 * 
 * Use in: admin_dashboard.php and partner/dashboard.php
 * Include with: require 'invoice_reminder.php'; then render_reminder_widget()
 */

require_once __DIR__ . '/../app/invoice_reminder.php';

function render_reminder_widget($db, $tenant_id = 1, $user_id = 0, $user_role = 'admin', $widget_height = '500px') {
    /**
     * Render dashboard reminder widget
     * Shows upcoming invoices with quick action buttons
     */
    
    $reminders = get_invoices_reminder($db, $tenant_id, 3, $user_id, $user_role);
    $stats = get_reminder_statistics($db, $tenant_id, $user_id, $user_role);
    
    $total_reminder = $stats['h_1'] + $stats['h_3'] + $stats['h_5'];
    
    // Get WhatsApp template
    $me = $db->query("SELECT wa_template FROM users WHERE id = $user_id")->fetch();
    $settings = $db->query("SELECT wa_template FROM settings WHERE tenant_id = $tenant_id")->fetch();
    $wa_tpl = !empty($me['wa_template']) ? $me['wa_template'] : ($settings['wa_template'] ?? "Halo {nama}, tagihan internet Anda {tagihan} ({bulan}) jatuh tempo pada {jatuh_tempo}. Hubungi admin untuk info pembayaran.");
    
    ?>
    <div class="glass-panel" style="padding:0; overflow:hidden; display:flex; flex-direction:column; height:<?= $widget_height ?>; background:linear-gradient(135deg, rgba(239, 68, 68, 0.08) 0%, rgba(59, 130, 246, 0.08) 100%); border:1px solid var(--glass-border);">
        
        <!-- Header -->
        <div style="padding:20px; border-bottom:1px solid var(--glass-border); background:linear-gradient(90deg, rgba(239, 68, 68, 0.1) 0%, rgba(59, 130, 246, 0.1) 100%);">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <h3 style="margin:0; font-size:16px; font-weight:800;"><i class="fas fa-bell" style="color:#ef4444;"></i> Reminder Tagihan Jatuh Tempo</h3>
                    <div style="font-size:11px; color:var(--text-secondary); margin-top:4px; opacity:0.8;">Tagihan yang harus ditindaklanjuti dalam 3 hari ke depan</div>
                </div>
                <div style="text-align:center; background:rgba(239, 68, 68, 0.2); padding:8px 12px; border-radius:8px; min-width:50px;">
                    <div style="font-size:20px; font-weight:800; color:#ef4444;"><?= $total_reminder ?></div>
                    <div style="font-size:10px; color:var(--text-secondary); font-weight:700; text-transform:uppercase;">Pending</div>
                </div>
            </div>
        </div>

        <!-- Stats Bar -->
        <div style="display:flex; gap:10px; padding:12px 20px; background:rgba(255,255,255,0.01); border-bottom:1px solid var(--glass-border); font-size:11px; font-weight:700;">
            <div style="flex:1; display:flex; align-items:center; gap:6px; padding:6px 10px; background:rgba(239, 68, 68, 0.1); border-radius:6px; color:#ef4444;">
                <i class="fas fa-exclamation-circle"></i> Overdue: <?= $stats['overdue'] ?>
            </div>
            <div style="flex:1; display:flex; align-items:center; gap:6px; padding:6px 10px; background:rgba(59, 130, 246, 0.1); border-radius:6px; color:#3b82f6;">
                <i class="fas fa-clock"></i> H-3: <?= $stats['h_3'] ?>
            </div>
            <div style="flex:1; display:flex; align-items:center; gap:6px; padding:6px 10px; background:rgba(250, 204, 21, 0.1); border-radius:6px; color:#fcc34d;">
                <i class="fas fa-hourglass-half"></i> Upcoming: <?= ($stats['h_5'] + $stats['h_7']) ?>
            </div>
        </div>

        <!-- List Container -->
        <div style="flex:1; overflow-y:auto; padding:0; display:flex; flex-direction:column;">
            <?php if (empty($reminders)): ?>
                <div style="flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center; padding:40px 20px; text-align:center; color:var(--text-secondary);">
                    <i class="fas fa-check-circle" style="font-size:40px; margin-bottom:10px; opacity:0.3;"></i>
                    <p style="margin:0; font-size:13px; font-weight:600;">Tidak ada tagihan yang mendekati jatuh tempo ✨</p>
                </div>
            <?php else: ?>
                <div style="padding:12px 20px;">
                    <?php foreach ($reminders as $inv): 
                        $wa_number = preg_replace('/^0/', '62', preg_replace('/[^0-9]/', '', $inv['customer_contact']));
                        $mon_names = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                        $due_month = $mon_names[intval(date('m', strtotime($inv['due_date']))) - 1] . ' ' . date('Y', strtotime($inv['due_date']));
                        $days_text = format_days_until($inv['due_date']);
                        
                        // Generate WA message
                        $wa_msg = str_replace(
                            ['{nama}', '{id_cust}', '{paket}', '{bulan}', '{tagihan}', '{jatuh_tempo}'],
                            [
                                $inv['customer_name'],
                                '*' . ($inv['customer_code'] ?: str_pad($inv['customer_id'], 5, "0", STR_PAD_LEFT)) . '*',
                                $inv['package_name'] ?: '-',
                                $due_month,
                                '*Rp ' . number_format($inv['amount'], 0, ',', '.') . '*',
                                '*' . date('d/m/Y', strtotime($inv['due_date'])) . '*'
                            ],
                            $wa_tpl
                        );
                    ?>
                    <div style="display:grid; grid-template-columns:1fr auto; gap:12px; padding:12px; background:rgba(255,255,255,0.02); border:1px solid var(--glass-border); border-radius:10px; margin-bottom:10px; align-items:center;">
                        <div>
                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                <strong style="font-size:13px; color:var(--text-primary);"><?= htmlspecialchars($inv['customer_name']) ?></strong>
                                <span style="font-size:9px; background:<?php
                                    $days_num = (int)$inv['days_until_due'];
                                    if ($days_num < 0) echo 'rgba(239, 68, 68, 0.2); color:#ef4444;';
                                    elseif ($days_num == 0) echo 'rgba(234, 179, 8, 0.2); color:#eab308;';
                                    else echo 'rgba(59, 130, 246, 0.2); color:#3b82f6;';
                                ?>; padding:2px 6px; border-radius:3px; font-weight:700; text-transform:uppercase;">
                                    <?= $days_text ?>
                                </span>
                            </div>
                            <div style="font-size:11px; color:var(--text-secondary); opacity:0.8; line-height:1.4;">
                                <div><i class="fas fa-calendar-alt" style="width:14px;"></i> Jatuh Tempo: <strong><?= date('d/m/Y', strtotime($inv['due_date'])) ?></strong></div>
                                <div><i class="fas fa-money-bill" style="width:14px;"></i> <strong>Rp <?= number_format($inv['amount'], 0, ',', '.') ?></strong></div>
                            </div>
                        </div>
                        <button onclick="sendReminderWA('<?= $wa_number ?>', <?= htmlspecialchars(json_encode($wa_msg)) ?>, this)" class="btn btn-sm" style="background:#25D366; color:white; border:none; padding:8px 12px; border-radius:8px; font-weight:700; font-size:11px; cursor:pointer; display:flex; align-items:center; gap:6px; white-space:nowrap; transition:all 0.2s;">
                            <i class="fab fa-whatsapp"></i> <span>Kirim</span>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer Action -->
        <div style="padding:12px 20px; border-top:1px solid var(--glass-border); background:rgba(255,255,255,0.01); display:flex; gap:10px;">
            <a href="index.php?page=admin_invoices&filter_status=belum&date_to=<?= date('Y-m-d', strtotime('+3 days')) ?>" class="btn btn-ghost btn-sm" style="flex:1; text-align:center;">
                <i class="fas fa-list"></i> Lihat Semua
            </a>
            <button onclick="sendAllReminders()" class="btn btn-primary btn-sm" style="flex:1;" <?= empty($reminders) ? 'disabled' : '' ?>>
                <i class="fas fa-paper-plane"></i> Kirim Semua
            </button>
        </div>
    </div>

    <script>
    async function sendReminderWA(phone, message, btn) {
        if (!phone || !message) {
            alert('Data tidak lengkap');
            return;
        }

        const old = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

        try {
            const r = await fetch(window.WAApiProxy + 'send', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ cid: window.WAGatewayCID, phone, message })
            });
            const data = await r.json();
            if (data.error) throw new Error(data.message);

            btn.style.background = '#10b981';
            btn.innerHTML = '<i class="fas fa-check"></i> <span>Terkirim</span>';
            setTimeout(() => { 
                btn.innerHTML = old; 
                btn.disabled = false; 
                btn.style.background = '#25D366';
            }, 2000);
        } catch (e) {
            console.error('Gateway failed:', e);
            alert('Gagal mengirim: ' + e.message + '\n\nPastikan WhatsApp Gateway sudah di-scan di menu "Perangkat WhatsApp"');
            btn.innerHTML = old;
            btn.disabled = false;
        }
    }

    function sendAllReminders() {
        if (!confirm('Kirim reminder ke semua pelanggan di list ini?\n(Delay 10 detik per pesan)')) return;
        alert('Fitur kirim semua belum diimplementasikan. Kirim satu per satu untuk sekarang.');
    }
    </script>
    <?php
}
?>
