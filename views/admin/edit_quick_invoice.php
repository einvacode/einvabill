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

 // Ensure this is a quick invoice (safety)
 $cols = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN,1);
 if (!in_array('created_via', $cols) || ($invoice['created_via'] ?? '') !== 'quick') {
     echo "<div class='glass-panel' style='padding:20px;'>Invoice ini bukan invoice cepat.</div>"; return;
 }

 // fetch items
 $items = $db->query("SELECT * FROM invoice_items WHERE invoice_id = " . intval($id))->fetchAll();

 ?>
<div class="glass-panel" style="max-width:900px; margin:20px auto; padding:20px;">
    <h3>Edit Invoice Cepat - INV-<?= str_pad($invoice['id'],5,'0',STR_PAD_LEFT) ?></h3>
    <form method="POST" action="index.php?page=admin_assets&action=invoice_update" style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
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
            <div id="editItems">
                <?php foreach($items as $it): ?>
                <div style="display:flex; gap:8px; margin-bottom:8px; align-items:center;">
                    <input type="text" name="item_desc[]" class="form-control" value="<?= htmlspecialchars($it['description']) ?>" style="flex:2;">
                    <input type="number" name="item_qty[]" class="form-control" value="<?= intval($it['qty'] ?? 1) ?>" style="width:80px;">
                    <input type="number" name="item_unit[]" class="form-control" value="<?= floatval($it['unit_price'] ?? 0) ?>" style="width:140px;">
                    <input type="number" name="item_amount[]" class="form-control" value="<?= floatval($it['amount']) ?>" style="width:140px;" readonly>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:8px; display:flex; gap:8px;"><button type="button" class="btn btn-sm btn-primary" onclick="addEditRow()">Tambah Baris</button></div>
            <div style="margin-top:12px; display:flex; justify-content:flex-end; gap:8px;"><a class="btn btn-ghost" href="index.php?page=admin_create_invoice">Batal</a><button class="btn btn-primary" type="submit">Simpan Perubahan</button></div>
        </div>
    </form>
</div>

<script>
function addEditRow(){
    const wrap = document.getElementById('editItems');
    const div = document.createElement('div');
    div.style.display='flex'; div.style.gap='8px'; div.style.marginBottom='8px'; div.innerHTML = `
        <input type="text" name="item_desc[]" class="form-control" style="flex:2;">
        <input type="number" name="item_qty[]" class="form-control" value="1" style="width:80px;">
        <input type="number" name="item_unit[]" class="form-control" value="0" style="width:140px;">
        <input type="number" name="item_amount[]" class="form-control" value="0" style="width:140px;" readonly>
    `;
    wrap.appendChild(div);
}
</script>
