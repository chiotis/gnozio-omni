# Changelog

## 0.1.2 — 2026-10-23 — Update the sites from Omni
- **Update all (N)** on the list, an update button on each row that has a newer GnozioCMS, and **Update to X** on the site's page. Omni sends the site a signed `update` command; the site accepts at once, makes its verified backup, installs the latest official release (taking it back by itself if it does not work) and reports how it went, which Omni shows on the site's page and on its list row (*Updating…*, then the outcome).
- **A command carries no version and no code.** The site installs from its own update source and checks the package, so Omni can only start the official update. The site's owner can switch it off; Omni says why a site refused (not allowed, busy, not answering, no longer recognising this Omni, clocks apart, too old to take commands).
- A command with no outcome after 30 minutes is taken as lost. A bad outcome (taken back, did not happen) stays among the site's *To look at* for a week.
- The texts now say GnozioCMS, the new name of the product the sites run.
- Needs GnozioCMS 0.1.177 or later on the site; the protocol's *Commands* section is in the GnozioCMS repository (`docs/omni-protocol.md`).
- **Tests:** the command's signing and path (also for a site in a folder), every answer a site can give and its wording, the outcome kept from a report (and one for a command Omni did not send ignored), and, with a stand-in for a site that checks the signature itself, the buttons, the page states, *Update all*, and that a stranger can ask for nothing (`tests/unit.php`, `tests/http.php`, `tests/fake-site.php`).

## 0.1.1 — 2026-10-13 — Updates from Omni itself
- **Updates in the header.** The version installed, the latest release on GitHub with its notes, **Check now**, **Install** and **Undo**. Omni looks for a new version at most every 12 hours, and the header marks one with a dot.
- **Install replaces only the code.** `storage/` (the database, the key) is never in a package and never touched, so no backup is needed first. The steps:
  - download the package and check its size and SHA-256 against `release.json`;
  - unpack it beside the live code, refusing anything outside the code (`storage/`, other folders, links, paths that climb out);
  - swap the code in with renames;
  - ask the new code whether it works (its API and sign-in page).
  - If it does not work, the old code goes back by itself, and the database too if the new code had changed it.
- **Undo** puts back the code of the last update. The old code of the last two updates is kept.
- **Releases** are published by a GitHub workflow when `VERSION` changes. It runs the tests and attaches `omni-<version>.zip` and `release.json` (the version, lowest PHP, SHA-256, size and these notes), built by `scripts/build-release.php`.
- **Tests:** `tests/update.php` updates a copy of Omni from releases on a local stand-in for GitHub: install, undo, a broken release taken back with its database, a package that does not match, one that writes into `storage/`. The unit tests cover what a package may hold, which `release.json` is accepted, the notes, and that a release always builds to the same bytes.
- **0.1.0 has no updater**, so this version goes in once by hand (`git pull`, or upload `system/`, `public/` and the files at the root, never `storage/`). Every later version installs from the Updates screen.

## 0.1.0 — 2026-10-13 — The first Omni
- **Setup.** The first visit asks for the owner's account (name, email, password of at least 12 characters, time zone) and closes once it exists. It is the only account.
- **Sign-in.** Limited to 8 failures per address in 15 minutes. Sign-out ends the session.
- **Sites.** A table of every site, with:
  - its state (healthy, warnings, needs attention, silent, waiting to connect, disconnected),
  - a chip each for health, storage, the last backup, the version and analytics,
  - when it was last heard from,
  - a link to its admin.
- **Filters.** Above the table: all, needs attention, update available, not connected.
- **Adding a site.** It takes a name and gives a connection code. The code is shown once, works once and lasts 24 hours.
- **A site's page.** It shows:
  - what to look at (failed checks, a failed or overdue backup, storage almost full, no report for 48 hours, an update),
  - the health checks, backups and cron job, storage, the version, analytics, the server and its extensions,
  - the latest report as JSON.
  - The owner can rename the site, make a new code to connect it again, or remove it.
- **The API of protocol v1:** ping, connect, signed report and disconnect.
  - The sites' secrets are sealed with libsodium; only the codes' hashes are kept.
  - Requests are refused for a wrong signature, a timestamp more than 5 minutes off, a replayed nonce, more than 64 KB, or a protocol Omni does not speak.
  - A removed site is answered with `410`.
  - Connecting is limited to 10 attempts per address per hour.
- **Tests:** `tests/unit.php` (60 checks), `tests/http.php` (58 checks, a real server), and `tests/seed-demo.php` for sample data.
