<?php
/**
 * Expense categories ("Kategori pengeluaran"), one list per tenant.
 * Managed from the Kategori tab of the expenses page; expenses store the
 * category by name, so renaming a category renames it on every expense too.
 */

const EXPENSE_DEFAULT_CATEGORIES = [
    ['Operasional', 'Listrik, sewa, internet upstream, dan biaya rutin lain'],
    ['Belanja Barang', 'Alat teknik, kabel, perangkat'],
    ['Insentif', 'Gaji, insentif, dan honor'],
    ['Lain-lain', 'Pengeluaran yang tidak masuk kategori lain'],
];

function expense_categories_list(PDO $db, int $tenant_id): array {
    try {
        $st = $db->prepare("SELECT id, name, description,
                (SELECT COUNT(*) FROM expenses e WHERE e.tenant_id = c.tenant_id AND e.category = c.name) AS used
            FROM expense_categories c WHERE tenant_id = ? ORDER BY sort_order ASC, LOWER(name) ASC");
        $st->execute([$tenant_id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

/** Seed defaults plus any category name already used by this tenant's expenses. */
function expense_categories_ensure(PDO $db, int $tenant_id): void {
    try {
        $count = $db->prepare("SELECT COUNT(*) FROM expense_categories WHERE tenant_id = ?");
        $count->execute([$tenant_id]);
        $ins = $db->prepare("INSERT INTO expense_categories (tenant_id, name, description, sort_order, created_at) VALUES (?, ?, ?, ?, ?)");
        if ((int)$count->fetchColumn() === 0) {
            foreach (EXPENSE_DEFAULT_CATEGORIES as $i => [$name, $desc]) $ins->execute([$tenant_id, $name, $desc, $i + 1, date('Y-m-d H:i:s')]);
        }
        $used = $db->prepare("SELECT DISTINCT TRIM(category) FROM expenses WHERE tenant_id = ? AND category IS NOT NULL AND TRIM(category) <> ''
            AND LOWER(TRIM(category)) NOT IN (SELECT LOWER(name) FROM expense_categories WHERE tenant_id = ?)");
        $used->execute([$tenant_id, $tenant_id]);
        foreach ($used->fetchAll(PDO::FETCH_COLUMN) as $name) $ins->execute([$tenant_id, $name, '', 99, date('Y-m-d H:i:s')]);
    } catch (Exception $e) {}
}

/** Insert or update a category. Returns an error message, or '' on success. */
function expense_categories_save(PDO $db, int $tenant_id, int $id, string $name, string $description): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    $description = trim($description);
    if ($name === '') return 'Nama kategori tidak boleh kosong.';
    if (mb_strlen($name) > 60) return 'Nama kategori maksimal 60 karakter.';
    try {
        $dup = $db->prepare("SELECT id FROM expense_categories WHERE tenant_id = ? AND LOWER(name) = LOWER(?) AND id <> ? LIMIT 1");
        $dup->execute([$tenant_id, $name, $id]);
        if ($dup->fetchColumn()) return 'Kategori dengan nama itu sudah ada.';
        if ($id > 0) {
            $old = $db->prepare("SELECT name FROM expense_categories WHERE id = ? AND tenant_id = ?");
            $old->execute([$id, $tenant_id]);
            $old_name = $old->fetchColumn();
            if ($old_name === false) return 'Kategori tidak ditemukan.';
            $db->prepare("UPDATE expense_categories SET name = ?, description = ? WHERE id = ? AND tenant_id = ?")->execute([$name, $description, $id, $tenant_id]);
            if ($old_name !== $name) {
                $db->prepare("UPDATE expenses SET category = ? WHERE tenant_id = ? AND category = ?")->execute([$name, $tenant_id, $old_name]);
            }
        } else {
            $max = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM expense_categories WHERE tenant_id = ?");
            $max->execute([$tenant_id]);
            $db->prepare("INSERT INTO expense_categories (tenant_id, name, description, sort_order, created_at) VALUES (?, ?, ?, ?, ?)")
               ->execute([$tenant_id, $name, $description, (int)$max->fetchColumn() + 1, date('Y-m-d H:i:s')]);
        }
        return '';
    } catch (Exception $e) { return 'Gagal menyimpan kategori.'; }
}

/** Delete a category that no expense uses. Returns an error message, or '' on success. */
function expense_categories_delete(PDO $db, int $tenant_id, int $id): string {
    try {
        $st = $db->prepare("SELECT name FROM expense_categories WHERE id = ? AND tenant_id = ?");
        $st->execute([$id, $tenant_id]);
        $name = $st->fetchColumn();
        if ($name === false) return 'Kategori tidak ditemukan.';
        $used = $db->prepare("SELECT COUNT(*) FROM expenses WHERE tenant_id = ? AND category = ?");
        $used->execute([$tenant_id, $name]);
        $n = (int)$used->fetchColumn();
        if ($n > 0) return "Kategori \"$name\" masih dipakai $n catatan pengeluaran. Ubah kategori catatan itu dulu.";
        $db->prepare("DELETE FROM expense_categories WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]);
        return '';
    } catch (Exception $e) { return 'Gagal menghapus kategori.'; }
}
