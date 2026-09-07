<?php
/**
 * Saved line items ("Item tersimpan") for external invoices.
 *
 * Table invoice_item_catalog holds one row per description per tenant with
 * the unit price to pre-fill. Managed from the "Item tersimpan" tab on the
 * external invoice page; every item saved on an invoice is remembered here
 * automatically so it can be edited or removed later.
 */

function invoice_catalog_list(PDO $db, int $tenant_id): array {
    try {
        $st = $db->prepare("SELECT id, description, unit_price FROM invoice_item_catalog WHERE tenant_id = ? ORDER BY LOWER(description) ASC");
        $st->execute([$tenant_id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

/** Insert or update one catalog row. Returns an error message, or '' on success. */
function invoice_catalog_save(PDO $db, int $tenant_id, int $id, string $description, float $unit_price): string {
    $description = trim(preg_replace('/\s+/', ' ', $description));
    if ($description === '') return 'Deskripsi item tidak boleh kosong.';
    if (mb_strlen($description) > 200) return 'Deskripsi item maksimal 200 karakter.';
    if ($unit_price < 0) return 'Harga satuan tidak boleh negatif.';
    try {
        $dup = $db->prepare("SELECT id FROM invoice_item_catalog WHERE tenant_id = ? AND LOWER(description) = LOWER(?) AND id <> ? LIMIT 1");
        $dup->execute([$tenant_id, $description, $id]);
        if ($dup->fetchColumn()) return 'Item dengan deskripsi itu sudah ada.';
        if ($id > 0) {
            $db->prepare("UPDATE invoice_item_catalog SET description = ?, unit_price = ? WHERE id = ? AND tenant_id = ?")
               ->execute([$description, $unit_price, $id, $tenant_id]);
        } else {
            $db->prepare("INSERT INTO invoice_item_catalog (tenant_id, description, unit_price, created_at) VALUES (?, ?, ?, ?)")
               ->execute([$tenant_id, $description, $unit_price, date('Y-m-d H:i:s')]);
        }
        return '';
    } catch (Exception $e) { return 'Gagal menyimpan item.'; }
}

function invoice_catalog_delete(PDO $db, int $tenant_id, int $id): void {
    try { $db->prepare("DELETE FROM invoice_item_catalog WHERE id = ? AND tenant_id = ?")->execute([$id, $tenant_id]); } catch (Exception $e) {}
}

/** Add a description used on an invoice to the catalog if it is not there yet. */
function invoice_catalog_remember(PDO $db, int $tenant_id, string $description, float $unit_price): void {
    $description = trim(preg_replace('/\s+/', ' ', $description));
    if ($description === '') return;
    try {
        $st = $db->prepare("SELECT id FROM invoice_item_catalog WHERE tenant_id = ? AND LOWER(description) = LOWER(?) LIMIT 1");
        $st->execute([$tenant_id, $description]);
        if ($st->fetchColumn()) return;
        $db->prepare("INSERT INTO invoice_item_catalog (tenant_id, description, unit_price, created_at) VALUES (?, ?, ?, ?)")
           ->execute([$tenant_id, $description, max(0, $unit_price), date('Y-m-d H:i:s')]);
    } catch (Exception $e) {}
}
