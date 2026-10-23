<?php

declare(strict_types=1);

namespace Omni\Core;

use Omni\Models\Sites;

/**
 * Asks sites to install the latest release (the `update` command of the protocol). The request is signed with the site's
 * own secret; the site answers at once and reports how it went, which Sites::recordReport() takes in.
 */
final class Commands
{
    /** A command with no outcome after this long is taken as lost, and the site can be asked again. */
    public const IN_FLIGHT = 1800;

    /**
     * @param callable(string, string, array<int, string>, string, int): array{status: int, body: string, error: string} $http
     * @param (callable(): int)|null $clock
     */
    public function __construct(private Sites $sites, private SecretBox $secrets, private $http, private $clock = null)
    {
    }

    /** Whether a command sent to the site is still waiting for its outcome. @param array<string, mixed> $site */
    public static function inFlight(array $site, int $now): bool
    {
        return in_array($site['command_state'] ?? '', ['sending', 'accepted', 'running'], true)
            && $now - (int)strtotime((string)($site['command_at'] ?? '')) < self::IN_FLIGHT;
    }

    /** Whether the site can be asked to update now: connected, its last report says a newer release exists, nothing in flight. @param array<string, mixed> $site */
    public static function canUpdate(array $site, int $now): bool
    {
        $report = is_string($site['latest_report'] ?? null) ? json_decode($site['latest_report'], true) : null;
        return ($site['status'] ?? '') === 'connected'
            && ($site['url'] ?? '') !== ''
            && is_array($report) && ($report['updates']['has_update'] ?? null) === true
            && !self::inFlight($site, $now);
    }

    /**
     * Sends `update` to a site and keeps what it answered.
     *
     * @param array<string, mixed> $site a row of `sites`
     * @return array{ok: bool, message: string}
     */
    public function update(array $site): array
    {
        $now = $this->clock !== null ? ($this->clock)() : time();
        $name = (string)($site['label'] ?: ($site['name'] ?: $site['url']));
        $secret = is_string($site['secret_enc'] ?? null) ? $this->secrets->decrypt($site['secret_enc']) : null;
        if (($site['status'] ?? '') !== 'connected' || $secret === null || ($site['url'] ?? '') === '') {
            return ['ok' => false, 'message' => $name . ' is not connected.'];
        }
        $id = 'c_' . bin2hex(random_bytes(8));
        $body = (string)json_encode(['protocol' => 1, 'command' => 'update', 'command_id' => $id]);
        $url = rtrim((string)$site['url'], '/') . '/_omni/command';
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '/_omni/command');
        $time = (string)$now;
        $nonce = bin2hex(random_bytes(16));
        $this->sites->setCommand((int)$site['id'], $id, 'sending', '', $now);
        $answer = ($this->http)('POST', $url, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Omni-Site: ' . $site['public_id'],
            'X-Omni-Timestamp: ' . $time,
            'X-Omni-Nonce: ' . $nonce,
            'X-Omni-Signature: ' . Signature::sign($secret, 'POST', $path, $time, $nonce, $body),
        ], $body, 15);
        $data = json_decode($answer['body'], true);
        $data = is_array($data) ? $data : [];

        if ($answer['status'] === 202 && ($data['ok'] ?? false) === true) {
            $this->sites->setCommand((int)$site['id'], $id, 'accepted', 'Asked to update; waiting for the site to report.', $now);
            return ['ok' => true, 'message' => $name . ' is updating.'];
        }
        $message = match (true) {
            $answer['status'] === 0 => 'The site could not be reached: ' . $answer['error'] . '.',
            $answer['status'] === 404 => 'The site does not take commands from Omni yet. Update it once from its own admin (GnozioCMS 0.1.177 or later).',
            ($data['error'] ?? '') === 'not_allowed' => 'The site does not let Omni install updates (Admin > Omni on the site).',
            ($data['error'] ?? '') === 'busy' => 'The site is already updating.',
            in_array($data['error'] ?? '', ['unknown_site', 'invalid_signature'], true) => 'The site no longer recognises this Omni (' . $data['error'] . '). Connect it again with a new code.',
            ($data['error'] ?? '') === 'stale_timestamp' => 'The site refused: the clocks of Omni and the site are more than five minutes apart.',
            default => 'The site refused (HTTP ' . $answer['status'] . (($data['error'] ?? '') !== '' ? ', ' . $data['error'] : '') . ').',
        };
        $this->sites->setCommand((int)$site['id'], $id, 'refused', $message, $now);
        return ['ok' => false, 'message' => $name . ': ' . $message];
    }
}
