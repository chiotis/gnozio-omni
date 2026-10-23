<?php

declare(strict_types=1);

namespace Omni\Controllers;

use Omni\Core\App;
use Omni\Core\Signature;
use Omni\Core\SecretBox;
use Omni\Models\Sites;

/** The four endpoints a GnozioCMS site calls (docs/omni-protocol.md in GnozioCMS). JSON in and out. */
final class ApiController
{
    public const PROTOCOLS = [1];
    public const MAX_BODY = 65536;
    private const CONNECT_MAX = 10;
    private const CONNECT_WINDOW = 3600;

    public function __construct(private App $app)
    {
    }

    public function ping(): void
    {
        self::json(200, ['omni' => true, 'name' => (string)$this->app->config['app_name'], 'protocols' => self::PROTOCOLS]);
    }

    public function connect(): void
    {
        $ip = $this->app->clientIp();
        $wait = $this->app->attempts->retryAfter('connect', $ip, self::CONNECT_MAX, self::CONNECT_WINDOW);
        if ($wait > 0) {
            header('Retry-After: ' . $wait);
            self::error(429, 'rate_limited', 'Too many connection attempts from this address.');
            return;
        }
        $this->app->attempts->record('connect', $ip);

        $data = $this->body();
        if ($data === null || !$this->protocolOk($data)) {
            return;
        }
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];
        $about = [
            'name' => Sites::text($site['name'] ?? '', 200),
            'url' => Sites::url($site['url'] ?? ''),
            'admin_url' => Sites::url($site['admin_url'] ?? ''),
            'version' => Sites::text($site['version'] ?? '', 40),
        ];
        if (!is_string($data['code'] ?? null) || $about['url'] === '') {
            self::error(400, 'invalid_request', 'A connection needs a code and the address of the site.');
            return;
        }

        $secret = SecretBox::newSecret();
        $connected = $this->app->sites->connect($data['code'], $about, $this->app->secrets->encrypt($secret));
        if ($connected === null) {
            self::error(403, 'invalid_code', 'The code is not known, was already used, or has expired. Make a new one in Omni.');
            return;
        }
        self::json(200, ['site_id' => (string)$connected['public_id'], 'secret' => $secret, 'report_every' => (int)$this->app->config['report_every']]);
    }

    public function report(string $path): void
    {
        $site = $this->authenticate($path, $raw);
        if ($site === null) {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || array_is_list($data) || !is_array($data['site'] ?? null)) {
            self::error(400, 'invalid_request', 'A report is a JSON object with at least `protocol` and `site`.');
            return;
        }
        if (!$this->protocolOk($data)) {
            return;
        }
        $this->app->sites->recordReport((int)$site['id'], $data, (int)$this->app->config['keep_reports']);
        self::json(200, ['ok' => true, 'report_every' => (int)$this->app->config['report_every']]);
    }

    public function disconnect(string $path): void
    {
        $site = $this->authenticate($path, $raw);
        if ($site === null) {
            return;
        }
        $this->app->sites->disconnect((int)$site['id']);
        self::json(200, ['ok' => true]);
    }

    /**
     * The site that signed this request, or null after answering with the error. $raw receives the body.
     *
     * @return array<string, mixed>|null
     */
    private function authenticate(string $path, ?string &$raw): ?array
    {
        $raw = $this->rawBody();
        if ($raw === null) {
            return null;
        }
        $publicId = (string)($_SERVER['HTTP_X_OMNI_SITE'] ?? '');
        $timestamp = (string)($_SERVER['HTTP_X_OMNI_TIMESTAMP'] ?? '');
        $nonce = (string)($_SERVER['HTTP_X_OMNI_NONCE'] ?? '');
        $signature = (string)($_SERVER['HTTP_X_OMNI_SIGNATURE'] ?? '');

        $site = $publicId !== '' ? $this->app->sites->findByPublicId($publicId) : null;
        if ($site !== null && $site['status'] === 'removed') {
            self::error(410, 'site_removed', 'The owner of this Omni removed this site.');
            return null;
        }
        if ($site === null || $site['status'] !== 'connected' || !is_string($site['secret_enc'] ?? null)) {
            self::error(401, 'unknown_site', 'This site is not connected to this Omni.');
            return null;
        }
        if (!Signature::fresh($timestamp, time())) {
            self::error(401, 'stale_timestamp', 'The timestamp is missing or too far from the time here.');
            return null;
        }
        if (!Signature::validNonce($nonce)) {
            self::error(400, 'invalid_request', 'The nonce must be 32 hex characters.');
            return null;
        }
        $secret = $this->app->secrets->decrypt((string)$site['secret_enc']);
        if ($secret === null || !Signature::matches($secret, $signature, 'POST', $path, $timestamp, $nonce, $raw)) {
            self::error(401, 'invalid_signature', 'The signature does not match.');
            return null;
        }
        if (!$this->app->sites->useNonce((int)$site['id'], $nonce)) {
            self::error(401, 'replayed_nonce', 'This request was already received.');
            return null;
        }
        return $site;
    }

    /** The body, or null after answering 413 when it is too large. */
    private function rawBody(): ?string
    {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY) {
            self::error(413, 'too_large', 'A request may be at most 64 KB.');
            return null;
        }
        $raw = (string)file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            self::error(413, 'too_large', 'A request may be at most 64 KB.');
            return null;
        }
        return $raw;
    }

    /** The body as a JSON object, or null after answering with the error. @return array<string, mixed>|null */
    private function body(): ?array
    {
        $raw = $this->rawBody();
        if ($raw === null) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || array_is_list($data)) {
            self::error(400, 'invalid_request', 'The body must be a JSON object.');
            return null;
        }
        return $data;
    }

    /** @param array<string, mixed> $data */
    private function protocolOk(array $data): bool
    {
        if (!in_array($data['protocol'] ?? null, self::PROTOCOLS, true)) {
            self::json(426, ['ok' => false, 'error' => 'unsupported_protocol', 'message' => 'This Omni does not speak that protocol version.', 'protocols' => self::PROTOCOLS]);
            return false;
        }
        return true;
    }

    public static function error(int $status, string $code, string $message): void
    {
        self::json($status, ['ok' => false, 'error' => $code, 'message' => $message]);
    }

    /** @param array<string, mixed> $data */
    public static function json(int $status, array $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
