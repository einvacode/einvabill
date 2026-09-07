<?php
// External / quick invoice: bill a company, agency or individual that is not a subscriber.
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

// Saved-item catalog actions (tab "Item tersimpan"); CSRF is enforced globally for POST.
$tenant_id = $_SESSION['tenant_id'] ?? 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['catalog_action'])) {
    $redirect = 'index.php?page=admin_create_invoice&tab=items';
    if ($_POST['catalog_action'] === 'save') {
        $err = invoice_catalog_save($db, (int)$tenant_id, intval($_POST['catalog_id'] ?? 0), (string)($_POST['catalog_description'] ?? ''), floatval($_POST['catalog_unit_price'] ?? 0));
        header('Location: ' . $redirect . ($err ? '&err=' . urlencode($err) : '&msg=item_saved'));
        exit;
    }
    if ($_POST['catalog_action'] === 'delete') {
        invoice_catalog_delete($db, (int)$tenant_id, intval($_POST['catalog_id'] ?? 0));
        header('Location: ' . $redirect . '&msg=item_deleted');
        exit;
    }
}
$catalog_items = invoice_catalog_list($db, (int)$tenant_id);
$initial_tab = in_array($_GET['tab'] ?? '', ['create', 'history', 'temps', 'items']) ? $_GET['tab'] : 'create';
$flash_msg = ['item_saved' => 'Item tersimpan.', 'item_deleted' => 'Item dihapus.', 'updated' => 'Invoice diperbarui.'][$_GET['msg'] ?? ''] ?? '';
$flash_err = trim((string)($_GET['err'] ?? ''));

// Fetch recent invoices issued by this user for history tab (safe with migrations)
$u_id = $_SESSION['user_id'] ?? 0;
$u_name = $_SESSION['user_name'] ?? '';
$invoices = [];
try {
    $cols = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN,1);
    $has_issued_id = is_array($cols) && in_array('issued_by_id', $cols);
    $has_created_via = is_array($cols) && in_array('created_via', $cols);

    $params = [];
    $conds = [];
    if ($has_issued_id) { $conds[] = 'i.issued_by_id = ?'; $params[] = $u_id; }
    $conds[] = 'i.issued_by_name = ?'; $params[] = $u_name;
    $conds[] = 'c.created_by = ?'; $params[] = $u_id;
    $where = ($has_created_via ? "i.created_via IN ('quick','external') AND " : '') . '(' . implode(' OR ', $conds) . ')';

    $sql = "SELECT i.*, c.name as customer_name, c.created_by as customer_created_by FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE $where ORDER BY i.created_at DESC LIMIT 200";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
} catch (Exception $e) { $invoices = []; }

$tenant_id = $_SESSION['tenant_id'] ?? 1;
try {
    $cust_cols = $db->query("PRAGMA table_info(customers)")->fetchAll(PDO::FETCH_COLUMN, 1);
} catch (Exception $e) { $cust_cols = []; }
$has_cust_email = is_array($cust_cols) && in_array('email', $cust_cols);
$cust_extra = $has_cust_email ? ", email, company_name, npwp" : ", '' AS email, '' AS company_name, '' AS npwp";

// Recent temporary customers (type 'note' or 'temp') for the separate tab
try {
    $recent_temps = $db->query("SELECT id, name, address, contact, registration_date $cust_extra FROM customers WHERE tenant_id = $tenant_id AND type IN ('note','temp') AND created_by = 0 ORDER BY registration_date DESC LIMIT 12")->fetchAll();
} catch (Exception $e) { $recent_temps = []; }

// Customers for autofill: only manually entered records, with their last payment instruction
try {
    $existing_customers = $db->query("SELECT id, name, address, contact, package_name, monthly_fee, customer_code $cust_extra,
        (SELECT payment_instructions FROM invoices i WHERE i.customer_id = customers.id AND i.payment_instructions IS NOT NULL AND i.payment_instructions <> '' ORDER BY i.id DESC LIMIT 1) AS last_payment_instructions
        FROM customers WHERE tenant_id = $tenant_id AND type IN ('note','temp') ORDER BY registration_date DESC, name ASC LIMIT 300")->fetchAll();
} catch (Exception $e) { $existing_customers = []; }

// Default payment instruction from company settings (editable per invoice)
$default_payment_instructions = trim((string)(($GLOBALS['site_settings']['bank_account'] ?? '')));
if ($default_payment_instructions !== '') {
    $default_payment_instructions = "Pembayaran dapat dilakukan melalui transfer ke:\n" . $default_payment_instructions . "\nMohon cantumkan nomor invoice pada berita transfer.";
}
$tab_active = 'cursor-pointer rounded-sm border-0 bg-card px-3 py-1.5 text-sm font-semibold text-foreground shadow-card';
$tab_idle = 'cursor-pointer rounded-sm border-0 bg-transparent px-3 py-1.5 text-sm font-medium text-muted-foreground hover:text-foreground';
?>

<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Invoice eksternal</h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Tagihan untuk perusahaan, instansi, atau perorangan di luar pelanggan berlangganan.</p>
        </div>
    </div>

    <div class="mb-5 flex w-fit flex-wrap gap-1 rounded-md bg-muted p-1" role="tablist">
        <button type="button" class="<?= $tab_active ?>" id="tabCreateBtn" onclick="showTab('create')">Buat invoice</button>
        <button type="button" class="<?= $tab_idle ?>" id="tabHistoryBtn" onclick="showTab('history')">Riwayat<?= $invoices ? ' <span class="tabular-nums text-muted-foreground">(' . count($invoices) . ')</span>' : '' ?></button>
        <button type="button" class="<?= $tab_idle ?>" id="tabTempsBtn" onclick="showTab('temps')">Pelanggan input manual</button>
        <button type="button" class="<?= $tab_idle ?>" id="tabItemsBtn" onclick="showTab('items')">Item tersimpan<?= $catalog_items ? ' <span class="tabular-nums text-muted-foreground">(' . count($catalog_items) . ')</span>' : '' ?></button>
    </div>

    <?php if ($flash_msg): ?>
        <div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Berhasil.</span> <?= htmlspecialchars($flash_msg) ?></div>
    <?php elseif ($flash_err): ?>
        <div class="ui-card mb-5 p-4 text-sm border-danger/40"><span class="font-semibold text-danger">Gagal.</span> <?= htmlspecialchars($flash_err) ?></div>
    <?php endif; ?>

    <style>
    /* Item table: fixed column widths shared by static and JS-created rows */
    #invoiceItemsTable { table-layout: fixed; width:100%; min-width:640px; }
    #invoiceItemsTable tbody td { padding:6px 8px; vertical-align:middle; }
    #invoiceItemsTable tbody td:first-child { width:46%; }
    #invoiceItemsTable tbody td:nth-child(2) { width:12%; }
    #invoiceItemsTable tbody td:nth-child(3) { width:18%; }
    #invoiceItemsTable tbody td:nth-child(4) { width:16%; }
    #invoiceItemsTable tbody td:nth-child(5) { width:8%; text-align:center; }
    #invoiceItemsTable tbody td input { width:100%; box-sizing:border-box; }
    #invoiceItemsTable tbody td input[name="item_qty[]"] { text-align:center; }
    #invoiceItemsTable tbody td input[name="item_unit[]"],
    #invoiceItemsTable tbody td input[name="item_amount[]"] { text-align:right; }
    #invoiceItemsTable tbody td input[readonly] { background:#F5F7F6; color:#5B6B72; }
    #invoiceItemsTable .row-remove { width:34px; height:34px; padding:0; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; }
    </style>

<?php require __DIR__ . '/../components/invoice_item_suggestions.php'; ?>

    <div id="createSection">
        <form method="POST" action="index.php?page=admin_assets&action=invoice_create" id="externalInvoiceForm" class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
<?= csrf_field() ?>
            <input type="hidden" name="created_via" value="external">
            <input type="hidden" name="customer_id" id="quick_invoice_customer_id" value="0">
            <input type="hidden" name="amount" id="invoice_total" value="0">

            <div class="grid gap-5">
                <section class="ui-card overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
                        <div>
                            <h3 class="m-0 text-[15px] font-bold">Penerima tagihan</h3>
                            <p class="m-0 text-xs text-muted-foreground">Data lengkap tercetak di invoice dan disimpan ke daftar pelanggan input manual.</p>
                        </div>
                        <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="clearRecipient()">Kosongkan</button>
                    </div>
                    <div class="grid gap-4 p-4 sm:p-5">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Ambil dari pelanggan input manual</span>
                            <select class="form-control" id="existing_customer_picker" onchange="useExistingCustomer(this.value)">
                                <option value="">Isi data baru</option>
                                <?php foreach($existing_customers as $cust): ?>
                                    <option value="<?= intval($cust['id']) ?>">
                                        <?= htmlspecialchars($cust['name']) ?><?= !empty($cust['company_name']) ? ' - ' . htmlspecialchars($cust['company_name']) : '' ?><?= !empty($cust['customer_code']) ? ' (' . htmlspecialchars($cust['customer_code']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama penerima <span class="text-danger">*</span></span>
                                <input type="text" name="recipient_name" class="form-control" placeholder="Nama orang yang ditagih" required>
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground">Perusahaan / instansi</span>
                                <input type="text" name="billing_company" class="form-control" placeholder="Opsional, contoh: PT Maju Jaya">
                            </label>
                        </div>

                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Alamat penagihan</span>
                            <textarea name="billing_address" class="form-control resize-none" rows="2" placeholder="Alamat lengkap yang dicantumkan di invoice"></textarea>
                        </label>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground">No. HP / WhatsApp</span>
                                <input type="text" name="billing_phone" class="form-control" placeholder="0812xxxxxxx" inputmode="tel">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground">Email</span>
                                <input type="email" name="billing_email" class="form-control" placeholder="nama@perusahaan.com">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground">NPWP</span>
                                <input type="text" name="billing_npwp" class="form-control" placeholder="Opsional">
                            </label>
                        </div>
                    </div>
                </section>

                <section class="ui-card overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
                        <div>
                            <h3 class="m-0 text-[15px] font-bold">Rincian tagihan</h3>
                            <p class="m-0 text-xs text-muted-foreground">Ketik deskripsi untuk memilih item yang pernah disimpan; harga satuan terisi otomatis.</p>
                        </div>
                        <button type="button" class="ui-btn ui-btn-sm ui-btn-outline" onclick="CreateInvoice.addItemRow()"><i class="fas fa-plus"></i> Tambah baris</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table id="invoiceItemsTable" class="w-full border-collapse text-sm">
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
                                <tr class="border-t border-solid border-border">
                                    <td class="pl-4 sm:pl-5"><input type="text" name="item_desc[]" class="form-control" placeholder="Contoh: Instalasi jaringan kantor" list="itemSuggestions" autocomplete="off" oninput="CreateInvoice.suggest(this)" required></td>
                                    <td><input type="number" name="item_qty[]" class="form-control" value="1" min="1" required oninput="CreateInvoice.recalculateRow(this)"></td>
                                    <td><input type="number" name="item_unit[]" class="form-control" value="0" min="0" required oninput="CreateInvoice.recalculateRow(this)"></td>
                                    <td><input type="number" name="item_amount[]" class="form-control" value="0" readonly tabindex="-1"></td>
                                    <td><button type="button" class="ui-btn ui-btn-ghost row-remove" onclick="CreateInvoice.removeItemRow(this)" title="Hapus baris"><i class="fas fa-trash"></i></button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-t border-solid border-border px-4 py-3 sm:px-5">
                        <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="CreateInvoice.clearItemRows()">Bersihkan semua</button>
                        <div class="text-sm text-muted-foreground">Subtotal <span class="ml-2 font-semibold tabular-nums text-foreground">Rp <span id="invoice_subtotal_display">0</span></span></div>
                    </div>
                </section>

                <section class="ui-card overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-solid border-border px-4 py-3 sm:px-5">
                        <div>
                            <h3 class="m-0 text-[15px] font-bold">Instruksi pembayaran</h3>
                            <p class="m-0 text-xs text-muted-foreground">Tercetak di bagian bawah invoice. Bisa diubah untuk tiap invoice.</p>
                        </div>
                        <?php if($default_payment_instructions !== ''): ?>
                        <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" onclick="useDefaultPaymentInstructions()">Pakai rekening perusahaan</button>
                        <?php endif; ?>
                    </div>
                    <div class="p-4 sm:p-5">
                        <textarea name="payment_instructions" id="payment_instructions" class="form-control" rows="4" placeholder="Contoh: Transfer ke BRI 0140 0100 2164 563 a.n. PT Contoh. Cantumkan nomor invoice pada berita transfer."><?= htmlspecialchars($default_payment_instructions) ?></textarea>
                    </div>
                </section>
            </div>

            <aside class="grid gap-5 lg:sticky lg:top-24">
                <section class="ui-card p-4 sm:p-5">
                    <h3 class="m-0 text-[15px] font-bold">Ringkasan</h3>
                    <dl class="m-0 mt-4 grid gap-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground">Tanggal terbit</dt>
                            <dd class="m-0 font-medium tabular-nums"><?= date('d/m/Y') ?></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground"><label for="due_date">Jatuh tempo</label></dt>
                            <dd class="m-0"><input type="date" name="due_date" id="due_date" class="form-control h-9 w-[160px] text-sm" value="<?= date('Y-m-d', strtotime('+7 days')) ?>"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground">Jumlah baris</dt>
                            <dd class="m-0 font-medium tabular-nums" id="invoice_item_count">1</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-muted-foreground">Dibuat oleh</dt>
                            <dd class="m-0 truncate font-medium"><?= htmlspecialchars($u_name ?: '-') ?></dd>
                        </div>
                    </dl>
                    <div class="mt-4 border-t border-solid border-border pt-4">
                        <div class="text-xs font-medium text-muted-foreground">Total tagihan</div>
                        <div class="mt-1 text-2xl font-extrabold tabular-nums text-primary">Rp <span id="invoice_total_display">0</span></div>
                    </div>
                    <div class="mt-5 grid gap-2">
                        <button class="ui-btn ui-btn-primary w-full" type="submit"><i class="fas fa-print"></i> Buat &amp; cetak</button>
                        <button class="ui-btn ui-btn-outline w-full" type="button" onclick="history.back()">Batal</button>
                    </div>
                    <p class="m-0 mt-3 text-xs text-muted-foreground">Setelah disimpan, invoice terbuka dalam tampilan cetak dan masuk ke tab Riwayat.</p>
                </section>
            </aside>
        </form>
    </div>

    <div id="historySection" style="display:none;">
        <section class="ui-card overflow-hidden">
            <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
                <h3 class="m-0 text-[15px] font-bold">Riwayat invoice eksternal</h3>
                <p class="m-0 text-xs text-muted-foreground">Invoice yang Anda buat dari halaman ini.</p>
            </div>
            <?php if(empty($invoices)): ?>
                <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada invoice yang Anda buat.</div>
            <?php else: ?>
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
                                $items = [];
                                try { $stmt_it = $db->prepare("SELECT description, qty, unit_price, amount FROM invoice_items WHERE invoice_id = ?"); $stmt_it->execute([intval($inv['id'])]); $items = $stmt_it->fetchAll(); } catch (Exception $e) { $items = []; }
                            ?>
                            <tr class="border-t border-solid border-border">
                                <td class="px-4 py-3 align-top text-xs tabular-nums text-muted-foreground whitespace-nowrap sm:px-5"><?= date('d/m/Y', strtotime($inv['created_at'])) ?></td>
                                <td class="px-3 py-3 align-top">
                                    <div class="text-sm font-semibold tabular-nums">INV-<?= str_pad($inv['id'],5,'0',STR_PAD_LEFT) ?></div>
                                    <div class="text-xs text-foreground"><?= htmlspecialchars($inv['customer_name'] ?? $inv['name'] ?? '-') ?><?= !empty($inv['billing_company']) ? ' <span class="text-muted-foreground">&middot; ' . htmlspecialchars($inv['billing_company']) . '</span>' : '' ?></div>
                                    <?php if(!empty($inv['billing_address'])): ?><div class="max-w-[280px] truncate text-xs text-muted-foreground"><?= htmlspecialchars($inv['billing_address']) ?></div><?php endif; ?>
                                    <?php $kontak = array_filter([$inv['billing_phone'] ?? '', $inv['billing_email'] ?? '']); if($kontak): ?><div class="text-xs text-muted-foreground"><?= htmlspecialchars(implode(' · ', $kontak)) ?></div><?php endif; ?>
                                </td>
                                <td class="px-3 py-3 text-right align-top font-bold tabular-nums whitespace-nowrap">Rp <?= number_format($inv['amount'],0,',','.') ?></td>
                                <td class="px-3 py-3 align-top whitespace-nowrap"><span class="ui-badge <?= $is_paid ? 'ui-badge-signal' : 'ui-badge-danger' ?>"><?= $is_paid ? 'Sudah bayar' : 'Belum bayar' ?></span></td>
                                <td class="px-4 py-3 align-top sm:px-5">
                                    <div class="flex justify-end gap-1.5">
                                    <?php if (!$is_paid): ?>
                                        <a data-method="post" class="ui-btn ui-btn-sm ui-btn-primary" title="Tandai lunas" href="index.php?page=admin_assets&action=invoice_mark_paid&id=<?= intval($inv['id']) ?>" onclick="return confirm('Tandai sebagai sudah dibayar?')"><i class="fas fa-money-bill-wave"></i></a>
                                    <?php endif; ?>
                                        <a class="ui-btn ui-btn-sm ui-btn-outline" title="Cetak" href="index.php?page=admin_invoices&action=print&id=<?= intval($inv['id']) ?>"><i class="fas fa-print"></i></a>
                                        <a class="ui-btn ui-btn-sm ui-btn-outline" title="Edit" href="index.php?page=admin_edit_quick_invoice&id=<?= intval($inv['id']) ?>"><i class="fas fa-edit"></i></a>
                                        <a class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus" href="index.php?page=admin_assets&action=invoice_delete_quick&id=<?= intval($inv['id']) ?>" onclick="return confirm('Hapus invoice ini?')"><i class="fas fa-trash"></i></a>
                                        <button type="button" class="ui-btn ui-btn-sm ui-btn-outline" title="Lihat item" onclick="CreateInvoice.toggleInvoiceItems(<?= intval($inv['id']) ?>)"><i class="fas fa-list"></i></button>
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
                                        <?php if(!empty($inv['payment_instructions'])): ?>
                                            <div class="mt-2 text-xs text-muted-foreground"><span class="font-semibold text-foreground">Instruksi pembayaran:</span> <?= nl2br(htmlspecialchars($inv['payment_instructions'])) ?></div>
                                        <?php endif; ?>
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
        <section class="ui-card overflow-hidden">
            <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
                <h3 class="m-0 text-[15px] font-bold">Pelanggan input manual</h3>
                <p class="m-0 text-xs text-muted-foreground">Penerima yang pernah ditagih dari halaman ini. Klik "Gunakan" untuk mengisi form.</p>
            </div>
            <?php if(empty($recent_temps)): ?>
                <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada pelanggan input manual.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3 sm:p-5">
                    <?php foreach($recent_temps as $t): ?>
                        <div class="flex flex-col gap-1 rounded-md border border-solid border-border p-4">
                            <div class="text-sm font-semibold"><?= htmlspecialchars($t['name']) ?></div>
                            <?php if(!empty($t['company_name'])): ?><div class="text-xs text-foreground"><?= htmlspecialchars($t['company_name']) ?></div><?php endif; ?>
                            <div class="text-xs text-muted-foreground"><?= htmlspecialchars($t['contact'] ?: '-') ?><?= !empty($t['email']) ? ' &middot; ' . htmlspecialchars($t['email']) : '' ?></div>
                            <div class="line-clamp-2 text-xs text-muted-foreground"><?= htmlspecialchars($t['address'] ?: '-') ?></div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" class="ui-btn ui-btn-sm ui-btn-primary" onclick="useTempCustomer(<?= intval($t['id']) ?>)">Gunakan</button>
                                <a class="ui-btn ui-btn-sm ui-btn-outline" href="index.php?page=admin_customers&action=details&id=<?= intval($t['id']) ?>">Detail</a>
                                <form method="POST" action="index.php?page=admin_temp_customers" class="m-0">
<?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= intval($t['id']) ?>">
                                    <button type="submit" class="ui-btn ui-btn-sm ui-btn-outline text-danger" onclick="return confirm('Hapus pelanggan input manual ini?')">Hapus</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div id="itemsSection" style="display:none;">
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
            <section class="ui-card overflow-hidden">
                <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
                    <h3 class="m-0 text-[15px] font-bold">Item tersimpan</h3>
                    <p class="m-0 text-xs text-muted-foreground">Muncul sebagai saran di kolom Deskripsi. Item baru yang dipakai pada invoice ditambahkan otomatis.</p>
                </div>
                <?php if (empty($catalog_items)): ?>
                    <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada item tersimpan. Tambahkan lewat form di samping.</div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse text-sm">
                            <thead>
                                <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                                    <th class="px-4 py-2.5 font-semibold sm:px-5">Deskripsi</th>
                                    <th class="px-3 py-2.5 text-right font-semibold">Harga satuan</th>
                                    <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($catalog_items as $ci): ?>
                                <tr class="border-t border-solid border-border" id="catalogRow-<?= intval($ci['id']) ?>">
                                    <td class="px-4 py-3 font-medium sm:px-5"><?= htmlspecialchars($ci['description']) ?></td>
                                    <td class="px-3 py-3 text-right tabular-nums whitespace-nowrap"><?= $ci['unit_price'] > 0 ? 'Rp ' . number_format($ci['unit_price'], 0, ',', '.') : '<span class="text-muted-foreground">-</span>' ?></td>
                                    <td class="px-4 py-3 sm:px-5">
                                        <div class="flex justify-end gap-1.5">
                                            <button type="button" class="ui-btn ui-btn-sm ui-btn-outline" title="Ubah" onclick="editCatalogItem(<?= intval($ci['id']) ?>)"><i class="fas fa-edit"></i><span class="hidden sm:inline">Ubah</span></button>
                                            <form method="POST" action="index.php?page=admin_create_invoice" class="m-0" onsubmit="return confirm('Hapus item ini dari daftar tersimpan?')">
<?= csrf_field() ?>
                                                <input type="hidden" name="catalog_action" value="delete">
                                                <input type="hidden" name="catalog_id" value="<?= intval($ci['id']) ?>">
                                                <button type="submit" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus"><i class="fas fa-trash"></i><span class="hidden sm:inline">Hapus</span></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="lg:sticky lg:top-24">
                <section class="ui-card p-4 sm:p-5">
                    <h3 class="m-0 text-[15px] font-bold" id="catalogFormTitle">Tambah item</h3>
                    <p class="m-0 mt-1 text-xs text-muted-foreground">Harga satuan dipakai untuk mengisi otomatis saat item dipilih.</p>
                    <form method="POST" action="index.php?page=admin_create_invoice" id="catalogForm" class="mt-4 grid gap-4">
<?= csrf_field() ?>
                        <input type="hidden" name="catalog_action" value="save">
                        <input type="hidden" name="catalog_id" id="catalog_id" value="0">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Deskripsi <span class="text-danger">*</span></span>
                            <input type="text" name="catalog_description" id="catalog_description" class="form-control" placeholder="Contoh: Instalasi jaringan kantor" maxlength="200" required>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Harga satuan (Rp)</span>
                            <input type="number" name="catalog_unit_price" id="catalog_unit_price" class="form-control" value="0" min="0" step="1">
                        </label>
                        <div class="grid gap-2">
                            <button type="submit" class="ui-btn ui-btn-primary w-full" id="catalogSubmitBtn">Simpan item</button>
                            <button type="button" class="ui-btn ui-btn-outline w-full hidden" id="catalogCancelBtn" onclick="resetCatalogForm()">Batal ubah</button>
                        </div>
                    </form>
                </section>
            </aside>
        </div>
    </div>
</div>

<script>
window.CreateInvoice = (function(){
    function rowTemplate() {
        return `
            <td class="pl-4 sm:pl-5"><input type="text" name="item_desc[]" class="form-control" placeholder="Deskripsi item" list="itemSuggestions" autocomplete="off" oninput="CreateInvoice.suggest(this)" required></td>
            <td><input type="number" name="item_qty[]" class="form-control" value="1" min="1" required oninput="CreateInvoice.recalculateRow(this)"></td>
            <td><input type="number" name="item_unit[]" class="form-control" value="0" min="0" required oninput="CreateInvoice.recalculateRow(this)"></td>
            <td><input type="number" name="item_amount[]" class="form-control" value="0" readonly tabindex="-1"></td>
            <td><button type="button" class="ui-btn ui-btn-ghost row-remove" onclick="CreateInvoice.removeItemRow(this)" title="Hapus baris"><i class="fas fa-trash"></i></button></td>
        `;
    }
    function addItemRow(focus) {
        const tb = document.getElementById('invoiceItemsTable').querySelector('tbody');
        const tr = document.createElement('tr');
        tr.className = 'border-t border-solid border-border';
        tr.innerHTML = rowTemplate();
        tb.appendChild(tr);
        updateGrandTotal();
        if (focus !== false) { const d = tr.querySelector('input[name="item_desc[]"]'); if (d) d.focus(); }
        return tr;
    }
    function removeItemRow(btn) {
        const tb = document.getElementById('invoiceItemsTable').querySelector('tbody');
        const tr = btn.closest('tr');
        if (tr) tr.remove();
        if (!tb.querySelector('tr')) addItemRow(false);
        updateGrandTotal();
    }
    function clearItemRows() {
        const tb = document.getElementById('invoiceItemsTable').querySelector('tbody');
        tb.innerHTML = '';
        addItemRow(false);
    }
    function recalculateRow(el) {
        const tr = el.closest('tr');
        if (!tr) return;
        const qtyEl = tr.querySelector('input[name="item_qty[]"]');
        const unitEl = tr.querySelector('input[name="item_unit[]"]');
        const amountEl = tr.querySelector('input[name="item_amount[]"]');
        const qty = parseInt(qtyEl.value) || 0;
        const unit = parseFloat(unitEl.value) || 0;
        amountEl.value = Math.round(qty * unit);
        updateGrandTotal();
    }
    function updateGrandTotal() {
        const amounts = Array.from(document.querySelectorAll('#invoiceItemsTable input[name="item_amount[]"]'));
        let total = 0;
        amounts.forEach(a => total += parseFloat(a.value) || 0);
        total = Math.round(total);
        const fmt = new Intl.NumberFormat('id-ID').format(total);
        const invTotalEl = document.getElementById('invoice_total');
        if (invTotalEl) invTotalEl.value = total;
        ['invoice_total_display', 'invoice_subtotal_display'].forEach(id => { const el = document.getElementById(id); if (el) el.innerText = fmt; });
        const count = document.getElementById('invoice_item_count');
        if (count) count.innerText = amounts.length;
    }
    function init() {
        document.querySelectorAll('#invoiceItemsTable input[name="item_qty[]"]').forEach(i => recalculateRow(i));
        updateGrandTotal();
    }
    function toggleInvoiceItems(id) {
        const el = document.getElementById('invItems-' + id);
        if (!el) return;
        el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'table-row' : 'none';
    }
    function suggest(el) { if (window.applyItemSuggestion) window.applyItemSuggestion(el, recalculateRow); }
    return { addItemRow, removeItemRow, clearItemRows, recalculateRow, updateGrandTotal, init, toggleInvoiceItems, suggest };
})();

document.addEventListener('DOMContentLoaded', function(){ try{ if(window.CreateInvoice) window.CreateInvoice.init(); }catch(e){} });

const TAB_ACTIVE = <?= json_encode($tab_active) ?>;
const TAB_IDLE = <?= json_encode($tab_idle) ?>;
function showTab(name) {
    const map = { create: 'createSection', history: 'historySection', temps: 'tempsSection', items: 'itemsSection' };
    Object.keys(map).forEach(k => { const s = document.getElementById(map[k]); if (s) s.style.display = (k === name) ? 'block' : 'none'; });
    const btns = { create: 'tabCreateBtn', history: 'tabHistoryBtn', temps: 'tabTempsBtn', items: 'tabItemsBtn' };
    Object.keys(btns).forEach(k => { const b = document.getElementById(btns[k]); if (b) b.className = (k === name) ? TAB_ACTIVE : TAB_IDLE; });
}

const DEFAULT_PAYMENT_INSTRUCTIONS = <?= json_encode($default_payment_instructions) ?>;
function useDefaultPaymentInstructions() {
    const el = document.getElementById('payment_instructions');
    if (el) el.value = DEFAULT_PAYMENT_INSTRUCTIONS;
}

// Recipient helpers ---------------------------------------------------------
const RECIPIENT_FIELDS = ['recipient_name', 'billing_company', 'billing_address', 'billing_phone', 'billing_email', 'billing_npwp'];
function setField(name, value) {
    const el = document.querySelector('[name="' + name + '"]');
    if (el) el.value = value || '';
}
function fillRecipient(c) {
    setField('recipient_name', c.name);
    setField('billing_company', c.company_name);
    setField('billing_address', c.address);
    setField('billing_phone', c.contact);
    setField('billing_email', c.email);
    setField('billing_npwp', c.npwp);
}
function clearRecipient() {
    RECIPIENT_FIELDS.forEach(n => setField(n, ''));
    const idField = document.getElementById('quick_invoice_customer_id'); if (idField) idField.value = 0;
    const picker = document.getElementById('existing_customer_picker'); if (picker) picker.value = '';
    const nameField = document.querySelector('[name="recipient_name"]'); if (nameField) nameField.focus();
}

let tempCustomers = [];
try { tempCustomers = <?= json_encode($recent_temps ?? []) ?>; } catch(e) { tempCustomers = []; }

function useTempCustomer(id) {
    const t = (Array.isArray(tempCustomers) ? tempCustomers.find(x => parseInt(x.id) === parseInt(id)) : null);
    if (!t) return alert('Data pelanggan input manual tidak ditemukan.');
    showTab('create');
    const idField = document.getElementById('quick_invoice_customer_id'); if (idField) idField.value = t.id;
    const picker = document.getElementById('existing_customer_picker'); if (picker) picker.value = String(t.id);
    fillRecipient(t);
    const firstDesc = document.querySelector('input[name="item_desc[]"]');
    if (firstDesc) firstDesc.focus();
    if (window.CreateInvoice) CreateInvoice.updateGrandTotal();
}

let existingCustomers = [];
try { existingCustomers = <?= json_encode($existing_customers ?? []) ?>; } catch(e) { existingCustomers = []; }

function useExistingCustomer(id) {
    if (!id) { clearRecipient(); return; }
    const c = (Array.isArray(existingCustomers) ? existingCustomers.find(x => parseInt(x.id) === parseInt(id)) : null);
    if (!c) return alert('Data pelanggan input manual tidak ditemukan.');

    const idField = document.getElementById('quick_invoice_customer_id'); if (idField) idField.value = id;
    showTab('create');
    fillRecipient(c);

    // Reuse the instruction that was last printed for this recipient
    if (c.last_payment_instructions) {
        const pi = document.getElementById('payment_instructions');
        if (pi) pi.value = c.last_payment_instructions;
    }

    // Subscribers with a monthly fee get their package pre-filled as the first line
    const fee = parseFloat(c.monthly_fee || 0);
    if (fee > 0) {
        const firstDesc = document.querySelector('input[name="item_desc[]"]');
        const firstQty = document.querySelector('input[name="item_qty[]"]');
        const firstUnit = document.querySelector('input[name="item_unit[]"]');
        if (firstDesc && !firstDesc.value) firstDesc.value = c.package_name || 'Tagihan Layanan';
        if (firstQty) firstQty.value = 1;
        if (firstUnit) { firstUnit.value = fee; CreateInvoice.recalculateRow(firstUnit); }
    }
    const firstDesc = document.querySelector('input[name="item_desc[]"]');
    if (firstDesc) firstDesc.focus();
    if (window.CreateInvoice) CreateInvoice.updateGrandTotal();
}

document.addEventListener('DOMContentLoaded', function(){ showTab(<?= json_encode($initial_tab) ?>); });

// Saved-item catalog form helpers
const CATALOG_ITEMS = <?= json_encode(array_map(fn($c) => ['id' => intval($c['id']), 'description' => $c['description'], 'unit_price' => floatval($c['unit_price'])], $catalog_items)) ?>;
function editCatalogItem(id) {
    const item = CATALOG_ITEMS.find(c => c.id === id); if (!item) return;
    document.getElementById('catalog_id').value = item.id;
    document.getElementById('catalog_description').value = item.description;
    document.getElementById('catalog_unit_price').value = item.unit_price;
    document.getElementById('catalogFormTitle').innerText = 'Ubah item';
    document.getElementById('catalogSubmitBtn').innerText = 'Simpan perubahan';
    document.getElementById('catalogCancelBtn').classList.remove('hidden');
    document.querySelectorAll('[id^="catalogRow-"]').forEach(r => r.classList.toggle('bg-muted', r.id === 'catalogRow-' + id));
    document.getElementById('catalog_description').focus();
}
function resetCatalogForm() {
    document.getElementById('catalog_id').value = 0;
    document.getElementById('catalog_description').value = '';
    document.getElementById('catalog_unit_price').value = 0;
    document.getElementById('catalogFormTitle').innerText = 'Tambah item';
    document.getElementById('catalogSubmitBtn').innerText = 'Simpan item';
    document.getElementById('catalogCancelBtn').classList.add('hidden');
    document.querySelectorAll('[id^="catalogRow-"]').forEach(r => r.classList.remove('bg-muted'));
}
</script>
