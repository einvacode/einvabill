<?php
/**
 * Progressive web app: installable staff app.
 *
 * Deliberately conservative about caching. This is a billing app, so pages are
 * never served from the cache — a stale invoice or balance would be worse than
 * no page at all. What the service worker keeps is the shell (stylesheets,
 * icons, the offline notice) so an install still opens and explains itself when
 * the signal drops, which is the normal state of a collector's phone in the
 * field.
 *
 * The landing page and the customer portal are not part of the installable app.
 */

/** URL path the app is served from, always with a trailing slash ("/" or "/einvabill/"). */
function pwa_base_path(): string {
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return rtrim($dir, '/') . '/';
}

/** The web app manifest, filled in with this tenant's name and logo. */
function pwa_manifest(PDO $db): array {
    $name = 'EinvaBill';
    $brand = '';
    try {
        $row = $db->query("SELECT company_name, landing_hero_title FROM settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        if (!empty($row['company_name'])) $name = trim((string) $row['company_name']);
        $brand = trim((string) ($row['landing_hero_title'] ?? ''));
    } catch (Exception $e) {}

    // A home screen shows about 12 characters, so short_name is the one word
    // people would recognise: the brand when there is one, otherwise the company
    // name without its legal prefix.
    $short = $brand !== '' ? $brand : preg_replace('/^(PT|CV|UD)\s+/i', '', $name);
    $short = preg_split('/\s+/', trim($short))[0] ?? $name;
    $short = mb_substr($short, 0, 12);
    $base = pwa_base_path();

    return [
        'id' => $base,
        'name' => $name,
        'short_name' => $short,
        'description' => 'Aplikasi penagihan dan keuangan ' . $name . '.',
        'lang' => 'id',
        'dir' => 'ltr',
        'start_url' => $base . 'index.php',
        'scope' => $base,
        'display' => 'standalone',
        'orientation' => 'portrait-primary',
        // The splash uses the same near-black as the icon plate, so the icon has
        // no visible edge while the app boots.
        'background_color' => '#1A1713',
        'theme_color' => '#0F3A47',
        'icons' => [
            ['src' => $base . 'public/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $base . 'public/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $base . 'public/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
        'shortcuts' => [
            ['name' => 'Tagihan', 'url' => $base . 'index.php?page=admin_invoices'],
            ['name' => 'Pengeluaran', 'url' => $base . 'index.php?page=admin_expenses'],
        ],
    ];
}

/**
 * Head tags for the installable pages. theme_color follows the chosen theme so
 * the phone's status bar matches the app instead of fighting it.
 */
function pwa_head_tags(): string {
    $base = pwa_base_path();
    $light = '#F5F7F6';
    $dark  = '#0E1518';
    $mode  = function_exists('theme_mode') ? theme_mode() : 'light';

    if ($mode === 'system') {
        $theme = '<meta name="theme-color" content="' . $light . '" media="(prefers-color-scheme: light)">' . "\n"
               . '    <meta name="theme-color" content="' . $dark . '" media="(prefers-color-scheme: dark)">';
    } else {
        $theme = '<meta name="theme-color" content="' . ($mode === 'dark' ? $dark : $light) . '">';
    }

    return $theme . "\n"
        . '    <link rel="manifest" href="' . $base . 'index.php?page=manifest">' . "\n"
        . '    <link rel="apple-touch-icon" href="' . $base . 'public/icons/apple-touch-icon.png">' . "\n"
        . '    <meta name="mobile-web-app-capable" content="yes">' . "\n"
        . '    <meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
        . '    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n"
        . '    <meta name="apple-mobile-web-app-title" content="EinvaBill">';
}

/** Registration plus the install button wiring. Safe to include on every app page. */
function pwa_script(): string {
    $base = pwa_base_path();
    return <<<HTML
<script>
(function () {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('{$base}sw.js', { scope: '{$base}' }).catch(function () {});
        });
    }
    // Chrome and Edge on Android fire this instead of installing straight away;
    // without a button of our own the offer is easy to miss.
    var deferred = null;
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferred = e;
        document.querySelectorAll('[data-pwa-install]').forEach(function (el) { el.hidden = false; });
    });
    window.pwaInstall = function () {
        if (!deferred) return;
        deferred.prompt();
        deferred.userChoice.finally(function () {
            deferred = null;
            document.querySelectorAll('[data-pwa-install]').forEach(function (el) { el.hidden = true; });
        });
    };
    window.addEventListener('appinstalled', function () {
        document.querySelectorAll('[data-pwa-install]').forEach(function (el) { el.hidden = true; });
    });
})();
</script>
HTML;
}
