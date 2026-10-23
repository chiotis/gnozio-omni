<?php

declare(strict_types=1);

namespace Omni\Models;

use Omni\Core\ConnectCode;
use PDO;

/**
 * The sites of this Omni. A site is `pending` until it connects with its code, then `connected`; it is `disconnected`
 * when it leaves (it can connect again with a new code) and `removed` when the owner removes it (its reports answer 410).
 */
final class Sites
{
    public function __construct(private PDO $db)
    {
    }

    /** Adds a site that waits for its first connection. @return int the new id */
    public function create(string $label): int
    {
        $stmt = $this->db->prepare('INSERT INTO sites (public_id, label, status, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute(['s_' . bin2hex(random_bytes(6)), trim($label), 'pending', gmdate('c')]);
        return (int)$this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM sites WHERE id = ? AND status != 'removed'");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Any site by the id it signs with, removed ones too (they are told so). @return array<string, mixed>|null */
    public function findByPublicId(string $publicId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sites WHERE public_id = ?');
        $stmt->execute([$publicId]);
        return $stmt->fetch() ?: null;
    }

    /** Every site that is not removed, by name. @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->query("SELECT * FROM sites WHERE status != 'removed' ORDER BY COALESCE(NULLIF(label, ''), NULLIF(name, ''), url) COLLATE NOCASE")->fetchAll();
    }

    /** A new connection code for a site; any code it had that was not used stops working. @return array{code: string, expires_at: string} */
    public function newCode(int $siteId, ?int $now = null): array
    {
        $now ??= time();
        $this->db->prepare('DELETE FROM connect_codes WHERE site_id = ? AND used_at IS NULL')->execute([$siteId]);
        $code = ConnectCode::generate();
        $expires = gmdate('c', $now + ConnectCode::LIFETIME);
        $this->db->prepare('INSERT INTO connect_codes (site_id, code_hash, created_at, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$siteId, ConnectCode::hash($code), gmdate('c', $now), $expires]);
        return ['code' => $code, 'expires_at' => $expires];
    }

    /** When the site's code that was not used yet expires, or null. */
    public function openCodeExpiry(int $siteId, ?int $now = null): ?string
    {
        $stmt = $this->db->prepare('SELECT expires_at FROM connect_codes WHERE site_id = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$siteId, gmdate('c', $now ?? time())]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }

    /**
     * Uses a code: the site it belongs to becomes connected with the given secret (sealed) and what it said about
     * itself. Returns the site, or null when the code is unknown, used or expired, or its site was removed.
     *
     * @param array{name: string, url: string, admin_url: string, version: string} $site
     * @return array<string, mixed>|null
     */
    public function connect(string $code, array $site, string $sealedSecret, ?int $now = null): ?array
    {
        if (ConnectCode::normalize($code) === '') {
            return null;
        }
        $now ??= time();
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->db->prepare(
                "SELECT c.id AS code_id, s.id AS site_id FROM connect_codes c JOIN sites s ON s.id = c.site_id
                 WHERE c.code_hash = ? AND c.used_at IS NULL AND c.expires_at > ? AND s.status != 'removed'"
            );
            $stmt->execute([ConnectCode::hash($code), gmdate('c', $now)]);
            $row = $stmt->fetch();
            if (!$row) {
                $this->db->exec('ROLLBACK');
                return null;
            }
            $this->db->prepare('UPDATE connect_codes SET used_at = ? WHERE id = ?')->execute([gmdate('c', $now), $row['code_id']]);
            $this->db->prepare(
                "UPDATE sites SET status = 'connected', secret_enc = ?, name = ?, url = ?, admin_url = ?, version = ?, connected_at = ?, disconnected_at = NULL WHERE id = ?"
            )->execute([$sealedSecret, $site['name'], $site['url'], $site['admin_url'], $site['version'], gmdate('c', $now), $row['site_id']]);
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
        return $this->find((int)$row['site_id']);
    }

    /**
     * Keeps a report as the site's latest and in its history (the newest $keep), and what it says about the site.
     *
     * @param array<string, mixed> $report
     */
    public function recordReport(int $siteId, array $report, int $keep, ?int $now = null): void
    {
        $json = (string)json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $at = gmdate('c', $now ?? time());
        $site = is_array($report['site'] ?? null) ? $report['site'] : [];
        $faros = is_array($report['faros'] ?? null) ? $report['faros'] : [];
        $this->db->prepare('UPDATE sites SET latest_report = ?, last_report_at = ?, name = ?, url = ?, admin_url = ?, version = ? WHERE id = ?')->execute([
            $json,
            $at,
            self::text($site['name'] ?? '', 200),
            self::url($site['url'] ?? ''),
            self::url($site['admin_url'] ?? ''),
            self::text($faros['version'] ?? '', 40),
            $siteId,
        ]);
        $this->db->prepare('INSERT INTO reports (site_id, received_at, report) VALUES (?, ?, ?)')->execute([$siteId, $at, $json]);
        // How the last command went, when the report says so for the command Omni sent.
        $command = is_array($report['command'] ?? null) ? $report['command'] : null;
        if ($command !== null && is_string($command['id'] ?? null)) {
            $state = (string)($command['state'] ?? '');
            $this->db->prepare('UPDATE sites SET command_state = ?, command_message = ?, command_at = ? WHERE id = ? AND command_id = ?')->execute([
                in_array($state, ['accepted', 'running', 'installed', 'unverified', 'rolled_back', 'up_to_date', 'failed'], true) ? $state : 'failed',
                self::text($command['message'] ?? '', 500),
                $at,
                $siteId,
                $command['id'],
            ]);
        }
        $this->db->prepare('DELETE FROM reports WHERE site_id = ? AND id NOT IN (SELECT id FROM reports WHERE site_id = ? ORDER BY id DESC LIMIT ?)')
            ->execute([$siteId, $siteId, max(1, $keep)]);
    }

    /** Keeps the command Omni sent the site and where it stands. */
    public function setCommand(int $siteId, string $commandId, string $state, string $message, ?int $now = null): void
    {
        $this->db->prepare('UPDATE sites SET command_id = ?, command_state = ?, command_message = ?, command_at = ? WHERE id = ?')
            ->execute([$commandId, $state, self::text($message, 500), gmdate('c', $now ?? time()), $siteId]);
    }

    public function countReports(int $siteId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM reports WHERE site_id = ?');
        $stmt->execute([$siteId]);
        return (int)$stmt->fetchColumn();
    }

    /** The site left: it keeps its history and can connect again with a new code. */
    public function disconnect(int $siteId, ?int $now = null): void
    {
        $this->db->prepare("UPDATE sites SET status = 'disconnected', secret_enc = NULL, disconnected_at = ? WHERE id = ?")->execute([gmdate('c', $now ?? time()), $siteId]);
    }

    /** The owner removed the site: it no longer shows, its codes stop working, and its next report is told so (410). */
    public function remove(int $siteId): void
    {
        $this->db->prepare("UPDATE sites SET status = 'removed', secret_enc = NULL WHERE id = ?")->execute([$siteId]);
        $this->db->prepare('DELETE FROM connect_codes WHERE site_id = ?')->execute([$siteId]);
        $this->db->prepare('DELETE FROM reports WHERE site_id = ?')->execute([$siteId]);
    }

    public function rename(int $siteId, string $label): void
    {
        $this->db->prepare('UPDATE sites SET label = ? WHERE id = ?')->execute([self::text($label, 120), $siteId]);
    }

    /** Records a nonce; false when the site already used it in the last ten minutes (a replay). */
    public function useNonce(int $siteId, string $nonce, ?int $now = null): bool
    {
        $now ??= time();
        $this->db->prepare('DELETE FROM nonces WHERE seen_at < ?')->execute([$now - 600]);
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO nonces (site_id, nonce, seen_at) VALUES (?, ?, ?)');
        $stmt->execute([$siteId, $nonce, $now]);
        return $stmt->rowCount() === 1;
    }

    /** Text from a site, on one line and not longer than $max characters. */
    public static function text(mixed $value, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', is_scalar($value) ? (string)$value : '') ?? '');
        return mb_substr($text, 0, $max);
    }

    /** An address from a site, only when it is http or https (it becomes a link). */
    public static function url(mixed $value): string
    {
        $url = self::text($value, 500);
        return preg_match('#^https?://[^\s"<>]+$#i', $url) ? $url : '';
    }
}
