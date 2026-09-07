<?php
/**
 * Jejak audit: siapa melakukan apa, kapan.
 *
 * Dicatat untuk tindakan yang menyentuh uang atau menghapus data:
 * pembayaran, penghapusan transaksi, pengeluaran, mutasi kas, pelanggan,
 * pengguna, dan pemulihan/reset database. Baris tidak pernah diubah atau
 * dihapus dari dalam aplikasi.
 */

/** Catat satu kejadian. Selalu aman dipanggil; kegagalan tidak menghentikan aksi utama. */
function audit_log(PDO $db, string $action, string $entity, $entity_id, string $summary, array $meta = []): void {
    try {
        $db->prepare("INSERT INTO audit_logs (tenant_id, user_id, user_name, user_role, action, entity, entity_id, summary, meta_json, ip, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([
               (int)($_SESSION['tenant_id'] ?? 1),
               (int)($_SESSION['user_id'] ?? 0),
               (string)($_SESSION['user_name'] ?? 'Sistem'),
               (string)($_SESSION['user_role'] ?? 'system'),
               $action, $entity, $entity_id !== null ? (string)$entity_id : null,
               mb_substr($summary, 0, 500),
               $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
               (string)($_SERVER['REMOTE_ADDR'] ?? ''),
               date('Y-m-d H:i:s'),
           ]);
    } catch (Exception $e) { /* jejak audit tidak boleh menggagalkan transaksi */ }
}

/** Label Indonesia untuk jenis aksi. */
function audit_action_label(string $action): string {
    return [
        'payment_create' => 'Pembayaran dicatat',
        'payment_delete' => 'Pembayaran dihapus',
        'invoice_create' => 'Tagihan dibuat',
        'invoice_delete' => 'Tagihan dihapus',
        'invoice_unpay'  => 'Status lunas dibatalkan',
        'expense_create' => 'Pengeluaran dicatat',
        'expense_update' => 'Pengeluaran diubah',
        'expense_delete' => 'Pengeluaran dihapus',
        'cash_transfer'  => 'Setoran / transfer kas',
        'cash_transfer_delete' => 'Mutasi kas dihapus',
        'cash_reassign'  => 'Transaksi pindah akun',
        'customer_delete' => 'Pelanggan dihapus',
        'user_save'      => 'Akun pengguna disimpan',
        'user_delete'    => 'Akun pengguna dihapus',
        'data_restore'   => 'Database dipulihkan',
        'data_reset'     => 'Data direset',
    ][$action] ?? $action;
}

/** Warna badge per jenis aksi: merah untuk penghapusan, hijau untuk uang masuk. */
function audit_action_tone(string $action): string {
    if (str_contains($action, 'delete') || str_contains($action, 'reset') || $action === 'invoice_unpay') return 'ui-badge-danger';
    if (str_contains($action, 'restore') || str_contains($action, 'reassign')) return 'ui-badge-accent';
    if (str_contains($action, 'payment') || str_contains($action, 'cash')) return 'ui-badge-signal';
    return 'ui-badge-muted';
}
