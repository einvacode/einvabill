<?php
// Kas & Bank: saldo per akun, setoran petugas / transfer antar akun, dan buku mutasi.
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    echo "<div class='ui-card p-10 text-center'><h2 class='m-0 text-xl font-bold'>Akses ditolak</h2></div>"; return;
}
$tenant_id = intval($_SESSION['tenant_id'] ?? 1);
$u_id = intval($_SESSION['user_id']);
cash_accounts_ensure($db, $tenant_id);

$tab = in_array($_GET['tab'] ?? '', ['accounts', 'transfers', 'ledger']) ? $_GET['tab'] : 'accounts';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cash_action'])) {
    $act = $_POST['cash_action'];
    $back = 'index.php?page=admin_cash&tab=';
    $err = ''; $msg = '';
    if ($act === 'account_save') { $err = cash_account_save($db, $tenant_id, intval($_POST['account_id'] ?? 0), $_POST); $msg = 'account_saved'; $back .= 'accounts'; }
    elseif ($act === 'account_delete') { $err = cash_account_delete($db, $tenant_id, intval($_POST['account_id'] ?? 0)); $msg = 'account_deleted'; $back .= 'accounts'; }
    elseif ($act === 'transfer_save') { $err = cash_transfer_save($db, $tenant_id, $u_id, $_POST); $msg = ($_POST['type'] ?? '') === 'adjustment' ? 'adjust_saved' : 'transfer_saved'; $back .= 'transfers'; }
    elseif ($act === 'transfer_delete') { cash_transfer_delete($db, $tenant_id, intval($_POST['transfer_id'] ?? 0)); $msg = 'transfer_deleted'; $back .= 'transfers'; }
    elseif ($act === 'reassign') { $err = cash_reassign($db, $tenant_id, (string)($_POST['kind'] ?? ''), intval($_POST['row_id'] ?? 0), intval($_POST['account_id'] ?? 0)); $msg = 'reassigned'; $back .= 'ledger&' . http_build_query(['account' => $_POST['ret_account'] ?? '', 'date_from' => $_POST['ret_from'] ?? '', 'date_to' => $_POST['ret_to'] ?? '']); }
    header('Location: ' . $back . ($err ? '&err=' . urlencode($err) : '&msg=' . $msg));
    exit;
}

$as_of = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['as_of'] ?? '') ? $_GET['as_of'] : date('Y-m-d');
$accounts = cash_accounts_with_balances($db, $tenant_id, $as_of);
$active_accounts = array_values(array_filter($accounts, fn($a) => $a['is_active']));
$total_balance = array_sum(array_map(fn($a) => $a['balance'], $active_accounts));
$wallets = array_values(array_filter($active_accounts, fn($a) => $a['type'] === 'wallet' || !empty($a['owner_user_id'])));
$office = array_values(array_filter($active_accounts, fn($a) => $a['type'] !== 'wallet' && empty($a['owner_user_id'])));
$held_by_staff = array_sum(array_map(fn($a) => $a['balance'], array_filter($wallets, fn($a) => $a['owner_role'] !== 'admin')));
$acc_by_id = []; foreach ($accounts as $a) $acc_by_id[$a['id']] = $a;
$staff = $db->query("SELECT id, name, role FROM users WHERE tenant_id = $tenant_id AND role IN ('admin','collector') ORDER BY role, name")->fetchAll(PDO::FETCH_ASSOC);

$type_label = ['cash' => 'Kas tunai', 'bank' => 'Rekening bank', 'ewallet' => 'Dompet digital', 'wallet' => 'Dompet petugas'];
if (!function_exists('rp')) { function rp($n): string { return 'Rp ' . number_format((float)($n ?: 0), 0, ',', '.'); } }
$flash = ['account_saved' => 'Akun tersimpan.', 'account_deleted' => 'Akun dihapus.', 'transfer_saved' => 'Transfer tercatat.', 'adjust_saved' => 'Penyesuaian saldo tercatat.', 'transfer_deleted' => 'Transaksi dihapus.', 'reassigned' => 'Transaksi dipindahkan ke akun lain.'][$_GET['msg'] ?? ''] ?? '';
$flash_err = trim((string)($_GET['err'] ?? ''));
$tab_active = 'rounded-sm bg-card px-3 py-1.5 text-sm font-semibold text-foreground shadow-card no-underline';
$tab_idle = 'rounded-sm px-3 py-1.5 text-sm font-medium text-muted-foreground hover:text-foreground no-underline';

// Ledger filters
$l_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-01');
$l_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'] ?? '') ? $_GET['date_to'] : date('Y-m-d');
$l_acc = intval($_GET['account'] ?? 0);
?>

<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Kas &amp; bank</h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Saldo tiap akun, uang yang masih dipegang petugas, dan mutasi masuk keluar. Keuangan mitra tidak termasuk; kantor hanya mencatat setoran kolektif mitra.</p>
        </div>
        <?php if ($tab === 'accounts'): ?>
        <form method="get" class="flex items-end gap-2">
            <input type="hidden" name="page" value="admin_cash">
            <label class="block">
                <span class="mb-1 block text-xs font-medium text-muted-foreground">Saldo per tanggal</span>
                <input type="date" name="as_of" class="form-control h-10 w-[170px]" value="<?= $as_of ?>" onchange="this.form.submit()">
            </label>
        </form>
        <?php endif; ?>
    </div>

    <div class="mb-5 flex w-fit flex-wrap gap-1 rounded-md bg-muted p-1" role="tablist">
        <a href="index.php?page=admin_cash&tab=accounts" class="<?= $tab === 'accounts' ? $tab_active : $tab_idle ?>">Saldo akun</a>
        <a href="index.php?page=admin_cash&tab=transfers" class="<?= $tab === 'transfers' ? $tab_active : $tab_idle ?>">Setoran &amp; transfer</a>
        <a href="index.php?page=admin_cash&tab=ledger" class="<?= $tab === 'ledger' ? $tab_active : $tab_idle ?>">Mutasi</a>
    </div>

    <?php if ($flash): ?>
        <div class="ui-card mb-5 p-4 text-sm"><span class="font-semibold text-signal">Berhasil.</span> <?= htmlspecialchars($flash) ?></div>
    <?php elseif ($flash_err): ?>
        <div class="ui-card mb-5 p-4 text-sm border-danger/40"><span class="font-semibold text-danger">Gagal.</span> <?= htmlspecialchars($flash_err) ?></div>
    <?php endif; ?>

    <?php if ($tab === 'accounts'): ?>
    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <div class="ui-card p-4 sm:p-5">
            <div class="text-xs font-medium text-muted-foreground">Total kas &amp; bank</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums text-primary"><?= rp($total_balance) ?></div>
            <div class="text-xs text-muted-foreground">Per <?= date('d/m/Y', strtotime($as_of)) ?>, semua akun aktif</div>
        </div>
        <div class="ui-card p-4 sm:p-5">
            <div class="text-xs font-medium text-muted-foreground">Di kantor &amp; bank</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums"><?= rp(array_sum(array_map(fn($a) => $a['balance'], $office))) ?></div>
            <div class="text-xs text-muted-foreground"><?= count($office) ?> akun</div>
        </div>
        <div class="ui-card p-4 sm:p-5">
            <div class="text-xs font-medium text-muted-foreground">Masih dipegang petugas</div>
            <div class="mt-1 text-2xl font-extrabold tabular-nums <?= $held_by_staff > 0 ? 'text-accent-ink' : '' ?>"><?= rp($held_by_staff) ?></div>
            <div class="text-xs text-muted-foreground">Belum disetor ke kantor</div>
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
        <section class="ui-card overflow-hidden">
            <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
                <h3 class="m-0 text-[15px] font-bold">Akun kas &amp; bank</h3>
                <p class="m-0 text-xs text-muted-foreground">Pembayaran masuk ke dompet siapa yang mencatat; pengeluaran keluar dari akun yang dipilih di formnya.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                            <th class="min-w-[240px] px-4 py-2.5 font-semibold sm:px-5">Akun</th>
                            <th class="px-3 py-2.5 text-right font-semibold">Saldo</th>
                            <th class="px-4 py-2.5 text-right font-semibold sm:px-5"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($accounts as $a): $in = $a['in_payments'] + $a['in_transfers']; $out = $a['out_expenses'] + $a['out_transfers']; ?>
                        <tr class="border-t border-solid border-border <?= $a['is_active'] ? '' : 'opacity-60' ?>" id="accRow-<?= intval($a['id']) ?>">
                            <td class="px-4 py-3 sm:px-5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold"><?= htmlspecialchars($a['name']) ?></span>
                                    <?php if ($a['is_default']): ?><span class="ui-badge ui-badge-muted">Utama</span><?php endif; ?>
                                    <?php if (!$a['is_active']): ?><span class="ui-badge ui-badge-muted">Nonaktif</span><?php endif; ?>
                                </div>
                                <div class="text-xs text-muted-foreground"><?= $type_label[$a['type']] ?? $a['type'] ?><?= !empty($a['account_number']) ? ' &middot; ' . htmlspecialchars($a['account_number']) : '' ?><?= !empty($a['owner_name']) ? ' &middot; ' . htmlspecialchars($a['owner_name']) : '' ?><?= (float)$a['opening_balance'] != 0 ? ' &middot; saldo awal ' . rp($a['opening_balance']) . (!empty($a['opening_date']) ? ' (' . date('d/m/Y', strtotime($a['opening_date'])) . ')' : '') : '' ?></div>
                            </td>
                            <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap">
                                <div class="font-bold <?= $a['balance'] < 0 ? 'text-danger' : '' ?>"><?= rp($a['balance']) ?></div>
                                <div class="text-[11px] text-muted-foreground"><span class="text-signal">+<?= rp($in) ?></span> &middot; <span class="text-danger">-<?= rp($out) ?></span></div>
                            </td>
                            <td class="px-4 py-3 align-top sm:px-5">
                                <div class="flex justify-end gap-1.5 whitespace-nowrap">
                                    <a class="ui-btn ui-btn-sm ui-btn-outline" href="index.php?page=admin_cash&tab=ledger&account=<?= intval($a['id']) ?>&date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-d') ?>" title="Mutasi"><i class="fas fa-list"></i></a>
                                    <button type="button" class="ui-btn ui-btn-sm ui-btn-outline" title="Ubah" onclick="editAccount(<?= intval($a['id']) ?>)"><i class="fas fa-edit"></i></button>
                                    <?php if (!$a['is_default'] && empty($a['owner_user_id'])): ?>
                                    <form method="POST" action="index.php?page=admin_cash" class="m-0" onsubmit="return confirm('Hapus akun ini?')">
<?= csrf_field() ?>
                                        <input type="hidden" name="cash_action" value="account_delete">
                                        <input type="hidden" name="account_id" value="<?= intval($a['id']) ?>">
                                        <button type="submit" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus"><i class="fas fa-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-solid border-border bg-muted font-semibold">
                            <td class="px-4 py-3 sm:px-5">Total akun aktif</td>
                            <td class="px-3 py-3 text-right tabular-nums"><?= rp($total_balance) ?></td>
                            <td class="px-4 py-3 sm:px-5"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <aside class="lg:sticky lg:top-24">
            <section class="ui-card p-4 sm:p-5">
                <h3 class="m-0 text-[15px] font-bold" id="accFormTitle">Tambah akun</h3>
                <p class="m-0 mt-1 text-xs text-muted-foreground">Contoh: Rekening BRI, Kas kantor, QRIS.</p>
                <form method="POST" action="index.php?page=admin_cash" class="mt-4 grid gap-4" id="accForm">
<?= csrf_field() ?>
                    <input type="hidden" name="cash_action" value="account_save">
                    <input type="hidden" name="account_id" id="acc_id" value="0">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Nama akun <span class="text-danger">*</span></span>
                        <input type="text" name="name" id="acc_name" class="form-control" placeholder="Rekening BRI" maxlength="60" required>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Jenis</span>
                            <select name="type" id="acc_type" class="form-control">
                                <option value="cash">Kas tunai</option>
                                <option value="bank">Rekening bank</option>
                                <option value="ewallet">Dompet digital</option>
                                <option value="wallet">Dompet petugas</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">No. rekening</span>
                            <input type="text" name="account_number" id="acc_number" class="form-control" placeholder="Opsional">
                        </label>
                    </div>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Pemegang (untuk dompet petugas)</span>
                        <select name="owner_user_id" id="acc_owner" class="form-control">
                            <option value="">Tidak ada (akun kantor)</option>
                            <?php foreach ($staff as $s): ?>
                                <option value="<?= intval($s['id']) ?>"><?= htmlspecialchars($s['name']) ?> (<?= $s['role'] === 'admin' ? 'admin' : 'petugas' ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Saldo awal (Rp)</span>
                            <input type="number" name="opening_balance" id="acc_opening" class="form-control" value="0" step="1">
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Per tanggal</span>
                            <input type="date" name="opening_date" id="acc_opening_date" class="form-control">
                        </label>
                    </div>
                    <div class="grid gap-2 text-sm">
                        <label class="flex items-center gap-2"><input type="checkbox" name="is_default" id="acc_default" value="1"> Jadikan akun utama (tujuan pengeluaran bila tidak dipilih)</label>
                        <label class="flex items-center gap-2"><input type="checkbox" name="is_active" id="acc_active" value="1" checked> Aktif</label>
                    </div>
                    <div class="grid gap-2">
                        <button type="submit" class="ui-btn ui-btn-primary w-full" id="accSubmitBtn">Simpan akun</button>
                        <button type="button" class="ui-btn ui-btn-outline w-full hidden" id="accCancelBtn" onclick="resetAccountForm()">Batal ubah</button>
                    </div>
                </form>
            </section>
        </aside>
    </div>

    <script>
    const CASH_ACCOUNTS = <?= json_encode(array_map(fn($a) => ['id' => intval($a['id']), 'name' => $a['name'], 'type' => $a['type'], 'account_number' => (string)$a['account_number'], 'owner_user_id' => intval($a['owner_user_id'] ?? 0), 'opening_balance' => (float)$a['opening_balance'], 'opening_date' => (string)$a['opening_date'], 'is_default' => (int)$a['is_default'], 'is_active' => (int)$a['is_active']], $accounts)) ?>;
    function editAccount(id) {
        const a = CASH_ACCOUNTS.find(x => x.id === id); if (!a) return;
        document.getElementById('acc_id').value = a.id;
        document.getElementById('acc_name').value = a.name;
        document.getElementById('acc_type').value = a.type;
        document.getElementById('acc_number').value = a.account_number;
        document.getElementById('acc_owner').value = a.owner_user_id || '';
        document.getElementById('acc_opening').value = a.opening_balance;
        document.getElementById('acc_opening_date').value = a.opening_date;
        document.getElementById('acc_default').checked = !!a.is_default;
        document.getElementById('acc_active').checked = !!a.is_active;
        document.getElementById('accFormTitle').innerText = 'Ubah akun';
        document.getElementById('accSubmitBtn').innerText = 'Simpan perubahan';
        document.getElementById('accCancelBtn').classList.remove('hidden');
        document.querySelectorAll('[id^="accRow-"]').forEach(r => r.classList.toggle('bg-muted', r.id === 'accRow-' + id));
        document.getElementById('acc_name').focus();
    }
    function resetAccountForm() {
        document.getElementById('accForm').reset();
        document.getElementById('acc_id').value = 0;
        document.getElementById('accFormTitle').innerText = 'Tambah akun';
        document.getElementById('accSubmitBtn').innerText = 'Simpan akun';
        document.getElementById('accCancelBtn').classList.add('hidden');
        document.querySelectorAll('[id^="accRow-"]').forEach(r => r.classList.remove('bg-muted'));
    }
    </script>

    <?php elseif ($tab === 'transfers'): ?>
    <?php
    $transfers = $db->prepare("SELECT x.*, u.name AS who FROM cash_transfers x LEFT JOIN users u ON u.id = x.created_by WHERE x.tenant_id = ? ORDER BY x.date DESC, x.id DESC LIMIT 200");
    $transfers->execute([$tenant_id]);
    $transfers = $transfers->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start">
        <section class="ui-card overflow-hidden">
            <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
                <h3 class="m-0 text-[15px] font-bold">Riwayat setoran &amp; transfer</h3>
                <p class="m-0 text-xs text-muted-foreground">Setoran petugas ke kantor, setor tunai ke bank, tarik tunai, dan penyesuaian saldo.</p>
            </div>
            <?php if (empty($transfers)): ?>
                <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada transaksi. Catat setoran pertama lewat form di samping.</div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                            <th class="px-4 py-2.5 font-semibold sm:px-5">Tanggal</th>
                            <th class="px-3 py-2.5 font-semibold">Dari</th>
                            <th class="px-3 py-2.5 font-semibold">Ke</th>
                            <th class="px-3 py-2.5 text-right font-semibold">Jumlah</th>
                            <th class="px-3 py-2.5 font-semibold">Catatan</th>
                            <th class="px-4 py-2.5 text-right font-semibold sm:px-5"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transfers as $x): ?>
                        <tr class="border-t border-solid border-border">
                            <td class="px-4 py-3 align-top tabular-nums whitespace-nowrap sm:px-5">
                                <div class="font-semibold"><?= date('d/m/Y', strtotime($x['date'])) ?></div>
                                <div class="text-xs text-muted-foreground"><?= htmlspecialchars($x['who'] ?: '-') ?></div>
                            </td>
                            <td class="px-3 py-3 align-top"><?= $x['from_account_id'] ? htmlspecialchars($acc_by_id[$x['from_account_id']]['name'] ?? '?') : '<span class="text-muted-foreground">-</span>' ?></td>
                            <td class="px-3 py-3 align-top"><?= $x['to_account_id'] ? htmlspecialchars($acc_by_id[$x['to_account_id']]['name'] ?? '?') : '<span class="text-muted-foreground">-</span>' ?></td>
                            <td class="px-3 py-3 text-right align-top font-semibold tabular-nums whitespace-nowrap"><?= rp($x['amount']) ?></td>
                            <td class="px-3 py-3 align-top text-muted-foreground">
                                <?php if ($x['type'] === 'adjustment'): ?><span class="ui-badge ui-badge-accent">Penyesuaian</span> <?php endif; ?><?= htmlspecialchars($x['note'] ?: '-') ?>
                            </td>
                            <td class="px-4 py-3 align-top sm:px-5">
                                <form method="POST" action="index.php?page=admin_cash" class="m-0 flex justify-end" onsubmit="return confirm('Hapus transaksi ini? Saldo akan dihitung ulang.')">
<?= csrf_field() ?>
                                    <input type="hidden" name="cash_action" value="transfer_delete">
                                    <input type="hidden" name="transfer_id" value="<?= intval($x['id']) ?>">
                                    <button type="submit" class="ui-btn ui-btn-sm ui-btn-outline text-danger" title="Hapus"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <aside class="grid gap-5 lg:sticky lg:top-24">
            <section class="ui-card p-4 sm:p-5">
                <h3 class="m-0 text-[15px] font-bold">Catat setoran / transfer</h3>
                <p class="m-0 mt-1 text-xs text-muted-foreground">Uang berpindah dari satu akun ke akun lain. Saldo dompet petugas berkurang, saldo kantor bertambah.</p>
                <form method="POST" action="index.php?page=admin_cash" class="mt-4 grid gap-4">
<?= csrf_field() ?>
                    <input type="hidden" name="cash_action" value="transfer_save">
                    <input type="hidden" name="type" value="transfer">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Dari akun</span>
                        <select name="from_account_id" class="form-control" required>
                            <?php foreach ($active_accounts as $a): ?>
                                <option value="<?= intval($a['id']) ?>" <?= (!empty($a['owner_user_id']) && $a['owner_role'] !== 'admin' && $a['balance'] > 0) ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?> (<?= rp($a['balance']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Ke akun</span>
                        <select name="to_account_id" class="form-control" required>
                            <?php foreach ($active_accounts as $a): ?>
                                <option value="<?= intval($a['id']) ?>" <?= $a['is_default'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Jumlah (Rp) <span class="text-danger">*</span></span>
                            <input type="number" name="amount" class="form-control" min="1" step="1" required>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal</span>
                            <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </label>
                    </div>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Catatan</span>
                        <input type="text" name="note" class="form-control" placeholder="Contoh: Setoran tagihan minggu ke-2" maxlength="200">
                    </label>
                    <button type="submit" class="ui-btn ui-btn-primary w-full">Catat transfer</button>
                </form>
            </section>

            <section class="ui-card p-4 sm:p-5">
                <h3 class="m-0 text-[15px] font-bold">Penyesuaian saldo</h3>
                <p class="m-0 mt-1 text-xs text-muted-foreground">Untuk mencocokkan dengan saldo rekening sebenarnya. Isi angka negatif bila saldo di aplikasi lebih besar.</p>
                <form method="POST" action="index.php?page=admin_cash" class="mt-4 grid gap-4">
<?= csrf_field() ?>
                    <input type="hidden" name="cash_action" value="transfer_save">
                    <input type="hidden" name="type" value="adjustment">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Akun</span>
                        <select name="account_id" class="form-control" required>
                            <?php foreach ($active_accounts as $a): ?>
                                <option value="<?= intval($a['id']) ?>"><?= htmlspecialchars($a['name']) ?> (<?= rp($a['balance']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Selisih (Rp)</span>
                            <input type="number" name="amount" class="form-control" step="1" placeholder="-25000" required>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium text-muted-foreground">Tanggal</span>
                            <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </label>
                    </div>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Alasan</span>
                        <input type="text" name="note" class="form-control" placeholder="Contoh: Biaya admin bank" maxlength="200">
                    </label>
                    <button type="submit" class="ui-btn ui-btn-outline w-full">Catat penyesuaian</button>
                </form>
            </section>
        </aside>
    </div>

    <?php else: ?>
    <?php $ledger = cash_ledger($db, $tenant_id, $l_from, $l_to, $l_acc); $sum_in = 0; $sum_out = 0; ?>
    <form method="get" class="ui-card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_180px_180px_auto] lg:items-end">
        <input type="hidden" name="page" value="admin_cash">
        <input type="hidden" name="tab" value="ledger">
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Akun</span>
            <select name="account" class="form-control">
                <option value="0">Semua akun</option>
                <?php foreach ($accounts as $a): ?><option value="<?= intval($a['id']) ?>" <?= $l_acc === intval($a['id']) ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label class="block"><span class="mb-1 block text-xs font-medium text-muted-foreground">Dari</span><input type="date" name="date_from" class="form-control" value="<?= $l_from ?>"></label>
        <label class="block"><span class="mb-1 block text-xs font-medium text-muted-foreground">Sampai</span><input type="date" name="date_to" class="form-control" value="<?= $l_to ?>"></label>
        <button type="submit" class="ui-btn ui-btn-primary">Tampilkan</button>
    </form>

    <section class="ui-card overflow-hidden">
        <div class="border-b border-solid border-border px-4 py-3 sm:px-5">
            <h3 class="m-0 text-[15px] font-bold">Mutasi <?= $l_acc && isset($acc_by_id[$l_acc]) ? htmlspecialchars($acc_by_id[$l_acc]['name']) : 'semua akun' ?></h3>
            <p class="m-0 text-xs text-muted-foreground"><?= date('d/m/Y', strtotime($l_from)) ?> - <?= date('d/m/Y', strtotime($l_to)) ?>. Pembayaran dan pengeluaran bisa dipindahkan ke akun lain lewat kolom Akun.</p>
        </div>
        <?php if (empty($ledger)): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">Tidak ada mutasi pada periode ini.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                        <th class="px-4 py-2.5 font-semibold sm:px-5">Tanggal</th>
                        <th class="px-3 py-2.5 font-semibold">Keterangan</th>
                        <th class="px-3 py-2.5 font-semibold">Akun</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Masuk</th>
                        <th class="px-4 py-2.5 text-right font-semibold sm:px-5">Keluar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ledger as $r):
                        $is_xfer = in_array($r['kind'], ['transfer', 'adjustment']);
                        if ($is_xfer) {
                            // Shown from the perspective of the filtered account, or as from -> to when unfiltered.
                            if ($l_acc) { $in = intval($r['counter_account_id']) === $l_acc ? $r['amount'] : 0; $out = intval($r['account_id']) === $l_acc ? $r['amount'] : 0; }
                            else { $in = $r['counter_account_id'] ? $r['amount'] : 0; $out = $r['account_id'] ? $r['amount'] : 0; }
                        } else { $in = $r['amount'] > 0 ? $r['amount'] : 0; $out = $r['amount'] < 0 ? -$r['amount'] : 0; }
                        $sum_in += $in; $sum_out += $out;
                        $acc_label = $is_xfer
                            ? (($r['account_id'] ? ($acc_by_id[$r['account_id']]['name'] ?? '?') : '-') . ' → ' . ($r['counter_account_id'] ? ($acc_by_id[$r['counter_account_id']]['name'] ?? '?') : '-'))
                            : ($acc_by_id[$r['account_id']]['name'] ?? '?');
                    ?>
                    <tr class="border-t border-solid border-border">
                        <td class="px-4 py-3 align-top tabular-nums whitespace-nowrap sm:px-5">
                            <div class="font-semibold"><?= date('d/m/Y', strtotime($r['at'])) ?></div>
                            <div class="text-xs text-muted-foreground"><?= htmlspecialchars($r['who'] ?: '-') ?></div>
                        </td>
                        <td class="px-3 py-3 align-top"><?= htmlspecialchars($r['label']) ?></td>
                        <td class="px-3 py-3 align-top">
                            <?php if ($is_xfer): ?>
                                <span class="text-xs"><?= htmlspecialchars($acc_label) ?></span>
                            <?php else: ?>
                                <form method="POST" action="index.php?page=admin_cash" class="m-0">
<?= csrf_field() ?>
                                    <input type="hidden" name="cash_action" value="reassign">
                                    <input type="hidden" name="kind" value="<?= $r['kind'] ?>">
                                    <input type="hidden" name="row_id" value="<?= intval($r['id']) ?>">
                                    <input type="hidden" name="ret_account" value="<?= $l_acc ?>"><input type="hidden" name="ret_from" value="<?= $l_from ?>"><input type="hidden" name="ret_to" value="<?= $l_to ?>">
                                    <select name="account_id" class="form-control h-9 w-[200px] text-sm" onchange="this.form.submit()" title="Pindahkan ke akun lain">
                                        <?php foreach ($accounts as $a): ?><option value="<?= intval($a['id']) ?>" <?= intval($r['account_id']) === intval($a['id']) ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option><?php endforeach; ?>
                                    </select>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-3 text-right align-top tabular-nums whitespace-nowrap <?= $in > 0 ? 'text-signal font-semibold' : 'text-muted-foreground' ?>"><?= $in > 0 ? rp($in) : '-' ?></td>
                        <td class="px-4 py-3 text-right align-top tabular-nums whitespace-nowrap sm:px-5 <?= $out > 0 ? 'text-danger font-semibold' : 'text-muted-foreground' ?>"><?= $out > 0 ? rp($out) : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t border-solid border-border bg-muted font-semibold">
                        <td class="px-4 py-3 sm:px-5" colspan="3">Total periode</td>
                        <td class="px-3 py-3 text-right tabular-nums text-signal"><?= rp($sum_in) ?></td>
                        <td class="px-4 py-3 text-right tabular-nums text-danger sm:px-5"><?= rp($sum_out) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
