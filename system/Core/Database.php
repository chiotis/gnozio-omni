<?php

declare(strict_types=1);

namespace Omni\Core;

use PDO;

/** The SQLite database: the owner, the sites, their connection codes and reports. Made and brought up to date on open. */
final class Database
{
    public static function open(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        self::restoreIfScheduled($path);
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::migrate($pdo);
        return $pdo;
    }

    /**
     * An update that was taken back after its code had changed the database leaves the copy made before it; the next
     * request puts it back before anything opens the database (the request that took the update back still had it open).
     */
    public static function scheduleRestore(string $path, string $copy): void
    {
        file_put_contents($path . '.restore', $copy);
    }

    private static function restoreIfScheduled(string $path): void
    {
        if (!is_file($path . '.restore')) {
            return;
        }
        $copy = trim((string)file_get_contents($path . '.restore'));
        if ($copy !== '' && is_file($copy)) {
            @unlink($path . '-wal');
            @unlink($path . '-shm');
            copy($copy, $path . '.tmp') && rename($path . '.tmp', $path);
        }
        @unlink($path . '.restore');
    }

    private static function migrate(PDO $pdo): void
    {
        $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version < 1) {
            $pdo->exec(<<<'SQL'
                CREATE TABLE owners (
                    id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    email TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    last_login_at TEXT
                );
                CREATE TABLE settings (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );
                CREATE TABLE sites (
                    id INTEGER PRIMARY KEY,
                    public_id TEXT NOT NULL UNIQUE,
                    label TEXT NOT NULL DEFAULT '',
                    name TEXT NOT NULL DEFAULT '',
                    url TEXT NOT NULL DEFAULT '',
                    admin_url TEXT NOT NULL DEFAULT '',
                    version TEXT NOT NULL DEFAULT '',
                    status TEXT NOT NULL DEFAULT 'pending',
                    secret_enc TEXT,
                    created_at TEXT NOT NULL,
                    connected_at TEXT,
                    disconnected_at TEXT,
                    last_report_at TEXT,
                    latest_report TEXT
                );
                CREATE TABLE connect_codes (
                    id INTEGER PRIMARY KEY,
                    site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                    code_hash TEXT NOT NULL UNIQUE,
                    created_at TEXT NOT NULL,
                    expires_at TEXT NOT NULL,
                    used_at TEXT
                );
                CREATE TABLE reports (
                    id INTEGER PRIMARY KEY,
                    site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                    received_at TEXT NOT NULL,
                    report TEXT NOT NULL
                );
                CREATE INDEX reports_site ON reports (site_id, id);
                CREATE TABLE nonces (
                    site_id INTEGER NOT NULL,
                    nonce TEXT NOT NULL,
                    seen_at INTEGER NOT NULL,
                    PRIMARY KEY (site_id, nonce)
                );
                CREATE TABLE attempts (
                    kind TEXT NOT NULL,
                    key TEXT NOT NULL,
                    at INTEGER NOT NULL
                );
                CREATE INDEX attempts_lookup ON attempts (kind, key, at);
                PRAGMA user_version = 1;
                SQL);
        }
        if ($version < 2) {
            // The last command Omni sent a site (an update), and how it went.
            $pdo->exec(<<<'SQL'
                ALTER TABLE sites ADD COLUMN command_id TEXT;
                ALTER TABLE sites ADD COLUMN command_state TEXT;
                ALTER TABLE sites ADD COLUMN command_message TEXT;
                ALTER TABLE sites ADD COLUMN command_at TEXT;
                PRAGMA user_version = 2;
                SQL);
        }
    }
}
