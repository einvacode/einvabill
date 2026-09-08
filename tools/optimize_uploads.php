<?php
/**
 * One-time cleanup for images uploaded before the app started downscaling them.
 *
 * The company logo alone was 2 MB — downloaded on every screen of the app and
 * on every visit to the landing page. New uploads are scaled on the way in;
 * this script fixes what is already on disk.
 *
 * Usage, from the app directory on the server:
 *
 *     php tools/optimize_uploads.php            # dry run, only reports
 *     php tools/optimize_uploads.php --apply    # rewrites the files
 *
 * Originals are copied to public/uploads/_original/ before anything is
 * rewritten, so a bad result can always be put back.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Jalankan lewat command line.\n");
}

require_once __DIR__ . '/../app/security.php';

$apply = in_array('--apply', $argv, true);
$root  = dirname(__DIR__);
$dirs  = [$root . '/public/uploads', $root . '/public/uploads/banners'];
$maxByPrefix = ['logo' => 800, 'qris' => 1200, 'banner' => 1600, 'struk' => 1600];

if (!function_exists('imagecreatetruecolor')) {
    exit("Ekstensi GD tidak aktif di PHP ini, gambar tidak bisa diperkecil.\n"
       . "Pasang dulu: sudo apt install php-gd && sudo systemctl reload apache2\n");
}

$backupDir = $root . '/public/uploads/_original';
$totalBefore = 0; $totalAfter = 0; $changed = 0; $seen = 0;

foreach ($dirs as $dir) {
    if (!is_dir($dir)) continue;
    foreach (glob($dir . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [] as $path) {
        $name = basename($path);
        if (strpos($path, '/_original/') !== false) continue;
        $before = filesize($path);
        $info = @getimagesize($path);
        if (!$info) continue;
        $seen++;
        $totalBefore += $before;

        $prefix = strtok($name, '_');
        $max = $maxByPrefix[$prefix] ?? 1600;
        // Nothing to gain from files that are already small and modest in size.
        if (max($info[0], $info[1]) <= $max && $before < 300 * 1024) { $totalAfter += $before; continue; }

        if (!$apply) {
            printf("%-46s %6.0f KB  %dx%d  -> maks %d px\n", mb_strimwidth($name, 0, 45, '…'), $before / 1024, $info[0], $info[1], $max);
            $totalAfter += $before;
            continue;
        }

        if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) exit("Tidak bisa membuat folder cadangan.\n");
        @copy($path, $backupDir . '/' . $name);

        $ok = image_downscale($path, $max);
        clearstatcache(true, $path);
        $after = filesize($path);
        $totalAfter += $after;
        if ($ok) {
            $changed++;
            printf("%-46s %6.0f KB -> %6.0f KB\n", mb_strimwidth($name, 0, 45, '…'), $before / 1024, $after / 1024);
        }
    }
}

printf("\n%d berkas diperiksa, %d diperkecil.\n", $seen, $changed);
printf("Total %.1f MB -> %.1f MB\n", $totalBefore / 1048576, $totalAfter / 1048576);
if (!$apply) {
    echo "\nIni baru simulasi. Jalankan ulang dengan --apply untuk benar-benar mengubah berkas.\n";
} else {
    echo "Berkas asli disimpan di public/uploads/_original/.\n";
}
