<?php

declare(strict_types=1);

namespace Omni\Core;

use Omni\Models\Settings;

/**
 * The latest GnozioCMS release, read by Omni itself from release.json of the GnozioCMS repository, so a site that has
 * not checked for updates lately still shows as behind. Kept in the settings and read again after CHECK_EVERY.
 * When GitHub cannot be read, the last version found stays in use.
 */
final class GnozioRelease
{
    public const CHECK_EVERY = 3600;
    public const CHECK_EVERY_FAILED = 900;

    /** @param callable(string, int): array{status: int, body: string, error: string} $fetch GET a URL (redirects followed), with a timeout */
    public function __construct(private Settings $settings, private string $url, private $fetch, private ?\Closure $clock = null)
    {
    }

    /** The latest version, or null when it was never read. */
    public function latest(bool $force = false): ?string
    {
        $now = $this->clock !== null ? ($this->clock)() : time();
        $kept = json_decode($this->settings->get('gnozio_release'), true);
        $kept = is_array($kept) && ($kept['url'] ?? '') === $this->url ? $kept : null;
        $version = $kept !== null && is_string($kept['version'] ?? null) && $kept['version'] !== '' ? $kept['version'] : null;
        $age = $kept !== null ? $now - (int)($kept['checked_at'] ?? 0) : PHP_INT_MAX;
        $ttl = ($kept['ok'] ?? false) ? self::CHECK_EVERY : self::CHECK_EVERY_FAILED;
        if (!$force && $kept !== null && $age >= 0 && $age < $ttl) {
            return $version;
        }

        $answer = ($this->fetch)($this->url, 5);
        $data = $answer['status'] === 200 ? json_decode($answer['body'], true) : null;
        $found = is_array($data) && is_string($data['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $data['version']) ? $data['version'] : null;
        $this->settings->set('gnozio_release', (string)json_encode([
            'url' => $this->url,
            'checked_at' => $now,
            'ok' => $found !== null,
            'version' => $found ?? $version,
        ]));
        return $found ?? $version;
    }
}
