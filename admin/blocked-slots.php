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

$errors = [];
$created_count = 0;

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

    $staff_id = (int)($_POST['staff_id'] ?? ($scope ?? 0));
    if ($scope !== null) $staff_id = (int)$scope;
    $date     = str_in($_POST, 'date', 10);
    $start    = normalize_time(str_in($_POST, 'start_time', 8)) ?? '';
    $end      = normalize_time(str_in($_POST, 'end_time', 8)) ?? '';
    $reason   = str_in($_POST, 'reason', 255);
    $repeat   = !empty($_POST['repeat']);
    $until    = str_in($_POST, 'until', 10);

    if ($staff_id <= 0) $errors[] = 'Staff is required.';
    if (!is_valid_date($date)) $errors[] = 'Date is required.';
    if (!is_valid_time($start) || !is_valid_time($end)) $errors[] = 'Start and end times are required.';
    if (!$errors && time_to_minutes($end) <= time_to_minutes($start)) $errors[] = 'End time must be after start time.';
    if ($repeat) {
        if (!is_valid_date($until)) $errors[] = 'Repeat-until date is required when "repeat on working days" is checked.';
        if (!$errors && $until < $date) $errors[] = 'Repeat-until date must be on or after the start date.';
    }

    if (!$errors) {
        if ($repeat) {
            // Pull staff's working_hours and insert a block for every non-off
            // day from $date through $until (inclusive).
            $wh = db_all(
                "SELECT day_of_week, is_off FROM working_hours WHERE staff_id = ?",
                [$staff_id]
            );
            $off_dows = [];
            foreach ($wh as $r) {
                if ((int)$r['is_off'] === 1) $off_dows[(int)$r['day_of_week']] = true;
            }

            $cur = new DateTime($date);
            $stop = new DateTime($until);
            $stop->modify('+1 day'); // make the loop inclusive of $until
            $max_days = 370; // safety cap — at most one year forward
            $i = 0;
            while ($cur < $stop && $i < $max_days) {
                $dow = (int)$cur->format('w'); // 0 = Sunday
                if (empty($off_dows[$dow])) {
                    db_insert(
                        "INSERT INTO blocked_slots (staff_id, date, start_time, end_time, reason) VALUES (?,?,?,?,?)",
                        [$staff_id, $cur->format('Y-m-d'), $start, $end, $reason]
                    );
                    $created_count++;
                }
                $cur->modify('+1 day');
                $i++;
            }
            flash('ok', $created_count > 0
                ? "Added $created_count blocked slot(s) across working days."
                : 'No working days found in that range — nothing was added.');
        } else {
            db_insert(
                "INSERT INTO blocked_slots (staff_id, date, start_time, end_time, reason) VALUES (?,?,?,?,?)",
                [$staff_id, $date, $start, $end, $reason]
            );
            flash('ok', 'Blocked slot added.');
        }
        redirect('/admin/blocked-slots.php');
    }
}

$where = "date >= ?";
$params = [date('Y-m-d', strtotime('-7 days'))];
if ($scope !== null) { $where .= " AND staff_id = ?"; $params[] = (int)$scope; }
$rows = db_all("SELECT bs.*, st.name AS staff_name FROM blocked_slots bs JOIN staff st ON st.id = bs.staff_id WHERE $where ORDER BY date DESC, start_time", $params);

admin_header();
?>
<?= flash_render() ?>
<h1 class="text-2xl font-semibold mb-4">Blocked slots</h1>

<?php if ($errors): ?>
  <script>window.addEventListener('DOMContentLoaded', function(){ <?php foreach ($errors as $er) echo 'window.toast && window.toast(' . json_encode($er) . ', { type: "error", timeout: 7000 });'; ?> });</script>
<?php endif; ?>

<form method="post" id="blockForm" class="bg-white border border-neutral-200 rounded-xl p-5 mb-6 max-w-3xl grid md:grid-cols-5 gap-3 text-sm">
  <?= csrf_field() ?>
  <?php if (is_admin()): ?>
  <label>Staff
    <select name="staff_id" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      <?php foreach ($staff_list as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>
  <label>Start date <input type="date" name="date" required value="<?= e(date('Y-m-d')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
  <label>Start <input type="time" name="start_time" required value="12:00" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
  <label>End <input type="time" name="end_time" required value="13:00" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
  <label class="md:col-span-<?= is_admin() ? '5' : '4' ?>">Reason <input name="reason" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md" placeholder="e.g. Lunch, vacation"></label>

  <div class="md:col-span-5 border-t border-neutral-100 pt-3 mt-1 space-y-2">
    <label class="inline-flex items-center gap-2 cursor-pointer">
      <input type="checkbox" name="repeat" id="repeatCheckbox">
      <span>Repeat on every working day <span class="text-neutral-400 text-xs">(perfect for lunch breaks or recurring off-hours)</span></span>
    </label>
    <div id="repeatFields" class="hidden">
      <label class="block max-w-xs">Until
        <input type="date" name="until" value="<?= e(date('Y-m-d', strtotime('+60 days'))) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <p class="text-xs text-neutral-500 mt-1">Blocks will only be added for the days that aren't marked OFF in the staff's working hours.</p>
    </div>
  </div>

  <div class="md:col-span-5"><button class="px-4 py-2 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">Add block</button></div>
</form>

<div class="bg-white border border-neutral-200 rounded-xl overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-neutral-50 text-left text-neutral-500 text-xs uppercase">
      <tr>
        <th class="px-3 py-2">Date</th>
        <th class="px-3 py-2">Time</th>
        <th class="px-3 py-2">Staff</th>
        <th class="px-3 py-2">Reason</th>
        <th class="px-3 py-2"></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5" class="px-3 py-8 text-center text-neutral-500">No blocked slots.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr class="border-t border-neutral-100">
        <td class="px-3 py-2"><?= e($r['date']) ?></td>
        <td class="px-3 py-2"><?= e(format_time_display($r['start_time'])) ?> – <?= e(format_time_display($r['end_time'])) ?></td>
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

<script>
(function(){
  const cb = document.getElementById('repeatCheckbox');
  const fields = document.getElementById('repeatFields');
  if (!cb || !fields) return;
  const toggle = () => { fields.classList.toggle('hidden', !cb.checked); };
  cb.addEventListener('change', toggle); toggle();
})();
</script>
<?php admin_footer(); ?>
