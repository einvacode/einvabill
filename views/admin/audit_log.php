<?php
// Jejak audit: catatan tindakan yang menyentuh uang atau menghapus data.
if (app_scope_role() !== 'admin') {
    echo "<div class='ui-card p-10 text-center'><h2 class='m-0 text-xl font-bold'>Akses ditolak</h2></div>"; return;
}
$tenant_id = intval($_SESSION['tenant_id'] ?? 1);

$f_action = trim((string)($_GET['action_type'] ?? ''));
$f_user = intval($_GET['user'] ?? 0);
$f_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-01');
$f_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'] ?? '') ? $_GET['date_to'] : date('Y-m-d');
$page_no = max(1, intval($_GET['p'] ?? 1));
$per_page = 60;

$where = "WHERE tenant_id = :t AND created_at BETWEEN :from AND :to";
$params = [':t' => $tenant_id, ':from' => $f_from . ' 00:00:00', ':to' => $f_to . ' 23:59:59'];
if ($f_action !== '') { $where .= " AND action = :a"; $params[':a'] = $f_action; }
if ($f_user > 0) { $where .= " AND user_id = :u"; $params[':u'] = $f_user; }

$count = $db->prepare("SELECT COUNT(*) FROM audit_logs $where");
$count->execute($params);
$total = (int)$count->fetchColumn();
$pages = max(1, (int)ceil($total / $per_page));
$page_no = min($page_no, $pages);

$st = $db->prepare("SELECT * FROM audit_logs $where ORDER BY created_at DESC, id DESC LIMIT $per_page OFFSET " . (($page_no - 1) * $per_page));
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$actions = $db->prepare("SELECT action, COUNT(*) n FROM audit_logs WHERE tenant_id = ? GROUP BY action ORDER BY action");
$actions->execute([$tenant_id]);
$actions = $actions->fetchAll(PDO::FETCH_ASSOC);
$actors = $db->prepare("SELECT DISTINCT user_id, user_name FROM audit_logs WHERE tenant_id = ? AND user_id > 0 ORDER BY user_name");
$actors->execute([$tenant_id]);
$actors = $actors->fetchAll(PDO::FETCH_ASSOC);

$role_label = ['admin' => 'Admin', 'bendahara' => 'Bendahara', 'collector' => 'Petugas', 'partner' => 'Mitra', 'system' => 'Sistem'];
$qs = fn(array $extra = []) => 'index.php?' . http_build_query(array_merge(['page' => 'admin_audit', 'action_type' => $f_action, 'user' => $f_user ?: '', 'date_from' => $f_from, 'date_to' => $f_to], $extra));
?>

<div>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="m-0 text-xl font-bold sm:text-2xl">Jejak audit</h2>
            <p class="m-0 mt-1 text-sm text-muted-foreground">Catatan siapa mencatat, mengubah, atau menghapus transaksi. Baris di sini tidak bisa diubah atau dihapus dari aplikasi.</p>
        </div>
        <div class="text-sm text-muted-foreground"><span class="font-semibold tabular-nums text-foreground"><?= number_format($total) ?></span> kejadian pada periode ini</div>
    </div>

    <form method="get" class="ui-card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_170px_170px_auto] lg:items-end">
        <input type="hidden" name="page" value="admin_audit">
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Jenis tindakan</span>
            <select name="action_type" class="form-control">
                <option value="">Semua tindakan</option>
                <?php foreach ($actions as $a): ?>
                <option value="<?= htmlspecialchars($a['action']) ?>" <?= $f_action === $a['action'] ? 'selected' : '' ?>><?= htmlspecialchars(audit_action_label($a['action'])) ?> (<?= number_format($a['n']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-muted-foreground">Pelaku</span>
            <select name="user" class="form-control">
                <option value="0">Semua pengguna</option>
                <?php foreach ($actors as $a): ?>
                <option value="<?= intval($a['user_id']) ?>" <?= $f_user === intval($a['user_id']) ? 'selected' : '' ?>><?= htmlspecialchars($a['user_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="block"><span class="mb-1 block text-xs font-medium text-muted-foreground">Dari</span><input type="date" name="date_from" class="form-control" value="<?= $f_from ?>"></label>
        <label class="block"><span class="mb-1 block text-xs font-medium text-muted-foreground">Sampai</span><input type="date" name="date_to" class="form-control" value="<?= $f_to ?>"></label>
        <button type="submit" class="ui-btn ui-btn-primary">Tampilkan</button>
    </form>

    <section class="ui-card overflow-hidden">
        <?php if (empty($rows)): ?>
            <div class="px-5 py-10 text-center text-sm text-muted-foreground">Belum ada kejadian tercatat pada periode ini.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-semibold text-muted-foreground">
                        <th class="px-4 py-2.5 font-semibold sm:px-5">Waktu</th>
                        <th class="px-3 py-2.5 font-semibold">Pelaku</th>
                        <th class="px-3 py-2.5 font-semibold">Tindakan</th>
                        <th class="min-w-[280px] px-4 py-2.5 font-semibold sm:px-5">Rincian</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr class="border-t border-solid border-border">
                        <td class="px-4 py-3 align-top tabular-nums whitespace-nowrap sm:px-5">
                            <div class="font-medium"><?= date('d/m/Y', strtotime($r['created_at'])) ?></div>
                            <div class="text-xs text-muted-foreground"><?= date('H:i', strtotime($r['created_at'])) ?></div>
                        </td>
                        <td class="px-3 py-3 align-top">
                            <div class="font-medium"><?= htmlspecialchars($r['user_name'] ?: 'Sistem') ?></div>
                            <div class="text-xs text-muted-foreground"><?= htmlspecialchars($role_label[$r['user_role']] ?? $r['user_role']) ?><?= !empty($r['ip']) ? ' &middot; ' . htmlspecialchars($r['ip']) : '' ?></div>
                        </td>
                        <td class="px-3 py-3 align-top whitespace-nowrap"><span class="ui-badge <?= audit_action_tone($r['action']) ?>"><?= htmlspecialchars(audit_action_label($r['action'])) ?></span></td>
                        <td class="px-4 py-3 align-top sm:px-5">
                            <div><?= htmlspecialchars($r['summary']) ?></div>
                            <?php if (!empty($r['entity'])): ?><div class="text-xs text-muted-foreground"><?= htmlspecialchars($r['entity']) ?><?= $r['entity_id'] !== null && $r['entity_id'] !== '' ? ' #' . htmlspecialchars($r['entity_id']) : '' ?></div><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
        <div class="flex items-center justify-between gap-3 border-t border-solid border-border px-4 py-3 sm:px-5">
            <div class="text-xs text-muted-foreground">Halaman <?= $page_no ?> dari <?= $pages ?></div>
            <div class="flex gap-2">
                <?php if ($page_no > 1): ?><a href="<?= htmlspecialchars($qs(['p' => $page_no - 1])) ?>" class="ui-btn ui-btn-sm ui-btn-outline">Sebelumnya</a><?php endif; ?>
                <?php if ($page_no < $pages): ?><a href="<?= htmlspecialchars($qs(['p' => $page_no + 1])) ?>" class="ui-btn ui-btn-sm ui-btn-outline">Berikutnya</a><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
