<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-layout.php';

require_login();
$page_title = 'Bookings';
$active = 'bookings';

$scope = scope_staff_id();

// Filters
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d', strtotime('+30 days'));
if (!is_valid_date($from)) $from = date('Y-m-d', strtotime('-7 days'));
if (!is_valid_date($to))   $to   = date('Y-m-d', strtotime('+30 days'));

$status_filter  = $_GET['status']     ?? '';
$staff_filter   = $_GET['staff_id']   ?? '';
$service_filter = $_GET['service_id'] ?? '';

$where = ['b.booking_date BETWEEN ? AND ?'];
$params = [$from, $to];
if (in_array($status_filter, ['pending','confirmed','cancelled','completed'], true)) {
    $where[] = 'b.status = ?'; $params[] = $status_filter;
}
if ($scope !== null) {
    $where[] = 'b.staff_id = ?'; $params[] = (int)$scope;
} elseif ($staff_filter !== '' && $staff_filter !== 'all') {
    $where[] = 'b.staff_id = ?'; $params[] = (int)$staff_filter;
}
if ($service_filter !== '' && $service_filter !== 'all') {
    $where[] = 'b.service_id = ?'; $params[] = (int)$service_filter;
}

$sql = "SELECT b.*, s.name AS service_name, s.price, st.name AS staff_name
        FROM bookings b
        JOIN services s ON s.id = b.service_id
        JOIN staff st ON st.id = b.staff_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY b.booking_date DESC, b.start_time DESC";

// CSV export
if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_all($sql, $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bookings-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Date','Start','End','Status','Customer','Email','Phone','Service','Staff','Price','Notes','Created']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['booking_date'], $r['start_time'], $r['end_time'], $r['status'],
            $r['customer_name'], $r['customer_email'], $r['customer_phone'],
            $r['service_name'], $r['staff_name'], number_format((float)$r['price'], 2, '.', ''),
            $r['notes'], $r['created_at']
        ]);
    }
    fclose($out);
    exit;
}

$rows = db_all($sql, $params);
$services = db_all("SELECT id, name FROM services ORDER BY name");
$staff = db_all("SELECT id, name FROM staff ORDER BY name");

admin_header();
?>
<?= flash_render() ?>
<div class="flex items-center mb-4">
  <h1 class="text-2xl font-semibold mr-auto">Bookings</h1>
  <a href="/admin/booking-edit.php" class="px-3 py-1.5 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">+ New booking</a>
</div>

<form method="get" class="bg-white border border-neutral-200 rounded-xl p-4 mb-4 grid md:grid-cols-5 gap-3 text-sm">
  <label>From<input type="date" name="from" value="<?= e($from) ?>" class="w-full mt-1 px-2 py-1.5 border border-neutral-200 rounded-md"></label>
  <label>To<input type="date" name="to" value="<?= e($to) ?>" class="w-full mt-1 px-2 py-1.5 border border-neutral-200 rounded-md"></label>
  <label>Status
    <select name="status" class="w-full mt-1 px-2 py-1.5 border border-neutral-200 rounded-md">
      <option value="">All</option>
      <?php foreach (['pending','confirmed','cancelled','completed'] as $s): ?>
        <option <?= $s===$status_filter?'selected':'' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if (is_admin()): ?>
  <label>Staff
    <select name="staff_id" class="w-full mt-1 px-2 py-1.5 border border-neutral-200 rounded-md">
      <option value="all">All</option>
      <?php foreach ($staff as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= (string)$s['id']===$staff_filter?'selected':'' ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>
  <label>Service
    <select name="service_id" class="w-full mt-1 px-2 py-1.5 border border-neutral-200 rounded-md">
      <option value="all">All</option>
      <?php foreach ($services as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= (string)$s['id']===$service_filter?'selected':'' ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="md:col-span-5 flex gap-2">
    <button class="px-3 py-1.5 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">Apply</button>
    <a class="px-3 py-1.5 rounded-lg bg-white border border-neutral-200 text-sm" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&status=<?= e($status_filter) ?>&staff_id=<?= e($staff_filter) ?>&service_id=<?= e($service_filter) ?>&export=csv">Export CSV</a>
    <a class="px-3 py-1.5 rounded-lg bg-white border border-neutral-200 text-sm" href="/admin/bookings.php">Reset</a>
  </div>
</form>

<div class="bg-white border border-neutral-200 rounded-xl overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead class="bg-neutral-50 text-left text-neutral-500 text-xs uppercase">
        <tr>
          <th class="px-3 py-2">Date</th>
          <th class="px-3 py-2">Time</th>
          <th class="px-3 py-2">Customer</th>
          <th class="px-3 py-2">Service</th>
          <th class="px-3 py-2">Staff</th>
          <th class="px-3 py-2">Status</th>
          <th class="px-3 py-2">Price</th>
          <th class="px-3 py-2"></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="px-3 py-8 text-center text-neutral-500">No bookings match these filters.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
        $colors = [
          'confirmed' => 'bg-emerald-100 text-emerald-700',
          'pending'   => 'bg-amber-100 text-amber-700',
          'cancelled' => 'bg-red-100 text-red-700',
          'completed' => 'bg-neutral-100 text-neutral-700',
        ];
      ?>
        <tr class="border-t border-neutral-100">
          <td class="px-3 py-2 whitespace-nowrap"><?= e($r['booking_date']) ?></td>
          <td class="px-3 py-2 whitespace-nowrap"><?= e(format_time_display($r['start_time'])) ?> – <?= e(format_time_display($r['end_time'])) ?></td>
          <td class="px-3 py-2">
            <div class="font-medium"><?= e($r['customer_name']) ?></div>
            <div class="text-xs text-neutral-500"><?= e($r['customer_email']) ?> · <?= e($r['customer_phone']) ?></div>
          </td>
          <td class="px-3 py-2"><?= e($r['service_name']) ?></td>
          <td class="px-3 py-2"><?= e($r['staff_name']) ?></td>
          <td class="px-3 py-2"><span class="text-xs px-2 py-0.5 rounded-full <?= e($colors[$r['status']] ?? '') ?>"><?= e($r['status']) ?></span></td>
          <td class="px-3 py-2">$<?= format_money($r['price']) ?></td>
          <td class="px-3 py-2 text-right"><a class="text-primary hover:underline" href="/admin/booking-edit.php?id=<?= (int)$r['id'] ?>">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="text-xs text-neutral-500 mt-2"><?= count($rows) ?> result(s)</p>
<?php admin_footer(); ?>
