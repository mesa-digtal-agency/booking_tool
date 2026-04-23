<?php
require_once __DIR__ . '/../includes/bootstrap.php';
ensure_migrations();

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$valid = (bool)preg_match('/^[0-9a-f]{64}$/', $token);

$errors = [];
$done = false;
$reset_row = null;

if ($valid) {
    $hash = hash('sha256', $token);
    $reset_row = db_fetch(
        "SELECT pr.*, s.id AS sid, s.name, s.email
         FROM password_resets pr
         JOIN staff s ON s.id = pr.staff_id
         WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at >= ?",
        [$hash, date('Y-m-d H:i:s')]
    );
    if (!$reset_row) $valid = false;
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $pwd  = (string)($_POST['password'] ?? '');
    $pwd2 = (string)($_POST['password_confirm'] ?? '');
    if (strlen($pwd) < 8)  $errors[] = 'Password must be at least 8 characters.';
    if ($pwd !== $pwd2)    $errors[] = 'Passwords do not match.';
    if (!$errors) {
        db_exec("UPDATE staff SET password_hash = ? WHERE id = ?",
            [password_hash($pwd, PASSWORD_DEFAULT), (int)$reset_row['sid']]);
        db_exec("UPDATE password_resets SET used_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s'), (int)$reset_row['id']]);
        // Invalidate any other outstanding reset tokens for this user.
        db_exec("UPDATE password_resets SET used_at = ? WHERE staff_id = ? AND used_at IS NULL",
            [date('Y-m-d H:i:s'), (int)$reset_row['sid']]);
        $done = true;
    }
}

$primary = primary_color();
$biz = business_name();
$logo = logo_url();
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset password · <?= e($biz) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { colors: { primary: '<?= e($primary) ?>' } } } };</script>
<link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
<style>:root{--primary-color: <?= e($primary) ?>;}</style>
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900 flex items-center justify-center p-4">
<div id="toastRoot"></div>
<div class="w-full max-w-sm bg-white border border-neutral-200 rounded-2xl p-7 shadow-sm">
  <div class="flex items-center gap-3 mb-5 justify-center flex-col">
    <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e($biz) ?>" class="max-h-14 max-w-full">
    <?php else: ?><div class="w-10 h-10 rounded-full" style="background: <?= e($primary) ?>"></div><?php endif; ?>
    <div class="text-center">
      <div class="font-semibold"><?= e($biz) ?></div>
      <div class="text-xs text-neutral-500">Choose a new password</div>
    </div>
  </div>

  <?php if (!$valid): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-3 py-3 mb-3">
      This reset link is invalid or has expired. Please request a new one.
    </div>
    <div class="text-center mt-3">
      <a class="text-sm text-primary hover:underline" href="/admin/forgot-password.php">Request a new link</a>
    </div>
  <?php elseif ($done): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-lg px-3 py-3 mb-3">
      Your password has been updated. You can now log in.
    </div>
    <div class="text-center mt-3">
      <a class="inline-block py-2 px-4 rounded-lg text-white text-sm font-medium" style="background: <?= e($primary) ?>" href="/admin/login.php">Go to login →</a>
    </div>
  <?php else: ?>
    <form method="post" class="space-y-3">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label class="block">
        <span class="text-sm text-neutral-600">New password</span>
        <input name="password" type="password" required minlength="8" autocomplete="new-password"
               class="w-full mt-1 px-3 py-2 rounded-lg border border-neutral-200 focus:border-primary focus:ring focus:ring-primary/20">
      </label>
      <label class="block">
        <span class="text-sm text-neutral-600">Confirm password</span>
        <input name="password_confirm" type="password" required minlength="8" autocomplete="new-password"
               class="w-full mt-1 px-3 py-2 rounded-lg border border-neutral-200 focus:border-primary focus:ring focus:ring-primary/20">
      </label>
      <button class="w-full py-2 rounded-lg text-white font-medium" style="background: <?= e($primary) ?>">Set new password</button>
    </form>
  <?php endif; ?>
</div>
<script src="<?= e(asset('/assets/js/toast.js')) ?>"></script>
<?php if ($errors): ?>
<script><?php foreach ($errors as $er): ?>window.toast(<?= json_encode($er) ?>, { type: 'error' });<?php endforeach; ?></script>
<?php endif; ?>
</body></html>
