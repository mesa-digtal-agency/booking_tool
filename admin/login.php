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
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Log in · <?= e($biz) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { colors: { primary: '<?= e($primary) ?>' } } } };</script>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900 flex items-center justify-center p-4">
<div class="w-full max-w-sm bg-white border border-neutral-200 rounded-xl p-6 shadow-sm">
  <div class="flex items-center gap-3 mb-5">
    <div class="w-10 h-10 rounded-full" style="background: <?= e($primary) ?>"></div>
    <div>
      <div class="font-semibold"><?= e($biz) ?></div>
      <div class="text-xs text-neutral-500">Admin login</div>
    </div>
  </div>
  <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-2 mb-3"><?= e($error) ?></div>
  <?php endif; ?>
  <form method="post" class="space-y-3">
    <?= csrf_field() ?>
    <label class="block">
      <span class="text-sm text-neutral-600">Email</span>
      <input name="email" type="email" required autocomplete="username" class="w-full mt-1 px-3 py-2 rounded-lg border border-neutral-200 focus:border-primary focus:ring focus:ring-primary/20">
    </label>
    <label class="block">
      <span class="text-sm text-neutral-600">Password</span>
      <input name="password" type="password" required autocomplete="current-password" class="w-full mt-1 px-3 py-2 rounded-lg border border-neutral-200 focus:border-primary focus:ring focus:ring-primary/20">
    </label>
    <button class="w-full py-2 rounded-lg text-white font-medium" style="background: <?= e($primary) ?>">Log in</button>
  </form>
  <div class="text-xs text-neutral-500 mt-4 text-center">
    <a href="/" class="underline">← Back to booking</a>
  </div>
</div>
</body></html>
