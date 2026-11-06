<?php

declare(strict_types=1);

namespace Omni\Models;

/**
 * What the owner sees about a site, worked out from its latest report: one state (ok, warning, error, silent, pending,
 * disconnected), a chip for each area, and the reasons it needs attention.
 */
final class SiteHealth
{
    /** A site is silent after this long without a report, or twice its report interval if that is longer. */
    public const SILENT_AFTER = 172800;

    private const MODES = ['off' => 'Off', 'custom' => 'Own code', 'platform' => 'GnozioCMS'];
    private const INTERVALS = ['daily' => 86400, 'weekly' => 604800, 'monthly' => 2678400];

    /**
     * @param array<string, mixed> $site a row of `sites`
     * @return array{state: string, label: string, tone: string, silent: bool, last_heard: ?string, report: ?array<string, mixed>, chips: array<string, array{label: string, tone: string, title: string}>, issues: array<int, array{text: string, tone: string}>}
     */
    public static function of(array $site, int $now, int $reportEvery): array
    {
        $report = is_string($site['latest_report'] ?? null) ? json_decode($site['latest_report'], true) : null;
        $report = is_array($report) ? $report : null;
        $status = (string)($site['status'] ?? 'pending');
        $lastHeard = $site['last_report_at'] ?? null ?: ($site['connected_at'] ?? null);
        $heardAt = $lastHeard ? strtotime((string)$lastHeard) : false;
        $silent = $status === 'connected' && $heardAt !== false && $now - $heardAt > max(self::SILENT_AFTER, 2 * $reportEvery);

        $chips = $report !== null ? self::chips($report, $now) : [];
        $issues = $report !== null ? self::issues($report, $now) : [];
        if ($silent) {
            array_unshift($issues, ['text' => 'No report since ' . gmdate('Y-m-d H:i', (int)$heardAt) . ' UTC', 'tone' => 'red']);
        }
        $command = self::command($site, $now);
        if ($command !== null && $command['state'] === 'busy') {
            $chips['updates'] = ['label' => 'Updating…', 'tone' => 'blue', 'title' => $command['text']];
        } elseif ($command !== null && $command['tone'] !== '') {
            array_unshift($issues, ['text' => $command['text'], 'tone' => $command['tone']]);
        }

        if ($status === 'disconnected') {
            [$state, $label, $tone] = ['disconnected', 'Disconnected', 'slate'];
        } elseif ($status === 'pending') {
            [$state, $label, $tone] = ['pending', 'Waiting to connect', 'slate'];
        } elseif ($silent) {
            [$state, $label, $tone] = ['silent', 'Silent', 'red'];
        } elseif ($report === null) {
            [$state, $label, $tone] = ['pending', 'Waiting for a report', 'slate'];
        } else {
            $tones = array_column($issues, 'tone');
            [$state, $label, $tone] = in_array('red', $tones, true) ? ['error', 'Needs attention', 'red'] : (in_array('amber', $tones, true) ? ['warning', 'Warnings', 'amber'] : ['ok', 'Healthy', 'green']);
        }

        return ['state' => $state, 'label' => $label, 'tone' => $tone, 'silent' => $silent, 'last_heard' => $lastHeard ? (string)$lastHeard : null, 'report' => $report, 'chips' => $chips, 'issues' => $issues];
    }

    /**
     * The last update Omni asked for, while it runs and for a week after it ended badly.
     *
     * @param array<string, mixed> $site
     * @return array{state: string, text: string, tone: string}|null state is busy (still running) or done
     */
    public static function command(array $site, int $now): ?array
    {
        $state = (string)($site['command_state'] ?? '');
        $at = strtotime((string)($site['command_at'] ?? ''));
        if ($state === '' || $at === false) {
            return null;
        }
        $message = trim((string)($site['command_message'] ?? ''));
        if (in_array($state, ['sending', 'accepted', 'running'], true)) {
            return $now - $at < \Omni\Core\Commands::IN_FLIGHT
                ? ['state' => 'busy', 'text' => $message !== '' ? $message : 'Updating.', 'tone' => '']
                : ['state' => 'done', 'text' => 'Asked to update on ' . gmdate('Y-m-d H:i', $at) . ' UTC, but the site never said how it went.', 'tone' => 'amber'];
        }
        if ($now - $at > 7 * 86400) {
            return null;
        }
        return match ($state) {
            'installed', 'up_to_date' => ['state' => 'done', 'text' => $message, 'tone' => ''],
            'unverified' => ['state' => 'done', 'text' => 'Updated, but the site could not check itself: ' . $message, 'tone' => 'amber'],
            'rolled_back' => ['state' => 'done', 'text' => 'The update was taken back: ' . $message, 'tone' => 'red'],
            default => ['state' => 'done', 'text' => 'The update did not happen: ' . $message, 'tone' => 'red'],
        };
    }

    /** @param array<string, mixed> $r @return array<string, array{label: string, tone: string, title: string}> */
    private static function chips(array $r, int $now): array
    {
        $summary = (array)($r['system']['summary'] ?? []);
        $health = (string)($summary['status'] ?? '');
        $storage = (array)($r['storage'] ?? []);
        $backups = (array)($r['backups'] ?? []);
        $updates = (array)($r['updates'] ?? []);
        $analytics = (array)($r['analytics'] ?? []);

        $used = isset($storage['used']) ? (int)$storage['used'] : null;
        $limit = (int)($storage['limit'] ?? 0);
        $level = (string)($storage['level'] ?? 'ok');
        $storageChip = [
            'label' => bytes($used) . ($limit > 0 ? ' / ' . bytes($limit) : ''),
            'tone' => !empty($storage['full']) || $level === 'danger' ? 'red' : ($level === 'warn' ? 'amber' : 'green'),
            'title' => $limit > 0 ? ((int)($storage['percent_of_limit'] ?? 0)) . '% of the limit' : 'No storage limit',
        ];

        $last = is_array($backups['last'] ?? null) ? $backups['last'] : null;
        if ($last === null) {
            $backupChip = ['label' => !empty($backups['scheduled']) ? 'None yet' : 'Off', 'tone' => !empty($backups['scheduled']) ? 'amber' : 'slate', 'title' => 'No backup was ever run'];
        } else {
            $lastStatus = (string)($last['status'] ?? 'failed');
            $backupChip = [
                'label' => ucfirst($lastStatus) . ' · ' . ago(isset($last['at']) ? (string)$last['at'] : null, $now),
                'tone' => $lastStatus === 'success' ? 'green' : ($lastStatus === 'warning' ? 'amber' : 'red'),
                'title' => trim((string)($last['message'] ?? '')) ?: 'Last backup ' . local_time(isset($last['at']) ? (string)$last['at'] : null),
            ];
        }

        $current = (string)($updates['current'] ?? ($r['faros']['version'] ?? ''));
        $has = $updates['has_update'] ?? null;
        $updateChip = $has === true
            ? ['label' => $current . ' → ' . (string)($updates['latest'] ?? ''), 'tone' => 'red', 'title' => 'An update is available']
            : ['label' => $current !== '' ? $current : '—', 'tone' => $has === false ? 'green' : 'slate', 'title' => $has === false ? 'Up to date' : 'Not checked yet'];

        $mode = (string)($analytics['mode'] ?? 'off');
        $analyticsChip = ['label' => self::MODES[$mode] ?? $mode, 'tone' => !empty($analytics['enabled']) ? 'green' : 'slate', 'title' => !empty($analytics['enabled']) ? 'Visits are counted' : 'No tracking'];

        return [
            'health' => ['label' => (string)($summary['label'] ?? '—'), 'tone' => $health === 'ok' ? 'green' : ($health === 'warning' ? 'amber' : ($health === 'error' ? 'red' : 'slate')), 'title' => (string)($summary['detail'] ?? '')],
            'storage' => $storageChip,
            'backup' => $backupChip,
            'updates' => $updateChip,
            'analytics' => $analyticsChip,
        ];
    }

    /** @param array<string, mixed> $r @return array<int, array{text: string, tone: string}> */
    private static function issues(array $r, int $now): array
    {
        $issues = [];
        foreach ((array)($r['system']['checks'] ?? []) as $check) {
            $status = (string)($check['status'] ?? 'ok');
            if ($status === 'error' || $status === 'warning') {
                $issues[] = ['text' => (string)($check['label'] ?? '') . ': ' . (string)($check['value'] ?? ''), 'tone' => $status === 'error' ? 'red' : 'amber'];
            }
        }

        $storage = (array)($r['storage'] ?? []);
        if (!empty($storage['full'])) {
            $issues[] = ['text' => 'Storage is full', 'tone' => 'red'];
        } elseif (($storage['level'] ?? '') === 'danger') {
            $issues[] = ['text' => 'Storage is almost full (' . (int)($storage['percent_of_limit'] ?? 0) . '%)', 'tone' => 'red'];
        } elseif (($storage['level'] ?? '') === 'warn') {
            $issues[] = ['text' => 'Storage is filling up (' . (int)($storage['percent_of_limit'] ?? 0) . '%)', 'tone' => 'amber'];
        }

        $backups = (array)($r['backups'] ?? []);
        $last = is_array($backups['last'] ?? null) ? $backups['last'] : null;
        if ($last !== null && ($last['status'] ?? '') === 'failed') {
            $issues[] = ['text' => 'The last backup failed' . (trim((string)($last['message'] ?? '')) !== '' ? ': ' . trim((string)$last['message']) : ''), 'tone' => 'red'];
        } elseif ($last !== null && ($last['status'] ?? '') === 'warning') {
            $issues[] = ['text' => 'The last backup has a warning' . (trim((string)($last['message'] ?? '')) !== '' ? ': ' . trim((string)$last['message']) : ''), 'tone' => 'amber'];
        }
        if (!empty($backups['scheduled'])) {
            $interval = self::INTERVALS[(string)($backups['schedule'] ?? 'daily')] ?? 86400;
            $success = isset($backups['last_success_at']) ? strtotime((string)$backups['last_success_at']) : false;
            if ($success === false) {
                $issues[] = ['text' => 'Scheduled backups are on, but none has succeeded', 'tone' => 'amber'];
            } elseif ($now - $success > $interval + 86400) {
                $issues[] = ['text' => 'No successful backup since ' . gmdate('Y-m-d', $success), 'tone' => 'amber'];
            }
        }

        if (($r['updates']['has_update'] ?? null) === true) {
            $issues[] = ['text' => 'GnozioCMS ' . (string)($r['updates']['latest'] ?? '') . ' is available', 'tone' => 'blue'];
        }
        return $issues;
    }
}
