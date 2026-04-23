<?php
/**
 * Admin layout helpers. Use like:
 *   $page_title = 'Dashboard'; $active = 'dashboard';
 *   require __DIR__ . '/../includes/admin-layout.php';
 *   admin_header();
 *     ... your content ...
 *   admin_footer();
 */

function admin_header(): void {
    global $page_title, $active;
    $user = current_user();
    $primary = primary_color();
    $biz = business_name();
    $current = $active ?? '';
    $navItems = [
        ['dashboard',    '/admin/index.php',        'Dashboard'],
        ['calendar',     '/admin/calendar.php',     'Calendar'],
        ['bookings',     '/admin/bookings.php',     'Bookings'],
        ['services',     '/admin/services.php',     'Services'],
    ];
    if (is_admin()) {
        $navItems[] = ['staff', '/admin/staff.php', 'Staff'];
    }
    $navItems[] = ['hours', '/admin/staff-hours.php', 'Working hours'];
    $navItems[] = ['blocked', '/admin/blocked-slots.php', 'Blocked slots'];

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
<body class="min-h-screen bg-neutral-50 text-neutral-900">
<div class="flex min-h-screen">
  <aside class="hidden md:flex flex-col w-60 bg-white border-r border-neutral-200 p-4">
    <div class="flex items-center gap-2 mb-6 px-2">
      <div class="w-8 h-8 rounded-full" style="background: <?= e($primary) ?>"></div>
      <div class="font-semibold text-sm"><?= e($biz) ?></div>
    </div>
    <nav class="space-y-1">
      <?php foreach ($navItems as [$key, $url, $label]):
        $cls = $current === $key ? 'nav-link active' : 'nav-link';
      ?>
        <a href="<?= e($url) ?>" class="<?= e($cls) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="mt-auto pt-6 border-t border-neutral-100 text-xs text-neutral-500">
      <div class="px-2"><?= e($user['name'] ?? '') ?></div>
      <div class="px-2 text-[10px] uppercase tracking-wide"><?= e($user['role'] ?? '') ?></div>
      <a href="/admin/logout.php" class="nav-link mt-2 text-red-600 hover:bg-red-50">Log out</a>
    </div>
  </aside>

  <div class="flex-1 flex flex-col min-w-0">
    <header class="bg-white border-b border-neutral-200 md:hidden px-4 py-3 flex items-center justify-between">
      <div class="font-semibold"><?= e($biz) ?> admin</div>
      <details><summary class="cursor-pointer text-sm">Menu</summary>
        <nav class="absolute right-2 top-12 bg-white border border-neutral-200 rounded-xl p-2 shadow-lg z-40 min-w-[200px]">
          <?php foreach ($navItems as [$key, $url, $label]): ?>
            <a href="<?= e($url) ?>" class="nav-link"><?= e($label) ?></a>
          <?php endforeach; ?>
          <a href="/admin/logout.php" class="nav-link text-red-600">Log out</a>
        </nav>
      </details>
    </header>
    <main class="flex-1 p-4 md:p-8">
<?php
}

function admin_footer(): void { ?>
    </main>
  </div>
</div>
</body></html>
<?php
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

function flash_render(): string {
    $html = '';
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $k => $m) {
            $color = $k === 'error' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200';
            $html .= '<div class="border ' . $color . ' rounded-lg px-3 py-2 text-sm mb-4">' . e($m) . '</div>';
        }
        $_SESSION['flash'] = [];
    }
    return $html;
}
