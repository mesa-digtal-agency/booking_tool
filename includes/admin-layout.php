<?php
/**
 * Admin layout helpers.
 *   $page_title = 'Dashboard'; $active = 'dashboard';
 *   require __DIR__ . '/../includes/admin-layout.php';
 *   admin_header();
 *     ... content ...
 *   admin_footer();
 */

function admin_nav_items(): array {
    $items = [
        ['dashboard', '/admin/index.php',       'Dashboard'],
        ['calendar',  '/admin/calendar.php',    'Calendar'],
        ['bookings',  '/admin/bookings.php',    'Bookings'],
        ['services',  '/admin/services.php',    'Services'],
    ];
    if (is_admin()) {
        $items[] = ['staff', '/admin/staff.php', 'Staff'];
    }
    $items[] = ['hours',   '/admin/staff-hours.php',   'Working hours'];
    $items[] = ['blocked', '/admin/blocked-slots.php', 'Blocked slots'];
    return $items;
}

function render_sidebar_logo(): string {
    $mode = logo_mode();
    $logo = logo_url();
    $biz  = business_name();
    $primary = primary_color();

    if ($mode === 'logo_only' && $logo) {
        return '<div class="logo-slot logo-only">
                    <img src="' . e($logo) . '" alt="' . e($biz) . '">
                </div>';
    }
    // Either logo + name, or just a placeholder dot + name if no logo is set.
    $img = $logo
        ? '<img src="' . e($logo) . '" alt="">'
        : '<div style="width:36px;height:36px;border-radius:50%;background:' . e($primary) . ';"></div>';
    return '<div class="logo-slot with-name">
                ' . $img . '
                <div class="font-semibold text-sm">' . e($biz) . '</div>
            </div>';
}

function admin_header(): void {
    global $page_title, $active;

    // Opportunistic auto-complete on every admin load.
    if (function_exists('auto_complete_elapsed_bookings')) auto_complete_elapsed_bookings();
    if (function_exists('ensure_migrations')) ensure_migrations();

    $user = current_user();
    $primary = primary_color();
    $biz = business_name();
    $tz = $GLOBALS['CONFIG']['business_timezone'] ?? 'UTC';
    $current = $active ?? '';
    $navItems = admin_nav_items();
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($page_title ?? 'Admin') ?> · <?= e($biz) ?></title>
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
  tailwind.config = { theme: { extend: { colors: { primary: '<?= e($primary) ?>' } } } };
  </script>
  <link rel="stylesheet" href="/assets/css/app.css">
  <style>:root{--primary-color: <?= e($primary) ?>;}</style>
</head>
<body class="admin-layout bg-neutral-50 text-neutral-900">
<div class="admin-shell">
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-sidebar-inner">
      <?= render_sidebar_logo() ?>
      <nav class="nav-scroll space-y-1">
        <?php foreach ($navItems as [$key, $url, $label]):
          $cls = $current === $key ? 'nav-link active' : 'nav-link';
        ?>
          <a href="<?= e($url) ?>" class="<?= e($cls) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="sidebar-footer">
        <div id="liveClock" class="clock" data-tz="<?= e($tz) ?>">
          <div class="time">—</div>
          <div class="date"><?= e($tz) ?></div>
        </div>
        <div class="px-2"><?= e($user['name'] ?? '') ?></div>
        <div class="px-2 text-[10px] uppercase tracking-wide"><?= e($user['role'] ?? '') ?></div>
        <a href="/admin/logout.php" class="nav-link mt-2 text-red-600 hover:bg-red-50">Log out</a>
      </div>
    </div>
  </aside>

  <div class="admin-main">
    <header class="admin-mobile-header">
      <button id="sidebarToggle" class="p-2 rounded-lg border border-neutral-200" aria-label="Menu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div class="font-semibold text-sm"><?= e($biz) ?></div>
      <div style="width:38px"></div>
    </header>
    <div class="admin-scroll">
<?php
}

function admin_footer(): void { ?>
    </div>
  </div>
</div>
<div id="toastRoot"></div>
<script src="/assets/js/toast.js"></script>
<script src="/assets/js/clock.js"></script>
<script>
// Mobile sidebar drawer
(function(){
  const btn = document.getElementById('sidebarToggle');
  const bar = document.getElementById('adminSidebar');
  const bd  = document.getElementById('sidebarBackdrop');
  if (!btn || !bar || !bd) return;
  const open = () => { bar.classList.add('open'); bd.classList.add('show'); };
  const close = () => { bar.classList.remove('open'); bd.classList.remove('show'); };
  btn.addEventListener('click', open);
  bd.addEventListener('click', close);
})();
// Render any flash messages queued server-side as toasts.
(function(){
  const flashes = window.__FLASHES__ || [];
  flashes.forEach(f => window.toast && window.toast(f.msg, { type: f.type }));
})();
</script>
</body></html>
<?php
    // Clear flashes after emitting.
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
 * Emit queued flash messages as a JS payload the admin footer picks up
 * and renders through the toast system.
 */
function flash_render(): string {
    if (empty($_SESSION['flash'])) return '';
    $payload = [];
    foreach ($_SESSION['flash'] as $k => $m) {
        $type = ($k === 'error') ? 'error' : ($k === 'warn' ? 'warn' : 'success');
        $payload[] = ['type' => $type, 'msg' => (string)$m];
    }
    // Don't clear here — admin_footer() clears after emitting. But also clear
    // if flash_render is called on a non-admin page.
    return '<script>window.__FLASHES__ = ' . json_encode($payload) . ';</script>';
}
