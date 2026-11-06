<?php
/**
 * @var array<int, array{site: array<string, mixed>, health: array<string, mixed>}> $rows
 * @var array<string, int> $counts @var string $show @var array<string, string> $filters @var int $now
 */
$countFor = ['all' => $counts['all'], 'attention' => $counts['attention'], 'updates' => $counts['updates'], 'waiting' => $counts['waiting']];
$chip = static fn(array $health, string $key): string => isset($health['chips'][$key]) ? badge($health['chips'][$key]['label'], $health['chips'][$key]['tone'], $health['chips'][$key]['title']) : '<span class="text-slate-300">—</span>';
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-lg font-semibold text-slate-900">Sites</h1>
    <p class="text-xs text-slate-500"><?= $counts['all'] ?> <?= $counts['all'] === 1 ? 'site' : 'sites' ?> · <?= $counts['ok'] ?> healthy · <?= $counts['attention'] ?> need attention</p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
  <?php if (($updatable ?? 0) > 0): ?>
    <form method="post" action="/sites/update-all" data-confirm="Ask <?= (int)$updatable ?> <?= $updatable === 1 ? 'site' : 'sites' ?> to install the latest release? Each makes its verified backup first and takes the update back by itself if it does not work there." data-update-all>
      <?= csrf_field() ?>
      <button type="submit" class="inline-flex items-center gap-1.5 rounded-md border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm font-semibold text-blue-800 hover:bg-blue-100">Update all (<?= (int)$updatable ?>)</button>
    </form>
  <?php endif; ?>
  <button type="button" data-canvas-open="canvas-add" class="inline-flex items-center gap-1.5 rounded-md bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-slate-700">
    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
    Add site
  </button>
  </div>
</div>

<nav class="mb-3 flex flex-wrap gap-1.5" aria-label="Show">
  <?php foreach ($filters as $key => $label): ?>
    <a href="<?= $key === 'all' ? '/' : '/?show=' . e($key) ?>"<?= $show === $key ? ' aria-current="page"' : '' ?>
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset <?= $show === $key ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' ?>">
      <?= e($label) ?> <span class="<?= $show === $key ? 'text-white/70' : 'text-slate-400' ?>"><?= $countFor[$key] ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
  <?php if ($rows === []): ?>
    <div class="px-6 py-12 text-center">
      <p class="text-sm font-medium text-slate-900"><?= $counts['all'] === 0 ? 'No sites yet' : 'No site here' ?></p>
      <p class="mx-auto mt-1 max-w-md text-xs text-slate-500"><?= $counts['all'] === 0 ? 'Add a site to get its connection code, then enter this Omni\'s address and the code in the site\'s Admin > Omni.' : 'Nothing matches this view.' ?></p>
    </div>
  <?php else: ?>
    <div class="relative overflow-x-auto">
      <table class="min-w-full text-sm" data-sites>
        <thead>
          <tr class="border-b border-slate-100 bg-slate-50/60 text-left text-xs font-medium text-slate-500">
            <th scope="col" class="px-4 py-2 font-medium">Site</th>
            <th scope="col" class="px-3 py-2 font-medium">State</th>
            <th scope="col" class="px-3 py-2 font-medium">Health</th>
            <th scope="col" class="px-3 py-2 font-medium">Storage</th>
            <th scope="col" class="px-3 py-2 font-medium">Last backup</th>
            <th scope="col" class="px-3 py-2 font-medium">Version</th>
            <th scope="col" class="px-3 py-2 font-medium">Analytics</th>
            <th scope="col" class="px-3 py-2 font-medium">Last heard</th>
            <th scope="col" class="px-4 py-2 text-right font-medium"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
          <?php foreach ($rows as $row): $site = $row['site']; $health = $row['health']; ?>
            <tr class="hover:bg-slate-50/60" data-site="<?= e($site['public_id']) ?>">
              <td class="px-4 py-2.5">
                <a href="/sites/<?= (int)$site['id'] ?>" class="font-medium text-slate-900 hover:underline"><?= e($site['label'] ?: ($site['name'] ?: 'Untitled')) ?></a>
                <div class="max-w-xs truncate text-xs text-slate-400"><?= e($site['url'] ? preg_replace('#^https?://#', '', rtrim((string)$site['url'], '/')) : 'not connected yet') ?></div>
              </td>
              <td class="px-3 py-2.5"><?= badge($health['label'], $health['tone']) ?></td>
              <td class="px-3 py-2.5"><?= $chip($health, 'health') ?></td>
              <td class="px-3 py-2.5"><?= $chip($health, 'storage') ?></td>
              <td class="px-3 py-2.5"><?= $chip($health, 'backup') ?></td>
              <td class="px-3 py-2.5"><?= $chip($health, 'updates') ?></td>
              <td class="px-3 py-2.5"><?= $chip($health, 'analytics') ?></td>
              <td class="whitespace-nowrap px-3 py-2.5 text-xs <?= $health['silent'] ? 'font-semibold text-red-600' : 'text-slate-500' ?>" title="<?= e(local_time($health['last_heard'])) ?>"><?= e($site['last_report_at'] ? ago($site['last_report_at'], $now) : '—') ?></td>
              <td class="whitespace-nowrap px-4 py-2.5 text-right">
                <?php if (\Omni\Core\Commands::canUpdate($site, $now, $latest ?? null)): ?>
                  <form method="post" action="/sites/<?= (int)$site['id'] ?>/update" class="inline" data-confirm="Ask <?= e($site['label'] ?: $site['name']) ?> to install the latest release?">
                    <?= csrf_field() ?>
                    <button type="submit" class="inline-flex rounded p-1 text-blue-500 hover:bg-blue-50 hover:text-blue-700" title="Update" aria-label="Update <?= e($site['label'] ?: ($site['name'] ?: 'the site')) ?>">
                      <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-4-4m4 4l4-4M5 20h14"/></svg>
                    </button>
                  </form>
                <?php endif; ?>
                <?php if ($site['admin_url'] !== ''): ?>
                  <a href="<?= e($site['admin_url']) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Open the admin" aria-label="Open the admin of <?= e($site['label'] ?: $site['name']) ?>">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/></svg>
                  </a>
                <?php endif; ?>
                <a href="/sites/<?= (int)$site['id'] ?>" class="inline-flex rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" title="Details" aria-label="Details of <?= e($site['label'] ?: ($site['name'] ?: 'the site')) ?>">
                  <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6"/></svg>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div data-canvas-overlay class="fixed inset-0 z-40 hidden bg-slate-900/30"></div>
<?php /* The closed panel waits off screen inside a frame that clips it, so it never widens the page on a phone. */ ?>
<div class="pointer-events-none fixed inset-0 z-50 overflow-hidden">
<aside id="canvas-add" data-canvas role="dialog" aria-modal="true" aria-labelledby="canvas-add-title" class="pointer-events-auto invisible absolute right-0 top-0 h-full w-full max-w-md translate-x-full overflow-y-auto bg-white shadow-xl transition-transform duration-300 ease-in-out">
  <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
    <h2 id="canvas-add-title" class="text-sm font-semibold text-slate-900">Add a site</h2>
    <button type="button" data-canvas-close class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
      <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
  </div>
  <form method="post" action="/sites" class="space-y-4 px-5 py-4">
    <?= csrf_field() ?>
    <label class="block">
      <span class="mb-1 block text-xs font-medium text-slate-600">Name</span>
      <input type="text" name="label" maxlength="120" required autocomplete="off" placeholder="Doxiadis Associates"
             class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm shadow-sm placeholder:text-slate-400 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-200">
      <span class="mt-1 block text-xs text-slate-400">What you call it here. The site sends its own title and address when it connects.</span>
    </label>
    <p class="text-xs text-slate-500">You get a connection code that works once, for 24 hours. In the site, a super admin enters this Omni's address and the code at Admin &gt; Omni.</p>
    <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-slate-700">Add and get a code</button>
  </form>
</aside>
</div>
