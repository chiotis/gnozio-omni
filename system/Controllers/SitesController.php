<?php

declare(strict_types=1);

namespace Omni\Controllers;

use Omni\Core\App;
use Omni\Core\Commands;
use Omni\Core\View;
use Omni\Models\SiteHealth;

/** The owner's screens: the list of sites, one site, adding a site and its connection codes. */
final class SitesController
{
    private const FILTERS = ['all' => 'All', 'attention' => 'Needs attention', 'updates' => 'Update available', 'waiting' => 'Not connected'];

    public function __construct(private App $app)
    {
    }

    public function index(): void
    {
        $owner = $this->app->requireOwner();
        // Looks for a new version of Omni at most every 12 hours (the header shows it from the next page).
        $this->app->updater()->status();
        $now = time();
        $every = (int)$this->app->config['report_every'];
        // The latest GnozioCMS, read by Omni at most every hour: a site whose own check is old still shows as behind.
        $latest = $this->app->gnozioRelease()->latest();
        $rows = [];
        foreach ($this->app->sites->all() as $site) {
            $rows[] = ['site' => $site, 'health' => SiteHealth::of($site, $now, $every, $latest)];
        }

        $counts = ['all' => count($rows), 'ok' => 0, 'attention' => 0, 'updates' => 0, 'waiting' => 0];
        foreach ($rows as $row) {
            $state = $row['health']['state'];
            $counts['ok'] += $state === 'ok' ? 1 : 0;
            $counts['attention'] += in_array($state, ['warning', 'error', 'silent'], true) ? 1 : 0;
            $counts['updates'] += $row['health']['behind'] !== null ? 1 : 0;
            $counts['waiting'] += in_array($state, ['pending', 'disconnected'], true) ? 1 : 0;
        }

        $show = (string)($_GET['show'] ?? 'all');
        $show = array_key_exists($show, self::FILTERS) ? $show : 'all';
        $rows = array_values(array_filter($rows, static fn(array $row): bool => match ($show) {
            'attention' => in_array($row['health']['state'], ['warning', 'error', 'silent'], true),
            'updates' => $row['health']['behind'] !== null,
            'waiting' => in_array($row['health']['state'], ['pending', 'disconnected'], true),
            default => true,
        }));

        $updatable = count(array_filter($rows, static fn(array $row): bool => Commands::canUpdate($row['site'], $now, $latest)));
        View::render('sites/index', [
            'updatable' => $updatable,
            'title' => 'Sites',
            'owner' => $owner,
            'rows' => $rows,
            'latest' => $latest,
            'counts' => $counts,
            'show' => $show,
            'filters' => self::FILTERS,
            'now' => $now,
        ]);
    }

    public function create(): void
    {
        $this->app->requireOwner();
        $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 120);
        if ($label === '') {
            flash('Give the site a name, so you can tell it apart until it connects.', 'error');
            redirect('/');
        }
        $id = $this->app->sites->create($label);
        $this->rememberCode($id, $this->app->sites->newCode($id));
        redirect('/sites/' . $id);
    }

    public function show(int $id): void
    {
        $owner = $this->app->requireOwner();
        $site = $this->app->sites->find($id);
        if ($site === null) {
            $this->notFound();
            return;
        }
        $code = null;
        if (($_SESSION['new_code']['site_id'] ?? null) === $id) {
            // Shown once: Omni keeps only its hash.
            $code = $_SESSION['new_code'];
            unset($_SESSION['new_code']);
        }
        $now = time();
        View::render('sites/show', [
            'title' => $site['label'] ?: ($site['name'] ?: 'Site'),
            'owner' => $owner,
            'site' => $site,
            'health' => SiteHealth::of($site, $now, (int)$this->app->config['report_every'], $latest = $this->app->gnozioRelease()->latest()),
            'latest' => $latest,
            'code' => $code,
            'open_code_expires' => $code === null ? $this->app->sites->openCodeExpiry($id, $now) : null,
            'reports_kept' => $this->app->sites->countReports($id),
            'omni_url' => $this->app->baseUrl(),
            'now' => $now,
        ]);
    }

    public function newCode(int $id): void
    {
        $this->app->requireOwner();
        if ($this->app->sites->find($id) === null) {
            $this->notFound();
            return;
        }
        $this->rememberCode($id, $this->app->sites->newCode($id));
        redirect('/sites/' . $id);
    }

    public function rename(int $id): void
    {
        $this->app->requireOwner();
        if ($this->app->sites->find($id) === null) {
            $this->notFound();
            return;
        }
        $this->app->sites->rename($id, (string)($_POST['label'] ?? ''));
        flash('Name saved.');
        redirect('/sites/' . $id);
    }

    public function remove(int $id): void
    {
        $this->app->requireOwner();
        $site = $this->app->sites->find($id);
        if ($site === null) {
            $this->notFound();
            return;
        }
        $this->app->sites->remove($id);
        flash('Removed ' . ($site['label'] ?: ($site['name'] ?: 'the site')) . '. It will be told so the next time it reports.');
        redirect('/');
    }

    /** Asks one site to install the latest release. */
    public function update(int $id): void
    {
        $this->app->requireOwner();
        $site = $this->app->sites->find($id);
        if ($site === null) {
            $this->notFound();
            return;
        }
        if (!Commands::canUpdate($site, time(), $this->app->gnozioRelease()->latest())) {
            flash('There is nothing to update on this site now.', 'info');
            redirect('/sites/' . $id);
        }
        $result = $this->app->commands()->update($site);
        flash($result['message'], $result['ok'] ? 'success' : 'error');
        redirect('/sites/' . $id);
    }

    /** Asks every site that has a newer release to install it, one after the other. */
    public function updateAll(): void
    {
        $this->app->requireOwner();
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $asked = 0;
        $refused = [];
        $latest = $this->app->gnozioRelease()->latest();
        foreach ($this->app->sites->all() as $site) {
            if (!Commands::canUpdate($site, time(), $latest)) {
                continue;
            }
            $result = $this->app->commands()->update($site);
            $result['ok'] ? $asked++ : $refused[] = $result['message'];
        }
        if ($asked === 0 && $refused === []) {
            flash('No site has an update to install.', 'info');
        } else {
            flash(($asked > 0 ? $asked . ($asked === 1 ? ' site is' : ' sites are') . ' updating.' : '') . ($refused !== [] ? ' ' . implode(' ', $refused) : ''), $refused === [] ? 'success' : 'warning');
        }
        redirect('/');
    }

    /** @param array{code: string, expires_at: string} $code */
    private function rememberCode(int $siteId, array $code): void
    {
        $_SESSION['new_code'] = ['site_id' => $siteId] + $code;
    }

    private function notFound(): void
    {
        http_response_code(404);
        View::render('errors/message', ['title' => 'Not found', 'message' => 'This site is not in Omni (it may have been removed).'], 'bare');
    }
}
