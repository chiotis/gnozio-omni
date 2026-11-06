<?php
/*
 * The parts of Omni without a server: signatures, connection codes, sealed secrets, the sites and their codes,
 * attempts, and what the owner is shown about a site.
 *   php tests/unit.php
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('SYSTEM_PATH', ROOT_PATH . '/system');
define('STORAGE_PATH', sys_get_temp_dir() . '/omni-unit-' . getmypid());
require SYSTEM_PATH . '/Core/autoload.php';
require SYSTEM_PATH . '/Core/helpers.php';

use Omni\Core\{ConnectCode, Database, SecretBox, Signature};
use Omni\Models\{Attempts, Owners, SiteHealth, Sites};

$fail = 0;
function check(string $label, $actual, $expected): void { global $fail; $ok = $actual === $expected; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : ' => ' . json_encode($actual, JSON_UNESCAPED_SLASHES) . ' expected ' . json_encode($expected, JSON_UNESCAPED_SLASHES)) . "\n"; }
date_default_timezone_set('UTC');

// ---- signatures
$secret = 'k3Y-secret_value';
$body = '{"protocol":1}';
check('the string to sign: method, path, timestamp, nonce, body hash', Signature::stringToSign('post', '/api/v1/report', '1791882000', str_repeat('a', 32), $body), "POST\n/api/v1/report\n1791882000\n" . str_repeat('a', 32) . "\n" . hash('sha256', $body));
$sig = Signature::sign($secret, 'POST', '/api/v1/report', '1791882000', str_repeat('a', 32), $body);
check('a signature is v1= and 64 hex characters', preg_match('/^v1=[0-9a-f]{64}$/', $sig), 1);
check('it matches itself', Signature::matches($secret, $sig, 'POST', '/api/v1/report', '1791882000', str_repeat('a', 32), $body), true);
check('not with another body', Signature::matches($secret, $sig, 'POST', '/api/v1/report', '1791882000', str_repeat('a', 32), '{"protocol":2}'), false);
check('not with another path', Signature::matches($secret, $sig, 'POST', '/api/v1/disconnect', '1791882000', str_repeat('a', 32), $body), false);
check('not with another secret', Signature::matches('other', $sig, 'POST', '/api/v1/report', '1791882000', str_repeat('a', 32), $body), false);
check('a known value (so both sides can check theirs)', Signature::sign('secret', 'POST', '/api/v1/report', '1700000000', '00112233445566778899aabbccddeeff', '{}'), 'v1=' . hash_hmac('sha256', "POST\n/api/v1/report\n1700000000\n00112233445566778899aabbccddeeff\n" . hash('sha256', '{}'), 'secret'));
check('a timestamp within five minutes is fresh, either way', [Signature::fresh('1000', 1300), Signature::fresh('1600', 1300), Signature::fresh('999', 1300), Signature::fresh('', 1300), Signature::fresh('1e3', 1300)], [true, true, false, false, false]);
check('a nonce is 32 lower hex characters', [Signature::validNonce(str_repeat('f', 32)), Signature::validNonce(str_repeat('F', 32)), Signature::validNonce('abc')], [true, false, false]);

// ---- connection codes
$code = ConnectCode::generate();
check('a code looks like XXXX-XXXX-XXXX-XXXX', preg_match('/^[2-9A-HJKMNP-Z]{4}(-[2-9A-HJKMNP-Z]{4}){3}$/', $code), 1);
check('no look-alikes in the alphabet', strpbrk(ConnectCode::ALPHABET, '01ILO') === false, true);
check('typed in small letters, with spaces, it is the same code', ConnectCode::hash(strtolower(str_replace('-', ' ', $code))), ConnectCode::hash($code));
check('a code that is too short or has other letters is no code', [ConnectCode::normalize('ABCD-EFGH'), ConnectCode::normalize('ABCD-EFGH-JKMN-PQR0')], ['', '']);
check('two codes differ', ConnectCode::generate() !== ConnectCode::generate(), true);

// ---- sealed secrets
$box = new SecretBox(STORAGE_PATH . '/key');
$new = SecretBox::newSecret();
check('a new secret is 43 base64url characters', preg_match('/^[A-Za-z0-9_-]{43}$/', $new), 1);
$sealed = $box->encrypt($new);
check('sealed, it does not show the secret', str_contains($sealed, $new), false);
check('and opens again', $box->decrypt($sealed), $new);
check('the key file is made, readable only by its owner', [is_file(STORAGE_PATH . '/key'), substr(sprintf('%o', fileperms(STORAGE_PATH . '/key')), -3)], [true, '600']);
check('another Omni\'s key cannot open it', (new SecretBox(STORAGE_PATH . '/other-key'))->decrypt($sealed), null);
check('a damaged value opens to nothing', [$box->decrypt('not base64!'), $box->decrypt(base64_encode('short'))], [null, null]);
check('a second box reads the same key', (new SecretBox(STORAGE_PATH . '/key'))->decrypt($sealed), $new);

// ---- the database, the owner and the sites
$db = Database::open(STORAGE_PATH . '/db/omni.sqlite');
$owners = new Owners($db);
check('no owner at first', $owners->exists(), false);
$id = $owners->createFirst('Christos', ' Owner@Example.test ', 'a long password');
check('the first owner is made, the email in small letters', [$id > 0, $owners->findByEmail('owner@example.test')['name'] ?? null], [true, 'Christos']);
check('and there can be no second', $owners->createFirst('Someone', 'x@example.test', 'another password'), 0);
check('the password is hashed', password_verify('a long password', (string)$owners->find($id)['password_hash']), true);

$sites = new Sites($db);
$now = 1791882000;
$siteId = $sites->create('  Doxiadis  ');
$site = $sites->find($siteId);
check('a new site waits, with an id to sign with', [$site['status'], $site['label'], preg_match('/^s_[0-9a-f]{12}$/', $site['public_id'])], ['pending', 'Doxiadis', 1]);
$first = $sites->newCode($siteId, $now);
$second = $sites->newCode($siteId, $now);
$about = ['name' => 'Doxiadis Associates', 'url' => 'https://doxiadis.com', 'admin_url' => 'https://doxiadis.com/admin', 'version' => '0.1.101'];
check('a new code replaces the one not used', $sites->connect($first['code'], $about, 'sealed', $now), null);
check('a code expires after a day', $sites->connect($second['code'], $about, 'sealed', $now + 86401), null);
check('the open code says when it expires', $sites->openCodeExpiry($siteId, $now), gmdate('c', $now + 86400));
$connected = $sites->connect($second['code'], $about, 'sealed', $now + 60);
check('a good code connects the site with what it said', [$connected['status'] ?? null, $connected['name'] ?? null, $connected['admin_url'] ?? null, $connected['secret_enc'] ?? null], ['connected', 'Doxiadis Associates', 'https://doxiadis.com/admin', 'sealed']);
check('and works only once', $sites->connect($second['code'], $about, 'sealed', $now + 120), null);
check('then no code is open', $sites->openCodeExpiry($siteId, $now), null);

$report = ['protocol' => 1, 'site' => ['name' => "New\nname", 'url' => 'javascript:alert(1)', 'admin_url' => 'https://doxiadis.com/admin'], 'faros' => ['version' => '0.1.102']];
for ($i = 0; $i < 5; $i++) {
    $sites->recordReport($siteId, $report, 3, $now + $i);
}
$site = $sites->find($siteId);
check('a report updates the site; text on one line, an address only when it is http(s)', [$site['name'], $site['url'], $site['version'], $site['last_report_at']], ['New name', '', '0.1.102', gmdate('c', $now + 4)]);
check('only the newest reports are kept', $sites->countReports($siteId), 3);
check('a nonce is used once', [$sites->useNonce($siteId, str_repeat('a', 32), $now), $sites->useNonce($siteId, str_repeat('a', 32), $now + 10)], [true, false]);
check('and forgotten after ten minutes', $sites->useNonce($siteId, str_repeat('a', 32), $now + 700), true);
$sites->disconnect($siteId, $now);
$site = $sites->find($siteId);
check('a site that leaves forgets its secret and keeps its history', [$site['status'], $site['secret_enc'], $sites->countReports($siteId)], ['disconnected', null, 3]);
$again = $sites->newCode($siteId, $now);
check('it can connect again with a new code', $sites->connect($again['code'], $about, 'sealed2', $now)['status'] ?? null, 'connected');
$code3 = $sites->newCode($siteId, $now);
$sites->remove($siteId);
check('a removed site is not listed, but is still known by its id', [$sites->find($siteId), count($sites->all()), $sites->findByPublicId($site['public_id'])['status'] ?? null], [null, 0, 'removed']);
check('its codes stop working', $sites->connect($code3['code'], $about, 'sealed', $now), null);

// ---- attempts
$attempts = new Attempts($db);
for ($i = 0; $i < 3; $i++) {
    $attempts->record('login', '10.0.0.1', $now + $i);
}
check('under the limit: no wait', $attempts->retryAfter('login', '10.0.0.1', 4, 900, $now + 5), 0);
$attempts->record('login', '10.0.0.1', $now + 3);
check('at the limit: wait until the oldest counted attempt leaves the window', $attempts->retryAfter('login', '10.0.0.1', 4, 900, $now + 5), 895);
check('another address is not affected', $attempts->retryAfter('login', '10.0.0.2', 4, 900, $now + 5), 0);
check('after the window it is open again', $attempts->retryAfter('login', '10.0.0.1', 4, 900, $now + 1000), 0);

// ---- what the owner sees
$report = static fn(array $over = []): array => array_replace_recursive([
    'protocol' => 1,
    'faros' => ['version' => '0.1.101'],
    'storage' => ['used' => 300 * 1048576, 'limit' => 1073741824, 'percent_of_limit' => 29, 'level' => 'ok', 'full' => false],
    'backups' => ['scheduled' => true, 'schedule' => 'daily', 'remote' => true, 'keep_local' => false, 'last' => ['at' => gmdate('c', $now - 3600), 'status' => 'success', 'size' => 1000, 'message' => ''], 'last_success_at' => gmdate('c', $now - 3600)],
    'analytics' => ['enabled' => true, 'mode' => 'platform'],
    'updates' => ['current' => '0.1.101', 'latest' => '0.1.101', 'has_update' => false],
    'system' => ['summary' => ['label' => 'Healthy', 'status' => 'ok', 'detail' => 'All checks passing'], 'checks' => [['label' => 'PHP', 'value' => '8.3', 'status' => 'ok']]],
], $over);
$row = static fn(array $r, string $status = 'connected', ?int $heard = null): array => ['status' => $status, 'latest_report' => json_encode($r), 'last_report_at' => gmdate('c', $heard ?? $now - 600), 'connected_at' => gmdate('c', $now - 86400)];
$h = SiteHealth::of($row($report()), $now, 21600);
check('a healthy site', [$h['state'], $h['label'], $h['issues']], ['ok', 'Healthy', []]);
check('its chips', [$h['chips']['health']['tone'], $h['chips']['storage']['label'], $h['chips']['backup']['label'], $h['chips']['updates']['tone'], $h['chips']['analytics']['label']], ['green', '300 MB / 1.0 GB', 'Success · 1 h ago', 'green', 'GnozioCMS']);
$h = SiteHealth::of($row($report(['updates' => ['latest' => '0.2.0', 'has_update' => true]])), $now, 21600);
check('an update is news, not a problem', [$h['state'], $h['chips']['updates']['label'], $h['chips']['updates']['tone'], $h['issues'][0]['tone']], ['ok', '0.1.101 → 0.2.0', 'red', 'blue']);
$h = SiteHealth::of($row($report(['system' => ['summary' => ['status' => 'warning', 'label' => 'Warnings'], 'checks' => [['label' => 'Email provider', 'value' => 'not configured', 'status' => 'warning']]]])), $now, 21600);
check('a warning check makes the site a warning, with the reason', [$h['state'], $h['issues'][0]['text']], ['warning', 'Email provider: not configured']);
$h = SiteHealth::of($row($report(['backups' => ['last' => ['status' => 'failed', 'message' => 'Disk full.']]])), $now, 21600);
check('a failed backup needs attention', [$h['state'], $h['chips']['backup']['tone'], $h['issues'][0]['text']], ['error', 'red', 'The last backup failed: Disk full.']);
$h = SiteHealth::of($row($report(['storage' => ['level' => 'danger', 'percent_of_limit' => 93]])), $now, 21600);
check('storage almost full needs attention', [$h['state'], $h['chips']['storage']['tone'], $h['issues'][0]['text']], ['error', 'red', 'Storage is almost full (93%)']);
$h = SiteHealth::of($row($report(['backups' => ['last_success_at' => gmdate('c', $now - 3 * 86400)]])), $now, 21600);
check('a daily backup that has not succeeded for three days is a warning', [$h['state'], $h['issues'][0]['text']], ['warning', 'No successful backup since ' . gmdate('Y-m-d', $now - 3 * 86400)]);
$h = SiteHealth::of($row($report(), 'connected', $now - 49 * 3600), $now, 21600);
check('49 hours without a report: silent', [$h['state'], $h['silent'], str_starts_with($h['issues'][0]['text'], 'No report since')], ['silent', true, true]);
check('47 hours is not silent yet', SiteHealth::of($row($report(), 'connected', $now - 47 * 3600), $now, 21600)['silent'], false);
check('a long interval stretches it (twice 30 hours)', SiteHealth::of($row($report(), 'connected', $now - 49 * 3600), $now, 108000)['silent'], false);
check('waiting to connect, and disconnected', [SiteHealth::of(['status' => 'pending'], $now, 21600)['label'], SiteHealth::of($row($report(), 'disconnected', $now - 99 * 3600), $now, 21600)['state']], ['Waiting to connect', 'disconnected']);
check('connected but no report yet', SiteHealth::of(['status' => 'connected', 'connected_at' => gmdate('c', $now - 60)], $now, 21600)['label'], 'Waiting for a report');
$h = SiteHealth::of($row($report(['analytics' => ['enabled' => false, 'mode' => 'off'], 'backups' => ['scheduled' => false, 'last' => null, 'last_success_at' => null]])), $now, 21600);
check('no tracking, no backups', [$h['chips']['analytics']['label'], $h['chips']['backup']['label'], $h['state']], ['Off', 'Off', 'ok']);

// ---- updates
use Omni\Core\Updater;
use Omni\Models\Settings;
check('a package may hold the code', [Updater::allowedPath('system/Core/App.php'), Updater::allowedPath('public/assets/css/app.css'), Updater::allowedPath('public/index.php'), Updater::allowedPath('VERSION')], [true, true, true, true]);
check('and nothing else: data, other folders, climbing out, absolute or Windows paths', [Updater::allowedPath('storage/key'), Updater::allowedPath('storage/db/omni.sqlite'), Updater::allowedPath('tests/unit.php'), Updater::allowedPath('system/../storage/key'), Updater::allowedPath('/system/x.php'), Updater::allowedPath('system\\x.php'), Updater::allowedPath('public/x.php'), Updater::allowedPath('')], [false, false, false, false, false, false, false, false]);
$updater = new Updater(ROOT_PATH, STORAGE_PATH, STORAGE_PATH . '/db/omni.sqlite', new Settings($db), 'https://example.test/release.json', fn() => [], fn() => [], fn() => []);
$good = ['version' => '0.2.0', 'min_php' => '8.1', 'package_url' => 'https://github.com/chiotis/gnozio-omni/releases/download/v0.2.0/omni-0.2.0.zip', 'sha256' => str_repeat('a', 64), 'size' => 40000, 'notes' => '- x'];
check('a valid release.json', $updater->validateManifest($good)['version'] ?? null, '0.2.0');
check('refused: no https, a bad hash, a size out of bounds, a version that is not one', [
    $updater->validateManifest(['package_url' => 'http://github.com/x.zip'] + $good), $updater->validateManifest(['sha256' => 'abc'] + $good),
    $updater->validateManifest(['size' => 0] + $good), $updater->validateManifest(['size' => 999999999] + $good), $updater->validateManifest(['version' => 'latest'] + $good), $updater->validateManifest('nope'),
], [null, null, null, null, null, null]);
check('http to this machine only when tests allow it', [(new Updater(ROOT_PATH, STORAGE_PATH, '', new Settings($db), '', fn() => [], fn() => [], fn() => [], true))->validateManifest(['package_url' => 'http://127.0.0.1:9/x.zip'] + $good)['package_url'] ?? null, $updater->validateManifest(['package_url' => 'http://127.0.0.1:9/x.zip'] + $good)], ['http://127.0.0.1:9/x.zip', null]);
$notes = release_notes("- **Bold** and `code` <script>\n  - nested\n- back\nA line.");
check('release notes: lists, nesting, bold, code; HTML escaped', [substr_count($notes, '<ul'), substr_count($notes, '</ul>'), str_contains($notes, '<strong>Bold</strong>'), str_contains($notes, '&lt;script&gt;'), str_contains($notes, '<p class="mt-2">A line.</p>')], [2, 2, true, true, true]);
$build = static function (string $out): string { exec('php ' . escapeshellarg(ROOT_PATH . '/scripts/build-release.php') . ' ' . escapeshellarg($out) . ' 2>&1'); return (string)@file_get_contents($out . '/release.json'); };
$one = json_decode($build(STORAGE_PATH . '/b1'), true);
$two = json_decode($build(STORAGE_PATH . '/b2'), true);
check('the release builds to the same bytes every time', [$one['sha256'] ?? '', isset($one['sha256'])], [$two['sha256'] ?? '-', true]);
$zip = new ZipArchive();
$zip->open(STORAGE_PATH . '/b1/' . $one['package']);
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
check('the package holds only what an update may write, and what it must', [array_values(array_filter($names, fn($n) => !Updater::allowedPath($n))), in_array('system/Core/Updater.php', $names, true), in_array('VERSION', $names, true)], [[], true, true]);
check('its manifest is one Omni accepts', $updater->validateManifest($one)['version'] ?? null, trim((string)file_get_contents(ROOT_PATH . '/VERSION')));
exec('rm -rf ' . escapeshellarg(STORAGE_PATH . '/b1') . ' ' . escapeshellarg(STORAGE_PATH . '/b2'));

// ---- commands
use Omni\Core\Commands;
$cDb = Database::open(STORAGE_PATH . '/db/commands.sqlite');
$cSites = new Sites($cDb);
$cBox = new SecretBox(STORAGE_PATH . '/key');
$cId = $cSites->create('Asked');
$cCode = $cSites->newCode($cId, $now);
$cSecret = SecretBox::newSecret();
$cSites->connect($cCode['code'], ['name' => 'Asked', 'url' => 'https://asked.test/sub/', 'admin_url' => 'https://asked.test/sub/admin', 'version' => '0.1.0'], $cBox->encrypt($cSecret), $now);
$withUpdate = ['protocol' => 1, 'site' => ['name' => 'Asked', 'url' => 'https://asked.test/sub/', 'admin_url' => 'https://asked.test/sub/admin'], 'faros' => ['version' => '0.1.0'], 'updates' => ['current' => '0.1.0', 'latest' => '0.2.0', 'has_update' => true]];
$cSites->recordReport($cId, $withUpdate, 10, $now);
check('a site with a newer release can be asked to update', Commands::canUpdate($cSites->find($cId), $now), true);
check('not one that is up to date, never reported, or not connected', [
    Commands::canUpdate(['status' => 'connected', 'url' => 'https://x.test', 'latest_report' => json_encode(['updates' => ['has_update' => false]])], $now),
    Commands::canUpdate(['status' => 'connected', 'url' => 'https://x.test'], $now),
    Commands::canUpdate(['status' => 'disconnected', 'url' => 'https://x.test', 'latest_report' => json_encode($withUpdate)], $now),
], [false, false, false]);
$seen = [];
$http = function (string $method, string $url, array $headers, string $body, int $timeout) use (&$seen, &$reply): array { $seen = compact('method', 'url', 'headers', 'body'); return $reply; };
$commands = new Commands($cSites, $cBox, $http, fn() => $now);
$reply = ['status' => 202, 'body' => '{"ok":true,"state":"accepted"}', 'error' => ''];
$r = $commands->update($cSites->find($cId));
$h = [];
foreach ($seen['headers'] as $line) { [$k, $v] = explode(':', $line, 2); $h[strtolower($k)] = trim($v); }
$data = json_decode($seen['body'], true);
check('the command goes to the site\'s own address, in its folder', [$seen['method'], $seen['url']], ['POST', 'https://asked.test/sub/_omni/command']);
check('it is update, protocol 1, with an id', [$data['protocol'], $data['command'], preg_match('/^c_[0-9a-f]{16}$/', $data['command_id'])], [1, 'update', 1]);
check('signed with the site\'s secret over the path under its folder', $h['x-omni-signature'], Signature::sign($cSecret, 'POST', '/sub/_omni/command', $h['x-omni-timestamp'], $h['x-omni-nonce'], $seen['body']));
check('and with the id the site knows, the time now', [$h['x-omni-site'], $h['x-omni-timestamp']], [$cSites->find($cId)['public_id'], (string)$now]);
check('accepted: told so, kept as accepted', [$r['ok'], $cSites->find($cId)['command_state'], $cSites->find($cId)['command_id'] === $data['command_id']], [true, 'accepted', true]);
check('while it runs the site cannot be asked again', Commands::canUpdate($cSites->find($cId), $now + 60), false);
check('a command with no outcome for 30 minutes is taken as lost', Commands::canUpdate($cSites->find($cId), $now + 1801), true);
$cSites->recordReport($cId, $withUpdate + ['command' => ['id' => $data['command_id'], 'state' => 'installed', 'message' => 'GnozioCMS 0.2.0 is installed.']], 10, $now + 90);
check('the site\'s report says how it ended', [$cSites->find($cId)['command_state'], $cSites->find($cId)['command_message']], ['installed', 'GnozioCMS 0.2.0 is installed.']);
$cSites->recordReport($cId, $withUpdate + ['command' => ['id' => 'c_ffffffffffffffff', 'state' => 'failed', 'message' => 'x']], 10, $now + 100);
check('a report about a command Omni did not send changes nothing', $cSites->find($cId)['command_state'], 'installed');
// A quick site: its report with the outcome arrives while Omni is still reading the 202.
$quick = function (string $method, string $url, array $headers, string $body, int $timeout) use ($cSites, $cId, $withUpdate, $now): array {
    $id = json_decode($body, true)['command_id'];
    $cSites->recordReport($cId, $withUpdate + ['command' => ['id' => $id, 'state' => 'installed', 'message' => 'Done before the answer was read.']], 10, $now + 5);
    return ['status' => 202, 'body' => '{"ok":true,"state":"accepted"}', 'error' => ''];
};
(new Commands($cSites, $cBox, $quick, fn() => $now + 3))->update($cSites->find($cId));
check('a site that reports before Omni has read its answer keeps its outcome', [$cSites->find($cId)['command_state'], $cSites->find($cId)['command_message']], ['installed', 'Done before the answer was read.']);
$reply = ['status' => 202, 'body' => '{"ok":true,"state":"accepted"}', 'error' => ''];
$commands->update($cSites->find($cId));
foreach ([[404, '{}', 'does not take commands from Omni yet'], [403, '{"error":"not_allowed"}', 'does not let Omni install updates'], [409, '{"error":"busy"}', 'already updating'], [401, '{"error":"invalid_signature"}', 'no longer recognises this Omni'], [401, '{"error":"stale_timestamp"}', 'clocks'], [500, '{}', 'HTTP 500']] as [$status, $body, $part]) {
    $reply = ['status' => $status, 'body' => $body, 'error' => ''];
    $r = $commands->update($cSites->find($cId));
    check("a $status from the site is said in words", [$r['ok'], str_contains($r['message'], $part), $cSites->find($cId)['command_state']], [false, true, 'refused']);
}
$reply = ['status' => 0, 'body' => '', 'error' => 'Connection refused'];
check('a site that cannot be reached', str_contains($commands->update($cSites->find($cId))['message'], 'Connection refused'), true);
check('a site that is not connected is not asked', $commands->update(['status' => 'pending', 'url' => '', 'label' => 'x', 'name' => '', 'id' => 1, 'public_id' => 's_x'])['ok'], false);
$row = ['command_state' => 'accepted', 'command_at' => gmdate('c', $now), 'command_message' => 'Asked.'];
check('SiteHealth: updating while it runs', [SiteHealth::command($row, $now + 10)['state'], SiteHealth::command($row, $now + 10)['tone']], ['busy', '']);
check('SiteHealth: lost after 30 minutes, with a warning', [SiteHealth::command($row, $now + 1801)['state'], SiteHealth::command($row, $now + 1801)['tone']], ['done', 'amber']);
check('SiteHealth: a good outcome says nothing is wrong; a rolled back one is red; a failed one too', [
    SiteHealth::command(['command_state' => 'installed', 'command_at' => gmdate('c', $now), 'command_message' => 'ok'], $now)['tone'],
    SiteHealth::command(['command_state' => 'rolled_back', 'command_at' => gmdate('c', $now), 'command_message' => 'x'], $now)['tone'],
    SiteHealth::command(['command_state' => 'failed', 'command_at' => gmdate('c', $now), 'command_message' => 'x'], $now)['tone'],
], ['', 'red', 'red']);
check('SiteHealth: a bad outcome is forgotten after a week', SiteHealth::command(['command_state' => 'failed', 'command_at' => gmdate('c', $now), 'command_message' => 'x'], $now + 8 * 86400), null);
check('SiteHealth: a site that was never asked', SiteHealth::command(['command_state' => null], $now), null);
$cDb = null;
check('the stream HTTP helper reports no answer as status 0, never an exception', [\Omni\Core\Http::request('GET', 'http://127.0.0.1:9/', [], '', 2)['status']], [0]);

// ---- helpers
check('sizes', [bytes(0), bytes(1536), bytes(300 * 1048576), bytes(null)], ['0 B', '1.5 KB', '300 MB', '—']);
check('ago', [ago(gmdate('c', $now - 30), $now), ago(gmdate('c', $now - 7200), $now), ago(null, $now)], ['just now', '2 h ago', 'never']);
check('text is escaped', e('<a href="x">'), '&lt;a href=&quot;x&quot;&gt;');

array_map('unlink', glob(STORAGE_PATH . '/db/*') ?: []);
@rmdir(STORAGE_PATH . '/db');
@unlink(STORAGE_PATH . '/key');
@unlink(STORAGE_PATH . '/other-key');
@rmdir(STORAGE_PATH);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
