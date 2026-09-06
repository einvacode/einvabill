<?php
/**
 * Change password. Reached voluntarily from the sidebar, or forced by
 * index.php when the account still uses the seeded default password.
 */
$u_id = intval($_SESSION['user_id'] ?? 0);
$forced = !empty($_SESSION['must_change_password']);
$cp_error = '';
$cp_success = '';

if ($page === 'change_password_post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$u_id]);
    $hash = (string) $stmt->fetchColumn();

    if ($hash === '' || !password_verify($current, $hash)) {
        $cp_error = 'Password saat ini salah.';
    } elseif (strlen($new) < 8) {
        $cp_error = 'Password baru minimal 8 karakter.';
    } elseif ($new === '123456' || password_is_default(password_hash($new, PASSWORD_DEFAULT))) {
        $cp_error = 'Password baru tidak boleh password bawaan.';
    } elseif ($new !== $confirm) {
        $cp_error = 'Konfirmasi password tidak sama.';
    } elseif ($new === $current) {
        $cp_error = 'Password baru harus berbeda dari password saat ini.';
    } else {
        $db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?")
           ->execute([password_hash($new, PASSWORD_DEFAULT), $u_id]);
        unset($_SESSION['must_change_password']);
        session_regenerate_id(true);
        $cp_success = 'Password berhasil diganti.';
        $forced = false;
    }
}
?>
<div class="glass-panel" style="padding: 30px; max-width: 520px; margin: 0 auto;">
    <div style="display:flex; align-items:center; gap:14px; margin-bottom:18px;">
        <div style="background: rgba(var(--primary-rgb), 0.1); width: 48px; height: 48px; border-radius: 12px; display:flex; align-items:center; justify-content:center; color: var(--primary);">
            <i class="fas fa-key" style="font-size:20px;"></i>
        </div>
        <div>
            <h3 style="margin:0; font-size:20px;">Ganti Password</h3>
            <div style="font-size:13px; color:var(--text-secondary);">Akun: <?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></div>
        </div>
    </div>

    <?php if ($forced): ?>
        <div style="background:rgba(245,158,11,0.12); color:#b45309; border:1px solid rgba(245,158,11,0.35); padding:12px 14px; border-radius:10px; font-size:13px; margin-bottom:16px;">
            <i class="fas fa-exclamation-triangle"></i>
            Akun ini masih memakai password bawaan. Demi keamanan, buat password baru sebelum melanjutkan.
        </div>
    <?php endif; ?>

    <?php if ($cp_error): ?>
        <div style="background:rgba(239,68,68,0.1); color:#ef4444; border:1px solid rgba(239,68,68,0.2); padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:14px;">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($cp_error) ?>
        </div>
    <?php endif; ?>

    <?php if ($cp_success): ?>
        <div style="background:rgba(16,185,129,0.1); color:#059669; border:1px solid rgba(16,185,129,0.25); padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:14px;">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($cp_success) ?>
            <a href="index.php" style="margin-left:8px; font-weight:600; color:var(--primary);">Lanjut ke beranda</a>
        </div>
    <?php endif; ?>

    <form action="index.php?page=change_password_post" method="POST" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Password saat ini</label>
            <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
        </div>
        <div class="form-group">
            <label>Password baru <span style="color:var(--text-secondary); font-weight:400;">(minimal 8 karakter)</span></label>
            <input type="password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
        </div>
        <div class="form-group">
            <label>Ulangi password baru</label>
            <input type="password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block" style="margin-top:16px; padding:12px;">
            <i class="fas fa-save"></i> Simpan Password
        </button>
    </form>

    <?php if ($forced): ?>
        <div style="text-align:center; margin-top:16px; font-size:13px;">
            <a href="index.php?page=logout" style="color:var(--text-secondary);">Keluar</a>
        </div>
    <?php endif; ?>
</div>
