<?php
// Simple create-invoice page for admin/partner quick access
 $u_role = $_SESSION['user_role'] ?? 'guest';
if (!in_array($u_role, ['admin','partner'])) {
    echo "<div class='glass-panel' style='padding:40px; text-align:center;'><h2>Akses Ditolak</h2></div>"; return;
}

// Normalize existing temporary customers so they don't appear in kemitraan lists (Tenant Scoped)
if ($u_role === 'admin') {
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    try {
        $db->exec("UPDATE customers SET created_by = 0 WHERE type IN ('note','temp') AND (created_by IS NULL OR created_by <> 0) AND tenant_id = $tenant_id");
    } catch (Exception $e) { /* ignore migration errors */ }
}
?>

<?php
// Fetch recent invoices issued by this user for history tab (safe with migrations)
$u_id = $_SESSION['user_id'] ?? 0;
$u_name = $_SESSION['user_name'] ?? '';
$invoices = [];
    try {
    $cols = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN,1);
    $has_issued_id = is_array($cols) && in_array('issued_by_id', $cols);
    $has_created_via = is_array($cols) && in_array('created_via', $cols);

    $params = [];
    if ($has_created_via) {
        // Only show quick invoices in this history
        $where = "i.created_via = 'quick' AND (";
        $conds = [];
        if ($has_issued_id) {
            $conds[] = 'i.issued_by_id = ?'; $params[] = $u_id;
        }
        $conds[] = 'i.issued_by_name = ?'; $params[] = $u_name;
        $conds[] = 'c.created_by = ?'; $params[] = $u_id;
        $where .= implode(' OR ', $conds) . ')';
    } else {
        // Fallback: include invoices that match issuer/name or customer created_by
        $where = '(';
        $conds = [];
        if ($has_issued_id) { $conds[] = 'i.issued_by_id = ?'; $params[] = $u_id; }
        $conds[] = 'i.issued_by_name = ?'; $params[] = $u_name;
        $conds[] = 'c.created_by = ?'; $params[] = $u_id;
        $where .= implode(' OR ', $conds) . ')';
    }

    $sql = "SELECT i.*, c.name as customer_name, c.created_by as customer_created_by FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE $where ORDER BY i.created_at DESC LIMIT 200";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
} catch (Exception $e) { $invoices = []; }

// Recent temporary customers (type 'note' or 'temp') for the separate sidebar tab
try {
    $recent_temps = $db->query("SELECT id, name, address, contact, registration_date FROM customers WHERE type IN ('note','temp') AND created_by = 0 ORDER BY registration_date DESC LIMIT 10")->fetchAll();
} catch (Exception $e) { $recent_temps = []; }

// Note: pendapatan handled in main reports/dashboard. no local pendapatan fetch here.
?>

<div style="max-width:1100px; margin:12px auto;">
    <div style="display:flex; gap:10px; margin-bottom:12px;">
        <button class="btn btn-sm btn-primary" id="tabCreateBtn" onclick="showTab('create')">Buat Nota</button>
        <button class="btn btn-sm btn-ghost" id="tabHistoryBtn" onclick="showTab('history')">Riwayat</button>
        <button class="btn btn-sm btn-ghost" id="tabTempsBtn" onclick="showTab('temps')">Pelanggan Baru</button>
    </div>

    <div id="createSection">
        <div class="glass-panel" style="padding:20px;">
            <h3 style="margin-top:0;"><i class="fas fa-plus-circle"></i> Buat Nota (Invoice) Cepat</h3>
            <p style="color:var(--text-secondary); margin-bottom:12px;">Isi data penerima, tambahkan item, lalu klik "Buat & Cetak".</p>

            <form method="POST" action="index.php?page=admin_assets&action=invoice_create">
                <input type="hidden" name="created_via" value="quick">
                <style>
                /* Grid alignment for create-invoice to match edit layout */
                #invoiceItemsTable { table-layout: fixed; width:100%; }
                #invoiceItemsTable tbody td { padding:10px 8px; }
                #invoiceItemsTable tbody td:first-child { width:60%; }
                #invoiceItemsTable tbody td:nth-child(2) { width:10%; }
                #invoiceItemsTable tbody td:nth-child(3) { width:15%; }
                #invoiceItemsTable tbody td:nth-child(4) { width:15%; }
                #invoiceItemsTable tbody td input { width:100%; box-sizing:border-box; }
                #invoiceItemsTable .btn-ghost { width:42px; height:42px; padding:0; border-radius:10px; }
                </style>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; align-items:start;">
                    <div>
                        <label>Nama Penerima</label>
                        <input type="text" name="recipient_name" class="form-control" placeholder="Nama orang/mitra" required>

                        <label style="margin-top:12px;">Alamat Penagihan</label>
                        <input type="text" name="billing_address" class="form-control" placeholder="Alamat untuk dicantumkan di invoice">

                        <div style="display:flex; gap:10px; margin-top:12px;">
                            <div style="flex:1;">
                                <label>No. HP / Telepon</label>
                                <input type="text" name="billing_phone" class="form-control" placeholder="0812xxxx">
                            </div>
                            <div style="width:180px;">
                                <label>Email</label>
                                <input type="email" name="billing_email" class="form-control" placeholder="email@example.com">
                            </div>
                        </div>

                        <label style="margin-top:12px;">Instruksi Pembayaran</label>
                        <textarea name="payment_instructions" class="form-control" rows="3" placeholder="Contoh: Transfer ke BCA 123456789 a.n. PT Contoh"></textarea>
                    </div>

                    <div style="border-left:1px solid rgba(255,255,255,0.04); padding-left:14px;">
                        <label>Tanggal Jatuh Tempo</label>
                        <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d') ?>">

                        <input type="hidden" name="amount" id="invoice_total" value="0">
                        <div style="margin-top:18px; font-weight:700; font-size:20px;">Total Nota</div>
                        <div style="font-size:20px; color:var(--primary); margin-top:6px;">Rp <span id="invoice_total_display">0</span></div>

                        <div style="display:flex; gap:10px; margin-top:18px;">
                            <button class="btn btn-ghost" type="button" onclick="history.back()">Batal</button>
                            <button class="btn btn-primary" type="submit">Buat & Cetak</button>
                        </div>
                    </div>
                </div>

                <div style="margin-top:18px;">
                    <div style="background:transparent; padding:12px; border-radius:8px;">
                        <h4 style="margin:4px 0 12px;"><i class="fas fa-list"></i> Daftar Item</h4>
                        <div style="overflow:auto;">
                            <table id="invoiceItemsTable" style="width:100%; border-collapse:collapse;">
                                <thead>
                                    <tr style="background:var(--nav-active-bg);">
                                        <th style="padding:8px; text-align:left; width:55%">Deskripsi</th>
                                        <th style="padding:8px; text-align:center; width:12%">Jumlah</th>
                                        <th style="padding:8px; text-align:right; width:16%">Harga Satuan (Rp)</th>
                                        <th style="padding:8px; text-align:right; width:12%">Total (Rp)</th>
                                        <th style="padding:8px; text-align:center; width:5%">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td style="padding:8px;"><input type="text" name="item_desc[]" class="form-control" placeholder="Contoh: Router Model X" required></td>
                                        <td style="padding:8px; text-align:center;"><input type="number" name="item_qty[]" class="form-control" value="1" min="1" required oninput="CreateInvoice.recalculateRow(this)"></td>
                                        <td style="padding:8px;"><input type="number" name="item_unit[]" class="form-control" value="0" required oninput="CreateInvoice.recalculateRow(this)"></td>
                                        <td style="padding:8px;"><input type="number" name="item_amount[]" class="form-control" value="0" readonly></td>
                                        <td style="padding:8px; text-align:center;"><button type="button" class="btn btn-ghost" onclick="CreateInvoice.removeItemRow(this)"><i class="fas fa-trash"></i></button></td>
                                    </tr>
                                </tbody>
                            </table>

                            <div style="margin-top:10px; display:flex; gap:10px;">
                                <button type="button" class="btn btn-sm btn-primary" onclick="CreateInvoice.addItemRow()"><i class="fas fa-plus"></i> Tambah Baris</button>
                                <button type="button" class="btn btn-sm btn-ghost" onclick="CreateInvoice.clearItemRows()">Bersihkan</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div id="historySection" style="display:none;">
        <div class="glass-panel" style="padding:16px; width:100%; margin:0 0 30px;">
            <h4 style="margin:4px 0 12px;"><i class="fas fa-history"></i> Riwayat Invoice yang Dibuat</h4>
            <?php if(empty($invoices)): ?>
                <div style="color:var(--text-secondary);">Belum ada invoice yang Anda buat.</div>
            <?php else: ?>
                <?php
                    // Recent temporary customers (type 'note' or 'temp') to help quick invoice creation
                    try {
                        $recent_temps = $db->query("SELECT id, name, address, contact, registration_date FROM customers WHERE type IN ('note','temp') ORDER BY registration_date DESC LIMIT 10")->fetchAll();
                    } catch (Exception $e) { $recent_temps = []; }
                ?>
                <div style="display:grid; grid-template-columns: 1fr; gap:12px; align-items:start;">
                    <div style="overflow:auto; width:100%;">
                        <table class="table" style="font-size:13px; width:100%; table-layout:fixed;">
                        <thead>
                            <tr style="background:var(--nav-active-bg);">
                                <th style="padding:8px; text-align:left; width:140px;">Tanggal</th>
                                <th style="padding:8px; text-align:left;">#INV / Penerima</th>
                                <th style="padding:8px; text-align:center; width:160px;">Aksi</th>
                                <th style="padding:8px; text-align:right; width:140px;">Jumlah (Rp)</th>
                                <th style="padding:8px; text-align:center; width:120px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($invoices as $inv):
                                $paid = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = " . intval($inv['id']))->fetchColumn() ?: 0;
                                $due = floatval($inv['amount']) - floatval($inv['discount'] ?? 0);
                                $is_paid = ($paid >= $due && $due > 0);
                                // items
                                $items = [];
                                try { $stmt_it = $db->prepare("SELECT description, qty, unit_price, amount FROM invoice_items WHERE invoice_id = ?"); $stmt_it->execute([intval($inv['id'])]); $items = $stmt_it->fetchAll(); } catch (Exception $e) { $items = []; }
                            ?>
                            <tr>
                                <td style="padding:8px; vertical-align:top;"><?= date('d/m H:i', strtotime($inv['created_at'])) ?></td>
                                <td style="padding:8px; vertical-align:top;"><strong>INV-<?= str_pad($inv['id'],5,'0',STR_PAD_LEFT) ?></strong><br><span style="color:var(--text-secondary); font-size:13px;"><?= htmlspecialchars($inv['customer_name'] ?? $inv['name'] ?? '-') ?></span>
                                    <?php if(!empty($inv['billing_address'])): ?><div style="font-size:12px; color:var(--text-secondary); margin-top:4px;"><?= htmlspecialchars($inv['billing_address']) ?></div><?php endif; ?>
                                </td>
                                <td style="padding:8px; text-align:center; vertical-align:top;">
                                    <div style="display:inline-flex; gap:6px; align-items:center;">
                                    <?php if (!$is_paid): ?>
                                        <a class="btn btn-xs btn-success" title="Bayar" href="index.php?page=admin_assets&action=invoice_mark_paid&id=<?= intval($inv['id']) ?>" onclick="return confirm('Tandai sebagai sudah dibayar?')"><i class="fas fa-money-bill-wave"></i></a>
                                    <?php endif; ?>
                                        <a class="btn btn-xs btn-ghost" title="Cetak" href="index.php?page=admin_invoices&action=print&id=<?= intval($inv['id']) ?>"><i class="fas fa-print"></i></a>
                                        <a class="btn btn-xs btn-ghost" title="Edit" href="index.php?page=admin_edit_quick_invoice&id=<?= intval($inv['id']) ?>"><i class="fas fa-edit"></i></a>
                                        <a class="btn btn-xs btn-danger" title="Hapus" href="index.php?page=admin_assets&action=invoice_delete_quick&id=<?= intval($inv['id']) ?>" onclick="return confirm('Hapus invoice ini?')"><i class="fas fa-trash"></i></a>
                                        <button class="btn btn-xs btn-ghost" title="Item" onclick="CreateInvoice.toggleInvoiceItems(<?= intval($inv['id']) ?>)"><i class="fas fa-list"></i></button>
                                    </div>
                                </td>
                                <td style="padding:8px; text-align:right; vertical-align:top;">Rp <?= number_format($inv['amount'],0,',','.') ?></td>
                                <td style="padding:8px; text-align:center; vertical-align:top; color:<?= $is_paid ? '#10b981' : '#ef4444' ?>; font-weight:700;"><?= $is_paid ? 'Sudah Bayar' : 'Belum Bayar' ?></td>
                            </tr>
                            <tr id="invItems-<?= intval($inv['id']) ?>" style="display:none; background:rgba(255,255,255,0.02);">
                                <td colspan="5" style="padding:8px;">
                                    <div style="overflow:auto;">
                                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                            <thead>
                                                <tr style="background:transparent;">
                                                    <th style="text-align:left; padding:6px;">Keterangan</th>
                                                    <th style="text-align:right; padding:6px; width:110px;">Harga</th>
                                                    <th style="text-align:center; padding:6px; width:60px;">Jml</th>
                                                    <th style="text-align:right; padding:6px; width:120px;">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if(empty($items)): ?>
                                                    <tr><td colspan="4" style="padding:8px; color:var(--text-secondary);">Tidak ada item tercatat.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach($items as $it):
                                                        $qty = intval($it['qty'] ?? 1);
                                                        $unit = isset($it['unit_price']) ? floatval($it['unit_price']) : ( ($qty>0) ? round(floatval($it['amount'])/$qty) : floatval($it['amount']) );
                                                        $lt = floatval($it['amount']);
                                                    ?>
                                                    <tr>
                                                        <td style="padding:6px;"><?= htmlspecialchars($it['description']) ?></td>
                                                        <td style="padding:6px; text-align:right;">Rp <?= number_format($unit,0,',','.') ?></td>
                                                        <td style="padding:6px; text-align:center;"><?= $qty ?></td>
                                                        <td style="padding:6px; text-align:right;">Rp <?= number_format($lt,0,',','.') ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="tempsSection" style="display:none;">
        <div class="glass-panel" style="padding:16px; width:100%; margin:0 0 30px;">
            <h4 style="margin:4px 0 12px;"><i class="fas fa-users"></i> Pelanggan Baru (Sementara)</h4>
            <?php if(empty($recent_temps)): ?>
                <div style="color:var(--text-secondary);">Tidak ada pelanggan sementara.</div>
            <?php else: ?>
                <style>
                .temps-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px; }
                .temp-card { padding:12px; border-radius:12px; background:rgba(0,0,0,0.02); display:flex; flex-direction:column; gap:8px; }
                .temp-card .meta { font-size:13px; color:var(--text-secondary); }
                .temp-card .actions { display:flex; gap:8px; margin-top:8px; }
                .temp-card .actions form { margin:0; }
                </style>
                <div class="temps-grid">
                    <?php foreach($recent_temps as $t): ?>
                        <div class="temp-card">
                            <div style="font-weight:700; font-size:15px;"><?= htmlspecialchars($t['name']) ?></div>
                            <div class="meta"><?= htmlspecialchars($t['contact'] ?: '-') ?></div>
                            <div class="meta" style="white-space:normal;"><?= htmlspecialchars($t['address'] ?: '-') ?></div>
                            <div class="actions">
                                <button class="btn btn-sm btn-primary" onclick="useTempCustomer(<?= intval($t['id']) ?>)">Gunakan</button>
                                <a class="btn btn-sm btn-ghost" href="index.php?page=admin_customers&action=details&id=<?= intval($t['id']) ?>">Detail</a>
                                <form method="POST" action="index.php?page=admin_temp_customers">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= intval($t['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Hapus pelanggan sementara ini?')">Hapus</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pendapatan tab removed: payments go to main reports/dashboard -->

    <script>
window.CreateInvoice = (function(){
    function addItemRow() {
        const tb = document.getElementById('invoiceItemsTable').querySelector('tbody');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="padding:8px;"><input type="text" name="item_desc[]" class="form-control" placeholder="Deskripsi item" required></td>
            <td style="padding:8px; text-align:center;"><input type="number" name="item_qty[]" class="form-control" value="1" min="1" required oninput="CreateInvoice.recalculateRow(this)"></td>
            <td style="padding:8px;"><input type="number" name="item_unit[]" class="form-control" value="0" required oninput="CreateInvoice.recalculateRow(this)"></td>
            <td style="padding:8px;"><input type="number" name="item_amount[]" class="form-control" value="0" readonly></td>
            <td style="padding:8px; text-align:center;"><button type="button" class="btn btn-ghost" onclick="CreateInvoice.removeItemRow(this)"><i class="fas fa-trash"></i></button></td>
        `;
        tb.appendChild(tr);
    }
    function removeItemRow(btn) {
        const tr = btn.closest('tr');
        if (tr) tr.remove();
    }
    function clearItemRows() {
        const tb = document.getElementById('invoiceItemsTable').querySelector('tbody');
        tb.innerHTML = '';
        addItemRow();
    }
    function recalculateRow(el) {
        const tr = el.closest('tr');
        if (!tr) return;
        const qtyEl = tr.querySelector('input[name="item_qty[]"]');
        const unitEl = tr.querySelector('input[name="item_unit[]"]');
        const amountEl = tr.querySelector('input[name="item_amount[]"]');
        const qty = parseInt(qtyEl.value) || 0;
        const unit = parseFloat(unitEl.value) || 0;
        const line = qty * unit;
        amountEl.value = Math.round(line);
        updateGrandTotal();
    }
    function updateGrandTotal() {
        const amounts = Array.from(document.querySelectorAll('input[name="item_amount[]"]'));
        let total = 0;
        amounts.forEach(a => total += parseFloat(a.value) || 0);
        const invTotalEl = document.getElementById('invoice_total');
        if (invTotalEl) invTotalEl.value = Math.round(total);
        const disp = document.getElementById('invoice_total_display');
        if (disp) disp.innerText = new Intl.NumberFormat('id-ID').format(Math.round(total));
    }
        function init() {
            document.querySelectorAll('input[name="item_qty[]"]').forEach(i => CreateInvoice.recalculateRow(i));
            CreateInvoice.updateGrandTotal();
    }
    return { addItemRow, removeItemRow, clearItemRows, recalculateRow, updateGrandTotal, init };
})();

// initialize
document.addEventListener('DOMContentLoaded', function(){ try{ if(window.CreateInvoice) window.CreateInvoice.init(); }catch(e){} });

function showTab(name) {
    document.getElementById('createSection').style.display = (name === 'create') ? 'block' : 'none';
    document.getElementById('historySection').style.display = (name === 'history') ? 'block' : 'none';
    document.getElementById('tempsSection').style.display = (name === 'temps') ? 'block' : 'none';
    document.getElementById('tabCreateBtn').className = name === 'create' ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-ghost';
    document.getElementById('tabHistoryBtn').className = name === 'history' ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-ghost';
    const tTemps = document.getElementById('tabTempsBtn'); if(tTemps) tTemps.className = name === 'temps' ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-ghost';
}
CreateInvoice.toggleItems = function(id) {
    window.open('index.php?page=admin_invoices&action=print&id=' + id, '_blank');
};
</script>
<script>
CreateInvoice.toggleInvoiceItems = function(id) {
    const el = document.getElementById('invItems-' + id);
    if(!el) return;
    el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'table-row' : 'none';
};
</script>
<script>
// Recent temps data and helper to populate form
let tempCustomers = {};
try {
    tempCustomers = <?= json_encode($recent_temps ?? []) ?>;
} catch(e) { tempCustomers = {}; }

function useTempCustomer(id) {
    const t = (Array.isArray(tempCustomers) ? tempCustomers.find(x => parseInt(x.id) === parseInt(id)) : null);
    if (!t) return alert('Data pelanggan tidak ditemukan.');
    // switch to create tab and fill fields
    showTab('create');
    document.querySelector('input[name="recipient_name"]').value = t.name || '';
    document.querySelector('input[name="billing_address"]').value = t.address || '';
    document.querySelector('input[name="billing_phone"]').value = t.contact || '';
    // focus first item desc
    const firstDesc = document.querySelector('input[name="item_desc[]"]');
    if (firstDesc) firstDesc.focus();
    if (window.CreateInvoice) CreateInvoice.updateGrandTotal();
}
</script>
<script>
// Defensive binding for tab buttons in case inline handlers don't run
document.addEventListener('DOMContentLoaded', function(){
    try {
        if(typeof showTab !== 'function') return;
        const tCreate = document.getElementById('tabCreateBtn');
        const tHistory = document.getElementById('tabHistoryBtn');
        if(tCreate) { tCreate.removeAttribute('onclick'); tCreate.addEventListener('click', function(e){ e.preventDefault(); showTab('create'); }); }
        if(tHistory) { tHistory.removeAttribute('onclick'); tHistory.addEventListener('click', function(e){ e.preventDefault(); showTab('history'); }); }
        const tTempsBtn = document.getElementById('tabTempsBtn');
        if(tTempsBtn) { tTempsBtn.removeAttribute('onclick'); tTempsBtn.addEventListener('click', function(e){ e.preventDefault(); showTab('temps'); }); }
        // Ensure default
        showTab('create');
    } catch (e) { console.warn('tab binding failed', e); }
});
</script>
