<?php
/**
 * Router for PHP's built-in web server (development only):
 *
 *     php -S 0.0.0.0:8000 router.php
 *
 * The built-in server has no .htaccess support, so without this file it
 * would happily serve database.sqlite, session files, and execute any
 * PHP uploaded into the uploads folders. This router applies the same
 * rules as .htaccess. It is NOT a substitute for Apache or Nginx in
 * production.
 */

$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$root = __DIR__;
$file = realpath($root . $uri);

// Directories and files that must never be reachable over HTTP.
$denied_dirs = ['/data/', '/app/', '/wa-gateway/', '/.git/'];
foreach ($denied_dirs as $d) {
    if (strpos($uri . '/', $d) === 0 || strpos($uri, $d) === 0) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
}
if (preg_match('~\.(sqlite|sqlite-wal|sqlite-shm|sqlite-journal|log|env|example|bat|sh|md|lock)$~i', $uri)
    || preg_match('~/.(env|git|htaccess)~i', $uri)
    || preg_match('~^/(package(-lock)?.json|tailwind..*.js|src/)~i', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Uploaded files are served as static data only, never executed.
if (preg_match('~^/(public/)?uploads/~i', $uri)) {
    if ($file && is_file($file) && strpos($file, $root) === 0
        && !preg_match('~\.(php|phtml|php[0-9]|phar|pl|py|cgi|sh)$~i', $file)) {
        return false; // let the built-in server stream it
    }
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Any other existing file (CSS, JS, images, index.php, wa_proxy.php ...)
// is handled by the built-in server as usual.
if ($file && is_file($file) && strpos($file, $root) === 0) {
    return false;
}

// Directory requests fall through to index.php; unknown paths 404.
if ($uri === '/' || $uri === '') {
    require $root . '/index.php';
    return true;
}
http_response_code(404);
echo 'Not Found';
return true;
