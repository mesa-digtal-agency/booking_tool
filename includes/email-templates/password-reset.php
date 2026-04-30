<?php
/** @var string $name */
/** @var string $reset_url */
$biz = business_name();
$primary = primary_color();
?>
<!doctype html>
<html lang="en"><body style="margin:0;padding:0;background:#f7f7f9;font-family:Helvetica,Arial,sans-serif;color:#111827;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px;"><tr><td align="center">
  <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 6px 20px rgba(0,0,0,.05);">
    <tr><td style="background:<?= e($primary) ?>;padding:20px 24px;color:#fff;">
      <div style="font-size:18px;font-weight:600;"><?= e($biz) ?></div>
      <div style="font-size:13px;opacity:.9;">Password reset</div>
    </td></tr>
    <tr><td style="padding:24px;">
      <p>Hi <?= e($name) ?>,</p>
      <p>We received a request to reset the password on your <?= e($biz) ?> admin account. Click the button below to set a new one. This link expires in 1 hour.</p>
      <p><a href="<?= e($reset_url) ?>" style="display:inline-block;padding:10px 18px;border-radius:8px;background:<?= e($primary) ?>;color:#fff;text-decoration:none;font-weight:600;">Reset password</a></p>
      <p style="color:#6b7280;font-size:12px;">If the button doesn't work, copy and paste this link:<br><span style="word-break:break-all;"><?= e($reset_url) ?></span></p>
      <p style="color:#6b7280;font-size:12px;margin-top:24px;">If you didn't request this, you can safely ignore this email — your password won't change.</p>
    </td></tr>
  </table>
</td></tr></table></body></html>
