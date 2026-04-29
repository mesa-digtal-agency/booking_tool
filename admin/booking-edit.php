<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-layout.php';
require_once __DIR__ . '/../includes/availability.php';

require_login();
$page_title = 'Booking';
$active = 'bookings';

$id = (int)($_GET['id'] ?? 0);
$scope = scope_staff_id();
$errors = [];

$booking = null;
if ($id > 0) {
    $booking = db_fetch(
        "SELECT * FROM bookings WHERE id = ?",
        [$id]
    );
    if (!$booking) { http_response_code(404); exit('Booking not found.'); }
    if ($scope !== null && (int)$booking['staff_id'] !== $scope) {
        http_response_code(403); exit('You do not have access to this booking.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'delete' && $booking && is_admin()) {
        db_exec("DELETE FROM bookings WHERE id = ?", [(int)$booking['id']]);
        flash('ok', 'Booking deleted.');
        redirect('/admin/bookings.php');
    }

    $data = [
        'customer_name'  => str_in($_POST, 'customer_name', 120),
        'customer_email' => strtolower(str_in($_POST, 'customer_email', 190)),
        'customer_phone' => str_in($_POST, 'customer_phone', 40),
        'notes'          => str_in($_POST, 'notes', 1000),
        'service_id'     => (int)($_POST['service_id'] ?? 0),
        'staff_id'       => (int)($_POST['staff_id'] ?? 0),
        'booking_date'   => str_in($_POST, 'booking_date', 10),
        'start_time'     => normalize_time(str_in($_POST, 'start_time', 8)) ?? '',
        'status'         => (string)($_POST['status'] ?? 'pending'),
    ];
    if (!in_array($data['status'], booking_all_statuses(), true)) $data['status'] = 'pending';
    if ($scope !== null) $data['staff_id'] = (int)$scope;

    // Validation — email is optional here in the admin form (staff often
    // book walk-ins by phone). The public /api/bookings.php endpoint still
    // requires email.
    if ($data['customer_name'] === '') $errors[] = 'Customer name is required.';
    if ($data['customer_email'] !== '' && !is_valid_email($data['customer_email'])) $errors[] = 'Email is not valid.';
    if (!is_valid_phone($data['customer_phone'])) $errors[] = 'Valid phone is required.';
    if ($data['service_id'] <= 0) $errors[] = 'Service is required.';
    if ($data['staff_id'] <= 0)   $errors[] = 'Staff is required.';
    if (!is_valid_date($data['booking_date'])) $errors[] = 'Date is required.';
    if (!is_valid_time($data['start_time']))   $errors[] = 'Time is required.';

    if (!$errors) {
        $svc = db_fetch("SELECT duration_minutes FROM services WHERE id = ?", [$data['service_id']]);
        if (!$svc) $errors[] = 'Selected service not found.';
    }

    if (!$errors) {
        $end = compute_end_time($data['start_time'], (int)$svc['duration_minutes']);
        // Only active bookings should reserve time.
        if (in_array($data['status'], booking_active_statuses(), true)) {
            if (has_booking_conflict((int)$data['staff_id'], $data['booking_date'], $data['start_time'], $end, (int)($booking['id'] ?? 0))) {
                $errors[] = 'That time conflicts with another booking for the same staff member.';
            }
            if (!$errors && has_blocked_overlap((int)$data['staff_id'], $data['booking_date'], $data['start_time'], $end)) {
                $errors[] = 'That time overlaps a blocked slot for the selected staff member.';
            }
        }
    }

    if (!$errors) {
        if ($booking) {
            $old_status = (string)($booking['status'] ?? '');
            // Staff role can only edit their own; already enforced above.
            db_exec(
                "UPDATE bookings SET customer_name=?, customer_email=?, customer_phone=?, notes=?,
                 service_id=?, staff_id=?, booking_date=?, start_time=?, end_time=?, status=?
                 WHERE id=?",
                [$data['customer_name'],$data['customer_email'],$data['customer_phone'],$data['notes'],
                 $data['service_id'],$data['staff_id'],$data['booking_date'],$data['start_time'],$end,$data['status'],
                 (int)$booking['id']]
            );
            if ($old_status !== $data['status']) {
                send_booking_status_change_email((int)$booking['id'], $old_status, $data['status']);
            }
            flash('ok', 'Booking updated.');
        } else {
            $token = uuid_v4();
            db_insert(
                "INSERT INTO bookings
                 (customer_name, customer_email, customer_phone, notes,
                  service_id, staff_id, booking_date, start_time, end_time,
                  status, management_token)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [$data['customer_name'],$data['customer_email'],$data['customer_phone'],$data['notes'],
                 $data['service_id'],$data['staff_id'],$data['booking_date'],$data['start_time'],$end,$data['status'], $token]
            );
            flash('ok', 'Booking created.');
        }
        redirect('/admin/bookings.php');
    }

    // Re-hydrate for re-render
    $booking = array_merge($booking ?? [], $data);
    $booking['id'] = $booking['id'] ?? null;
}

$services = db_all("SELECT id, name, duration_minutes, price FROM services ORDER BY name");
$staff = is_admin()
    ? db_all("SELECT id, name FROM staff WHERE is_active = 1 ORDER BY name")
    : [['id' => current_user()['id'], 'name' => current_user()['name']]];

// Build working-hours map: { staff_id: { dow: {start, end, is_off} } }
$wh_rows = db_all("SELECT staff_id, day_of_week, start_time, end_time, is_off FROM working_hours");
$working_hours_map = [];
foreach ($wh_rows as $r) {
    $working_hours_map[(int)$r['staff_id']][(int)$r['day_of_week']] = [
        'start' => $r['start_time'],
        'end'   => $r['end_time'],
        'is_off'=> (int)$r['is_off'] === 1,
    ];
}
// Map service_id -> duration_minutes for the client.
$service_duration_map = [];
foreach ($services as $s) $service_duration_map[(int)$s['id']] = (int)$s['duration_minutes'];

admin_header();
?>
<h1 class="text-2xl font-semibold mb-4"><?= $booking && !empty($booking['id']) ? 'Edit booking' : 'New booking' ?></h1>

<?php if ($errors): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
  <?php foreach ($errors as $er): ?>window.toast && window.toast(<?= json_encode($er) ?>, { type: 'error', timeout: 7000 });
  <?php endforeach; ?>
});
</script>
<?php endif; ?>

<form id="bookingEditForm" method="post" class="bg-white border border-neutral-200 rounded-xl p-5 space-y-4 max-w-2xl">
  <?= csrf_field() ?>
  <div class="grid md:grid-cols-2 gap-3">
    <label class="block text-sm">Customer name
      <input name="customer_name" required value="<?= e($booking['customer_name'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
    </label>
    <label class="block text-sm">Email <span class="text-neutral-400">(optional)</span>
      <input name="customer_email" type="email" value="<?= e($booking['customer_email'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
    </label>
    <label class="block text-sm">Phone
      <div class="mt-1" data-phone-input data-name="customer_phone" data-required data-default-cc="<?= e(default_phone_country_code()) ?>"
           data-value="<?= e($booking['customer_phone'] ?? '') ?>"></div>
    </label>
    <label class="block text-sm">Status
      <select name="status" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
        <?php foreach (booking_all_statuses() as $s): ?>
          <option value="<?= e($s) ?>" <?= ($booking['status'] ?? 'pending')===$s?'selected':'' ?>><?= e(booking_status_label($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="block text-sm">Service
      <select name="service_id" required class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
        <option value="">-</option>
        <?php foreach ($services as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= ($booking['service_id'] ?? 0)==(int)$s['id']?'selected':'' ?>><?= e($s['name']) ?> (<?= (int)$s['duration_minutes'] ?>m / <?= e(money_with_currency($s['price'])) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="block text-sm">Staff
      <select name="staff_id" required class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
        <?php foreach ($staff as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= ($booking['staff_id'] ?? 0)==(int)$s['id']?'selected':'' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="block text-sm">Date
      <input name="booking_date" type="date" required value="<?= e($booking['booking_date'] ?? business_today()) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
    </label>
    <label class="block text-sm">Start time
      <input name="start_time" type="time" required value="<?= e($booking['start_time'] ?? '10:00') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
    </label>
  </div>
  <label class="block text-sm">Notes
    <textarea name="notes" rows="3" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"><?= e($booking['notes'] ?? '') ?></textarea>
  </label>
  <div class="flex items-center gap-3">
    <button class="px-4 py-2 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">Save</button>
    <a href="/admin/bookings.php" class="px-4 py-2 rounded-lg border border-neutral-200 text-sm bg-white">Cancel</a>
    <?php if (!empty($booking['id']) && is_admin()): ?>
      <button name="action" value="delete" onclick="return confirm('Delete this booking?')" class="ml-auto px-4 py-2 rounded-lg border border-red-500 text-red-600 bg-white text-sm">Delete</button>
    <?php endif; ?>
  </div>
</form>

<script>
(function(){
  const workingHours = <?= json_encode($working_hours_map) ?>;
  const serviceDurations = <?= json_encode($service_duration_map) ?>;
  const form = document.getElementById('bookingEditForm');
  if (!form) return;

  function toMinutes(t) { const [h,m] = t.split(':'); return (+h)*60 + (+m); }

  form.addEventListener('submit', function(e){
    // Skip the working-hours check if the user clicked the Delete button.
    if (e.submitter && e.submitter.name === 'action' && e.submitter.value === 'delete') return;

    const staffId = +form.staff_id.value;
    const serviceId = +form.service_id.value;
    const date = form.booking_date.value;
    const start = form.start_time.value;
    if (!staffId || !serviceId || !date || !start) return;

    const dow = new Date(date + 'T00:00:00').getDay(); // 0=Sun..6=Sat
    const wh = (workingHours[staffId] || {})[dow];
    const duration = serviceDurations[serviceId] || 0;
    const startMin = toMinutes(start);
    const endMin = startMin + duration;

    let reason = null;
    if (!wh || wh.is_off) {
      reason = 'This staff member is marked OFF on ' + ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][dow] + '.';
    } else {
      const whStart = toMinutes(wh.start);
      let whEnd = toMinutes(wh.end);
      if (whEnd === whStart && whStart === 0) whEnd = 24 * 60;
      if (startMin < whStart || endMin > whEnd) {
        reason = 'This booking (' + start + ' – ' + String(Math.floor(endMin/60)).padStart(2,'0') + ':' + String(endMin%60).padStart(2,'0') +
          ') is outside the staff member\'s working hours (' + wh.start + ' – ' + wh.end + ').';
      }
    }

    if (reason) {
      // Always intercept — we need a Promise-based confirm dialog.
      e.preventDefault();
      (async () => {
        const ok = await window.confirmDialog(reason + '\n\nBook anyway?', {
          danger: true, okLabel: 'Book anyway', cancelLabel: 'Cancel'
        });
        if (ok) {
          // Temporarily disable this handler and resubmit.
          form._skipCheck = true;
          HTMLFormElement.prototype.submit.call(form);
        }
      })();
    }
  });
})();
</script>
<script src="/assets/js/phone-input.js"></script>
<?php admin_footer(); ?>
