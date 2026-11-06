<?php
/*
 * Omni through a real server: setup, sign-in, adding a site, the connection code, and the API a site calls
 * (connect, signed reports, every way a request is refused, disconnect, removal), then what the owner sees.
 *   php tests/http.php
 * Runs PHP's built-in server on a free port with a storage folder of its own, and removes it afterwards.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/system/Core/autoload.php';
use Omni\Core\Signature;

$fail = 0;
function check(string $label, bool $ok, mixed $detail = ''): void { global $fail; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok ? '' : '  ' . substr(preg_replace('/\s+/', ' ', is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_SLASHES)), 0, 300)) . "\n"; }

$storage = sys_get_temp_dir() . '/omni-http-' . getmypid();
mkdir($storage . '/db', 0775, true);
$socket = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr((string)stream_socket_get_name($socket, false), strrpos((string)stream_socket_get_name($socket, false), ':') + 1);
fclose($socket);
$base = 'http://127.0.0.1:' . $port;
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $root . '/public', $root . '/public/index.php'], [1 => ['file', $storage . '/server.log', 'a'], 2 => ['file', $storage . '/server.log', 'a']], $pipes, $root, ['OMNI_STORAGE' => $storage, 'OMNI_RELEASE_URL' => 'http://127.0.0.1:9/release.json', 'OMNI_GNOZIO_RELEASE_URL' => 'http://127.0.0.1:9/gnozio.json'] + getenv());
register_shutdown_function(static function () use ($server, $storage): void {
    proc_terminate($server);
    proc_close($server);
    exec('rm -rf ' . escapeshellarg($storage));
});
for ($i = 0; $i < 50 && @file_get_contents($base . '/api/v1/ping') === false; $i++) {
    usleep(100000);
}

/** A browser with its own cookies. @return callable(string, string, array|string|null, array): array{0: int, 1: array<string, string>, 2: string} */
function client(string $base): callable
{
    $jar = tempnam(sys_get_temp_dir(), 'omni-jar');
    return static function (string $method, string $path, array|string|null $data = null, array $headers = []) use ($base, $jar): array {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers]);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        }
        $response = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head = [];
        foreach (explode("\r\n", substr($response, 0, $size)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $head[strtolower(trim($k))] = trim($v);
            }
        }
        return [$status, $head, substr($response, $size)];
    };
}
function token(string $html): string { return preg_match('/name="_csrf" value="([0-9a-f]+)"/', $html, $m) ? $m[1] : ''; }
function api(string $base, string $path, string $body, array $headers = []): array
{
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers), CURLOPT_HEADER => true]);
    $response = (string)curl_exec($ch);
    $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode(substr($response, $size), true), substr($response, 0, $size)];
}
/** @return array<int, string> the headers of a signed request */
function signed(string $siteId, string $secret, string $path, string $body, ?int $time = null, ?string $nonce = null): array
{
    $time = (string)($time ?? time());
    $nonce ??= bin2hex(random_bytes(16));
    return ['X-Omni-Site: ' . $siteId, 'X-Omni-Timestamp: ' . $time, 'X-Omni-Nonce: ' . $nonce, 'X-Omni-Signature: ' . Signature::sign($secret, 'POST', $path, $time, $nonce, $body)];
}

// ---- setup
$owner = client($base);
[$st, $h] = $owner('GET', '/');
check('a new Omni sends everyone to setup', $st === 303 && $h['location'] === '/setup', [$st, $h['location'] ?? '']);
[$st, $h] = $owner('GET', '/login');
check('sign-in too, while there is no owner', $st === 303 && $h['location'] === '/setup');
[$st, $h, $html] = $owner('GET', '/setup');
check('the setup page asks for the owner', $st === 200 && str_contains($html, 'name="password_confirm"') && str_contains($html, 'Europe/Athens'), $st);
check('pages carry a strict content policy and refuse frames', str_contains($h['content-security-policy'] ?? '', "script-src 'self'") && ($h['x-frame-options'] ?? '') === 'DENY');
[$cssSt, $cssH] = $owner('GET', '/assets/css/app.css');
check('the stylesheet is served as such', $cssSt === 200 && str_starts_with($cssH['content-type'] ?? '', 'text/css'), [$cssSt, $cssH['content-type'] ?? '']);
$csrf = token($html);
[$st] = $owner('POST', '/setup', ['name' => 'Christos', 'email' => 'owner@example.test', 'password' => 'a long password', 'password_confirm' => 'a long password', 'timezone' => 'Europe/Athens']);
check('a form without its token is refused', $st === 403, $st);
[$st, , $html] = $owner('POST', '/setup', ['_csrf' => $csrf, 'name' => 'Christos', 'email' => 'owner@example.test', 'password' => 'short', 'password_confirm' => 'short', 'timezone' => 'Europe/Athens']);
check('a short password is refused, and the form keeps what was typed', $st === 422 && str_contains($html, 'at least 12') && str_contains($html, 'value="owner@example.test"'), $st);
[$st, $h] = $owner('POST', '/setup', ['_csrf' => $csrf, 'name' => 'Christos', 'email' => 'owner@example.test', 'password' => 'a long password', 'password_confirm' => 'a long password', 'timezone' => 'Europe/Athens']);
check('setup makes the owner and signs them in', $st === 303 && $h['location'] === '/', [$st, $h]);
check('the key for the secrets is made, outside the web root', is_file($storage . '/key'));
[$st, , $html] = $owner('GET', '/');
check('the list of sites, empty', $st === 200 && str_contains($html, 'No sites yet'), $st);
check('the welcome toast', str_contains($html, 'data-flash-message="Omni is ready. Add your first site."'));
$csrf = token($html);

$stranger = client($base);
[$st, $h] = $stranger('GET', '/setup');
check('setup is closed once the owner exists', $st === 303 && $h['location'] === '/login');
[, , $html] = $stranger('GET', '/login');
[$st, $h] = $stranger('POST', '/setup', ['_csrf' => token($html), 'name' => 'Mallory', 'email' => 'm@example.test', 'password' => 'a long password', 'password_confirm' => 'a long password', 'timezone' => 'UTC']);
check('and a second owner cannot be made', $st === 303 && $h['location'] === '/login');
[$st, $h] = $stranger('GET', '/sites/1');
check('a stranger is sent to sign in', $st === 303 && $h['location'] === '/login');

// ---- sign-in
[, , $html] = $stranger('GET', '/login');
[$st, , $html] = $stranger('POST', '/login', ['_csrf' => token($html), 'email' => 'owner@example.test', 'password' => 'wrong password']);
check('a wrong password is refused, without saying which part was wrong', $st === 401 && str_contains($html, 'The email or the password is not right.'), $st);
$me = client($base);
[, , $html] = $me('GET', '/login');
[$st, $h] = $me('POST', '/login', ['_csrf' => token($html), 'email' => ' OWNER@example.test ', 'password' => 'a long password']);
check('the owner signs in (email in any case)', $st === 303 && $h['location'] === '/', [$st, $h['location'] ?? '']);

// ---- adding a site
[$st, $h] = $owner('POST', '/sites', ['_csrf' => $csrf, 'label' => 'Doxiadis']);
check('adding a site goes to its page', $st === 303 && preg_match('#^/sites/\d+$#', $h['location'] ?? ''), [$st, $h]);
$sitePath = $h['location'];
[$st, , $html] = $owner('GET', $sitePath);
preg_match('/data-copy-source="code">([^<]+)</', $html, $m);
$code = $m[1] ?? '';
check('its page shows the connection code once, with this Omni\'s address', $st === 200 && preg_match('/^[2-9A-Z]{4}(-[2-9A-Z]{4}){3}$/', $code) === 1 && str_contains($html, $base), $code);
[, , $html] = $owner('GET', $sitePath);
check('and never again', !str_contains($html, $code) && str_contains($html, 'Not connected yet'));
[, , $html] = $owner('GET', '/');
check('the list shows it waiting', str_contains($html, 'Waiting to connect') && str_contains($html, 'Doxiadis'));

// ---- the API
[$st, $body] = api($base, '/api/v1/ping', '');
check('ping is GET only', $st === 404, $st);
$ping = json_decode((string)file_get_contents($base . '/api/v1/ping'), true);
check('ping says it is an Omni and which protocols', $ping === ['omni' => true, 'name' => 'Omni', 'protocols' => [1]], $ping);
$connect = static fn(string $code, int $protocol = 1): string => json_encode(['protocol' => $protocol, 'code' => $code, 'site' => ['name' => 'Doxiadis Associates', 'url' => 'https://doxiadis.test', 'admin_url' => 'https://doxiadis.test/admin', 'version' => '0.1.101']]);
[$st, $body] = api($base, '/api/v1/connect', $connect('AAAA-BBBB-CCCC-DDDD'));
check('an unknown code is refused', $st === 403 && ($body['error'] ?? '') === 'invalid_code', [$st, $body]);
[$st, $body] = api($base, '/api/v1/connect', $connect($code, 2));
check('a protocol this Omni does not speak: 426, with the ones it does', $st === 426 && ($body['protocols'] ?? null) === [1], [$st, $body]);
[$st, $body] = api($base, '/api/v1/connect', '[1,2]');
check('a body that is not an object: 400', $st === 400 && ($body['error'] ?? '') === 'invalid_request', [$st, $body]);
[$st, $body] = api($base, '/api/v1/connect', $connect(strtolower($code)));
check('the code connects the site (typed in any case)', $st === 200 && preg_match('/^s_[0-9a-f]{12}$/', $body['site_id'] ?? '') === 1 && strlen($body['secret'] ?? '') === 43 && ($body['report_every'] ?? 0) === 21600, [$st, $body]);
$siteId = (string)($body['site_id'] ?? '');
$secret = (string)($body['secret'] ?? '');
[$st, $body] = api($base, '/api/v1/connect', $connect($code));
check('a code works once', $st === 403, $st);
$db = new PDO('sqlite:' . $storage . '/db/omni.sqlite');
$stored = (string)$db->query('SELECT secret_enc FROM sites')->fetchColumn();
check('the secret is kept sealed, not as it is', $stored !== '' && !str_contains($stored, $secret));

$report = [
    'protocol' => 1, 'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'site' => ['name' => 'Doxiadis <b>Associates</b>', 'url' => 'https://doxiadis.test/', 'admin_url' => 'https://doxiadis.test/admin'],
    'faros' => ['version' => '0.1.101', 'commit' => 'abc1234'],
    'storage' => ['used' => 734003200, 'limit' => 2147483648, 'disk_free' => null, 'percent_of_limit' => 34, 'level' => 'ok', 'full' => false, 'parts' => ['uploads' => 612368384, 'content' => 4194304, 'system' => 117440512], 'measured_at' => gmdate('Y-m-d\TH:i:s\Z')],
    'backups' => ['scheduled' => true, 'schedule' => 'daily', 'remote' => true, 'keep_local' => false, 'last' => ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200), 'status' => 'failed', 'size' => 0, 'message' => 'Disk full.'], 'last_success_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 90000), 'failed_last_7_days' => 1, 'cron' => ['state' => 'fresh', 'last_seen' => gmdate('Y-m-d\TH:i:s\Z'), 'after_visit' => true]],
    'analytics' => ['enabled' => true, 'mode' => 'custom'],
    'updates' => ['current' => '0.1.101', 'latest' => '0.2.0', 'has_update' => true, 'checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'source_status' => 'ok'],
    'system' => ['summary' => ['label' => 'Warnings', 'status' => 'warning', 'detail' => '1 check to review'], 'checks' => [['label' => 'PHP', 'value' => '8.3.12', 'status' => 'ok'], ['label' => 'Email provider', 'value' => 'not configured', 'status' => 'warning']], 'extensions' => [['name' => 'zip', 'loaded' => true, 'status' => 'ok']], 'environment' => ['PHP' => '8.3.12 (fpm-fcgi)', 'HTTPS' => 'yes']],
];
$json = json_encode($report, JSON_UNESCAPED_SLASHES);
$path = '/api/v1/report';
$nonce = bin2hex(random_bytes(16));
[$st, $body] = api($base, $path, $json, signed($siteId, $secret, $path, $json, null, $nonce));
check('a signed report is taken', $st === 200 && $body === ['ok' => true, 'report_every' => 21600], [$st, $body]);
[$st, $body] = api($base, $path, $json, signed($siteId, $secret, $path, $json, null, $nonce));
check('the same request again is a replay', $st === 401 && ($body['error'] ?? '') === 'replayed_nonce', [$st, $body]);
$headers = signed($siteId, $secret, $path, $json);
[$st, $body] = api($base, $path, $json . ' ', $headers);
check('a body changed on the way does not match its signature', $st === 401 && ($body['error'] ?? '') === 'invalid_signature', [$st, $body]);
[$st, $body] = api($base, $path, $json, signed($siteId, 'not-the-secret', $path, $json));
check('another secret does not match', $st === 401 && ($body['error'] ?? '') === 'invalid_signature', [$st, $body]);
[$st, $body] = api($base, $path, $json, signed($siteId, $secret, '/api/v1/disconnect', $json));
check('a signature made for another endpoint does not match', $st === 401 && ($body['error'] ?? '') === 'invalid_signature', [$st, $body]);
[$st, $body] = api($base, $path, $json, signed($siteId, $secret, $path, $json, time() - 301));
check('a timestamp more than five minutes old is refused', $st === 401 && ($body['error'] ?? '') === 'stale_timestamp', [$st, $body]);
[$st, $body] = api($base, $path, $json, signed('s_000000000000', $secret, $path, $json));
check('an unknown site is refused', $st === 401 && ($body['error'] ?? '') === 'unknown_site', [$st, $body]);
[$st, $body] = api($base, $path, $json, array_slice(signed($siteId, $secret, $path, $json), 0, 3));
check('no signature at all is refused', $st === 401 && ($body['error'] ?? '') === 'invalid_signature', [$st, $body]);
$big = json_encode(['protocol' => 1, 'site' => [], 'pad' => str_repeat('x', 70000)]);
[$st, $body] = api($base, $path, $big, signed($siteId, $secret, $path, $big));
check('a report over 64 KB is refused', $st === 413 && ($body['error'] ?? '') === 'too_large', [$st, $body]);
$v2 = json_encode(['protocol' => 2, 'site' => []]);
[$st, $body] = api($base, $path, $v2, signed($siteId, $secret, $path, $v2));
check('a report in a protocol it does not speak: 426', $st === 426, [$st, $body]);
$noSite = json_encode(['protocol' => 1]);
[$st, $body] = api($base, $path, $noSite, signed($siteId, $secret, $path, $noSite));
check('a report without its site is refused', $st === 400, [$st, $body]);

// ---- what the owner sees
[, , $html] = $owner('GET', '/');
check('the list: needs attention, with the failed backup, the update and the analytics kind', str_contains($html, 'Needs attention') && str_contains($html, 'Failed · 2 h ago') && str_contains($html, '0.1.101 → 0.2.0') && str_contains($html, 'Own code'), substr(strip_tags($html), 0, 600));
check('text from a site is escaped', !str_contains($html, '<b>Associates</b>'));
check('a link to the admin, opened apart', str_contains($html, 'href="https://doxiadis.test/admin" target="_blank" rel="noopener noreferrer"'));
[, , $html] = $owner('GET', '/?show=updates');
check('the update filter lists it', str_contains($html, 'data-site="' . $siteId . '"'));
[, , $html] = $owner('GET', '/?show=waiting');
check('the not connected filter does not', !str_contains($html, 'data-site="' . $siteId . '"'));
[, , $html] = $owner('GET', $sitePath);
check('the site page: the reasons, the checks, the storage, the admin button', str_contains($html, 'The last backup failed: Disk full.') && str_contains($html, 'Email provider: not configured') && str_contains($html, '700 MB') && str_contains($html, 'data-admin-link'), substr(strip_tags($html), 0, 600));
check('the times are in the owner\'s zone', str_contains($html, date_create('now', new DateTimeZone('Europe/Athens'))->format('Y-m-d')));

// ---- rename, disconnect, reconnect, remove
[$st] = $owner('POST', $sitePath . '/rename', ['_csrf' => $csrf, 'label' => 'Doxiadis (client)']);
[, , $html] = $owner('GET', $sitePath);
check('the owner renames the site in Omni', $st === 303 && str_contains($html, 'Doxiadis (client)'));
$empty = '{}';
[$st, $body] = api($base, '/api/v1/disconnect', $empty, signed($siteId, $secret, '/api/v1/disconnect', $empty));
check('the site disconnects', $st === 200 && $body === ['ok' => true], [$st, $body]);
[$st, $body] = api($base, $path, $json, signed($siteId, $secret, $path, $json));
check('and is no longer heard', $st === 401 && ($body['error'] ?? '') === 'unknown_site', [$st, $body]);
[, , $html] = $owner('GET', $sitePath);
check('Omni shows it disconnected, with its history', str_contains($html, 'The site disconnected') && str_contains($html, '1 report kept'));
[$st] = $owner('POST', $sitePath . '/code', ['_csrf' => $csrf]);
[, , $html] = $owner('GET', $sitePath);
preg_match('/data-copy-source="code">([^<]+)</', $html, $m);
[$st, $body] = api($base, '/api/v1/connect', $connect($m[1] ?? ''));
check('a new code connects it again, with a new secret', $st === 200 && ($body['site_id'] ?? '') === $siteId && ($body['secret'] ?? '') !== $secret, [$st, $body]);
$secret = (string)($body['secret'] ?? '');
[$st, $h] = $owner('POST', $sitePath . '/remove', ['_csrf' => $csrf]);
check('the owner removes it', $st === 303 && $h['location'] === '/');
[$st, $body] = api($base, $path, $json, signed($siteId, $secret, $path, $json));
check('and its next report is told so: 410', $st === 410 && ($body['error'] ?? '') === 'site_removed', [$st, $body]);
[$st] = $owner('GET', $sitePath);
[, , $html] = $owner('GET', '/');
check('it is gone from the list and its page', $st === 404 && !str_contains($html, 'data-site="'));

// ---- asking a site to update (a stand-in for the site takes the command)
$fakePort = (int)(function () { $sock = stream_socket_server('tcp://127.0.0.1:0'); $n = (string)stream_socket_get_name($sock, false); fclose($sock); return substr($n, strrpos($n, ':') + 1); })();
$fakeLog = $storage . '/fake-site.log';
$fakeMode = $storage . '/fake-site.mode';
$fakeSecret = $storage . '/fake-site.secret';
$fake = proc_open(['php', '-S', '127.0.0.1:' . $fakePort, $root . '/tests/fake-site.php'], [1 => ['file', $storage . '/fake.out', 'a'], 2 => ['file', $storage . '/fake.out', 'a']], $fp, $root, ['FAKE_SITE_LOG' => $fakeLog, 'FAKE_SITE_MODE' => $fakeMode, 'FAKE_SITE_SECRET_FILE' => $fakeSecret] + getenv());
register_shutdown_function(static function () use ($fake): void { proc_terminate($fake); proc_close($fake); });
for ($i = 0; $i < 50; $i++) { if (($c = @stream_socket_client("tcp://127.0.0.1:$fakePort", $en, $es, 0.2)) !== false) { fclose($c); break; } usleep(100000); }
$addSite = function (string $label, bool $update) use ($owner, $csrf, $base, $fakePort, &$storage): array {
    [, $h] = $owner('POST', '/sites', ['_csrf' => $csrf, 'label' => $label]);
    $path = $h['location'];
    [, , $html] = $owner('GET', $path);
    preg_match('/data-copy-source="code">([^<]+)</', $html, $m);
    $body = json_encode(['protocol' => 1, 'code' => $m[1], 'site' => ['name' => $label, 'url' => "http://127.0.0.1:$fakePort", 'admin_url' => "http://127.0.0.1:$fakePort/admin", 'version' => '0.1.0']]);
    [, $data] = api($base, '/api/v1/connect', $body);
    $report = json_encode(['protocol' => 1, 'site' => ['name' => $label, 'url' => "http://127.0.0.1:$fakePort", 'admin_url' => "http://127.0.0.1:$fakePort/admin"], 'faros' => ['version' => '0.1.0'], 'updates' => ['current' => '0.1.0', 'latest' => '0.2.0', 'has_update' => $update]]);
    api($base, '/api/v1/report', $report, signed($data['site_id'], $data['secret'], '/api/v1/report', $report));
    return ['path' => $path, 'id' => (int)substr($path, strrpos($path, '/') + 1), 'site_id' => $data['site_id'], 'secret' => $data['secret'], 'report' => $report];
};
$a = $addSite('Update me', true);
$b = $addSite('Up to date', false);
file_put_contents($fakeSecret, $a['secret']);

[, , $html] = $owner('GET', '/');
check('the list offers Update all for the one site that has a newer release', str_contains($html, 'Update all (1)') && str_contains($html, '/sites/' . $a['id'] . '/update') && !str_contains($html, '/sites/' . $b['id'] . '/update'), substr(strip_tags($html), 0, 400));
[, , $html] = $owner('GET', $a['path']);
check('its page has Update to 0.2.0', str_contains($html, 'Update to 0.2.0') && str_contains($html, 'data-update-site'));
[, , $html] = $owner('GET', $b['path']);
check('the one that is up to date has no such button', !str_contains($html, 'data-update-site'));
[$st] = $owner('POST', $b['path'] . '/update', ['_csrf' => $csrf]);
check('and asking it anyway does nothing', $st === 303 && !is_file($fakeLog), $st);

[$st, $h] = $owner('POST', $a['path'] . '/update', ['_csrf' => $csrf]);
[, , $html] = $owner('GET', $a['path']);
$log = array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents($fakeLog))));
check('Update sends the site a command it accepts: signed, for the id it knows, update, protocol 1', $st === 303 && count($log) === 1 && $log[0]['good'] === true && $log[0]['site'] === $a['site_id'] && ($log[0]['body']['command'] ?? '') === 'update' && ($log[0]['body']['protocol'] ?? 0) === 1, $log);
check('the page says it is updating, and offers nothing more to press', str_contains($html, 'is updating.') && str_contains($html, 'data-command') && !str_contains($html, 'data-update-site'), substr(strip_tags($html), 0, 500));
[, , $list] = $owner('GET', '/');
check('the list shows Updating… for it, and no Update all', str_contains($list, 'Updating…') && !str_contains($list, 'Update all'));

$commandId = $log[0]['body']['command_id'];
$done = json_decode($a['report'], true) + [];
$done['updates'] = ['current' => '0.2.0', 'latest' => '0.2.0', 'has_update' => false];
$done['command'] = ['id' => $commandId, 'type' => 'update', 'state' => 'installed', 'message' => 'GnozioCMS 0.2.0 is installed.', 'from' => '0.1.0', 'to' => '0.2.0'];
$body = json_encode($done);
[$st, $resp] = api($base, '/api/v1/report', $body, signed($a['site_id'], $a['secret'], '/api/v1/report', $body));
[, , $html] = $owner('GET', $a['path']);
[, , $list] = $owner('GET', '/');
check('the report with the outcome is taken, and Omni shows it done', $st === 200 && str_contains($html, 'GnozioCMS 0.2.0 is installed.') && str_contains($html, 'Installed') && !str_contains($list, 'Updating…'), [$st, substr(strip_tags($html), 0, 300)]);

// refused answers
$again = json_decode($a['report'], true);
$body = json_encode($again);
api($base, '/api/v1/report', $body, signed($a['site_id'], $a['secret'], '/api/v1/report', $body));
foreach ([['not_allowed', 'does not let Omni install updates'], ['busy', 'already updating'], ['404', 'does not take commands from Omni yet']] as [$mode, $words]) {
    file_put_contents($fakeMode, $mode);
    $owner('POST', $a['path'] . '/update', ['_csrf' => $csrf]);
    [, , $html] = $owner('GET', $a['path']);
    check("a site that answers $mode: Omni says so in words, and the button stays", str_contains($html, $words) && str_contains($html, 'data-update-site'), substr(strip_tags($html), 0, 300));
}
file_put_contents($fakeMode, 'accept');
[$st] = $owner('POST', '/sites/update-all', ['_csrf' => $csrf]);
[, , $html] = $owner('GET', '/');
check('Update all asks every site that has an update', $st === 303 && str_contains($html, '1 site is updating.'), substr(strip_tags($html), 0, 300));

// a command is never started by a stranger
$lines = static fn(): int => count(array_filter(explode("\n", (string)file_get_contents($GLOBALS['fakeLog']))));
$before = $lines();
$stranger2 = client($base);
[$st] = $stranger2('POST', $a['path'] . '/update', ['_csrf' => 'x']);
[$st2] = $stranger2('POST', '/sites/update-all', ['_csrf' => 'x']);
check('a stranger cannot ask a site to update, or all of them', in_array($st, [303, 403], true) && in_array($st2, [303, 403], true) && $lines() === $before, [$st, $st2]);

// ---- limits on guessing
for ($i = 0, $last = 0; $i < 10; $i++) {
    [$last] = api($base, '/api/v1/connect', $connect('AAAA-BBBB-CCCC-DDDD'));
}
[$st, , $head] = api($base, '/api/v1/connect', $connect('AAAA-BBBB-CCCC-DDDD'));
check('ten connection attempts an hour per address, then 429 with Retry-After', $st === 429 && stripos($head, 'Retry-After:') !== false, $st);
for ($i = 0; $i < 8; $i++) {
    [, , $html] = $stranger('GET', '/login');
    $stranger('POST', '/login', ['_csrf' => token($html), 'email' => 'owner@example.test', 'password' => 'guess ' . $i]);
}
[, , $html] = $stranger('GET', '/login');
[$st, , $html] = $stranger('POST', '/login', ['_csrf' => token($html), 'email' => 'owner@example.test', 'password' => 'a long password']);
check('eight failed sign-ins, then even the right password waits', $st === 429 && str_contains($html, 'Too many attempts'), $st);

// ---- sign out
[$st, $h] = $me('POST', '/logout', ['_csrf' => token($me('GET', '/')[2])]);
[$st2, $h2] = $me('GET', '/');
check('signing out ends the session', $st === 303 && $st2 === 303 && $h2['location'] === '/login');

$log = (string)@file_get_contents($storage . '/server.log');
check('no PHP warnings or errors on the server', !preg_match('/(Warning|Fatal|Deprecated|Notice):/i', $log), $log);

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
