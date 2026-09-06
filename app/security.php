<?php
/**
 * Security helpers: data directory, CSRF tokens, upload validation,
 * login throttling. Loaded from app/init.php before the session starts.
 */

/**
 * Directory for runtime data (database, sessions, logs).
 * Override with the EINVABILL_DATA_DIR environment variable.
 * Protected from HTTP access by data/.htaccess and router.php.
 */
function app_data_dir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;
    $dir = getenv('EINVABILL_DATA_DIR') ?: dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    // Belt and braces: deny direct HTTP access on Apache even if the
    // repo copy of data/.htaccess is missing.
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
    return $dir;
}

/**
 * Resolve the SQLite database path.
 * Order: EINVABILL_DB_PATH env, data/database.sqlite, legacy ./database.sqlite
 * (kept for existing installs), else a new data/database.sqlite.
 */
function app_db_path(): string {
    $env = getenv('EINVABILL_DB_PATH');
    if ($env) return $env;
    $new = app_data_dir() . '/database.sqlite';
    if (file_exists($new)) return $new;
    $legacy = dirname(__DIR__) . '/database.sqlite';
    if (file_exists($legacy)) return $legacy;
    return $new;
}

/** Send baseline security headers. Safe to call once per request. */
function send_security_headers(): void {
    if (headers_sent()) return;
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input for HTML forms. */
function csrf_field(): string {
    return '<input type="hidden" name="_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** Token supplied by the current request: POST field, header, or query string. */
function csrf_request_token(): string {
    if (!empty($_POST['_token'])) return (string) $_POST['_token'];
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) return (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
    if (!empty($_GET['_token'])) return (string) $_GET['_token'];
    return '';
}

function csrf_is_valid(): bool {
    $expected = $_SESSION['csrf_token'] ?? '';
    $given = csrf_request_token();
    return $expected !== '' && $given !== '' && hash_equals($expected, $given);
}

/**
 * Abort the request with 403 unless a valid CSRF token was supplied.
 * JSON callers (fetch with Accept: application/json or X-Requested-With)
 * get a JSON body; everyone else gets a small HTML page.
 */
function csrf_require(): void {
    if (csrf_is_valid()) return;
    http_response_code(403);
    $wants_json = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']))
        || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
    if ($wants_json) {
        header('Content-Type: application/json');
        echo json_encode(['error' => true, 'message' => 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.']);
    } else {
        echo "<!doctype html><html lang='id'><head><meta charset='utf-8'><title>Token tidak valid</title></head><body style='font-family:sans-serif;padding:40px;text-align:center'>"
           . "<h2>Permintaan ditolak</h2><p>Token keamanan tidak valid atau sesi sudah berganti. Kembali ke halaman sebelumnya, muat ulang, lalu ulangi.</p>"
           . "<p><a href='javascript:history.back()'>Kembali</a></p></body></html>";
    }
    exit;
}

// ---------------------------------------------------------------------
// Uploads
// ---------------------------------------------------------------------

/**
 * Validate and store an uploaded image.
 *
 * Checks the PHP upload status, the extension against an allowlist, the
 * real MIME type via finfo, and that the file decodes as an image. The
 * stored name is random, so user-supplied names never reach the disk.
 *
 * @param array  $file    One entry of $_FILES.
 * @param string $dir_fs  Destination directory on disk (created if missing).
 * @param string $prefix  Filename prefix, e.g. "logo".
 * @return array{ok:bool,filename?:string,error?:string}
 */
function save_uploaded_image(array $file, string $dir_fs, string $prefix = 'img'): array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload gagal atau file kosong.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'File upload tidak valid.'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Ukuran gambar maksimal 5 MB.'];
    }
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return ['ok' => false, 'error' => 'Format file tidak didukung. Gunakan JPG, PNG, GIF, atau WebP.'];
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string) finfo_file($fi, $file['tmp_name']) : '';
        if ($fi) finfo_close($fi);
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($file['tmp_name']);
    }
    if (!in_array($mime, array_values($allowed), true)) {
        return ['ok' => false, 'error' => 'Isi file bukan gambar yang valid.'];
    }
    if (@getimagesize($file['tmp_name']) === false) {
        return ['ok' => false, 'error' => 'File gambar rusak atau tidak terbaca.'];
    }
    // Normalise extension to match the detected MIME type.
    $ext = array_search($mime, $allowed, true) ?: $ext;
    if ($ext === 'jpeg') $ext = 'jpg';

    $dir_fs = rtrim($dir_fs, '/\\');
    if (!is_dir($dir_fs) && !@mkdir($dir_fs, 0755, true)) {
        return ['ok' => false, 'error' => 'Folder upload tidak bisa dibuat.'];
    }
    $prefix = preg_replace('/[^a-z0-9_-]/i', '', $prefix) ?: 'img';
    $filename = $prefix . '_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir_fs . '/' . $filename)) {
        return ['ok' => false, 'error' => 'Gagal menyimpan file.'];
    }
    @chmod($dir_fs . '/' . $filename, 0644);
    return ['ok' => true, 'filename' => $filename];
}

// ---------------------------------------------------------------------
// Login throttling
// ---------------------------------------------------------------------

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_SECONDS = 900; // 15 minutes

function client_ip(): string {
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

/** True when this username or IP has too many recent failures. */
function login_is_throttled(PDO $db, string $username): bool {
    try {
        $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_SECONDS);
        $st = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE attempted_at > ? AND (username = ? OR ip = ?)");
        $st->execute([$since, $username, client_ip()]);
        return (int) $st->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
    } catch (Exception $e) {
        return false; // table missing during first migration: fail open, never lock everyone out
    }
}

function login_record_failure(PDO $db, string $username): void {
    try {
        $db->prepare("INSERT INTO login_attempts (username, ip, attempted_at) VALUES (?, ?, ?)")
           ->execute([substr($username, 0, 100), client_ip(), date('Y-m-d H:i:s')]);
        // Opportunistic cleanup so the table never grows unbounded.
        if (mt_rand(1, 20) === 1) {
            $db->prepare("DELETE FROM login_attempts WHERE attempted_at < ?")
               ->execute([date('Y-m-d H:i:s', time() - 86400)]);
        }
    } catch (Exception $e) {}
}

function login_clear_failures(PDO $db, string $username): void {
    try {
        $db->prepare("DELETE FROM login_attempts WHERE username = ? OR ip = ?")->execute([$username, client_ip()]);
    } catch (Exception $e) {}
}

/** Passwords that must be changed before the account can be used. */
function password_is_default(string $hash): bool {
    return password_verify('123456', $hash);
}
