<?php
// Simple create-invoice page for admin/partner quick access
 $u_role = $_SESSION['user_role'] ?? 'guest';
if (!in_array($u_role, ['admin','partner'])) {
    echo "<div class='ui-card p-10 text-center'><h2 class='m-0 text-xl font-bold'>Akses ditolak</h2></div>"; return;
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
        // Only show quick/external invoices in this history
        $where = "i.created_via IN ('quick','external') AND (";
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

// Customers for quick invoice autofill: only manually entered temporary/non-primary records
try {
    $tenant_id = $_SESSION['tenant_id'] ?? 1;
    $existing_customers = $db->query("SELECT id, name, address, contact, package_name, monthly_fee, ip_address, customer_code FROM customers WHERE tenant_id = $tenant_id AND type IN ('note','temp') ORDER BY registration_date DESC, name ASC LIMIT 300")->fetchAll();
} catch (Exception $e) { $existing_customers = []; }

// Note: pendapatan handled in main reports/dashboard. no local pendapatan fetch here.
?>

<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Buat invoice cepat</h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Isi data penerima, tambahkan item, lalu klik "Buat & cetak".</p>
        </div>
    </div>

    <div class="mb-5 flex flex-wrap gap-2">
        <button class="btn btn-sm btn-primary" id="tabCreateBtn" onclick="showTab('create')">Buat invoice</button>
        <button class="btn btn-sm btn-ghost" id="tabHistoryBtn" onclick="showTab('history')">Riwayat</button>
        <button class="btn btn-sm btn-ghost" id="tabTempsBtn" onclick="showTab('temps')">Pelanggan baru</button>
    </div>

    <div id="createSection">
        <form method="POST" action="index.php?page=admin_assets&action=invoice_create" class="ui-card p-4 sm:p-5">
<?= csrf_field() ?>
            <input type="hidden" name="created_via" value="admin_manual">
            <input type="hidden" name="customer_id" id="quick_invoice_customer_id" value="0">
            <label class="mb-4 block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Pilih customer input manual</span>
                <select class="form-control" id="existing_customer_picker" onchange="useExistingCustomer(this.value)">
                    <option value="">-- Pilih customer input manual --</option>
                    <?php foreach($existing_customers as $cust): ?>
                        <option value="<?= intval($cust['id']) ?>">
                            <?= htmlspecialchars($cust['name']) ?><?= !empty($cust['customer_code']) ? ' - ' . htmlspecialchars($cust['customer_code']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <style>
            /* Item table: fixed column widths shared by static and JS-created rows */
            #invoiceItemsTable { table-layout: fixed; width:100%; }
            #invoiceItemsTable tbody td { padding:8px; }
            #invoiceItemsTable tbody td:first-child { width:50%; }
            #invoiceItemsTable tbody td:nth-child(2) { width:12%; }
            #invoiceItemsTable tbody td:nth-child(3) { width:17%; }
            #invoiceItemsTable tbody td:nth-child(4) { width:15%; }
            #invoiceItemsTable tbody td input { width:100%; box-sizing:border-box; }
            #invoiceItemsTable tbody td input[name="item_qty[]"] { text-align:center; }
            #invoiceItemsTable tbody td input[name="item_unit[]"],
            #invoiceItemsTable tbody td input[name="item_amount[]"] { text-align:right; }
            #invoiceItemsTable .btn-ghost { width:36px; height:36px; padding:0; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; }
            </style>
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="grid content-start gap-4">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama penerima</span>
                        <input type="text" name="recipient_name" class="form-control" placeholder="Nama orang/mitra" required>
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat penagihan</span>
                        <input type="text" name="billing_address" class="form-control" placeholder="Alamat untuk dicantumkan di invoice">
                    </label>

                    <div class="grid gap-4 sm:grid-cols-[1fr_200px]">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">No. HP / telepon</span>
                            <input type="text" name="billing_phone" class="form-control" placeholder="0812xxxx">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Email</span>
                            <input type="email" name="billing_email" class="form-control" placeholder="email@example.com">
                        </label>
                    </div>

                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Instruksi pembayaran</span>
                        <textarea name="payment_instructions" class="form-control" rows="3" placeholder="Contoh: Transfer ke BCA 123456789 a.n. PT Contoh"></textarea>
                    </label>
                </div>

                <div class="grid content-start gap-4 lg:border-l lg:border-solid lg:border-border lg:pl-5">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal jatuh tempo</span>
                        <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </label>

                    <input type="hidden" name="amount" id="invoice_total" value="0">
                    <div class="rounded-md bg-muted p-4">
                        <div class="text-xs font-medium text-muted-foreground">Total nota</div>
                        <div class="mt-1 text-2xl font-extrabold tabular-nums text-primary">Rp <span id="invoice_total_display">0</span></div>
                    </div>
                </div>
            </div>

            <div class="mt-6">
                <h3 class="m-0 mb-2 text-[15px] font-bold">Daftar item</h3>
                <div class="overflow-x-auto rounded-md border border-solid border-border">
                    <table id="invoiceItemsTable" class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                                <th class="px-2 py-2.5 font-semibold" style="width:50%">Deskripsi</th>
                                <th class="px-2 py-2.5 text-center font-semibold" style="width:12%">Jumlah</th>
                                <th class="px-2 py-2.5 text-right font-semibold" style="width:17%">Harga satuan (Rp)</th>
                                <th class="px-2 py-2.5 text-right font-semibold" style="width:15%">Total (Rp)</th>
                                <th class="px-2 py-2.5 text-center font-semibold">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td style="padding:8px;"><input type="text" name="item_desc[]" class="form-control" placeholder="Contoh: Router Model X" required></td>
                                <td style="padding:8px; text-align:center;"><input type="number" name="item_qty[]" class="form-control" value="1" min="1" required oninput="CreateInvoice.recalculateRow(this)"></td>
                                <td style="padding:8px;"><input type="number" name="item_unit[]" class="form-control" value="0" required oninput="CreateInvoice.recalculateRow(this)"></td>
                                <td style="padding:8px;"><input type="number" name="item_amount[]" class="form-control" value="0" readonly></td>
                                <td style="padding:8px; text-align:center;"><button type="button" class="btn btn-ghost" onclick="CreateInvoice.removeItemRow(this)" title="Hapus baris"><i class="fas fa-trash"></i></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" class="ui-btn ui-btn-sm ui-btn-outline" onclick="CreateInvoice.addItemRow()"><i class="fas fa-plus"></i> Tambah baris</button>
                    <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="CreateInvoice.clearItemRows()">Bersihkan</button>
                </div>
            </div>

            <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button class="ui-btn ui-btn-outline w-full sm:w-auto" type="button" onclick="history.back()">Batal</button>
                <button class="ui-btn ui-btn-primary w-full sm:w-auto" type="submit">Buat & cetak</button>
            </div>
        </form>
    </div>

    <div id="historySection" style="display:none;">
        <section class="ui-card overflow-hidden">
            <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
                <h3 class="m-0 text-[15px] font-bold">Riwayat invoice yang dibuat</h3>
            </div>
            <?php if(empty($invoices)): ?>
                <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada invoice yang Anda buat.</div>
            <?php else: ?>
                <?php
                    // Recent temporary customers (type 'note' or 'temp') to help quick invoice creation
                    try {
                        $recent_temps = $db->query("SELECT id, name, address, contact, registration_date FROM customers WHERE type IN ('note','temp') ORDER BY registration_date DESC LIMIT 10")->fetchAll();
                    } catch (Exception $e) { $recent_temps = []; }
                ?>
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                                <th class="px-4 py-2.5 font-semibold sm:px-5">Tanggal</th>
                                <th class="px-3 py-2.5 font-semibold">Invoice / penerima</th>
                                <th class="px-3 py-2.5 text-right font-semibold">Jumlah</th>
                                <th class="px-3 py-2.5 font-semibold">Status</th>
                                <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
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
                            <tr class="border-t border-solid border-border">
                                <td class="px-4 py-3 align-top text-xs tabular-nums text-muted-foreground whitespace-nowrap sm:px-5"><?= date('d/m H:i', strtotime($inv['created_at'])) ?></td>
                                <td class="px-3 py-3 align-top">
                                    <div class="text-sm font-semibold tabular-nums">INV-<?= str_pad($inv['id'],5,'0',STR_PAD_LEFT) ?></div>
                                    <div class="text-xs text-muted-foreground"><?= htmlspecialchars($inv['customer_name'] ?? $inv['name'] ?? '-') ?></div>
                                    <?php if(!empty($inv['billing_address'])): ?><div class="text-xs text-muted-foreground"><?= htmlspecialchars($inv['billing_address']) ?></div><?php endif; ?>
                                </td>
                                <td class="px-3 py-3 text-right align-top font-bold tabular-nums whitespace-nowrap">Rp <?= number_format($inv['amount'],0,',','.') ?></td>
                                <td class="px-3 py-3 align-top"><span class="ui-badge <?= $is_paid ? 'ui-badge-signal' : 'ui-badge-danger' ?>"><?= $is_paid ? 'Sudah bayar' : 'Belum bayar' ?></span></td>
                                <td class="px-4 py-3 align-top sm:px-5">
                                    <div class="flex justify-end gap-1.5">
                                    <?php if (!$is_paid): ?>
                                        <a class="ui-btn ui-btn-sm ui-btn-primary" title="Bayar" href="index.php?page=admin_assets&action=invoice_mark_paid&id=<?= intval($inv['id']) ?>" onclick="return confirm('Tandai sebagai sudah dibayar?')"><i class="fas fa-money-bill-wave"></i></a>
                                    <?php endif; ?>
                                        <a class="ui-btn ui-btn-sm ui-btn-outline" title="Cetak" href="index.php?page=admin_invoices&action=print&id=<?= intval($inv['id']) ?>"><i class="fas fa-print"></i></a>
                                        <a class="ui-btn ui-btn-sm ui-btn-outline" title="Edit" href="index.php?page=admin_edit_quick_invoice&id=<?= intval($inv['id']) ?>"><i class="fas fa-edit"></i></a>
                                        <a class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus" href="index.php?page=admin_assets&action=invoice_delete_quick&id=<?= intval($inv['id']) ?>" onclick="return confirm('Hapus invoice ini?')"><i class="fas fa-trash"></i></a>
                                        <button type="button" class="ui-btn ui-btn-sm ui-btn-outline" title="Item" onclick="CreateInvoice.toggleInvoiceItems(<?= intval($inv['id']) ?>)"><i class="fas fa-list"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <tr id="invItems-<?= intval($inv['id']) ?>" class="bg-muted" style="display:none;">
                                <td colspan="5" class="px-4 py-3 sm:px-5">
                                    <div class="overflow-x-auto">
                                        <table class="w-full border-collapse text-sm">
                                            <thead>
                                                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                                                    <th class="px-2 py-1.5 font-semibold">Keterangan</th>
                                                    <th class="px-2 py-1.5 text-right font-semibold" style="width:110px;">Harga</th>
                                                    <th class="px-2 py-1.5 text-center font-semibold" style="width:60px;">Jml</th>
                                                    <th class="px-2 py-1.5 text-right font-semibold" style="width:120px;">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if(empty($items)): ?>
                                                    <tr><td colspan="4" class="px-2 py-2 text-muted-foreground">Tidak ada item tercatat.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach($items as $it):
                                                        $qty = intval($it['qty'] ?? 1);
                                                        $unit = isset($it['unit_price']) ? floatval($it['unit_price']) : ( ($qty>0) ? round(floatval($it['amount'])/$qty) : floatval($it['amount']) );
                                                        $lt = floatval($it['amount']);
                                                    ?>
                                                    <tr class="border-t border-solid border-border">
                                                        <td class="px-2 py-1.5"><?= htmlspecialchars($it['description']) ?></td>
                                                        <td class="px-2 py-1.5 text-right tabular-nums">Rp <?= number_format($unit,0,',','.') ?></td>
                                                        <td class="px-2 py-1.5 text-center tabular-nums"><?= $qty ?></td>
                                                        <td class="px-2 py-1.5 text-right tabular-nums">Rp <?= number_format($lt,0,',','.') ?></td>
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
            <?php endif; ?>
        </section>
    </div>

    <div id="tempsSection" style="display:none;">
        <section class="ui-card p-4 sm:p-5">
            <h3 class="m-0 mb-3 text-[15px] font-bold">Pelanggan baru (sementara)</h3>
            <?php if(empty($recent_temps)): ?>
                <div class="px-5 py-10 text-center text-sm text-muted-foreground">Tidak ada pelanggan sementara.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <?php foreach($recent_temps as $t): ?>
                        <div class="flex flex-col gap-1 rounded-md border border-solid border-border p-3">
                            <div class="text-sm font-semibold"><?= htmlspecialchars($t['name']) ?></div>
                            <div class="text-xs text-muted-foreground"><?= htmlspecialchars($t['contact'] ?: '-') ?></div>
                            <div class="text-xs text-muted-foreground"><?= htmlspecialchars($t['address'] ?: '-') ?></div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" class="ui-btn ui-btn-sm ui-btn-primary" onclick="useTempCustomer(<?= intval($t['id']) ?>)">Gunakan</button>
                                <a class="ui-btn ui-btn-sm ui-btn-outline" href="index.php?page=admin_customers&action=details&id=<?= intval($t['id']) ?>">Detail</a>
                                <form method="POST" action="index.php?page=admin_temp_customers" class="m-0">
<?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= intval($t['id']) ?>">
                                    <button type="submit" class="ui-btn ui-btn-sm ui-btn-outline text-danger" onclick="return confirm('Hapus pelanggan sementara ini?')">Hapus</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Pendapatan tab removed: payments go to main reports/dashboard -->
</div>

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
    if (!t) return alert('Data customer input manual tidak ditemukan.');
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

let existingCustomers = {};
try {
    existingCustomers = <?= json_encode($existing_customers ?? []) ?>;
} catch(e) { existingCustomers = {}; }

function useExistingCustomer(id) {
    if (!id) return;
    const c = (Array.isArray(existingCustomers) ? existingCustomers.find(x => parseInt(x.id) === parseInt(id)) : null);
    if (!c) return alert('Data customer input manual tidak ditemukan.');

    const customerIdField = document.getElementById('quick_invoice_customer_id');
    if (customerIdField) customerIdField.value = id;

    showTab('create');

    const nameField = document.querySelector('input[name="recipient_name"]');
    const addressField = document.querySelector('input[name="billing_address"]');
    const phoneField = document.querySelector('input[name="billing_phone"]');
    const emailField = document.querySelector('input[name="billing_email"]');
    const amountField = document.getElementById('invoice_total');
    const amountDisplay = document.getElementById('invoice_total_display');

    if (nameField) nameField.value = c.name || '';
    if (addressField) addressField.value = c.address || '';
    if (phoneField) phoneField.value = c.contact || '';
    if (emailField && 'email' in c) emailField.value = c.email || '';

    const firstDesc = document.querySelector('input[name="item_desc[]"]');
    const firstQty = document.querySelector('input[name="item_qty[]"]');
    const firstUnit = document.querySelector('input[name="item_unit[]"]');
    const firstAmount = document.querySelector('input[name="item_amount[]"]');

    if (firstDesc) firstDesc.value = c.package_name || 'Tagihan Layanan';
    if (firstQty) firstQty.value = 1;
    if (firstUnit) firstUnit.value = parseFloat(c.monthly_fee || 0);
    if (firstAmount) firstAmount.value = Math.round(parseFloat(c.monthly_fee || 0));

    const total = Math.round(parseFloat(c.monthly_fee || 0));
    if (amountField) amountField.value = total;
    if (amountDisplay) amountDisplay.innerText = new Intl.NumberFormat('id-ID').format(total);

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
