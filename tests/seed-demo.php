<?php
/*
 * Fills an empty storage folder with a demo: the owner and five sites in different states, to look at the screens.
 *   OMNI_STORAGE=/tmp/omni-demo php tests/seed-demo.php
 * Then serve with the same OMNI_STORAGE and sign in as demo@omni.test with the password "demo password 123".
 * Never run it against the storage of a real Omni (it refuses when an owner exists).
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
define('SYSTEM_PATH', ROOT_PATH . '/system');
define('STORAGE_PATH', getenv('OMNI_STORAGE') ?: exit("Set OMNI_STORAGE to an empty folder.\n"));
require SYSTEM_PATH . '/Core/autoload.php';
require SYSTEM_PATH . '/Core/helpers.php';

use Omni\Core\{Database, SecretBox};
use Omni\Models\{Owners, Settings, Sites};

$db = Database::open(STORAGE_PATH . '/db/omni.sqlite');
$owners = new Owners($db);
if ($owners->exists()) {
    exit("This storage already has an owner; nothing was changed.\n");
}
$owners->createFirst('Demo Owner', 'demo@omni.test', 'demo password 123');
(new Settings($db))->set('timezone', 'Europe/Athens');
$box = new SecretBox(STORAGE_PATH . '/key');
$sites = new Sites($db);
$now = time();
$iso = static fn(int $t): string => gmdate('Y-m-d\TH:i:s\Z', $t);

$report = static fn(array $over) => array_replace_recursive([
    'protocol' => 1, 'sent_at' => $iso($now),
    'site' => ['name' => '', 'url' => '', 'admin_url' => ''],
    'faros' => ['version' => '0.1.101', 'commit' => '05aa250'],
    'storage' => ['used' => 420 * 1048576, 'limit' => 2147483648, 'disk_free' => 18253611008, 'percent_of_limit' => 21, 'level' => 'ok', 'full' => false, 'parts' => ['uploads' => 380 * 1048576, 'content' => 6 * 1048576, 'system' => 34 * 1048576], 'measured_at' => $iso($now - 3600)],
    'backups' => ['scheduled' => true, 'schedule' => 'daily', 'remote' => true, 'keep_local' => false, 'last' => ['at' => $iso($now - 5 * 3600), 'status' => 'success', 'size' => 402653184, 'message' => ''], 'last_success_at' => $iso($now - 5 * 3600), 'failed_last_7_days' => 0, 'cron' => ['state' => 'fresh', 'last_seen' => $iso($now - 5 * 3600), 'after_visit' => true]],
    'analytics' => ['enabled' => true, 'mode' => 'platform'],
    'updates' => ['current' => '0.1.101', 'latest' => '0.1.101', 'has_update' => false, 'checked_at' => $iso($now - 7200), 'source_status' => 'ok'],
    'system' => [
        'summary' => ['label' => 'Healthy', 'status' => 'ok', 'detail' => 'All checks passing'],
        'checks' => [
            ['label' => 'PHP', 'value' => '8.3.12', 'status' => 'ok'], ['label' => 'SQLite', 'value' => 'available', 'status' => 'ok'],
            ['label' => 'Content directory', 'value' => 'writable', 'status' => 'ok'], ['label' => 'Email provider', 'value' => 'SMTP', 'status' => 'ok'],
            ['label' => 'Disk free', 'value' => '17 GB', 'status' => 'ok'], ['label' => 'Cron job', 'value' => 'running', 'status' => 'ok'],
        ],
        'extensions' => [['name' => 'pdo_sqlite', 'loaded' => true, 'status' => 'ok'], ['name' => 'zip', 'loaded' => true, 'status' => 'ok'], ['name' => 'curl', 'loaded' => true, 'status' => 'ok'], ['name' => 'intl', 'loaded' => false, 'status' => 'warning']],
        'environment' => ['PHP' => '8.3.12 (fpm-fcgi)', 'SQLite' => '3.46.1', 'GnozioCMS' => '0.1.101 @ 05aa250', 'Memory limit' => '256M', 'HTTPS' => 'yes'],
    ],
], $over);

$demo = [
    ['Doxiadis Associates', 'https://doxiadis.test', [], $now - 1800],
    ['Aegina Music Festival', 'https://festival.test', ['updates' => ['latest' => '0.2.0', 'has_update' => true], 'analytics' => ['enabled' => true, 'mode' => 'custom']], $now - 4000],
    ['Diazoma', 'https://diazoma.test', ['backups' => ['last' => ['status' => 'failed', 'message' => 'Remote storage refused the upload.', 'size' => 0, 'at' => $iso($now - 3 * 3600)], 'last_success_at' => $iso($now - 4 * 86400), 'failed_last_7_days' => 3], 'storage' => ['used' => 1950 * 1048576, 'percent_of_limit' => 95, 'level' => 'danger'], 'system' => ['summary' => ['label' => 'Needs attention', 'status' => 'error', 'detail' => '1 critical check']]], $now - 3000],
    ['Kipseli', 'https://kipseli.test', ['analytics' => ['enabled' => false, 'mode' => 'off'], 'system' => ['summary' => ['label' => 'Warnings', 'status' => 'warning', 'detail' => '1 check to review'], 'checks' => [['label' => 'Email provider', 'value' => 'not configured', 'status' => 'warning']]]], $now - 60 * 3600],
];
foreach ($demo as [$name, $url, $over, $heard]) {
    $id = $sites->create($name);
    $code = $sites->newCode($id, $heard - 60);
    $sites->connect($code['code'], ['name' => $name, 'url' => $url, 'admin_url' => $url . '/admin', 'version' => '0.1.101'], $box->encrypt(SecretBox::newSecret()), $heard - 60);
    $sites->recordReport($id, $report(['site' => ['name' => $name, 'url' => $url, 'admin_url' => $url . '/admin']] + $over), 120, $heard);
}
$pending = $sites->create('Rural Greece');
$sites->newCode($pending, $now);
echo "Demo ready: sign in as demo@omni.test.\n";
