<?php
// Edit quick invoice (only for invoices created via quick tool)
 $u_role = $_SESSION['user_role'] ?? 'guest';
 if (!in_array($u_role, ['admin','partner'])) { echo "<div class='glass-panel' style='padding:40px; text-align:center;'><h2>Akses Ditolak</h2></div>"; return; }

 $id = intval($_GET['id'] ?? 0);
 if ($id <= 0) { echo "<div class='glass-panel' style='padding:20px;'>Invalid invoice ID</div>"; return; }

 try {
     $inv = $db->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
     $inv->execute([$id]);
     $invoice = $inv->fetch();
 } catch (Exception $e) { $invoice = null; }

 if (!$invoice) { echo "<div class='glass-panel' style='padding:20px;'>Invoice tidak ditemukan.</div>"; return; }

 // Ensure this is a quick or external invoice (safety)
 $cols = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN,1);
 $created_via = $invoice['created_via'] ?? '';
 if (!in_array('created_via', $cols) || !in_array($created_via, ['quick', 'external'])) {
     echo "<div class='glass-panel' style='padding:20px;'>Invoice ini bukan invoice cepat atau eksternal.</div>"; return;
 }

 // fetch items
 $items = $db->query("SELECT * FROM invoice_items WHERE invoice_id = " . intval($id))->fetchAll();

 ?>
<div class="glass-panel" style="width:100%; margin:20px 0; padding:20px;">
    <h3>Edit Invoice <?= strtoupper($created_via ?: 'Cepat') ?> - INV-<?= str_pad($invoice['id'],5,'0',STR_PAD_LEFT) ?></h3>
    <style>
    /* Make edit items inputs easier to use and more visible */
    #editItemsTable .form-control { width:100% !important; padding:10px 12px !important; height:44px !important; box-sizing:border-box; font-size:15px; }
    #editItemsTable td { vertical-align: middle; }
    #editItemsTable thead th { padding:12px 8px; color:var(--text-secondary); font-weight:700; }
    /* Make table responsive and align with left column: equal two-column layout */
    #editItemsTable { table-layout: fixed; width:100%; }
    #editItemsTable tbody td { padding: 10px 8px; }
    /* Column widths: desc 60%, qty 10%, unit 15%, total 15%, action fixed */
    #editItemsTable tbody td:first-child { width: 60%; }
    #editItemsTable tbody td:nth-child(2) { width: 10%; }
    #editItemsTable tbody td:nth-child(3) { width: 15%; }
    #editItemsTable tbody td:nth-child(4) { width: 15%; }
    #editItemsTable tbody td:nth-child(5) { width: 64px; text-align:center; }
    /* Inputs should fill their cells */
    #editItemsTable tbody td input[name="item_desc[]"] { width:100%; }
    #editItemsTable tbody td input[name="item_qty[]"],
    #editItemsTable tbody td input[name="item_unit[]"],
    #editItemsTable tbody td input[name="item_amount[]"] { width:100%; box-sizing:border-box; }
    #editItemsTable tbody td input[name="item_qty[]"] { text-align:center; }
    #editItemsTable tbody td input[name="item_unit[]"],
    #editItemsTable tbody td input[name="item_amount[]"] { text-align:right; }
    /* Make delete button compact and consistent */
    #editItemsTable .btn-ghost { width:42px; height:42px; padding:0; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.06); }
    #editItemsTable .btn-ghost i { color:var(--text-secondary); }
    </style>
    <form method="POST" action="index.php?page=admin_assets&action=invoice_update" style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
        <input type="hidden" name="invoice_id" value="<?= intval($invoice['id']) ?>">
        <div>
            <label>Nama Penerima</label>
            <input type="text" name="recipient_name" class="form-control" value="<?= htmlspecialchars($invoice['name'] ?? '') ?>">
            <label>Alamat Penagihan</label>
            <input type="text" name="billing_address" class="form-control" value="<?= htmlspecialchars($invoice['billing_address'] ?? $invoice['address'] ?? '') ?>">
            <label>No. Telp</label>
            <input type="text" name="billing_phone" class="form-control" value="<?= htmlspecialchars($invoice['billing_phone'] ?? $invoice['contact'] ?? '') ?>">
            <label>Email</label>
            <input type="email" name="billing_email" class="form-control" value="<?= htmlspecialchars($invoice['billing_email'] ?? '') ?>">
            <label>Instruksi Pembayaran</label>
            <textarea name="payment_instructions" class="form-control" rows="3"><?= htmlspecialchars($invoice['payment_instructions'] ?? '') ?></textarea>
        </div>
        <div>
            <label>Tanggal Jatuh Tempo</label>
            <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime($invoice['due_date'])) ?>">
            <label>Status</label>
            <select name="status" class="form-control">
                <option value="Belum Lunas" <?= ($invoice['status'] ?? '') === 'Belum Lunas' ? 'selected' : '' ?>>Belum Lunas</option>
                <option value="Lunas" <?= ($invoice['status'] ?? '') === 'Lunas' ? 'selected' : '' ?>>Lunas</option>
            </select>
            <div style="margin-top:12px; font-weight:700;">Item Nota</div>
            <div style="background:transparent; padding:8px; border-radius:8px;">
                <table id="editItemsTable" style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background:transparent;">
                            <th style="padding:8px; text-align:left; width:55%">Deskripsi</th>
                            <th style="padding:8px; text-align:center; width:12%">Jumlah</th>
                            <th style="padding:8px; text-align:right; width:16%">Harga Satuan (Rp)</th>
                            <th style="padding:8px; text-align:right; width:12%">Total (Rp)</th>
                            <th style="padding:8px; text-align:center; width:5%">Aksi</th>
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
                            <td style="padding:8px; text-align:center;"><input type="number" name="item_qty[]" class="form-control" value="<?= $qty ?>" min="1" oninput="recalculateEditRow(this)"></td>
                            <td style="padding:8px; text-align:right;"><input type="number" name="item_unit[]" class="form-control" value="<?= $unit ?>" oninput="recalculateEditRow(this)"></td>
                            <td style="padding:8px; text-align:right;"><input type="number" name="item_amount[]" class="form-control" value="<?= $amt ?>" readonly></td>
                            <td style="padding:8px; text-align:center;"><button type="button" class="btn btn-ghost" onclick="removeEditRow(this)"><i class="fas fa-trash"></i></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top:10px; display:flex; gap:10px;">
                    <button type="button" class="btn btn-sm btn-primary" onclick="addEditRow()"><i class="fas fa-plus"></i> Tambah Baris</button>
                    <button type="button" class="btn btn-sm btn-ghost" onclick="clearEditItemRows()">Bersihkan</button>
                </div>
            </div>
            <div style="margin-top:12px; display:flex; justify-content:flex-end; gap:12px; align-items:center;">
                <a class="btn btn-ghost" href="index.php?page=admin_create_invoice">Batal</a>
                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px; margin-right:8px;">
                    <div style="font-size:12px; color:var(--text-secondary);">Total Nota</div>
                    <div id="edit_invoice_total_display" style="font-size:20px; font-weight:800; color:var(--primary); background: rgba(var(--primary-rgb),0.06); padding:8px 14px; border-radius:12px; box-shadow: 0 8px 30px rgba(var(--primary-rgb),0.08);">0</div>
                </div>
                <button class="btn btn-primary" type="submit">Simpan Perubahan</button>
            </div>
        </div>
    </form>
</div>

<script>
function addEditRow(){
    const tb = document.getElementById('editItemsTable').querySelector('tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td style="padding:8px;"><input type="text" name="item_desc[]" class="form-control" required></td>
        <td style="padding:8px; text-align:center;"><input type="number" name="item_qty[]" class="form-control" value="1" min="1" oninput="recalculateEditRow(this)"></td>
        <td style="padding:8px; text-align:right;"><input type="number" name="item_unit[]" class="form-control" value="0" oninput="recalculateEditRow(this)"></td>
        <td style="padding:8px; text-align:right;"><input type="number" name="item_amount[]" class="form-control" value="0" readonly></td>
        <td style="padding:8px; text-align:center;"><button type="button" class="btn btn-ghost" onclick="removeEditRow(this)"><i class="fas fa-trash"></i></button></td>
    `;
    tb.appendChild(tr);
}

function removeEditRow(btn) {
    const tr = btn.closest('tr'); if (tr) tr.remove(); updateEditGrandTotal();
}

function clearEditItemRows(){
    const tb = document.getElementById('editItemsTable').querySelector('tbody');
    tb.innerHTML = '';
    addEditRow();
    updateEditGrandTotal();
}

function recalculateEditRow(el) {
    const tr = el.closest('tr');
    if (!tr) return;
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
    let total = 0;
    amounts.forEach(a => total += parseFloat(a.value) || 0);
    const el = document.getElementById('edit_invoice_total_display');
    if (el) el.innerText = new Intl.NumberFormat('id-ID').format(Math.round(total));
}

document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('#editItemsTable input[name="item_qty[]"]').forEach(i => recalculateEditRow(i));
    updateEditGrandTotal();
});
</script>
