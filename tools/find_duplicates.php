<?php
// Simple duplicate file detector. Run from repo root: php tools/find_duplicates.php
$root = realpath(__DIR__ . '/..');
$exclude = [
    '.git',
    'node_modules',
    'public/uploads',
    'wa-gateway/.wwebjs_cache',
];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
$hashes = [];
foreach ($it as $file) {
    if ($file->isDir()) continue;
    $path = $file->getPathname();
    $rel = substr($path, strlen($root) + 1);
    $skip = false;
    foreach ($exclude as $ex) {
        if (strpos($rel, $ex) === 0) { $skip = true; break; }
    }
    if ($skip) continue;
    $content = @file_get_contents($path);
    if ($content === false) continue;
    // skip binary-ish files
    if (strpos($content, "\0") !== false) continue;
    $h = md5($content);
    $hashes[$h][] = $rel;
}

$dups = array_filter($hashes, function($a){ return count($a) > 1; });
if (empty($dups)) {
    echo "No duplicate files found.\n";
    exit(0);
}

echo "Duplicate files found:\n";
foreach ($dups as $h => $files) {
    echo "---\n";
    foreach ($files as $f) {
        echo "  " . $f . "\n";
    }
}

echo "\nSuggestion: review these groups and remove/merge duplicates if unintended.\n";
