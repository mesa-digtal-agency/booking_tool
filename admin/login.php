<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) redirect('/admin/index.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = str_in($_POST, 'email', 190);
    $pwd   = (string)($_POST['password'] ?? '');
    if (attempt_login($email, $pwd)) {
        redirect('/admin/index.php');
    } else {
        $error = 'Invalid email or password.';
    }
}

$primary = primary_color();
$biz = business_name();
$logo = logo_url();
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Log in · <?= e($biz) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { colors: { primary: '<?= e($primary) ?>' } } } };</script>
<link rel="stylesheet" href="/assets/css/app.css">
<style>:root{--primary-color: <?= e($primary) ?>;}</style>
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900 flex items-center justify-center p-4">
<div id="toastRoot"></div>
<div class="w-full max-w-sm bg-white border border-neutral-200 rounded-2xl p-7 shadow-sm">
  <div class="flex flex-col items-center gap-3 mb-6">
    <?php if ($logo): ?>
      <img src="<?= e($logo) ?>" alt="<?= e($biz) ?>" class="max-h-14 max-w-full">
    <?php else: ?>
      <div class="w-12 h-12 rounded-full" style="background: <?= e($primary) ?>"></div>
    <?php endif; ?>
    <div class="text-center">
      <div class="font-semibold text-lg"><?= e($biz) ?></div>
      <div class="text-xs text-neutral-500">Staff portal</div>
    </div>
  </div>
  <form method="post" class="space-y-3">
    <?= csrf_field() ?>
    <label class="block">
      <span class="text-sm text-neutral-600">Email</span>
      <input name="email" type="email" required autocomplete="username"
             class="w-full mt-1 px-3 py-2 rounded-lg border border-neutral-200 focus:border-primary focus:ring focus:ring-primary/20">
    </label>
    <label class="block">
      <span class="text-sm text-neutral-600">Password</span>
      <input name="password" type="password" required autocomplete="current-password"
             class="w-full mt-1 px-3 py-2 rounded-lg border border-neutral-200 focus:border-primary focus:ring focus:ring-primary/20">
    </label>
    <button class="w-full py-2 rounded-lg text-white font-medium" style="background: <?= e($primary) ?>">Log in</button>
  </form>
  <div class="text-xs text-neutral-500 mt-4 flex items-center justify-between">
    <a href="/admin/forgot-password.php" class="hover:text-primary">Forgot password?</a>
    <a href="/book.php" class="hover:text-primary">Book appointment →</a>
  </div>
</div>
<script src="/assets/js/toast.js"></script>
<?php if ($error): ?>
<script>window.toast(<?= json_encode($error) ?>, { type: 'error' });</script>
<?php endif; ?>
</body></html>
