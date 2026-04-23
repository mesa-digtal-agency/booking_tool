<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-layout.php';

require_login();
$page_title = 'Working hours';
$active = 'hours';

$scope = scope_staff_id();
$staff_list = is_admin()
    ? db_all("SELECT id, name FROM staff WHERE is_active = 1 ORDER BY name")
    : db_all("SELECT id, name FROM staff WHERE id = ? AND is_active = 1", [(int)$scope]);

$selected_id = (int)($_GET['staff_id'] ?? ($staff_list[0]['id'] ?? 0));
if ($scope !== null) $selected_id = (int)$scope;
if (!$selected_id) { admin_header(); echo '<p>No staff to manage.</p>'; admin_footer(); exit; }

// Ensure 7 rows exist for this staff.
$rows = db_all("SELECT * FROM working_hours WHERE staff_id = ? ORDER BY day_of_week", [$selected_id]);
if (count($rows) < 7) {
    $present = [];
    foreach ($rows as $r) $present[(int)$r['day_of_week']] = true;
    for ($d = 0; $d <= 6; $d++) {
        if (!isset($present[$d])) {
            $off = ($d === 0 || $d === 6) ? 1 : 0;
            db_insert("INSERT INTO working_hours (staff_id, day_of_week, start_time, end_time, is_off) VALUES (?,?,?,?,?)",
                [$selected_id, $d, '09:00', '17:00', $off]);
        }
    }
    $rows = db_all("SELECT * FROM working_hours WHERE staff_id = ? ORDER BY day_of_week", [$selected_id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($rows as $r) {
        $d = (int)$r['day_of_week'];
        $start = normalize_time((string)($_POST["start_$d"] ?? '09:00')) ?? '09:00';
        $end   = normalize_time((string)($_POST["end_$d"] ?? '17:00')) ?? '17:00';
        $off   = isset($_POST["off_$d"]) ? 1 : 0;
        db_exec("UPDATE working_hours SET start_time = ?, end_time = ?, is_off = ? WHERE id = ?",
            [$start, $end, $off, (int)$r['id']]);
    }
    flash('ok', 'Working hours saved.');
    redirect('/admin/staff-hours.php?staff_id=' . $selected_id);
}

$day_names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

admin_header();
?>
<?= flash_render() ?>
<div class="flex items-center gap-3 mb-4">
  <h1 class="text-2xl font-semibold mr-auto">Working hours</h1>
  <?php if (is_admin()): ?>
  <form method="get" class="inline">
    <select name="staff_id" onchange="this.form.submit()" class="px-3 py-1.5 rounded-lg border border-neutral-200 text-sm">
      <?php foreach ($staff_list as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$selected_id?'selected':'' ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
</div>

<form method="post" class="bg-white border border-neutral-200 rounded-xl p-5 max-w-2xl">
  <?= csrf_field() ?>
  <div class="divide-y divide-neutral-100">
    <?php foreach ($rows as $r): $d = (int)$r['day_of_week']; ?>
      <div class="flex items-center gap-3 py-2">
        <div class="w-28 text-sm font-medium"><?= $day_names[$d] ?></div>
        <label class="text-sm inline-flex items-center gap-2">
          <input type="checkbox" name="off_<?= $d ?>" <?= (int)$r['is_off']===1?'checked':'' ?>> Off
        </label>
        <input type="time" name="start_<?= $d ?>" value="<?= e($r['start_time']) ?>" class="px-2 py-1 border border-neutral-200 rounded-md text-sm">
        <span class="text-neutral-400">–</span>
        <input type="time" name="end_<?= $d ?>" value="<?= e($r['end_time']) ?>" class="px-2 py-1 border border-neutral-200 rounded-md text-sm">
      </div>
    <?php endforeach; ?>
  </div>
  <div class="mt-4">
    <button class="px-4 py-2 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">Save</button>
  </div>
</form>
<?php admin_footer(); ?>
