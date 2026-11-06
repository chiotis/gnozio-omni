<?php

declare(strict_types=1);

namespace Omni\Core;

use Omni\Controllers\ApiController;
use Omni\Controllers\AuthController;
use Omni\Controllers\SitesController;
use Omni\Controllers\UpdatesController;
use Omni\Models\Attempts;
use Omni\Models\Owners;
use Omni\Models\Settings;
use Omni\Models\Sites;
use PDO;

/** Wires the parts together and answers one request: the API for sites under /api/v1, the owner's screens elsewhere. */
final class App
{
    public PDO $db;
    public Owners $owners;
    public Sites $sites;
    public Settings $settings;
    public Attempts $attempts;
    public SecretBox $secrets;
    private ?Updater $updater = null;
    private ?GnozioRelease $gnozio = null;

    /** @param array<string, mixed> $config */
    public function __construct(public array $config)
    {
        $this->db = Database::open((string)$config['db_path']);
        $this->owners = new Owners($this->db);
        $this->sites = new Sites($this->db);
        $this->settings = new Settings($this->db);
        $this->attempts = new Attempts($this->db);
        $this->secrets = new SecretBox((string)$config['key_path']);
    }

    public function run(): void
    {
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = '/' . trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');

        if (str_starts_with($path, '/api/')) {
            $api = new ApiController($this);
            $router = new Router();
            $router->get('/api/v1/ping', fn() => $api->ping());
            $router->post('/api/v1/connect', fn() => $api->connect());
            $router->post('/api/v1/report', fn() => $api->report($path));
            $router->post('/api/v1/disconnect', fn() => $api->disconnect($path));
            if (!$router->dispatch($method, $path)) {
                ApiController::error(404, 'not_found', 'There is nothing at this address.');
            }
            return;
        }

        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
        $this->startSession();
        date_default_timezone_set($this->settings->get('timezone', 'UTC') ?: 'UTC');
        View::share('update_available', $this->updater()->knownUpdate());

        if ($method === 'POST' && !csrf_valid()) {
            http_response_code(403);
            View::render('errors/message', ['title' => 'Expired form', 'message' => 'The form was open too long or came from elsewhere. Go back, reload the page and try again.'], 'bare');
            return;
        }

        $auth = new AuthController($this);
        $sites = new SitesController($this);
        $router = new Router();
        $router->get('/setup', fn() => $auth->setupForm());
        $router->post('/setup', fn() => $auth->setup());
        $router->get('/login', fn() => $auth->loginForm());
        $router->post('/login', fn() => $auth->login());
        $router->post('/logout', fn() => $auth->logout());
        $router->get('/', fn() => $sites->index());
        $router->post('/sites', fn() => $sites->create());
        $router->get('/sites/{id}', fn(string $id) => $sites->show((int)$id));
        $router->post('/sites/{id}/code', fn(string $id) => $sites->newCode((int)$id));
        $router->post('/sites/{id}/rename', fn(string $id) => $sites->rename((int)$id));
        $router->post('/sites/{id}/remove', fn(string $id) => $sites->remove((int)$id));
        $router->post('/sites/{id}/update', fn(string $id) => $sites->update((int)$id));
        $router->post('/sites/update-all', fn() => $sites->updateAll());
        $updates = new UpdatesController($this);
        $router->get('/updates', fn() => $updates->index());
        $router->post('/updates/check', fn() => $updates->check());
        $router->post('/updates/install', fn() => $updates->install());
        $router->post('/updates/undo', fn() => $updates->undo());
        if (!$router->dispatch($method, $path)) {
            http_response_code(404);
            View::render('errors/message', ['title' => 'Not found', 'message' => 'There is nothing at this address.'], 'bare');
        }
    }

    /** The signed-in owner, or a redirect to setup (no owner yet) or to sign-in. @return array<string, mixed> */
    public function requireOwner(): array
    {
        if (!$this->owners->exists()) {
            redirect('/setup');
        }
        $owner = isset($_SESSION['owner_id']) ? $this->owners->find((int)$_SESSION['owner_id']) : null;
        if ($owner === null) {
            redirect('/login');
        }
        return $owner;
    }

    public function signIn(int $ownerId): void
    {
        session_regenerate_id(true);
        $_SESSION['owner_id'] = $ownerId;
        $this->owners->touchLogin($ownerId);
    }

    public function commands(): Commands
    {
        return new Commands($this->sites, $this->secrets, [Http::class, 'request']);
    }

    public function gnozioRelease(): GnozioRelease
    {
        return $this->gnozio ??= new GnozioRelease($this->settings, (string)$this->config['gnozio_release_url'], [Updater::class, 'fetch']);
    }

    public function updater(): Updater
    {
        return $this->updater ??= new Updater(
            ROOT_PATH,
            STORAGE_PATH,
            (string)$this->config['db_path'],
            $this->settings,
            (string)$this->config['release_url'],
            [Updater::class, 'fetch'],
            [Updater::class, 'downloadTo'],
            fn(): array => $this->checkItself(),
            (bool)$this->config['allow_local_release']
        );
    }

    /**
     * After an update: the code now in place answers the API and the sign-in page, in requests of their own, without a PHP error.
     *
     * @return array{ok: bool, error: string, reachable: bool}
     */
    private function checkItself(): array
    {
        $ping = Updater::fetch($this->baseUrl() . '/api/v1/ping', 15);
        if ($ping['status'] === 0) {
            return ['ok' => false, 'error' => 'Omni could not reach itself: ' . $ping['error'], 'reachable' => false];
        }
        $data = json_decode($ping['body'], true);
        if ($ping['status'] !== 200 || !is_array($data) || ($data['omni'] ?? null) !== true) {
            return ['ok' => false, 'error' => 'its API did not answer as Omni (HTTP ' . $ping['status'] . ')', 'reachable' => true];
        }
        $login = Updater::fetch($this->baseUrl() . '/login', 15, false);
        if (!in_array($login['status'], [200, 303], true) || preg_match('/(Fatal error|Parse error|Uncaught )/', $login['body'])) {
            return ['ok' => false, 'error' => 'the sign-in page answered HTTP ' . $login['status'], 'reachable' => true];
        }
        return ['ok' => true, 'error' => '', 'reachable' => true];
    }

    public function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** This Omni's own address, as a site enters it. */
    public function baseUrl(): string
    {
        return ($this->isHttps() ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    public function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private function startSession(): void
    {
        ini_set('session.use_strict_mode', '1');
        session_name('omni_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $this->isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}
