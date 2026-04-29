<?php
require_once __DIR__ . '/../includes/bootstrap.php';

ensure_migrations();

if (is_logged_in()) redirect('/admin/index.php');

$sent = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = strtolower(str_in($_POST, 'email', 190));
    $now = time();
    $bucket = &$_SESSION['password_reset_attempts'];
    if (!is_array($bucket ?? null)) $bucket = [];
    $bucket = array_values(array_filter($bucket, fn($ts) => (int)$ts > $now - 3600));
    if (!is_valid_email($email)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (count($bucket) >= 8) {
        $sent = true;
    } else {
        $bucket[] = $now;
        // Lookup silently — never reveal whether an account exists.
        $staff = db_fetch("SELECT id, name, email FROM staff WHERE email = ? AND is_active = 1", [$email]);
        if ($staff) {
            // Per-account throttle; the session bucket above also limits
            // anonymous enumeration and reset-email abuse.
            $recent = (int)db_scalar(
                "SELECT COUNT(*) FROM password_resets WHERE staff_id = ? AND created_at >= ?",
                [(int)$staff['id'], gmdate('Y-m-d H:i:s', time() - 300)]
            );
            if ($recent < 3) {
                $raw = issue_password_reset_token((int)$staff['id'], 3600);
                if (!$raw) {
                    $sent = true;
                    goto done_post;
                }
                $reset_url = app_url('/admin/reset-password.php?token=' . $raw);
                ob_start();
                $tmpl = ['name' => $staff['name'], 'reset_url' => $reset_url];
                extract($tmpl);
                include APP_ROOT . '/includes/email-templates/password-reset.php';
                $html = ob_get_clean();
                @send_mail($staff['email'], 'Reset your password', $html);
            }
        }
        $sent = true;
    }
}
done_post:

$primary = primary_color();
$accent = accent_color();
$font_stack = app_font_stack();
$font_url = app_font_stylesheet_url();
$dark = dark_mode_enabled();
$biz = business_name();
$logo = logo_url();
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot password · <?= e($biz) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { colors: { primary: '<?= e($primary) ?>' } } } };</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="<?= e($font_url) ?>">
<link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
<style>:root{--primary-color: <?= e($primary) ?>;--accent-color: <?= e($accent) ?>;--app-font-family: <?= $font_stack ?>;color-scheme: <?= $dark ? 'dark' : 'light' ?>;}</style>
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900 flex items-center justify-center p-4 <?= $dark ? 'theme-dark' : '' ?>">
<div id="toastRoot"></div>
<div class="w-full max-w-sm bg-white border border-neutral-200 rounded-2xl p-7 shadow-sm">
  <div class="flex items-center gap-3 mb-5 justify-center flex-col">
    <?php if ($logo): ?>
      <img src="<?= e($logo) ?>" alt="<?= e($biz) ?>" class="max-h-14 max-w-full">
    <?php else: ?>
      <div class="w-10 h-10 rounded-full" style="background: <?= e($primary) ?>"></div>
    <?php endif; ?>
    <div class="text-center">
      <div class="font-semibold"><?= e($biz) ?></div>
      <div class="text-xs text-neutral-500">Reset your password</div>
    </div>
  </div>

  <?php if ($sent): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-lg px-3 py-3 mb-3">
      If an account with that email exists, we've sent a password reset link. Check your inbox.
    </div>
    <div class="text-center mt-4">
      <a href="/admin/login.php" class="text-sm text-primary hover:underline">← Back to login</a>
    </div>
  <?php else: ?>
    <form method="post" class="space-y-3">
      <?= csrf_field() ?>
      <label class="block">
        <span class="text-sm text-neutral-600">Your email</span>
        <input name="email" type="email" required autocomplete="email"
               value="<?= e($_POST['email'] ?? '') ?>"
               class="w-full mt-1 px-3 py-2 rounded-lg border border-neutral-200 focus:border-primary focus:ring focus:ring-primary/20">
      </label>
      <button class="w-full py-2 rounded-lg text-white font-medium" style="background: <?= e($primary) ?>">Send reset link</button>
    </form>
    <div class="text-xs text-neutral-500 mt-4 text-center">
      <a href="/admin/login.php" class="underline">← Back to login</a>
    </div>
  <?php endif; ?>
</div>
<script src="<?= e(asset('/assets/js/toast.js')) ?>"></script>
<?php if ($errors): ?>
<script>
<?php foreach ($errors as $er): ?>
  window.toast(<?= json_encode($er) ?>, { type: 'error' });
<?php endforeach; ?>
</script>
<?php endif; ?>
</body></html>
