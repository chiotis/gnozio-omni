<?php
/*
 * Builds the package an Omni installs from its Updates screen: the code of one version and none of its data.
 *
 *   php scripts/build-release.php [output-dir] [--repo=owner/name]
 *
 * Writes omni-<version>.zip and release.json (the version, the lowest PHP, where the package is, its SHA-256 and size,
 * and the version's notes from CHANGELOG.md) into the output directory (build/ by default). The package is built from a
 * list of what to include, so storage/ (the database and the key) can never end up in it. The same version builds to the
 * same bytes.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$out = $root . '/build';
$repo = getenv('GITHUB_REPOSITORY') ?: 'chiotis/gnozio-omni';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--repo=')) {
        $repo = substr($arg, 7);
    } else {
        $out = $arg;
    }
}

/** What the package holds: folders (everything inside) and single files. The installer accepts nothing else. */
const DIRECTORIES = ['system', 'public/assets'];
const FILES = ['public/index.php', 'public/.htaccess', '.htaccess', 'VERSION', 'CHANGELOG.md', 'README.md'];
const MIN_PHP = '8.1';

$version = trim((string)@file_get_contents($root . '/VERSION'));
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fwrite(STDERR, "VERSION must be like 0.1.0 (it is '$version').\n");
    exit(1);
}

$files = array_values(array_filter(FILES, static fn(string $f): bool => is_file($root . '/' . $f)));
foreach (DIRECTORIES as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if ($item->isLink() || !$item->isFile() || $item->getFilename() === '.DS_Store') {
            continue;
        }
        $files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
    }
}
sort($files, SORT_STRING);
$files = array_values(array_unique($files));

if (!is_dir($out) && !mkdir($out, 0775, true)) {
    fwrite(STDERR, "Cannot create $out.\n");
    exit(1);
}
$name = "omni-$version.zip";
$zipPath = $out . '/' . $name;
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot write $zipPath.\n");
    exit(1);
}
// A fixed time and permissions, so the same version always builds to the same package.
$time = (int)(getenv('SOURCE_DATE_EPOCH') ?: 1767225600);
foreach ($files as $file) {
    $zip->addFile($root . '/' . $file, $file);
    $zip->setMtimeName($file, $time);
    $zip->setExternalAttributesName($file, ZipArchive::OPSYS_UNIX, 0100644 << 16);
}
$zip->close();

// The notes of this version: its CHANGELOG entry, up to the next heading.
$notes = '';
if (preg_match('/^## ' . preg_quote($version, '/') . '\b[^\n]*\n(.*?)(?=^## |\z)/ms', (string)@file_get_contents($root . '/CHANGELOG.md'), $m)) {
    $notes = trim($m[1]);
}

$manifest = [
    'version' => $version,
    'min_php' => MIN_PHP,
    'package' => $name,
    'package_url' => "https://github.com/$repo/releases/download/v$version/$name",
    'sha256' => hash_file('sha256', $zipPath),
    'size' => filesize($zipPath),
    'files' => count($files),
    'notes' => $notes,
];
file_put_contents($out . '/release.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "Built $name: " . count($files) . " files, " . round($manifest['size'] / 1024) . " KB, sha256 {$manifest['sha256']}\n";
