<?php
/** @var array $booking */
$biz = business_name();
$primary = primary_color();
$date_h = (new DateTime($booking['booking_date']))->format('l, F j, Y');
?>
<!doctype html>
<html lang="en"><body style="margin:0;padding:0;background:#f7f7f9;font-family:Helvetica,Arial,sans-serif;color:#111827;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px;"><tr><td align="center">
  <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 6px 20px rgba(0,0,0,.05);">
    <tr><td style="background:#b91c1c;padding:20px 24px;color:#fff;">
      <div style="font-size:18px;font-weight:600;"><?= e($biz) ?></div>
      <div style="font-size:13px;opacity:.9;">Booking cancelled</div>
    </td></tr>
    <tr><td style="padding:24px;">
      <p>Hi <?= e($booking['customer_name']) ?>,</p>
      <p>Your booking below has been <strong>cancelled</strong>.</p>
      <table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;margin:14px 0;background:#f9fafb;border-radius:8px;">
        <tr><td style="color:#6b7280;">Service</td><td><?= e($booking['service_name']) ?></td></tr>
        <tr><td style="color:#6b7280;">With</td><td><?= e($booking['staff_name']) ?></td></tr>
        <tr><td style="color:#6b7280;">Date</td><td><?= e($date_h) ?></td></tr>
        <tr><td style="color:#6b7280;">Time</td><td><?= e(format_time_display($booking['start_time'])) ?></td></tr>
      </table>
      <p>We'd love to see you again soon. <a href="<?= e(app_url('/')) ?>" style="color:<?= e($primary) ?>;">Book another appointment</a>.</p>
    </td></tr>
  </table>
</td></tr></table></body></html>
