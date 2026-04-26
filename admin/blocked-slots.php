<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-layout.php';

require_login();
$page_title = 'Blocked slots';
$active = 'blocked';

$scope = scope_staff_id();
$staff_list = is_admin()
    ? db_all("SELECT id, name FROM staff WHERE is_active = 1 ORDER BY name")
    : db_all("SELECT id, name FROM staff WHERE id = ? AND is_active = 1", [(int)$scope]);
$staff_ids = array_map(fn($s) => (int)$s['id'], $staff_list);
$selected_staff_scope = is_admin() ? (string)($_POST['staff_scope'] ?? 'all') : (string)($scope ?? 0);
if (is_admin() && $selected_staff_scope !== 'all' && !in_array((int)$selected_staff_scope, $staff_ids, true)) {
    $selected_staff_scope = 'all';
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'create');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $row = db_fetch("SELECT * FROM blocked_slots WHERE id = ?", [$id]);
        if ($row && ($scope === null || (int)$row['staff_id'] === $scope)) {
            db_exec("DELETE FROM blocked_slots WHERE id = ?", [$id]);
            flash('ok', 'Blocked slot removed.');
        }
        redirect('/admin/blocked-slots.php');
    }

    if ($action === 'bulk_delete') {
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_values(array_unique(array_map('intval', $_POST['ids']))) : [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = $ids;
            $scope_sql = '';
            if ($scope !== null) {
                $scope_sql = ' AND staff_id = ?';
                $params[] = (int)$scope;
            }
            $deleted = db_exec("DELETE FROM blocked_slots WHERE id IN ($placeholders)$scope_sql", $params);
            flash('ok', $deleted > 0 ? "Removed $deleted blocked slot(s)." : 'No blocked slots were removed.');
        } else {
            flash('warn', 'Select at least one blocked slot to delete.');
        }
        redirect('/admin/blocked-slots.php');
    }

    $staff_scope = is_admin() ? (string)($_POST['staff_scope'] ?? 'all') : (string)($scope ?? 0);
    $apply_all_staff = is_admin() && $staff_scope === 'all';
    $target_staff_ids = $apply_all_staff ? $staff_ids : [(int)$staff_scope];
    if ($scope !== null) $target_staff_ids = [(int)$scope];
    $target_staff_ids = array_values(array_filter($target_staff_ids, fn($id) => in_array((int)$id, $staff_ids, true)));
    $date   = str_in($_POST, 'date', 10);
    $start  = normalize_time(str_in($_POST, 'start_time', 8)) ?? '';
    $end    = normalize_time(str_in($_POST, 'end_time', 8)) ?? '';
    $reason = str_in($_POST, 'reason', 255);
    $repeat = !empty($_POST['repeat']);
    $repeat_until_enabled = $repeat && !empty($_POST['repeat_until_enabled']);
    $until  = str_in($_POST, 'until', 10);

    if (!$target_staff_ids) $errors[] = 'Staff is required.';
    if (!is_valid_date($date)) $errors[] = 'Date is required.';
    if (!is_valid_time($start) || !is_valid_time($end)) $errors[] = 'Start and end times are required.';
    if (!$errors) {
        $sm = time_to_minutes($start);
        $em = time_to_minutes($end);
        if ($em <= $sm) $errors[] = 'End time must be after start time.';
        if (!$errors && ($em - $sm) >= 24 * 60) $errors[] = 'A single block cannot span 24 hours or more.';
    }
    if ($repeat_until_enabled) {
        if (!is_valid_date($until)) $errors[] = 'Repeat-until date is required when the repeat end date is enabled.';
        if (!$errors && $until < $date) $errors[] = 'Repeat-until date must be on or after the start date.';
    }
    if (!$repeat_until_enabled) $until = '';

    if (!$errors) {
        foreach ($target_staff_ids as $target_staff_id) {
            db_insert(
                "INSERT INTO blocked_slots (staff_id, date, start_time, end_time, reason, repeat_until, repeat_mode)
                 VALUES (?,?,?,?,?,?,?)",
                [(int)$target_staff_id, $date, $start, $end, $reason, $repeat && $until !== '' ? $until : null, $repeat ? 'working_day' : null]
            );
        }
        if (count($target_staff_ids) > 1) {
            flash('ok', $repeat ? 'Recurring blocked slot added for all staff.' : 'Blocked slot added for all staff.');
        } else {
            flash('ok', $repeat ? 'Recurring blocked slot added.' : 'Blocked slot added.');
        }
        redirect('/admin/blocked-slots.php');
    }
}

$cutoff = date('Y-m-d', strtotime('-7 days'));
$where = "(bs.date >= ? OR (COALESCE(bs.repeat_mode, '') = 'working_day' AND (bs.repeat_until IS NULL OR bs.repeat_until >= ?)))";
$params = [$cutoff, $cutoff];
if ($scope !== null) {
    $where .= " AND bs.staff_id = ?";
    $params[] = (int)$scope;
}
$rows = db_all(
    "SELECT bs.*, st.name AS staff_name
     FROM blocked_slots bs
     JOIN staff st ON st.id = bs.staff_id
     WHERE $where
     ORDER BY bs.date DESC, bs.start_time",
    $params
);

admin_header();
?>
<?= flash_render() ?>
<h1 class="text-2xl font-semibold mb-4">Blocked slots</h1>

<?php if ($errors): ?>
  <script>window.addEventListener('DOMContentLoaded', function(){ <?php foreach ($errors as $er) echo 'window.toast && window.toast(' . json_encode($er) . ', { type: "error", timeout: 7000 });'; ?> });</script>
<?php endif; ?>

<form method="post" id="blockForm" class="bg-white border border-neutral-200 rounded-xl p-5 mb-6 max-w-3xl text-sm">
  <?= csrf_field() ?>
  <?php if (is_admin()): ?>
    <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 p-4">
      <div class="grid md:grid-cols-[180px_1fr] gap-3 md:items-center">
        <div>
          <div class="text-sm font-semibold">Blockers apply to</div>
          <div class="text-xs text-neutral-500">Use All staff for business-wide blocked time.</div>
        </div>
        <select name="staff_scope" class="w-full px-3 py-2 rounded-lg border border-neutral-200 text-sm">
          <option value="all" <?= $selected_staff_scope === 'all' ? 'selected' : '' ?>>All staff</option>
          <?php foreach ($staff_list as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $selected_staff_scope === (string)(int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  <?php else: ?>
    <input type="hidden" name="staff_scope" value="<?= (int)($scope ?? 0) ?>">
  <?php endif; ?>
  <div class="grid md:grid-cols-5 gap-3">
    <label>Start date <input type="date" name="date" required value="<?= e(business_today()) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
    <label>Start <input type="time" name="start_time" required value="12:00" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
    <label>End <input type="time" name="end_time" required value="13:00" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
    <label class="md:col-span-<?= is_admin() ? '5' : '4' ?>">Reason <input name="reason" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md" placeholder="e.g. Lunch, vacation"></label>
  </div>

  <div class="border-t border-neutral-100 pt-3 mt-4 space-y-2">
    <label class="inline-flex items-center gap-2 cursor-pointer">
      <input type="checkbox" name="repeat" id="repeatCheckbox">
      <span>Repeat on every working day <span class="text-neutral-400 text-xs">(stored as one recurring blocker)</span></span>
    </label>
    <div id="repeatFields" class="hidden space-y-2">
      <label class="inline-flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="repeat_until_enabled" id="repeatUntilCheckbox">
        <span>End repeat on a date</span>
      </label>
      <div id="repeatUntilField" class="hidden max-w-xs">
        <label class="block">Until
          <input type="date" name="until" id="repeatUntilDate" value="<?= e((new DateTimeImmutable(business_today(), business_timezone_obj()))->modify('+60 days')->format('Y-m-d')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md" disabled>
        </label>
      </div>
      <p class="text-xs text-neutral-500">Without an end date, the recurring blocker repeats forever on days that are not marked off.</p>
    </div>
  </div>

  <div class="mt-4"><button class="px-4 py-2 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">Add block</button></div>
</form>

<div class="bg-white border border-neutral-200 rounded-xl overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-neutral-50 text-left text-neutral-500 text-xs uppercase">
      <tr>
        <th class="px-3 py-2 w-10"><input type="checkbox" id="selectAllBlocks"></th>
        <th class="px-3 py-2">Date</th>
        <th class="px-3 py-2">Time</th>
        <th class="px-3 py-2">Staff</th>
        <th class="px-3 py-2">Reason</th>
        <th class="px-3 py-2"></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="px-3 py-8 text-center text-neutral-500">No blocked slots.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr class="border-t border-neutral-100">
        <td class="px-3 py-2"><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" class="blockCheckbox" form="bulkDeleteForm"></td>
        <td class="px-3 py-2">
          <?= e($r['date']) ?>
          <?php if (($r['repeat_mode'] ?? '') === 'working_day'): ?>
            <div class="text-xs text-neutral-500">
              <?= !empty($r['repeat_until']) ? 'Repeats on working days until ' . e($r['repeat_until']) : 'Repeats on working days forever' ?>
            </div>
          <?php endif; ?>
        </td>
        <td class="px-3 py-2"><?= e(format_time_display($r['start_time'])) ?> - <?= e(format_time_display($r['end_time'])) ?></td>
        <td class="px-3 py-2"><?= e($r['staff_name']) ?></td>
        <td class="px-3 py-2"><?= e($r['reason']) ?></td>
        <td class="px-3 py-2 text-right">
          <form method="post" class="inline" onsubmit="return confirm('Remove this blocked slot?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="text-red-600 hover:underline">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="mt-3 flex items-center justify-between text-sm">
  <div class="text-neutral-500"><?= count($rows) ?> blocker record(s)</div>
  <button type="submit" form="bulkDeleteForm" id="bulkDeleteButton" class="px-3 py-1.5 rounded-lg border border-red-500 text-red-600 bg-white">Delete selected</button>
</div>
<form method="post" id="bulkDeleteForm" class="hidden">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_delete">
</form>

<script>
(function(){
  const cb = document.getElementById('repeatCheckbox');
  const fields = document.getElementById('repeatFields');
  const untilCb = document.getElementById('repeatUntilCheckbox');
  const untilField = document.getElementById('repeatUntilField');
  const untilDate = document.getElementById('repeatUntilDate');
  if (cb && fields) {
    const toggle = () => {
      fields.classList.toggle('hidden', !cb.checked);
      if (!cb.checked && untilCb) untilCb.checked = false;
      const showUntil = !!(cb.checked && untilCb && untilCb.checked);
      if (untilField) untilField.classList.toggle('hidden', !showUntil);
      if (untilDate) untilDate.disabled = !showUntil;
    };
    cb.addEventListener('change', toggle);
    if (untilCb) untilCb.addEventListener('change', toggle);
    toggle();
  }

  const all = document.getElementById('selectAllBlocks');
  if (all) {
    all.addEventListener('change', function(){
      document.querySelectorAll('.blockCheckbox').forEach(el => { el.checked = all.checked; });
    });
  }

  const bulkButton = document.getElementById('bulkDeleteButton');
  const bulkForm = document.getElementById('bulkDeleteForm');
  if (bulkButton && bulkForm) {
    bulkButton.addEventListener('click', function(e) {
      const selected = document.querySelectorAll('.blockCheckbox:checked').length;
      if (selected === 0) {
        e.preventDefault();
        window.toast && window.toast('Select at least one blocked slot to delete.', { type: 'warn' });
        return;
      }
      if (!window.confirm('Remove the selected blocked slots?')) {
        e.preventDefault();
      }
    });
  }
})();
</script>
<?php admin_footer(); ?>
