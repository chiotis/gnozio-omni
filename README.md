# Omni

One place to watch many [GnozioCMS](https://github.com/chiotis/gnoziocms) sites: their health checks, storage, backups, analytics and updates, with a link to each site's admin. Only the owner signs in.

The public Omni is `https://omni.faroscms.com`. Anyone can run their own and connect their own sites to it: the Omni address is a setting of each site.

Sites talk to Omni with the protocol in GnozioCMS's `docs/omni-protocol.md`. In short:

- The site always calls Omni; Omni never calls the site.
- A site joins with a one-time connection code made in Omni, and gets a secret of its own.
- Every report after that is signed with the secret (HMAC-SHA256, with a timestamp and a nonce).

## Running it

Omni needs PHP 8.1 or later with `pdo_sqlite` and `sodium`. It has no Composer and no build step on the server: upload and run.

1. Point the web server's document root to `public/`. Where that cannot be set, the `.htaccess` at the root sends everything there (Apache).
   - **Nginx:** `root /path/to/omni/public; location / { try_files $uri /index.php?$query_string; }`
2. Make `storage/` writable by PHP. It holds the database (`storage/db/omni.sqlite`) and the key that seals the sites' secrets (`storage/key`). Back both up together: without the key, the connected sites have to connect again.
3. Open the address. The first page asks for the owner's account (name, email, password, time zone). It closes once the account exists.
4. **Add site** gives a connection code. In the site, a super admin enters the Omni address and the code at Admin > Omni.

Omni is meant to run at the root of its own domain (`https://omni.example.com`). Serve it over HTTPS: sites refuse an Omni address that is not HTTPS, except for local ones (`localhost`, `*.test`).

To develop locally:

```bash
php -S 127.0.0.1:8121 -t public public/index.php
```

## What is where

| Folder | |
|---|---|
| `public/` | The only folder the web serves: `index.php`, the compiled CSS, the JavaScript, the icon |
| `system/Core/` | The app, router, database and its tables, views, signatures, connection codes, sealed secrets, the updater |
| `system/Models/` | The owner, the sites (codes, reports, nonces), attempts (rate limits), settings, and `SiteHealth` (what the owner is shown about a site) |
| `system/Controllers/` | Setup and sign-in, the owner's screens, the API |
| `system/Views/` | Plain PHP views |
| `storage/` | The database, the key and the update work folders (not in git) |
| `scripts/build-release.php` | Builds the release package and `release.json` |

### The API

| Request | What it does |
|---|---|
| `GET /api/v1/ping` | Says it is an Omni and which protocols it speaks |
| `POST /api/v1/connect` | Takes a code and gives the site its id and secret. Limited to 10 attempts per address per hour |
| `POST /api/v1/report` | A signed report. Omni keeps the latest one and the last 120 per site |
| `POST /api/v1/disconnect` | The site leaves. Its history stays |

Omni itself calls the sites at one address only: `POST <site>/_omni/command`, to ask for an update (see above).

A request is refused when:

- its signature does not match, or it comes from an unknown site,
- its timestamp is more than 5 minutes off,
- its nonce was already used,
- it is larger than 64 KB.

A site the owner removed is told so with `410`.

### Security

- **The owner's account.** The password is hashed. Sign-in is limited to 8 failures per address in 15 minutes. The session cookie is `HttpOnly` and `SameSite=Lax`, and `Secure` over HTTPS.
- **Forms.** Every form carries a CSRF token.
- **Pages.** They are sent with a strict Content Security Policy (no inline scripts) and cannot be framed.
- **Secrets.** Each site's secret is sealed with libsodium in the database, under the key in `storage/key`.
- **Connection codes.** Only their hash is kept.
- **What a site sends.** It is shown escaped. Addresses become links only when they are http(s).

## Updating the sites from Omni

When a site's report says a newer GnozioCMS exists, the list shows **Update all (N)** and each such site has an update button (and **Update to X** on its page). Omni then sends that site a signed `update` command (the protocol's [Commands](https://github.com/chiotis/gnoziocms/blob/main/docs/omni-protocol.md)). The site:

1. answers at once that it accepted, so Omni never waits for the update;
2. makes its verified pre-update backup;
3. installs the latest official release and checks it, taking it back by itself if it does not work;
4. reports at once how it went, which Omni shows on the site's page.

A command names no version and carries no code: the site installs from its own update source and checks the package's SHA-256, so even a stolen Omni could only make sites install the official release. A site owner can switch this off (Admin > Omni > *Allow Omni to install updates*), and Omni then says so in words. The site needs GnozioCMS 0.1.177 or later, and its address must be reachable from the Omni server.

A command that has not been answered after 30 minutes is taken as lost, and the site can be asked again. Each site is asked one after the other, so *Update all* returns when all have answered that they accepted (up to 15 seconds each).

## Updates

**Updates** (in the header) shows the version installed and the latest release on GitHub, with its notes. Omni looks for a new one at most every 12 hours, and the header marks it when there is one.

**Install** replaces the code, and only the code. `storage/` (the database, the key) is never in a package and never touched, so no backup is needed first. The steps:

1. Download the package of the release and check its size and SHA-256 against `release.json`.
2. Unpack it beside the live code (`storage/updates/`). A package may hold only `system/`, `public/assets/`, `public/index.php`, `public/.htaccess`, `.htaccess`, `VERSION`, `CHANGELOG.md` and `README.md`; anything else, a link, or a path that climbs out stops the update before anything changes.
3. Copy the database. The copy is used only if step 5 fails after the new code has changed the database.
4. Swap the code folders in with renames, keeping the old ones.
5. Ask the new code whether it works: its API and its sign-in page, in requests of their own. If it does not, the old code goes back by itself, and the database too if it was changed (on the next request).

**Undo** puts back the code the last update replaced (not the database, which has been used since). The old code of the last two updates is kept.

The folder Omni runs from, `system/` and `public/` must be writable by PHP for this. Where they are not, update with `git pull` or by uploading the files, as before. Either way, never upload `storage/`.

### Publishing a release

1. Raise `VERSION` and add its entry at the top of `CHANGELOG.md` (`## 0.1.2 — date — title`).
2. Push to `main`.

The Release workflow (`.github/workflows/release.yml`) then runs the tests, tags the version, and attaches `omni-<version>.zip` and `release.json` to the GitHub Release (built by `scripts/build-release.php`, the same bytes for the same version). Every Omni finds it at `https://github.com/chiotis/gnozio-omni/releases/latest/download/release.json`.

## Styles

Tailwind CSS 3.4, compiled into `public/assets/css/app.css` (committed, so the server needs nothing). After changing classes in a view:

```bash
npm install
npm run build:css
```

## Tests

```bash
tests/run.sh
```

- `tests/unit.php` covers signatures, codes, sealed secrets, the sites model, rate limits and `SiteHealth`.
- `tests/http.php` starts PHP's own server with a storage folder of its own and goes through setup, sign-in, adding a site, the whole API with every refusal, and the screens.
- `tests/update.php` updates a copy of Omni from releases published to a local stand-in for GitHub:
  - a good release: install, undo, install again;
  - one that breaks Omni after changing its database: taken back, database included;
  - one whose package does not match its SHA-256;
  - one that tries to write into `storage/`.

To look at the screens with sample data:

```bash
OMNI_STORAGE=/tmp/omni-demo php tests/seed-demo.php
OMNI_STORAGE=/tmp/omni-demo php -S 127.0.0.1:8121 -t public public/index.php
```

Then sign in with the demo account named in `tests/seed-demo.php`.
