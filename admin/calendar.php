<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-layout.php';
require_once __DIR__ . '/../includes/availability.php';

require_login();
$page_title = 'Calendar';
$active = 'calendar';

$view = $_GET['view'] ?? 'week'; // week|day
$today = business_today();
$start_param = $_GET['start'] ?? $today;
if (!is_valid_date($start_param)) $start_param = $today;
$scope = scope_staff_id();

// For week: find Monday of the start.
if ($view === 'week') {
    $dt = new DateTime($start_param);
    $wd = (int)$dt->format('w'); // 0..6 Sun..Sat
    $offset = ($wd === 0) ? -6 : 1 - $wd;
    $dt->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
    $week_start = $dt->format('Y-m-d');
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = (clone $dt)->modify("+$i days")->format('Y-m-d');
    }
} else {
    $days = [$start_param];
    $week_start = $start_param;
}

$from = $days[0];
$to   = end($days);

// Staff filter / dropdown
$staff_filter_raw = $_GET['staff_id'] ?? 'all';
$staff_filter = null;
if ($scope !== null) {
    $staff_filter = (int)$scope;
} elseif ($staff_filter_raw !== 'all' && $staff_filter_raw !== '') {
    $staff_filter = (int)$staff_filter_raw;
}
$staff_where = $staff_filter ? " AND b.staff_id = " . (int)$staff_filter : "";

$bookings = db_all(
    "SELECT b.*, s.name AS service_name, st.name AS staff_name
     FROM bookings b
     JOIN services s ON s.id = b.service_id
     JOIN staff st ON st.id = b.staff_id
     WHERE b.booking_date BETWEEN ? AND ? AND b.status IN ('pending','confirmed','cancelled','completed','no_show') $staff_where
     ORDER BY b.booking_date,
              b.start_time,
              CASE WHEN b.status IN ('pending','confirmed') THEN 1 ELSE 0 END,
              b.id",
    [$from, $to]
);

$blocked = db_all(
    "SELECT bs.*, st.name AS staff_name
     FROM blocked_slots bs
     JOIN staff st ON st.id = bs.staff_id
     WHERE (bs.date BETWEEN ? AND ?
            OR (COALESCE(bs.repeat_mode, '') = 'working_day' AND bs.date <= ? AND bs.repeat_until >= ?))" . ($staff_filter ? " AND bs.staff_id = " . (int)$staff_filter : ""),
    [$from, $to, $to, $from]
);

$staff_list = db_all("SELECT id, name FROM staff WHERE is_active = 1 ORDER BY name");

// Hourly grid 7am-10pm by default, but expand to fit data.
$hour_start = 7; $hour_end = 22;
$by_day = array_fill_keys($days, []);
foreach ($bookings as $b) {
    $by_day[$b['booking_date']][] = $b + ['__type' => 'booking'];
    $hs = (int)substr($b['start_time'], 0, 2);
    $he = (int)substr($b['end_time'], 0, 2) + 1;
    if ($hs < $hour_start) $hour_start = $hs;
    if ($he > $hour_end)   $hour_end = $he;
}
foreach ($blocked as $bl) {
    foreach (expand_blocked_slot_for_days($bl, $days) as $occurrence) {
        $by_day[$occurrence['date']][] = $occurrence + ['__type' => 'blocked'];
    }
}
$hour_start = max(0, $hour_start);
$hour_end   = min(24, $hour_end);
$total_mins = ($hour_end - $hour_start) * 60;

$status_bg = [
    'confirmed' => '#10b981',
    'pending'   => '#f59e0b',
    'cancelled' => '#ef4444',
    'completed' => '#6b7280',
    'no_show'   => '#64748b',
];

// Nav
$dt = new DateTime($week_start);
$prev = (clone $dt)->modify($view === 'week' ? '-7 days' : '-1 day')->format('Y-m-d');
$next = (clone $dt)->modify($view === 'week' ? '+7 days' : '+1 day')->format('Y-m-d');

admin_header();
?>
<?= flash_render() ?>
<div class="flex flex-wrap items-center gap-2 mb-4">
    <h1 class="text-2xl font-semibold mr-auto">Calendar</h1>
    <div class="flex items-center border border-neutral-200 rounded-lg overflow-hidden text-sm">
        <a class="px-3 py-1.5 <?= $view==='day' ? 'bg-primary text-white' : 'bg-white' ?>" href="?view=day&start=<?= e($from) ?>&staff_id=<?= e($staff_filter_raw) ?>">Day</a>
        <a class="px-3 py-1.5 <?= $view==='week' ? 'bg-primary text-white' : 'bg-white' ?>" href="?view=week&start=<?= e($from) ?>&staff_id=<?= e($staff_filter_raw) ?>">Week</a>
    </div>
    <a class="px-3 py-1.5 rounded-lg bg-white border border-neutral-200 text-sm" href="?view=<?= e($view) ?>&start=<?= e($prev) ?>&staff_id=<?= e($staff_filter_raw) ?>">←</a>
    <a class="px-3 py-1.5 rounded-lg bg-white border border-neutral-200 text-sm" href="?view=<?= e($view) ?>&start=<?= e($today) ?>&staff_id=<?= e($staff_filter_raw) ?>">Today</a>
    <a class="px-3 py-1.5 rounded-lg bg-white border border-neutral-200 text-sm" href="?view=<?= e($view) ?>&start=<?= e($next) ?>&staff_id=<?= e($staff_filter_raw) ?>">→</a>
    <?php if (is_admin()): ?>
    <form method="get" class="flex items-center gap-2">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <input type="hidden" name="start" value="<?= e($from) ?>">
        <select name="staff_id" onchange="this.form.submit()" class="px-3 py-1.5 rounded-lg border border-neutral-200 text-sm">
            <option value="all" <?= $staff_filter_raw==='all'?'selected':'' ?>>All staff</option>
            <?php foreach ($staff_list as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ((string)$s['id']===$staff_filter_raw)?'selected':'' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
    <a class="px-3 py-1.5 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>" href="/admin/booking-edit.php">+ Booking</a>
    <a class="px-3 py-1.5 rounded-lg bg-white border border-neutral-200 text-sm" href="/admin/blocked-slots.php">+ Block time</a>
</div>

<div class="bg-white border border-neutral-200 rounded-xl overflow-hidden calendar-scroll">
    <div class="grid" style="grid-template-columns: 60px repeat(<?= count($days) ?>, 1fr);">
        <!-- Header row -->
        <div></div>
        <?php foreach ($days as $d): $dt2 = new DateTime($d); ?>
            <div class="p-2 text-center text-xs text-neutral-500 border-l border-neutral-100">
                <div class="uppercase tracking-wide"><?= $dt2->format('D') ?></div>
                <div class="text-lg <?= $d === $today ? 'text-primary font-semibold' : 'text-neutral-900' ?>"><?= $dt2->format('j') ?></div>
                <div class="text-[10px]"><?= $dt2->format('M') ?></div>
            </div>
        <?php endforeach; ?>
        <!-- Time grid -->
        <div class="relative">
            <?php for ($h = $hour_start; $h < $hour_end; $h++): ?>
                <div class="h-20 text-right pr-2 text-[11px] text-neutral-400 border-t border-neutral-100"><?= e(format_time_display(sprintf('%02d:00', $h))) ?></div>
            <?php endfor; ?>
        </div>
        <?php foreach ($days as $d): ?>
            <div class="relative border-l border-neutral-100" style="min-height: <?= ($hour_end - $hour_start) * 80 ?>px;">
                <?php for ($h = $hour_start; $h < $hour_end; $h++): ?>
                    <div class="h-20 border-t border-neutral-100"></div>
                <?php endfor; ?>
                <?php foreach (($by_day[$d] ?? []) as $item):
                    $is_b = ($item['__type'] ?? '') === 'blocked';
                    $sm = time_to_minutes($item['start_time']) - $hour_start * 60;
                    $em = time_to_minutes($item['end_time']) - $hour_start * 60;
                    $pxPerMin = 80 / 60; // h-20 = 80px per hour
                    $top_px = max(0, $sm * $pxPerMin);
                    $height_px = max(44, ($em - $sm) * $pxPerMin); // min-height 44px so short slots stay readable
                    $is_cancelled = !$is_b && ($item['status'] ?? '') === 'cancelled';
                    $bg = $is_b ? '#f3f4f6' : ($status_bg[$item['status']] ?? '#6b7280') . ($is_cancelled ? '14' : '22');
                    $border = $is_b ? '#9ca3af' : ($status_bg[$item['status']] ?? '#6b7280');
                    $href = $is_b ? '/admin/blocked-slots.php' : '/admin/booking-edit.php?id=' . (int)$item['id'];
                    $item_class = $is_cancelled
                        ? 'absolute left-1 right-1 rounded-md px-2 py-1 text-[11px] leading-tight overflow-hidden z-0 opacity-70 hover:z-20 hover:opacity-100 hover:shadow-md hover:h-auto'
                        : 'absolute left-1 right-1 rounded-md px-2 py-1 text-[11px] leading-tight overflow-hidden z-10 hover:z-20 hover:shadow-md hover:h-auto';
                ?>
                <a href="<?= e($href) ?>" class="<?= e($item_class) ?>"
                   style="top: <?= $top_px ?>px; height: <?= $height_px ?>px; min-height: 44px; background: <?= e($bg) ?>; border-left: 3px solid <?= e($border) ?>;">
                   <?php if ($is_b): ?>
                       <div class="font-medium truncate">Blocked - <?= e($item['staff_name']) ?></div>
                       <div class="text-neutral-500 truncate"><?= e($item['reason']) ?></div>
                   <?php else: ?>
                       <div class="font-medium truncate <?= $is_cancelled ? 'line-through decoration-red-300' : '' ?>"><?= e(format_time_display($item['start_time'])) ?> <?= e($item['customer_name']) ?></div>
                       <div class="text-neutral-600 truncate"><?= e($item['service_name']) ?> - <?= e($item['staff_name']) ?><?= $is_cancelled ? ' - cancelled' : '' ?></div>
                   <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="flex items-center gap-4 text-xs text-neutral-500 mt-3 flex-wrap">
    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded" style="background:#10b98122;border-left:3px solid #10b981"></span>Confirmed</span>
    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded" style="background:#f59e0b22;border-left:3px solid #f59e0b"></span>Pending</span>
    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded" style="background:#ef444422;border-left:3px solid #ef4444"></span>Cancelled</span>
    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded" style="background:#6b728022;border-left:3px solid #6b7280"></span>Completed</span>
    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded" style="background:#64748b22;border-left:3px solid #64748b"></span>No-show</span>
    <span class="flex items-center gap-1"><span class="w-3 h-3 rounded" style="background:#f3f4f6;border-left:3px solid #9ca3af"></span>Blocked</span>
</div>
<?php admin_footer(); ?>
<?php
function expand_blocked_slot_for_days(array $row, array $days): array {
    $out = [];
    foreach ($days as $day) {
        if (!blocked_slot_applies_on_date($row, $day)) continue;
        $copy = $row;
        $copy['date'] = $day;
        $out[] = $copy;
    }
    return $out;
}
