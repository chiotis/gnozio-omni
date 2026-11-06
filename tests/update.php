<?php
/*
 * Updating Omni from its own screen, end to end: a copy of Omni runs on PHP's server, a second server plays GitHub Releases,
 * and the test publishes releases to it: a good one (install, undo, install again), one that breaks Omni and changes its
 * database (taken back by itself, database included), one whose package is not what its release.json says, and one that
 * tries to write into storage/. Omni's data must come through all of it untouched.
 *   php tests/update.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__);
$fail = 0;
function check(string $label, bool $ok, mixed $detail = ''): void { global $fail; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : '  ' . substr(preg_replace('/\s+/', ' ', is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_SLASHES)), 0, 300)) . "\n"; }
function freePort(): int { $s = stream_socket_server('tcp://127.0.0.1:0'); $n = (string)stream_socket_get_name($s, false); fclose($s); return (int)substr($n, strrpos($n, ':') + 1); }
function copyTree(string $from, string $to, array $skip = []): void
{
    @mkdir($to, 0775, true);
    foreach (scandir($from) ?: [] as $e) {
        if ($e === '.' || $e === '..' || in_array($e, $skip, true)) continue;
        is_dir("$from/$e") ? copyTree("$from/$e", "$to/$e") : copy("$from/$e", "$to/$e");
    }
}
function replaceIn(string $file, string $from, string $to): void
{
    $text = (string)file_get_contents($file);
    if (!str_contains($text, $from)) { throw new RuntimeException("'$from' is not in $file"); }
    file_put_contents($file, str_replace($from, $to, $text));
}

$tmp = sys_get_temp_dir() . '/omni-update-' . getmypid();
$live = "$tmp/live";
$storage = "$tmp/storage";
$rel = "$tmp/releases";
copyTree($repo, $live, ['.git', 'storage', 'build', 'node_modules', 'tests']);
mkdir("$storage/db", 0775, true);
mkdir($rel, 0775, true);
$from = trim((string)file_get_contents("$live/VERSION"));
[$maj, $min, $patch] = array_map('intval', explode('.', $from));
$v = static fn(int $add): string => "$maj.$min." . ($patch + $add);

$relPort = freePort();
$port = freePort();
$base = "http://127.0.0.1:$port";
$servers = [
    proc_open(['php', '-S', "127.0.0.1:$relPort", '-t', $rel], [1 => ['file', "$tmp/rel.log", 'a'], 2 => ['file', "$tmp/rel.log", 'a']], $p1, $rel),
    proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$live/public", "$live/public/index.php"], [1 => ['file', "$tmp/live.log", 'a'], 2 => ['file', "$tmp/live.log", 'a']], $p2, $live,
        ['OMNI_STORAGE' => $storage, 'OMNI_RELEASE_URL' => "http://127.0.0.1:$relPort/release.json", 'OMNI_GNOZIO_RELEASE_URL' => 'http://127.0.0.1:9/gnozio.json', 'PHP_CLI_SERVER_WORKERS' => '4'] + getenv()),
];
register_shutdown_function(static function () use ($servers, $tmp): void {
    foreach ($servers as $s) { proc_terminate($s); proc_close($s); }
    exec('rm -rf ' . escapeshellarg($tmp));
});
// Both servers must answer before the test starts (on a slow machine the second one takes a moment).
file_put_contents("$rel/ready.txt", 'ok');
for ($i = 0; $i < 100 && (@file_get_contents("$base/api/v1/ping") === false || @file_get_contents("http://127.0.0.1:$relPort/ready.txt") === false); $i++) { usleep(100000); }

/**
 * Publishes a release: a copy of this Omni at the given version, changed by $change, built with the real build script.
 * $manifest can change release.json after it is built.
 */
function publish(string $repo, string $tmp, string $rel, int $relPort, string $version, ?callable $change = null, ?callable $manifest = null): void
{
    $src = "$tmp/src-$version";
    exec('rm -rf ' . escapeshellarg($src));
    copyTree($repo, $src, ['.git', 'storage', 'build', 'node_modules', 'tests']);
    file_put_contents("$src/VERSION", "$version\n");
    replaceIn("$src/CHANGELOG.md", "# Changelog\n", "# Changelog\n\n## $version — test — A test release\n- **New:** the release $version, with `code`.\n  - and a nested line\n");
    replaceIn("$src/system/Views/updates/index.php", '<h1 class="text-lg font-semibold text-slate-900">Updates</h1>', '<h1 class="text-lg font-semibold text-slate-900">Updates</h1><span data-marker="' . $version . '"></span>');
    if ($change) { $change($src); }
    exec('php ' . escapeshellarg("$src/scripts/build-release.php") . ' ' . escapeshellarg($rel) . ' 2>&1', $out, $code);
    if ($code !== 0) { throw new RuntimeException(implode("\n", $out)); }
    $data = json_decode((string)file_get_contents("$rel/release.json"), true);
    $data['package_url'] = "http://127.0.0.1:$relPort/omni-$version.zip";
    if ($manifest) { $data = $manifest($data); }
    file_put_contents("$rel/release.json", json_encode($data, JSON_UNESCAPED_SLASHES));
}

$jar = "$tmp/jar";
function req(string $base, string $jar, string $method, string $path, ?array $data = null): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 120]);
    if ($data !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $r = (string)curl_exec($ch);
    $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), substr($r, 0, $size), substr($r, $size)];
}
function token(string $html): string { return preg_match('/name="_csrf" value="([0-9a-f]+)"/', $html, $m) ? $m[1] : ''; }
function post(string $base, string $jar, string $path): string
{
    [, , $html] = req($base, $jar, 'GET', '/updates');
    req($base, $jar, 'POST', $path, ['_csrf' => token($html)]);
    [, , $html] = req($base, $jar, 'GET', '/updates');
    return $html;
}
function toast(string $html): string { return preg_match('/data-flash-message="([^"]*)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : ''; }
function installed(string $live): string { return trim((string)file_get_contents("$live/VERSION")); }
function dbVersion(string $storage): int { return (int)(new PDO("sqlite:$storage/db/omni.sqlite"))->query('PRAGMA user_version')->fetchColumn(); }

// ---- an Omni with an owner and a site
[, , $html] = req($base, $jar, 'GET', '/setup');
req($base, $jar, 'POST', '/setup', ['_csrf' => token($html), 'name' => 'Owner', 'email' => 'o@example.test', 'password' => 'a long password', 'password_confirm' => 'a long password', 'timezone' => 'UTC']);
[, , $html] = req($base, $jar, 'GET', '/');
req($base, $jar, 'POST', '/sites', ['_csrf' => token($html), 'label' => 'Kept through updates']);
$key = (string)file_get_contents("$storage/key");

// ---- nothing newer
publish($repo, $tmp, $rel, $relPort, $from);
$html = post($base, $jar, '/updates/check');
check('the latest release is the version installed: nothing to install', str_contains(toast($html), "Omni $from is the latest version.") && !str_contains($html, '/updates/install'), toast($html));

// ---- a good release
$good = $v(1);
publish($repo, $tmp, $rel, $relPort, $good);
$html = post($base, $jar, '/updates/check');
check('a newer release is found, with its notes and an Install button', str_contains(toast($html), "Omni $good is available.") && str_contains($html, "Install $good") && str_contains($html, '<strong>New:</strong>') && str_contains($html, '<code') && str_contains($html, 'list-[circle]'), toast($html));
[, , $html] = req($base, $jar, 'GET', '/');
check('the header marks Updates', str_contains($html, 'aria-label="An update is available"'));
$html = post($base, $jar, '/updates/install');
check('Install puts the new version in place and checks it', toast($html) === "Omni $good is installed." && installed($live) === $good && str_contains($html, 'data-marker="' . $good . '"'), [toast($html), installed($live)]);
[, , $html] = req($base, $jar, 'GET', '/');
check('the data came through: still signed in, the site still there, the same key', str_contains($html, 'Kept through updates') && (string)file_get_contents("$storage/key") === $key);
check('and no Install button any more', !str_contains(post($base, $jar, '/updates/check'), '/updates/install'));
$html = post($base, $jar, '/updates/undo');
check('Undo puts the old version back', toast($html) === "Omni $from is back." && installed($live) === $from && !str_contains($html, 'data-marker='), [toast($html), installed($live)]);
check('it cannot be undone twice', !str_contains($html, '/updates/undo'));
$html = post($base, $jar, '/updates/install');
check('and it installs again', installed($live) === $good && toast($html) === "Omni $good is installed.", toast($html));

// ---- a release that breaks Omni after changing its database
$broken = $v(2);
publish($repo, $tmp, $rel, $relPort, $broken, static function (string $src): void {
    replaceIn("$src/system/Core/Database.php", "        if (\$version < 1) {", "        if (\$version < 2) {\n            \$pdo->exec('CREATE TABLE IF NOT EXISTS added_by_broken (x INTEGER); PRAGMA user_version = 2;');\n        }\n        if (\$version < 1) {");
    replaceIn("$src/system/Core/App.php", "    public function run(): void\n    {", "    public function run(): void\n    {\n        throw new \\RuntimeException('This release is broken.');");
});
$before = dbVersion($storage);
$html = post($base, $jar, '/updates/install');
check('a release that does not work is taken back by itself', installed($live) === $good && str_contains(toast($html), "did not work here") && str_contains(toast($html), "$good was put back"), [toast($html), installed($live)]);
req($base, $jar, 'GET', '/');
check('and the database it changed is put back too', dbVersion($storage) === $before && (int)(new PDO("sqlite:$storage/db/omni.sqlite"))->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'added_by_broken'")->fetchColumn() === 0, dbVersion($storage));
[, , $html] = req($base, $jar, 'GET', '/');
check('Omni works and the data is there', str_contains($html, 'Kept through updates'));

// ---- a package that is not what release.json says
$tampered = $v(3);
publish($repo, $tmp, $rel, $relPort, $tampered, null, static fn(array $m): array => ['sha256' => str_repeat('0', 64)] + $m);
$html = post($base, $jar, '/updates/install');
check('a package whose SHA-256 does not match is refused, nothing changed', str_contains(toast($html), 'not the one the release describes') && installed($live) === $good, toast($html));

// ---- a package that writes into storage/
$sneaky = $v(4);
publish($repo, $tmp, $rel, $relPort, $sneaky);
$zip = new ZipArchive();
$zip->open("$rel/omni-$sneaky.zip");
$zip->addFromString('storage/key', 'replaced');
$zip->close();
$m = json_decode((string)file_get_contents("$rel/release.json"), true);
$m['sha256'] = hash_file('sha256', "$rel/omni-$sneaky.zip");
$m['size'] = filesize("$rel/omni-$sneaky.zip");
file_put_contents("$rel/release.json", json_encode($m, JSON_UNESCAPED_SLASHES));
$html = post($base, $jar, '/updates/install');
check('a package with anything outside the code is refused', str_contains(toast($html), 'may not write (storage/key)') && installed($live) === $good && (string)file_get_contents("$storage/key") === $key, toast($html));

// ---- a release.json that is not one
file_put_contents("$rel/release.json", '{"version":"9.9.9","package_url":"ftp://x/y.zip","sha256":"x","size":1}');
$html = post($base, $jar, '/updates/check');
check('a release.json that is not valid is said so', str_contains(toast($html), 'no valid release.json'), toast($html));

$log = (string)@file_get_contents("$tmp/live.log");
preg_match_all('/^.*(Warning|Deprecated|Notice):.*$/mi', $log, $found);
check('no PHP warnings on the server (the broken release\'s own error aside)', $found[0] === [], array_slice($found[0], 0, 3));

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
