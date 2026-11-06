<?php

declare(strict_types=1);

return [
    'app_name' => 'Omni',
    'db_path' => STORAGE_PATH . '/db/omni.sqlite',
    // The key that encrypts the sites' secrets at rest. Made on first use; keep it out of the web root and out of git.
    'key_path' => STORAGE_PATH . '/key',
    // How often a site is asked to report, in seconds (the protocol allows 15 minutes to 24 hours).
    'report_every' => 21600,
    // How many reports are kept per site, for trends.
    'keep_reports' => 120,
    // Where Omni learns about new versions (Admin > Updates). Tests point it at a local server.
    'release_url' => getenv('OMNI_RELEASE_URL') ?: 'https://github.com/chiotis/gnozio-omni/releases/latest/download/release.json',
    // Where Omni learns the latest GnozioCMS, to tell which sites are behind without waiting for their own check.
    'gnozio_release_url' => getenv('OMNI_GNOZIO_RELEASE_URL') ?: 'https://github.com/chiotis/gnoziocms/releases/latest/download/release.json',
    'allow_local_release' => getenv('OMNI_RELEASE_URL') !== false,
];
