<?php
/**
 * @var array<string, mixed> $site @var array<string, mixed> $health @var array{code: string, expires_at: string}|null $code
 * @var string|null $open_code_expires @var int $reports_kept @var string $omni_url @var int $now
 */
$r = $health['report'];
$name = $site['label'] ?: ($site['name'] ?: 'Untitled');
$card = 'rounded-lg border border-slate-200 bg-white';
$cardHead = 'flex items-center justify-between gap-2 border-b border-slate-200 px-4 py-2.5';
$h2 = 'text-sm font-semibold text-slate-900';
$row = static fn(string $label, string $value): string => '<div class="flex items-center justify-between gap-3 px-4 py-2 text-sm"><dt class="text-slate-500">' . e($label) . '</dt><dd class="text-right text-slate-800">' . $value . '</dd></div>';
$yes = static fn(mixed $v): string => $v ? 'Yes' : 'No';
$statusTone = ['ok' => 'green', 'success' => 'green', 'warning' => 'amber', 'error' => 'red', 'failed' => 'red'];
?>
<a href="/" class="mb-3 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-900">
  <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 6l-6 6 6 6"/></svg>
  Sites
</a>

<div class="mb-4 flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-4">
  <div class="min-w-0">
    <div class="flex flex-wrap items-center gap-2">
      <h1 class="text-lg font-semibold text-slate-900"><?= e($name) ?></h1>
      <?= badge($health['label'], $health['tone']) ?>
    </div>
    <p class="mt-0.5 text-xs text-slate-500">
      <?php if ($site['url'] !== ''): ?><a href="<?= e($site['url']) ?>" target="_blank" rel="noopener noreferrer" class="hover:underline"><?= e($site['url']) ?></a> · <?php endif; ?>
      <?= $site['name'] !== '' && $site['name'] !== $name ? e($site['name']) . ' · ' : '' ?>
      last heard <?= e($site['last_report_at'] ? ago($site['last_report_at'], $now) : 'never') ?>
    </p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
  <?php if (\Omni\Core\Commands::canUpdate($site, $now)): ?>
    <form method="post" action="/sites/<?= (int)$site['id'] ?>/update" data-confirm="Ask <?= e($name) ?> to install GnozioCMS <?= e((string)($r['updates']['latest'] ?? '')) ?>? It makes its verified backup first and takes the update back by itself if it does not work there." data-update-site>
      <?= csrf_field() ?>
      <button type="submit" class="rounded-md border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm font-semibold text-blue-800 hover:bg-blue-100">Update to <?= e((string)($r['updates']['latest'] ?? '')) ?></button>
    </form>
  <?php endif; ?>
  <?php if ($site['admin_url'] !== ''): ?>
    <a href="<?= e($site['admin_url']) ?>" target="_blank" rel="noopener noreferrer" data-admin-link class="inline-flex items-center gap-1.5 rounded-md bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-slate-700">
      Open admin
      <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/></svg>
    </a>
  <?php endif; ?>
  </div>
</div>

<?php if ($code !== null): ?>
  <section class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4" data-connect-code>
    <h2 class="text-sm font-semibold text-blue-900">Connection code</h2>
    <p class="mt-0.5 text-xs text-blue-800">Shown only now. It works once, until <?= e(local_time($code['expires_at'])) ?>.</p>
    <div class="mt-3 flex flex-wrap items-center gap-2">
      <code class="rounded-md bg-white px-3 py-2 font-mono text-lg font-semibold tracking-wider text-slate-900 ring-1 ring-blue-200" data-copy-source="code"><?= e($code['code']) ?></code>
      <button type="button" data-copy="code" class="rounded-md border border-blue-300 bg-white px-3 py-1.5 text-sm font-medium text-blue-800 hover:bg-blue-100">Copy</button>
    </div>
    <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-blue-900">
      <li>In the site, sign in as a super admin and open <strong>Admin &gt; Omni</strong>.</li>
      <li>Enter this Omni's address, <code class="rounded bg-white px-1 font-mono text-xs" data-copy-source="url"><?= e($omni_url) ?></code>, and the code.</li>
      <li>The site connects and sends its first report; refresh this page to see it.</li>
    </ol>
  </section>
<?php elseif (in_array($site['status'], ['pending', 'disconnected'], true)): ?>
  <section class="mb-4 <?= $card ?> p-4">
    <h2 class="<?= $h2 ?>"><?= $site['status'] === 'disconnected' ? 'The site disconnected' : 'Not connected yet' ?></h2>
    <p class="mt-1 text-sm text-slate-600">
      <?= $site['status'] === 'disconnected' ? 'It left on ' . e(local_time($site['disconnected_at'])) . '. Its history stays here.' : 'It connects with a code entered at Admin > Omni in the site.' ?>
      <?= $open_code_expires !== null ? 'A code made earlier works until ' . e(local_time($open_code_expires)) . '; a new one replaces it.' : '' ?>
    </p>
    <form method="post" action="/sites/<?= (int)$site['id'] ?>/code" class="mt-3">
      <?= csrf_field() ?>
      <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-slate-700">Make a connection code</button>
    </form>
  </section>
<?php endif; ?>

<?php if ($health['issues'] !== []): ?>
  <section class="mb-4 <?= $card ?>" data-issues>
    <div class="<?= $cardHead ?>"><h2 class="<?= $h2 ?>">To look at</h2></div>
    <ul class="divide-y divide-slate-50">
      <?php foreach ($health['issues'] as $issue): ?>
        <li class="flex items-center gap-2 px-4 py-2 text-sm">
          <span class="h-2 w-2 shrink-0 rounded-full <?= ['red' => 'bg-red-500', 'amber' => 'bg-amber-500', 'blue' => 'bg-blue-500'][$issue['tone']] ?? 'bg-slate-400' ?>" aria-hidden="true"></span>
          <?= e($issue['text']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php if ($r !== null):
  $storage = (array)($r['storage'] ?? []);
  $backups = (array)($r['backups'] ?? []);
  $last = is_array($backups['last'] ?? null) ? $backups['last'] : null;
  $cron = (array)($backups['cron'] ?? []);
  $updates = (array)($r['updates'] ?? []);
  $system = (array)($r['system'] ?? []);
?>
  <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
      <section class="<?= $card ?>">
        <div class="<?= $cardHead ?>">
          <h2 class="<?= $h2 ?>">Health checks</h2>
          <?= isset($health['chips']['health']) ? badge($health['chips']['health']['label'], $health['chips']['health']['tone'], $health['chips']['health']['title']) : '' ?>
        </div>
        <dl class="divide-y divide-slate-50">
          <?php foreach ((array)($system['checks'] ?? []) as $check): ?>
            <?= $row((string)($check['label'] ?? ''), '<span class="mr-2 text-slate-500">' . e((string)($check['value'] ?? '')) . '</span>' . badge((string)($check['status'] ?? ''), $statusTone[(string)($check['status'] ?? '')] ?? 'slate')) ?>
          <?php endforeach; ?>
        </dl>
      </section>

      <section class="<?= $card ?>">
        <div class="<?= $cardHead ?>">
          <h2 class="<?= $h2 ?>">Backups</h2>
          <?= isset($health['chips']['backup']) ? badge($health['chips']['backup']['label'], $health['chips']['backup']['tone'], $health['chips']['backup']['title']) : '' ?>
        </div>
        <dl class="divide-y divide-slate-50">
          <?= $row('Scheduled', !empty($backups['scheduled']) ? e(ucfirst((string)($backups['schedule'] ?? ''))) : 'Off') ?>
          <?= $row('Remote copy', $yes($backups['remote'] ?? false) . (!empty($backups['remote']) ? (!empty($backups['keep_local']) ? ', and kept on the server' : ', not kept on the server') : '')) ?>
          <?= $row('Last run', $last !== null ? e(local_time($last['at'] ?? null)) . ' · ' . badge((string)($last['status'] ?? ''), $statusTone[(string)($last['status'] ?? '')] ?? 'slate') . ' · ' . e(bytes(isset($last['size']) ? (int)$last['size'] : null)) : 'None') ?>
          <?php if ($last !== null && trim((string)($last['message'] ?? '')) !== ''): ?><?= $row('Message', e((string)$last['message'])) ?><?php endif; ?>
          <?= $row('Last success', e(local_time($backups['last_success_at'] ?? null))) ?>
          <?= $row('Failed, last 7 days', e((string)(int)($backups['failed_last_7_days'] ?? 0))) ?>
          <?= $row('Cron job', badge(['fresh' => 'Running', 'stale' => 'Stopped?', 'never' => 'Not set up'][(string)($cron['state'] ?? 'never')] ?? '—', ['fresh' => 'green', 'stale' => 'amber'][(string)($cron['state'] ?? '')] ?? 'slate') . (!empty($cron['last_seen']) ? ' <span class="text-xs text-slate-400">' . e(local_time($cron['last_seen'])) . '</span>' : '')) ?>
          <?= $row('Due work after a visit', $yes($cron['after_visit'] ?? false)) ?>
        </dl>
      </section>

      <details class="<?= $card ?>">
        <summary class="cursor-pointer px-4 py-2.5 text-sm font-semibold text-slate-900">Latest report <span class="font-normal text-slate-400">· received <?= e(local_time($site['last_report_at'])) ?>, JSON</span></summary>
        <div class="border-t border-slate-200 p-4">
          <pre class="max-h-96 overflow-auto rounded-md bg-slate-950 p-3 text-xs leading-relaxed text-slate-100"><?= e((string)json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
        </div>
      </details>
    </div>

    <div class="space-y-4">
      <section class="<?= $card ?>">
        <div class="<?= $cardHead ?>">
          <h2 class="<?= $h2 ?>">Storage</h2>
          <?= isset($health['chips']['storage']) ? badge($health['chips']['storage']['label'], $health['chips']['storage']['tone'], $health['chips']['storage']['title']) : '' ?>
        </div>
        <dl class="divide-y divide-slate-50">
          <?= $row('Used', e(bytes(isset($storage['used']) ? (int)$storage['used'] : null))) ?>
          <?= $row('Limit', (int)($storage['limit'] ?? 0) > 0 ? e(bytes((int)$storage['limit'])) . ' · ' . (int)($storage['percent_of_limit'] ?? 0) . '%' : 'None') ?>
          <?php foreach (['uploads' => 'Uploads', 'content' => 'Content', 'system' => 'Database and backups'] as $key => $label): ?>
            <?= $row($label, e(bytes(isset($storage['parts'][$key]) ? (int)$storage['parts'][$key] : null))) ?>
          <?php endforeach; ?>
          <?= $row('Disk free', e(bytes(isset($storage['disk_free']) ? (int)$storage['disk_free'] : null))) ?>
          <?= $row('Measured', e(local_time($storage['measured_at'] ?? null))) ?>
        </dl>
      </section>

      <section class="<?= $card ?>">
        <div class="<?= $cardHead ?>"><h2 class="<?= $h2 ?>">Version and analytics</h2></div>
        <dl class="divide-y divide-slate-50">
          <?= $row('GnozioCMS', e((string)($updates['current'] ?? '')) . (!empty($r['faros']['commit']) ? ' <span class="text-xs text-slate-400">@ ' . e((string)$r['faros']['commit']) . '</span>' : '')) ?>
          <?= $row('Latest', ($updates['has_update'] ?? null) === true ? badge((string)$updates['latest'], 'blue', 'An update is available') : e((string)($updates['latest'] ?? '—'))) ?>
          <?= $row('Checked', e(local_time($updates['checked_at'] ?? null)) . (($updates['source_status'] ?? '') === 'unreachable' ? ' ' . badge('unreachable', 'amber') : '')) ?>
          <?php if (!empty($site['command_state'])): $cmdTone = ['installed' => 'green', 'up_to_date' => 'green', 'sending' => 'blue', 'accepted' => 'blue', 'running' => 'blue', 'unverified' => 'amber', 'rolled_back' => 'amber']; ?>
            <div class="px-4 py-2 text-sm" data-command>
              <div class="flex items-center justify-between gap-3"><span class="text-slate-500">Update from Omni</span><?= badge(ucfirst(str_replace('_', ' ', (string)$site['command_state'])), $cmdTone[(string)$site['command_state']] ?? 'red') ?></div>
              <p class="mt-1 text-xs text-slate-500"><?= e((string)$site['command_message']) ?> <span class="text-slate-400"><?= e(local_time((string)$site['command_at'])) ?></span></p>
            </div>
          <?php endif; ?>
          <?= $row('Analytics', isset($health['chips']['analytics']) ? badge($health['chips']['analytics']['label'], $health['chips']['analytics']['tone'], $health['chips']['analytics']['title']) : '—') ?>
        </dl>
      </section>

      <section class="<?= $card ?>">
        <div class="<?= $cardHead ?>"><h2 class="<?= $h2 ?>">Server</h2></div>
        <dl class="divide-y divide-slate-50">
          <?php foreach ((array)($system['environment'] ?? []) as $label => $value): ?>
            <?= $row((string)$label, e((string)$value)) ?>
          <?php endforeach; ?>
        </dl>
        <div class="flex flex-wrap gap-1.5 border-t border-slate-100 px-4 py-3">
          <?php foreach ((array)($system['extensions'] ?? []) as $ext): ?>
            <?= badge((string)($ext['name'] ?? ''), !empty($ext['loaded']) ? 'green' : (($ext['status'] ?? '') === 'warning' ? 'amber' : 'red'), !empty($ext['loaded']) ? 'Loaded' : 'Missing') ?>
          <?php endforeach; ?>
        </div>
      </section>
    </div>
  </div>
<?php endif; ?>

<section class="mt-4 <?= $card ?>">
  <div class="<?= $cardHead ?>"><h2 class="<?= $h2 ?>">Manage</h2></div>
  <div class="grid grid-cols-1 gap-4 p-4 md:grid-cols-3">
    <form method="post" action="/sites/<?= (int)$site['id'] ?>/rename" class="flex items-end gap-2">
      <?= csrf_field() ?>
      <label class="block min-w-0 flex-1">
        <span class="mb-1 block text-xs font-medium text-slate-600">Name in Omni</span>
        <input type="text" name="label" value="<?= e($site['label']) ?>" maxlength="120" class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200">
      </label>
      <button type="submit" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">Save</button>
    </form>
    <?php if ($site['status'] === 'connected'): ?>
      <form method="post" action="/sites/<?= (int)$site['id'] ?>/code" class="flex flex-col justify-end gap-1">
        <?= csrf_field() ?>
        <span class="text-xs text-slate-500">To connect it again (a new secret), for example after moving it to another server.</span>
        <button type="submit" class="self-start rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50">New connection code</button>
      </form>
    <?php else: ?>
      <div></div>
    <?php endif; ?>
    <form method="post" action="/sites/<?= (int)$site['id'] ?>/remove" class="flex flex-col justify-end gap-1" data-confirm="Remove <?= e($name) ?> from Omni? Its history is deleted and the site is told the next time it reports.">
      <?= csrf_field() ?>
      <span class="text-xs text-slate-500">Deletes its history here. The site stops reporting.</span>
      <button type="submit" class="self-start rounded-md border border-red-200 bg-white px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50">Remove site</button>
    </form>
  </div>
  <p class="border-t border-slate-100 px-4 py-2 text-xs text-slate-400">
    Id <?= e($site['public_id']) ?> · added <?= e(local_time($site['created_at'])) ?><?= $site['connected_at'] ? ' · connected ' . e(local_time($site['connected_at'])) : '' ?> · <?= $reports_kept ?> <?= $reports_kept === 1 ? 'report' : 'reports' ?> kept
  </p>
</section>
