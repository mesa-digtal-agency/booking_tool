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
        'status'         => (string)($_POST['status'] ?? 'confirmed'),
    ];
    if (!in_array($data['status'], ['pending','confirmed','cancelled','completed'], true)) $data['status'] = 'confirmed';

    // Validation
    if ($data['customer_name'] === '') $errors[] = 'Customer name is required.';
    if (!is_valid_email($data['customer_email'])) $errors[] = 'Valid email is required.';
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
        // Conflict check (skip the row we're editing and skip cancelled status).
        $conflict = db_scalar(
            "SELECT 1 FROM bookings
             WHERE staff_id = ? AND booking_date = ? AND status IN ('pending','confirmed')
             AND id <> ?
             AND NOT (end_time <= ? OR start_time >= ?)
             LIMIT 1",
            [$data['staff_id'], $data['booking_date'], (int)($booking['id'] ?? 0), $data['start_time'], $end]
        );
        if ($conflict && $data['status'] !== 'cancelled') {
            $errors[] = 'That time conflicts with another booking for the same staff member.';
        }
    }

    if (!$errors) {
        if ($booking) {
            // Staff role can only edit their own; already enforced above.
            db_exec(
                "UPDATE bookings SET customer_name=?, customer_email=?, customer_phone=?, notes=?,
                 service_id=?, staff_id=?, booking_date=?, start_time=?, end_time=?, status=?
                 WHERE id=?",
                [$data['customer_name'],$data['customer_email'],$data['customer_phone'],$data['notes'],
                 $data['service_id'],$data['staff_id'],$data['booking_date'],$data['start_time'],$end,$data['status'],
                 (int)$booking['id']]
            );
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

admin_header();
?>
<h1 class="text-2xl font-semibold mb-4"><?= $booking && !empty($booking['id']) ? 'Edit booking' : 'New booking' ?></h1>

<?php if ($errors): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 text-sm mb-4">
    <?php foreach ($errors as $er) echo '<div>' . e($er) . '</div>'; ?>
  </div>
<?php endif; ?>

<form method="post" class="bg-white border border-neutral-200 rounded-xl p-5 space-y-4 max-w-2xl">
  <?= csrf_field() ?>
  <div class="grid md:grid-cols-2 gap-3">
    <label class="block text-sm">Customer name
      <input name="customer_name" required value="<?= e($booking['customer_name'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
    </label>
    <label class="block text-sm">Email
      <input name="customer_email" type="email" required value="<?= e($booking['customer_email'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
    </label>
    <label class="block text-sm">Phone
      <input name="customer_phone" required value="<?= e($booking['customer_phone'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
    </label>
    <label class="block text-sm">Status
      <select name="status" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
        <?php foreach (['pending','confirmed','cancelled','completed'] as $s): ?>
          <option <?= ($booking['status'] ?? 'confirmed')===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="block text-sm">Service
      <select name="service_id" required class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
        <option value="">—</option>
        <?php foreach ($services as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= ($booking['service_id'] ?? 0)==(int)$s['id']?'selected':'' ?>><?= e($s['name']) ?> (<?= (int)$s['duration_minutes'] ?>m · $<?= format_money($s['price']) ?>)</option>
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
      <input name="booking_date" type="date" required value="<?= e($booking['booking_date'] ?? date('Y-m-d')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
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
<?php admin_footer(); ?>
