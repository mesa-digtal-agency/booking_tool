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

$day_names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$errors = [];

// Ensure 7 rows exist for this staff.
$rows = db_all("SELECT * FROM working_hours WHERE staff_id = ? ORDER BY day_of_week", [$selected_id]);
if (count($rows) < 7) {
    $present = [];
    foreach ($rows as $r) $present[(int)$r['day_of_week']] = true;
    for ($d = 0; $d <= 6; $d++) {
        if (!isset($present[$d])) {
            $off = ($d === 0 || $d === 6) ? 1 : 0;
            db_insert(
                "INSERT INTO working_hours (staff_id, day_of_week, start_time, end_time, is_off) VALUES (?,?,?,?,?)",
                [$selected_id, $d, '09:00', '17:00', $off]
            );
        }
    }
    $rows = db_all("SELECT * FROM working_hours WHERE staff_id = ? ORDER BY day_of_week", [$selected_id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $updates = [];
    $all_week_24h = isset($_POST['all_week_24h']);

    foreach ($rows as $r) {
        $d = (int)$r['day_of_week'];
        $all_day = $all_week_24h || isset($_POST["all_day_$d"]);
        $off = (!$all_day && isset($_POST["off_$d"])) ? 1 : 0;

        if ($all_day) {
            $start = '00:00';
            $end = '00:00';
        } else {
            $start = normalize_time((string)($_POST["start_$d"] ?? ($r['start_time'] ?? '09:00'))) ?? '09:00';
            $end = normalize_time((string)($_POST["end_$d"] ?? ($r['end_time'] ?? '17:00'))) ?? '17:00';

            if (!$off && (!is_valid_time($start) || !is_valid_time($end))) {
                $errors[] = $day_names[$d] . ': start and end times must be valid.';
            } elseif (!$off && time_to_minutes($end) <= time_to_minutes($start)) {
                $errors[] = $day_names[$d] . ': end time must be after start time, or use 24 hours.';
            }
        }

        $updates[] = [$start, $end, $off, (int)$r['id']];
    }

    if (!$errors) {
        foreach ($updates as $u) {
            db_exec("UPDATE working_hours SET start_time = ?, end_time = ?, is_off = ? WHERE id = ?", $u);
        }
        flash('ok', 'Working hours saved.');
        redirect('/admin/staff-hours.php?staff_id=' . $selected_id);
    }
}

$all_week_24h = count($rows) === 7;
foreach ($rows as $r) {
    $is_row_24h = (int)$r['is_off'] !== 1 && $r['start_time'] === $r['end_time'];
    if (!$is_row_24h) { $all_week_24h = false; break; }
}

admin_header();
?>
<?= flash_render() ?>
<?php if ($errors): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
  <?php foreach ($errors as $er): ?>window.toast && window.toast(<?= json_encode($er) ?>, { type: 'error', timeout: 7000 });
  <?php endforeach; ?>
});
</script>
<?php endif; ?>

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

<form id="workingHoursForm" method="post" class="bg-white border border-neutral-200 rounded-xl p-5 max-w-3xl">
  <?= csrf_field() ?>
  <div class="divide-y divide-neutral-100">
    <?php foreach ($rows as $r):
      $d = (int)$r['day_of_week'];
      $is_off = (int)$r['is_off'] === 1;
      $is_24h = !$is_off && $r['start_time'] === $r['end_time'];
      $disable_times = $is_off || $is_24h || $all_week_24h;
    ?>
      <div class="flex flex-wrap items-center gap-3 py-2" data-hours-row>
        <div class="w-28 text-sm font-medium"><?= e($day_names[$d]) ?></div>
        <label class="text-sm inline-flex items-center gap-2">
          <input type="checkbox" name="off_<?= $d ?>" data-off <?= $is_off?'checked':'' ?> <?= $all_week_24h?'disabled':'' ?>> Off
        </label>
        <label class="text-sm inline-flex items-center gap-2">
          <input type="checkbox" name="all_day_<?= $d ?>" data-all-day <?= ($is_24h || $all_week_24h)?'checked':'' ?> <?= $all_week_24h?'disabled':'' ?>> 24 hours
        </label>
        <input type="time" name="start_<?= $d ?>" value="<?= e($is_24h ? '00:00' : $r['start_time']) ?>" data-start class="px-2 py-1 border border-neutral-200 rounded-md text-sm disabled:bg-neutral-100 disabled:text-neutral-400" <?= $disable_times?'disabled':'' ?>>
        <span class="text-neutral-400">&ndash;</span>
        <input type="time" name="end_<?= $d ?>" value="<?= e($is_24h ? '00:00' : $r['end_time']) ?>" data-end class="px-2 py-1 border border-neutral-200 rounded-md text-sm disabled:bg-neutral-100 disabled:text-neutral-400" <?= $disable_times?'disabled':'' ?>>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="mt-4 flex flex-wrap items-center gap-3">
    <label class="text-sm inline-flex items-center gap-2 mr-auto">
      <input type="checkbox" name="all_week_24h" id="allWeek24h" <?= $all_week_24h?'checked':'' ?>> 24/7
    </label>
    <button class="px-4 py-2 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">Save</button>
  </div>
</form>

<script>
(function(){
  const form = document.getElementById('workingHoursForm');
  if (!form) return;
  const allWeek = document.getElementById('allWeek24h');

  function syncRow(row) {
    const off = row.querySelector('[data-off]');
    const allDay = row.querySelector('[data-all-day]');
    const start = row.querySelector('[data-start]');
    const end = row.querySelector('[data-end]');
    const isAllWeek = allWeek && allWeek.checked;
    const isOff = !!(off && off.checked);
    const isAllDay = !!(allDay && allDay.checked);

    if (isAllWeek) {
      if (off) { off.checked = false; off.disabled = true; }
      if (allDay) { allDay.checked = true; allDay.disabled = true; }
      if (start) { start.value = '00:00'; start.disabled = true; }
      if (end) { end.value = '00:00'; end.disabled = true; }
      return;
    }

    if (off) off.disabled = false;
    if (allDay) allDay.disabled = false;
    if (allDay && isOff) allDay.checked = false;

    const disableTimes = isOff || !!(allDay && allDay.checked);
    if (start) {
      if (allDay && allDay.checked) start.value = '00:00';
      start.disabled = disableTimes;
    }
    if (end) {
      if (allDay && allDay.checked) end.value = '00:00';
      end.disabled = disableTimes;
    }
  }

  function syncAll() {
    form.querySelectorAll('[data-hours-row]').forEach(syncRow);
  }

  form.addEventListener('change', function(e) {
    const row = e.target.closest('[data-hours-row]');
    if (row) syncRow(row);
    if (e.target === allWeek) syncAll();
  });

  form.addEventListener('submit', function() {
    form.querySelectorAll('[data-hours-row]').forEach(function(row) {
      const allDay = row.querySelector('[data-all-day]');
      const start = row.querySelector('[data-start]');
      const end = row.querySelector('[data-end]');
      if ((allWeek && allWeek.checked) || (allDay && allDay.checked)) {
        if (start) { start.disabled = false; start.value = '00:00'; }
        if (end) { end.disabled = false; end.value = '00:00'; }
      }
    });
  });

  syncAll();
})();
</script>
<?php admin_footer(); ?>
