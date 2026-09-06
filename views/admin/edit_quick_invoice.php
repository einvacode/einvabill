<?php
// Edit quick invoice (only for invoices created via quick tool)
 $u_role = $_SESSION['user_role'] ?? 'guest';
 if (!in_array($u_role, ['admin','partner'])) { echo "<div class='ui-card p-10 text-center'><h2 class='m-0 text-xl font-bold'>Akses ditolak</h2></div>"; return; }

 $id = intval($_GET['id'] ?? 0);
 if ($id <= 0) { echo "<div class='ui-card p-5 text-sm text-muted-foreground'>Invalid invoice ID</div>"; return; }

 try {
     $inv = $db->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
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

 ?>
<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Edit invoice INV-<?= str_pad($invoice['id'],5,'0',STR_PAD_LEFT) ?></h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Invoice <?= htmlspecialchars(strtolower($created_via ?: 'cepat')) ?>.</p>
        </div>
    </div>
    <style>
    /* Item table: fixed column widths shared by static and JS-created rows */
    #editItemsTable { table-layout: fixed; width:100%; }
    #editItemsTable td { vertical-align: middle; }
    #editItemsTable tbody td { padding: 8px; }
    #editItemsTable tbody td:first-child { width: 50%; }
    #editItemsTable tbody td:nth-child(2) { width: 12%; }
    #editItemsTable tbody td:nth-child(3) { width: 17%; }
    #editItemsTable tbody td:nth-child(4) { width: 15%; }
    #editItemsTable tbody td:nth-child(5) { width: 64px; text-align:center; }
    #editItemsTable tbody td input { width:100%; box-sizing:border-box; }
    #editItemsTable tbody td input[name="item_qty[]"] { text-align:center; }
    #editItemsTable tbody td input[name="item_unit[]"],
    #editItemsTable tbody td input[name="item_amount[]"] { text-align:right; }
    #editItemsTable .btn-ghost { width:36px; height:36px; padding:0; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; }
    </style>
    <form method="POST" action="index.php?page=admin_assets&action=invoice_update" class="ui-card p-4 sm:p-5">
<?= csrf_field() ?>
        <input type="hidden" name="invoice_id" value="<?= intval($invoice['id']) ?>">
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="grid content-start gap-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama penerima</span>
                    <input type="text" name="recipient_name" class="form-control" value="<?= htmlspecialchars($invoice['name'] ?? '') ?>">
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat penagihan</span>
                    <input type="text" name="billing_address" class="form-control" value="<?= htmlspecialchars($invoice['billing_address'] ?? $invoice['address'] ?? '') ?>">
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">No. telepon</span>
                        <input type="text" name="billing_phone" class="form-control" value="<?= htmlspecialchars($invoice['billing_phone'] ?? $invoice['contact'] ?? '') ?>">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Email</span>
                        <input type="email" name="billing_email" class="form-control" value="<?= htmlspecialchars($invoice['billing_email'] ?? '') ?>">
                    </label>
                </div>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Instruksi pembayaran</span>
                    <textarea name="payment_instructions" class="form-control" rows="3"><?= htmlspecialchars($invoice['payment_instructions'] ?? '') ?></textarea>
                </label>
            </div>
            <div class="grid content-start gap-4 lg:border-l lg:border-solid lg:border-border lg:pl-5">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal jatuh tempo</span>
                    <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime($invoice['due_date'])) ?>">
                </label>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-muted-foreground">Status</span>
                    <select name="status" class="form-control">
                        <option value="Belum Lunas" <?= ($invoice['status'] ?? '') === 'Belum Lunas' ? 'selected' : '' ?>>Belum Lunas</option>
                        <option value="Lunas" <?= ($invoice['status'] ?? '') === 'Lunas' ? 'selected' : '' ?>>Lunas</option>
                    </select>
                </label>
                <div class="rounded-md bg-muted p-4">
                    <div class="text-xs font-medium text-muted-foreground">Total nota</div>
                    <div class="mt-1 text-2xl font-extrabold tabular-nums text-primary">Rp <span id="edit_invoice_total_display">0</span></div>
                </div>
            </div>
        </div>

        <div class="mt-6">
            <h3 class="m-0 mb-2 text-[15px] font-bold">Item nota</h3>
            <div class="overflow-x-auto rounded-md border border-solid border-border">
                <table id="editItemsTable" class="w-full border-collapse text-sm">
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
                        <?php foreach($items as $it):
                            $qty = intval($it['qty'] ?? 1);
                            $unit = floatval($it['unit_price'] ?? 0);
                            $amt = floatval($it['amount'] ?? ($qty * $unit));
                        ?>
                        <tr>
                            <td style="padding:8px;"><input type="text" name="item_desc[]" class="form-control" value="<?= htmlspecialchars($it['description']) ?>" required></td>
                            <td style="padding:8px; text-align:center;"><input type="number" name="item_qty[]" class="form-control" value="<?= $qty ?>" min="1" oninput="EditInvoice.recalculateEditRow(this)"></td>
                            <td style="padding:8px; text-align:right;"><input type="number" name="item_unit[]" class="form-control" value="<?= $unit ?>" oninput="EditInvoice.recalculateEditRow(this)"></td>
                            <td style="padding:8px; text-align:right;"><input type="number" name="item_amount[]" class="form-control" value="<?= $amt ?>" readonly></td>
                            <td style="padding:8px; text-align:center;"><button type="button" class="btn btn-ghost" onclick="EditInvoice.removeEditRow(this)" title="Hapus baris"><i class="fas fa-trash"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" class="ui-btn ui-btn-sm ui-btn-outline" onclick="EditInvoice.addEditRow()"><i class="fas fa-plus"></i> Tambah baris</button>
                <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="EditInvoice.clearEditItemRows()">Bersihkan</button>
            </div>
        </div>

        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <a class="ui-btn ui-btn-outline w-full sm:w-auto" href="index.php?page=admin_create_invoice">Batal</a>
            <button class="ui-btn ui-btn-primary w-full sm:w-auto" type="submit">Simpan perubahan</button>
        </div>
    </form>
</div>

<script>
window.EditInvoice = (function(){
    function addEditRow(){
        const tb = document.getElementById('editItemsTable').querySelector('tbody');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td style="padding:8px;"><input type="text" name="item_desc[]" class="form-control" required></td>
            <td style="padding:8px; text-align:center;"><input type="number" name="item_qty[]" class="form-control" value="1" min="1" oninput="EditInvoice.recalculateEditRow(this)"></td>
            <td style="padding:8px; text-align:right;"><input type="number" name="item_unit[]" class="form-control" value="0" oninput="EditInvoice.recalculateEditRow(this)"></td>
            <td style="padding:8px; text-align:right;"><input type="number" name="item_amount[]" class="form-control" value="0" readonly></td>
            <td style="padding:8px; text-align:center;"><button type="button" class="btn btn-ghost" onclick="EditInvoice.removeEditRow(this)"><i class="fas fa-trash"></i></button></td>
        `;
        tb.appendChild(tr);
    }
    function removeEditRow(btn) { const tr = btn.closest('tr'); if (tr) tr.remove(); updateEditGrandTotal(); }
    function clearEditItemRows(){ const tb = document.getElementById('editItemsTable').querySelector('tbody'); tb.innerHTML = ''; addEditRow(); updateEditGrandTotal(); }
    function recalculateEditRow(el) {
        const tr = el.closest('tr'); if (!tr) return;
        const qtyEl = tr.querySelector('input[name="item_qty[]"]');
        const unitEl = tr.querySelector('input[name="item_unit[]"]');
        const amountEl = tr.querySelector('input[name="item_amount[]"]');
        const qty = parseInt(qtyEl.value) || 0;
        const unit = parseFloat(unitEl.value) || 0;
        const line = qty * unit;
        amountEl.value = Math.round(line);
        updateEditGrandTotal();
    }
    function updateEditGrandTotal() {
        const amounts = Array.from(document.querySelectorAll('input[name="item_amount[]"]'));
        let total = 0; amounts.forEach(a => total += parseFloat(a.value) || 0);
        const el = document.getElementById('edit_invoice_total_display'); if (el) el.innerText = new Intl.NumberFormat('id-ID').format(Math.round(total));
    }
    function init(){ document.querySelectorAll('#editItemsTable input[name="item_qty[]"]').forEach(i => recalculateEditRow(i)); updateEditGrandTotal(); }
    return { addEditRow, removeEditRow, clearEditItemRows, recalculateEditRow, updateEditGrandTotal, init };
})();

document.addEventListener('DOMContentLoaded', function(){ try{ if(window.EditInvoice) window.EditInvoice.init(); }catch(e){} });
</script>
