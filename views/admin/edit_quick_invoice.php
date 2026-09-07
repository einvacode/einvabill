<?php
// Edit external / quick invoice (only for invoices created via the external invoice page)
$u_role = $_SESSION['user_role'] ?? 'guest';
if (!in_array($u_role, ['admin','partner'])) { echo "<div class='ui-card p-10 text-center'><h2 class='m-0 text-xl font-bold'>Akses ditolak</h2></div>"; return; }

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { echo "<div class='ui-card p-5 text-sm text-muted-foreground'>Invalid invoice ID</div>"; return; }

try {
    $inv = $db->prepare("SELECT i.*, c.name AS customer_name, c.address AS customer_address, c.contact AS customer_contact FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.id = ? LIMIT 1");
    $inv->execute([$id]);
    $invoice = $inv->fetch();
} catch (Exception $e) { $invoice = null; }

if (!$invoice) { echo "<div class='ui-card p-5 text-sm text-muted-foreground'>Invoice tidak ditemukan.</div>"; return; }

// Ensure this is a quick or external invoice (safety)
$cols = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN,1);
$created_via = $invoice['created_via'] ?? '';
if (!in_array('created_via', $cols) || !in_array($created_via, ['quick', 'external'])) {
    echo "<div class='ui-card p-5 text-sm text-muted-foreground'>Invoice ini bukan invoice cepat atau eksternal.</div>"; return;
}

// fetch items
$items = $db->query("SELECT * FROM invoice_items WHERE invoice_id = " . intval($id))->fetchAll();
$inv_no = 'INV-' . str_pad($invoice['id'], 5, '0', STR_PAD_LEFT);
?>
<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Edit invoice <?= $inv_no ?></h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Dibuat <?= date('d/m/Y', strtotime($invoice['created_at'])) ?><?= !empty($invoice['issued_by_name']) ? ' oleh ' . htmlspecialchars($invoice['issued_by_name']) : '' ?>.</p>
        </div>
        <div class="flex gap-2">
            <a class="ui-btn ui-btn-outline" href="index.php?page=admin_invoices&action=print&id=<?= intval($invoice['id']) ?>"><i class="fas fa-print"></i> Cetak</a>
            <a class="ui-btn ui-btn-outline" href="index.php?page=admin_create_invoice">Kembali</a>
        </div>
    </div>

    <style>
    #editItemsTable { table-layout: fixed; width:100%; min-width:640px; }
    #editItemsTable tbody td { padding:6px 8px; vertical-align:middle; }
    #editItemsTable tbody td:first-child { width:46%; }
    #editItemsTable tbody td:nth-child(2) { width:12%; }
    #editItemsTable tbody td:nth-child(3) { width:18%; }
    #editItemsTable tbody td:nth-child(4) { width:16%; }
    #editItemsTable tbody td:nth-child(5) { width:8%; text-align:center; }
    #editItemsTable tbody td input { width:100%; box-sizing:border-box; }
    #editItemsTable tbody td input[name="item_qty[]"] { text-align:center; }
    #editItemsTable tbody td input[name="item_unit[]"],
    #editItemsTable tbody td input[name="item_amount[]"] { text-align:right; }
    #editItemsTable tbody td input[readonly] { background:#F5F7F6; color:#5B6B72; }
    #editItemsTable .row-remove { width:34px; height:34px; padding:0; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; }
    </style>

    <form method="POST" action="index.php?page=admin_assets&action=invoice_update" class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
<?= csrf_field() ?>
        <input type="hidden" name="invoice_id" value="<?= intval($invoice['id']) ?>">

        <div class="grid gap-5">
            <section class="ui-card overflow-hidden">
                <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
                    <h3 class="m-0 text-[15px] font-bold">Penerima tagihan</h3>
                    <p class="m-0 text-xs text-muted-foreground">Perubahan di sini ikut memperbarui data pelanggan input manual.</p>
                </div>
                <div class="grid gap-4 p-4 sm:p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama penerima <span class="text-danger">*</span></span>
                            <input type="text" name="recipient_name" class="form-control" value="<?= htmlspecialchars($invoice['customer_name'] ?? '') ?>" required>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Perusahaan / instansi</span>
                            <input type="text" name="billing_company" class="form-control" value="<?= htmlspecialchars($invoice['billing_company'] ?? '') ?>" placeholder="Opsional">
                        </label>
                    </div>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat penagihan</span>
                        <textarea name="billing_address" class="form-control resize-none" rows="2"><?= htmlspecialchars($invoice['billing_address'] ?? $invoice['customer_address'] ?? '') ?></textarea>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">No. HP / WhatsApp</span>
                            <input type="text" name="billing_phone" class="form-control" value="<?= htmlspecialchars($invoice['billing_phone'] ?? $invoice['customer_contact'] ?? '') ?>" inputmode="tel">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Email</span>
                            <input type="email" name="billing_email" class="form-control" value="<?= htmlspecialchars($invoice['billing_email'] ?? '') ?>">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">NPWP</span>
                            <input type="text" name="billing_npwp" class="form-control" value="<?= htmlspecialchars($invoice['billing_npwp'] ?? '') ?>" placeholder="Opsional">
                        </label>
                    </div>
                </div>
            </section>

            <section class="ui-card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
                    <div>
                        <h3 class="m-0 text-[15px] font-bold">Rincian tagihan</h3>
                        <p class="m-0 text-xs text-muted-foreground">Total per baris dihitung otomatis dari jumlah dan harga satuan.</p>
                    </div>
                    <button type="button" class="ui-btn ui-btn-sm ui-btn-outline" onclick="EditInvoice.addEditRow()"><i class="fas fa-plus"></i> Tambah baris</button>
                </div>
                <div class="overflow-x-auto">
                    <table id="editItemsTable" class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                                <th class="px-2 py-2.5 pl-4 font-semibold sm:pl-5">Deskripsi</th>
                                <th class="px-2 py-2.5 text-center font-semibold">Jumlah</th>
                                <th class="px-2 py-2.5 text-right font-semibold">Harga satuan (Rp)</th>
                                <th class="px-2 py-2.5 text-right font-semibold">Total (Rp)</th>
                                <th class="px-2 py-2.5 font-semibold"><span class="sr-only">Aksi</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($items as $it):
                                $qty = intval($it['qty'] ?? 1);
                                $unit = floatval($it['unit_price'] ?? 0);
                                $amt = floatval($it['amount'] ?? ($qty * $unit));
                                if ($unit <= 0 && $qty > 0) $unit = round($amt / $qty);
                            ?>
                            <tr class="border-t border-solid border-border">
                                <td class="pl-4 sm:pl-5"><input type="text" name="item_desc[]" class="form-control" value="<?= htmlspecialchars($it['description']) ?>" required></td>
                                <td><input type="number" name="item_qty[]" class="form-control" value="<?= $qty ?>" min="1" oninput="EditInvoice.recalculateEditRow(this)"></td>
                                <td><input type="number" name="item_unit[]" class="form-control" value="<?= $unit ?>" min="0" oninput="EditInvoice.recalculateEditRow(this)"></td>
                                <td><input type="number" name="item_amount[]" class="form-control" value="<?= $amt ?>" readonly tabindex="-1"></td>
                                <td><button type="button" class="ui-btn ui-btn-ghost row-remove" onclick="EditInvoice.removeEditRow(this)" title="Hapus baris"><i class="fas fa-trash"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between gap-3 border-t border-solid border-border px-4 py-3 sm:px-5">
                    <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="EditInvoice.clearEditItemRows()">Bersihkan semua</button>
                    <div class="text-sm text-muted-foreground">Subtotal <span class="ml-2 font-semibold tabular-nums text-foreground">Rp <span id="edit_invoice_subtotal_display">0</span></span></div>
                </div>
            </section>

            <section class="ui-card overflow-hidden">
                <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
                    <h3 class="m-0 text-[15px] font-bold">Instruksi pembayaran</h3>
                    <p class="m-0 text-xs text-muted-foreground">Tercetak di bagian bawah invoice.</p>
                </div>
                <div class="p-4 sm:p-5">
                    <textarea name="payment_instructions" class="form-control" rows="4"><?= htmlspecialchars($invoice['payment_instructions'] ?? '') ?></textarea>
                </div>
            </section>
        </div>

        <aside class="grid gap-5 lg:sticky lg:top-24">
            <section class="ui-card p-4 sm:p-5">
                <h3 class="m-0 text-[15px] font-bold">Ringkasan</h3>
                <dl class="m-0 mt-4 grid gap-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-muted-foreground">Nomor</dt>
                        <dd class="m-0 font-medium tabular-nums"><?= $inv_no ?></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-muted-foreground">Tanggal terbit</dt>
                        <dd class="m-0 font-medium tabular-nums"><?= date('d/m/Y', strtotime($invoice['created_at'])) ?></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-muted-foreground"><label for="due_date">Jatuh tempo</label></dt>
                        <dd class="m-0"><input type="date" name="due_date" id="due_date" class="form-control h-9 w-[160px] text-sm" value="<?= date('Y-m-d', strtotime($invoice['due_date'])) ?>"></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-muted-foreground"><label for="status">Status</label></dt>
                        <dd class="m-0">
                            <select name="status" id="status" class="form-control h-9 w-[160px] text-sm">
                                <option value="Belum Lunas" <?= ($invoice['status'] ?? '') === 'Belum Lunas' ? 'selected' : '' ?>>Belum lunas</option>
                                <option value="Lunas" <?= ($invoice['status'] ?? '') === 'Lunas' ? 'selected' : '' ?>>Lunas</option>
                            </select>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-muted-foreground">Jumlah baris</dt>
                        <dd class="m-0 font-medium tabular-nums" id="edit_invoice_item_count"><?= count($items) ?></dd>
                    </div>
                </dl>
                <div class="mt-4 border-t border-solid border-border pt-4">
                    <div class="text-xs font-medium text-muted-foreground">Total tagihan</div>
                    <div class="mt-1 text-2xl font-extrabold tabular-nums text-primary">Rp <span id="edit_invoice_total_display">0</span></div>
                </div>
                <div class="mt-5 grid gap-2">
                    <button class="ui-btn ui-btn-primary w-full" type="submit">Simpan perubahan</button>
                    <a class="ui-btn ui-btn-outline w-full" href="index.php?page=admin_create_invoice">Batal</a>
                </div>
            </section>
        </aside>
    </form>
</div>

<script>
window.EditInvoice = (function(){
    function rowTemplate() {
        return `
            <td class="pl-4 sm:pl-5"><input type="text" name="item_desc[]" class="form-control" placeholder="Deskripsi item" required></td>
            <td><input type="number" name="item_qty[]" class="form-control" value="1" min="1" oninput="EditInvoice.recalculateEditRow(this)"></td>
            <td><input type="number" name="item_unit[]" class="form-control" value="0" min="0" oninput="EditInvoice.recalculateEditRow(this)"></td>
            <td><input type="number" name="item_amount[]" class="form-control" value="0" readonly tabindex="-1"></td>
            <td><button type="button" class="ui-btn ui-btn-ghost row-remove" onclick="EditInvoice.removeEditRow(this)" title="Hapus baris"><i class="fas fa-trash"></i></button></td>
        `;
    }
    function addEditRow(focus){
        const tb = document.getElementById('editItemsTable').querySelector('tbody');
        const tr = document.createElement('tr');
        tr.className = 'border-t border-solid border-border';
        tr.innerHTML = rowTemplate();
        tb.appendChild(tr);
        updateEditGrandTotal();
        if (focus !== false) { const d = tr.querySelector('input[name="item_desc[]"]'); if (d) d.focus(); }
    }
    function removeEditRow(btn) {
        const tb = document.getElementById('editItemsTable').querySelector('tbody');
        const tr = btn.closest('tr'); if (tr) tr.remove();
        if (!tb.querySelector('tr')) addEditRow(false);
        updateEditGrandTotal();
    }
    function clearEditItemRows(){ const tb = document.getElementById('editItemsTable').querySelector('tbody'); tb.innerHTML = ''; addEditRow(false); }
    function recalculateEditRow(el) {
        const tr = el.closest('tr'); if (!tr) return;
        const qty = parseInt(tr.querySelector('input[name="item_qty[]"]').value) || 0;
        const unit = parseFloat(tr.querySelector('input[name="item_unit[]"]').value) || 0;
        tr.querySelector('input[name="item_amount[]"]').value = Math.round(qty * unit);
        updateEditGrandTotal();
    }
    function updateEditGrandTotal() {
        const amounts = Array.from(document.querySelectorAll('#editItemsTable input[name="item_amount[]"]'));
        let total = 0; amounts.forEach(a => total += parseFloat(a.value) || 0);
        const fmt = new Intl.NumberFormat('id-ID').format(Math.round(total));
        ['edit_invoice_total_display', 'edit_invoice_subtotal_display'].forEach(id => { const el = document.getElementById(id); if (el) el.innerText = fmt; });
        const count = document.getElementById('edit_invoice_item_count'); if (count) count.innerText = amounts.length;
    }
    function init(){ document.querySelectorAll('#editItemsTable input[name="item_qty[]"]').forEach(i => recalculateEditRow(i)); updateEditGrandTotal(); }
    return { addEditRow, removeEditRow, clearEditItemRows, recalculateEditRow, updateEditGrandTotal, init };
})();

document.addEventListener('DOMContentLoaded', function(){ try{ if(window.EditInvoice) window.EditInvoice.init(); }catch(e){} });
</script>
