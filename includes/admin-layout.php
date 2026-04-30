<?php
/**
 * Admin layout helpers.
 *   $page_title = 'Dashboard'; $active = 'dashboard';
 *   require __DIR__ . '/../includes/admin-layout.php';
 *   admin_header();
 *     ... content ...
 *   admin_footer();
 *
 * Layout uses Tailwind utility classes directly (robust to CSS cache
 * misses) with custom polish in /assets/css/app.css.
 */

function admin_nav_items(): array {
    $items = [
        ['dashboard', '/admin/index.php',       'Dashboard',       'fa-solid fa-chart-line'],
        ['calendar',  '/admin/calendar.php',    'Calendar',        'fa-regular fa-calendar-days'],
        ['bookings',  '/admin/bookings.php',    'Bookings',        'fa-regular fa-calendar-check'],
        ['services',  '/admin/services.php',    'Services',        'fa-solid fa-scissors'],
    ];
    if (is_admin()) {
        $items[] = ['staff', '/admin/staff.php', 'Staff', 'fa-solid fa-user-group'];
    }
    $items[] = ['hours',   '/admin/staff-hours.php',   'Working hours', 'fa-regular fa-clock'];
    $items[] = ['blocked', '/admin/blocked-slots.php', 'Blocked slots', 'fa-solid fa-ban'];
    if (is_admin() && import_export_enabled()) {
        $items[] = ['data', '/admin/import-export.php', 'Import / export', 'fa-solid fa-file-arrow-up'];
    }
    if (is_admin() && settings_page_enabled()) {
        $items[] = ['settings', '/admin/settings.php', 'Settings', 'fa-solid fa-gear'];
    }
    return $items;
}

function render_sidebar_logo(): string {
    $mode = logo_mode();
    $logo = logo_url();
    $biz  = business_name();
    $primary = primary_color();

    if ($mode === 'logo_only' && $logo) {
        // Full sidebar width within the sidebar's inner padding (p-4), so
        // the image breathes on the sides. No business-name text.
        return '<a href="/admin/index.php" class="mb-5 flex items-center justify-center px-3 py-2 rounded-xl" aria-label="Dashboard">
                    <img src="' . e($logo) . '" alt="' . e($biz) . '" class="block w-full h-auto">
                </a>';
    }
    $img = $logo
        ? '<img src="' . e($logo) . '" alt="" class="w-9 h-9 object-contain rounded-lg flex-shrink-0">'
        : '<div class="w-9 h-9 rounded-full flex-shrink-0" style="background:' . e($primary) . '"></div>';
    return '<a href="/admin/index.php" class="flex items-center gap-2.5 px-3 py-2 mb-5 rounded-xl" aria-label="Dashboard">
                ' . $img . '
                <div class="font-semibold text-sm truncate">' . e($biz) . '</div>
            </a>';
}

function admin_header(): void {
    global $page_title, $active;

    // Opportunistic auto-complete on every admin load.
    if (function_exists('ensure_migrations')) ensure_migrations();
    if (function_exists('auto_complete_elapsed_bookings')) auto_complete_elapsed_bookings();

    $user = current_user();
    $primary = primary_color();
    $accent = function_exists('accent_color') ? accent_color() : $primary;
    $font_stack = function_exists('app_font_stack') ? app_font_stack() : "ui-sans-serif, system-ui, sans-serif";
    $font_url = function_exists('app_font_stylesheet_url') ? app_font_stylesheet_url() : '';
    $dark = function_exists('dark_mode_enabled') && dark_mode_enabled();
    $biz = business_name();
    $tz = $GLOBALS['CONFIG']['business_timezone'] ?? 'UTC';
    $current = $active ?? '';
    $navItems = admin_nav_items();
    ?><!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($page_title ?? 'Admin') ?> · <?= e($biz) ?></title>
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><circle cx="8" cy="8" r="8" fill="' . e($primary) . '"/></svg>') ?>">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
  tailwind.config = { theme: { extend: { colors: { primary: '<?= e($primary) ?>' } } } };
  </script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <?php if ($font_url !== ''): ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="<?= e($font_url) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
  <style>:root{--primary-color: <?= e($primary) ?>;--accent-color: <?= e($accent) ?>;--app-font-family: <?= $font_stack ?>;color-scheme: <?= $dark ? 'dark' : 'light' ?>;}</style>
</head>
<body class="bg-neutral-50 text-neutral-900 h-full overflow-hidden <?= $dark ? 'theme-dark' : '' ?>">
<div class="flex h-full overflow-hidden">
  <!-- Mobile backdrop -->
  <div id="sidebarBackdrop" class="fixed inset-0 bg-black/35 z-40 hidden md:hidden"></div>

  <!-- Sidebar: off-canvas on mobile, static 240px on md+ -->
  <aside id="adminSidebar"
         class="fixed inset-y-0 left-0 w-60 bg-white border-r border-neutral-200 flex-shrink-0 flex flex-col
                z-50 -translate-x-full transition-transform duration-200
                md:static md:translate-x-0 md:h-screen md:z-0">
    <div class="flex flex-col flex-1 min-h-0 p-4">
      <?= render_sidebar_logo() ?>
      <nav class="flex-1 overflow-y-auto space-y-1 -mx-2 px-2">
        <?php foreach ($navItems as [$key, $url, $label, $icon]):
          $cls = $current === $key ? 'nav-link active' : 'nav-link';
        ?>
          <a href="<?= e($url) ?>" class="<?= e($cls) ?>"><i class="<?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
      </nav>
      <div class="flex-shrink-0 pt-3 mt-3 border-t border-neutral-100 text-xs text-neutral-500">
        <div id="liveClock" class="mb-2 text-center bg-neutral-50 border border-neutral-100 rounded-lg px-2.5 py-2 text-neutral-900"
             data-tz="<?= e($tz) ?>" data-format="<?= e(time_format()) ?>" style="font-variant-numeric: tabular-nums;">
          <div class="time font-semibold text-[15px]">—</div>
          <div class="date text-[11px] text-neutral-500"><?= e($tz) ?></div>
        </div>
        <div class="px-2 font-medium text-neutral-700 truncate"><?= e($user['name'] ?? '') ?></div>
        <div class="px-2 text-[10px] uppercase tracking-wide text-neutral-400"><?= e($user['role'] ?? '') ?></div>
        <a href="/admin/logout.php" class="nav-link mt-2 text-red-600 hover:bg-red-50"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span>Log out</span></a>
      </div>
    </div>
  </aside>

  <!-- Main area -->
  <div class="relative flex-1 min-w-0 flex flex-col h-screen overflow-hidden">
    <header class="md:hidden flex items-center justify-between bg-white border-b border-neutral-200 px-4 py-3">
      <button id="sidebarToggle" class="p-2 rounded-lg border border-neutral-200" aria-label="Menu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="font-semibold text-sm truncate"><?= e($biz) ?></div>
      <div style="width:38px"></div>
    </header>
    <div class="admin-main flex-1 overflow-y-auto p-4 md:p-8">
<?php
}

function admin_footer(): void { ?>
    </div>
    <div id="adminLoadingOverlay" class="admin-loading-overlay" aria-hidden="true">
      <div class="banter-loader" aria-label="Loading">
        <div class="banter-loader__box"></div>
        <div class="banter-loader__box"></div>
        <div class="banter-loader__box"></div>
        <div class="banter-loader__box"></div>
        <div class="banter-loader__box"></div>
        <div class="banter-loader__box"></div>
        <div class="banter-loader__box"></div>
        <div class="banter-loader__box"></div>
        <div class="banter-loader__box"></div>
      </div>
    </div>
  </div>
</div>
<div id="toastRoot"></div>
<script src="<?= e(asset('/assets/js/toast.js')) ?>"></script>
<script src="<?= e(asset('/assets/js/clock.js')) ?>"></script>
<script src="<?= e(asset('/assets/js/admin-loader.js')) ?>"></script>
<script>
// Mobile sidebar drawer
(function(){
  const btn = document.getElementById('sidebarToggle');
  const bar = document.getElementById('adminSidebar');
  const bd  = document.getElementById('sidebarBackdrop');
  if (!btn || !bar || !bd) return;
  const open  = () => { bar.classList.remove('-translate-x-full'); bd.classList.remove('hidden'); };
  const close = () => { bar.classList.add('-translate-x-full'); bd.classList.add('hidden'); };
  btn.addEventListener('click', open);
  bd.addEventListener('click', close);
})();
(function(){
  const flashes = window.__FLASHES__ || [];
  flashes.forEach(f => window.toast && window.toast(f.msg, { type: f.type }));
})();
window.confirmDialog = window.confirmDialog || function(message) {
  return Promise.resolve(window.confirm(message));
};
</script>
</body></html>
<?php
    $_SESSION['flash'] = [];
}

function flash(string $key, string $msg = null): ?string {
    if ($msg !== null) {
        $_SESSION['flash'][$key] = $msg;
        return null;
    }
    $out = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $out;
}

/**
 * Queue flash messages for the admin footer to render as toasts.
 */
function flash_render(): string {
    if (empty($_SESSION['flash'])) return '';
    $payload = [];
    foreach ($_SESSION['flash'] as $k => $m) {
        $type = ($k === 'error') ? 'error' : ($k === 'warn' ? 'warn' : 'success');
        $payload[] = ['type' => $type, 'msg' => (string)$m];
    }
    return '<script>window.__FLASHES__ = ' . json_encode($payload) . ';</script>';
}
