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

$staff_ids = array_map(fn($s) => (int)$s['id'], $staff_list);
$selected_raw = (string)($_GET['staff_id'] ?? (is_admin() ? 'all' : (string)($staff_list[0]['id'] ?? 0)));
$selected_scope = (is_admin() && $selected_raw === 'all') ? 'all' : (string)(int)$selected_raw;
if ($scope !== null) $selected_scope = (string)(int)$scope;
if ($selected_scope !== 'all' && !in_array((int)$selected_scope, $staff_ids, true)) {
    $selected_scope = (string)(int)($staff_list[0]['id'] ?? 0);
}

$selected_id = $selected_scope === 'all' ? (int)($staff_list[0]['id'] ?? 0) : (int)$selected_scope;
if (!$selected_id) { admin_header(); echo '<p>No staff to manage.</p>'; admin_footer(); exit; }

$day_names = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$errors = [];

function ensure_staff_working_hours_rows(int $staff_id): void {
    $existing = db_all("SELECT day_of_week FROM working_hours WHERE staff_id = ?", [$staff_id]);
    $present = [];
    foreach ($existing as $r) $present[(int)$r['day_of_week']] = true;
    for ($d = 0; $d <= 6; $d++) {
        if (!isset($present[$d])) {
            $off = ($d === 0 || $d === 6) ? 1 : 0;
            db_insert(
                "INSERT INTO working_hours (staff_id, day_of_week, start_time, end_time, is_off) VALUES (?,?,?,?,?)",
                [$staff_id, $d, '09:00', '17:00', $off]
            );
        }
    }
}

// When viewing All staff, use the first active staff member's hours as the editable template.
ensure_staff_working_hours_rows($selected_id);
$rows = db_all("SELECT * FROM working_hours WHERE staff_id = ? ORDER BY day_of_week", [$selected_id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $updates = [];
    $all_week_24h = isset($_POST['all_week_24h']);
    $posted_scope = is_admin() ? (string)($_POST['staff_scope'] ?? $selected_scope) : (string)$selected_id;
    $save_all_staff = is_admin() && $posted_scope === 'all';
    $target_staff_ids = $save_all_staff ? $staff_ids : [(int)$posted_scope];
    $target_staff_ids = array_values(array_filter($target_staff_ids, fn($id) => in_array((int)$id, $staff_ids, true)));
    if (!$target_staff_ids) $errors[] = 'Choose a staff member to update.';

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
                $errors[] = $day_names[$d] . ': end time must be after start time, or use All day.';
            }
        }

        $updates[] = [$d, $start, $end, $off];
    }

    if (!$errors) {
        foreach ($target_staff_ids as $target_staff_id) {
            ensure_staff_working_hours_rows((int)$target_staff_id);
            foreach ($updates as [$d, $start, $end, $off]) {
                db_exec(
                    "UPDATE working_hours SET start_time = ?, end_time = ?, is_off = ? WHERE staff_id = ? AND day_of_week = ?",
                    [$start, $end, $off, (int)$target_staff_id, (int)$d]
                );
            }
        }
        flash('ok', $save_all_staff ? 'Working hours saved for all staff.' : 'Working hours saved.');
        redirect('/admin/staff-hours.php?staff_id=' . rawurlencode($posted_scope));
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
</div>

<form id="workingHoursForm" method="post" class="bg-white border border-neutral-200 rounded-xl p-5 max-w-3xl">
  <?= csrf_field() ?>
  <?php if (is_admin()): ?>
    <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 p-4">
      <div class="grid md:grid-cols-[180px_1fr] gap-3 md:items-center">
        <div>
          <div class="text-sm font-semibold">Hours apply to</div>
          <div class="text-xs text-neutral-500">Use All staff for business-wide hours.</div>
        </div>
        <select name="staff_scope" id="staffScope" class="w-full px-3 py-2 rounded-lg border border-neutral-200 text-sm">
          <option value="all" <?= $selected_scope === 'all' ? 'selected' : '' ?>>All staff</option>
          <?php foreach ($staff_list as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $selected_scope === (string)(int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  <?php else: ?>
    <input type="hidden" name="staff_scope" value="<?= (int)$selected_id ?>">
  <?php endif; ?>
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
          <input type="checkbox" name="all_day_<?= $d ?>" data-all-day <?= ($is_24h || $all_week_24h)?'checked':'' ?> <?= $all_week_24h?'disabled':'' ?>> All day
        </label>
        <input type="time" name="start_<?= $d ?>" value="<?= e($is_24h ? '00:00' : $r['start_time']) ?>" data-start class="px-2 py-1 border border-neutral-200 rounded-md text-sm disabled:bg-neutral-100 disabled:text-neutral-400" <?= $disable_times?'disabled':'' ?>>
        <span class="text-neutral-400">&ndash;</span>
        <input type="time" name="end_<?= $d ?>" value="<?= e($is_24h ? '00:00' : $r['end_time']) ?>" data-end class="px-2 py-1 border border-neutral-200 rounded-md text-sm disabled:bg-neutral-100 disabled:text-neutral-400" <?= $disable_times?'disabled':'' ?>>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="mt-4 flex flex-wrap items-center gap-3">
    <label class="text-sm inline-flex items-center gap-2 mr-auto">
      <input type="checkbox" name="all_week_24h" id="allWeek24h" <?= $all_week_24h?'checked':'' ?>> Open 24/7
    </label>
    <button class="px-4 py-2 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">Save</button>
  </div>
</form>

<script>
(function(){
  const form = document.getElementById('workingHoursForm');
  if (!form) return;
  const allWeek = document.getElementById('allWeek24h');
  const staffScope = document.getElementById('staffScope');

  if (staffScope) {
    staffScope.addEventListener('change', function() {
      const url = new URL(window.location.href);
      url.searchParams.set('staff_id', staffScope.value);
      window.location.href = url.toString();
    });
  }

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
