<?php
/*
 * A stand-in for a site, for tests/http.php: takes POST /_omni/command the way a site does (checks the signature with the
 * secret in the file $FAKE_SITE_SECRET_FILE) and records what it received in $FAKE_SITE_LOG, one JSON line each.
 * Answers by $FAKE_SITE_MODE in the file named there: accept (default), not_allowed, busy, 404.
 */
declare(strict_types=1);

$path = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string)file_get_contents('php://input');
$modeFile = (string)getenv('FAKE_SITE_MODE');
$mode = is_file($modeFile) ? trim((string)file_get_contents($modeFile)) : 'accept';
header('Content-Type: application/json');
if ($path !== '/_omni/command' || $mode === '404') {
    http_response_code(404);
    echo '{}';
    return;
}
$time = (string)($_SERVER['HTTP_X_OMNI_TIMESTAMP'] ?? '');
$nonce = (string)($_SERVER['HTTP_X_OMNI_NONCE'] ?? '');
$expected = 'v1=' . hash_hmac('sha256', implode("\n", ['POST', $path, $time, $nonce, hash('sha256', $body)]), trim((string)@file_get_contents((string)getenv('FAKE_SITE_SECRET_FILE'))));
$good = hash_equals($expected, (string)($_SERVER['HTTP_X_OMNI_SIGNATURE'] ?? '')) && abs(time() - (int)$time) <= 300;
file_put_contents((string)getenv('FAKE_SITE_LOG'), json_encode(['site' => $_SERVER['HTTP_X_OMNI_SITE'] ?? '', 'good' => $good, 'body' => json_decode($body, true)]) . "\n", FILE_APPEND);
if (!$good) {
    http_response_code(401);
    echo '{"ok":false,"error":"invalid_signature"}';
    return;
}
$data = json_decode($body, true);
if ($mode === 'not_allowed') { http_response_code(403); echo '{"ok":false,"error":"not_allowed"}'; return; }
if ($mode === 'busy') { http_response_code(409); echo '{"ok":false,"error":"busy"}'; return; }
http_response_code(202);
echo json_encode(['ok' => true, 'command_id' => $data['command_id'] ?? '', 'state' => 'accepted']);
