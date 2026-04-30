<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-layout.php';
require_once __DIR__ . '/../includes/availability.php';

require_login();

$page_title = 'Dashboard';
$active = 'dashboard';

// Scope: staff only sees their own stats.
$scope = scope_staff_id();
$scope_where = $scope ? " AND staff_id = " . (int)$scope : "";

$today_dt = new DateTimeImmutable(business_today(), business_timezone_obj());
$today = $today_dt->format('Y-m-d');
$monday = $today_dt->modify('monday this week')->format('Y-m-d');
$sunday = $today_dt->modify('sunday this week')->format('Y-m-d');
if ($sunday < $monday) $sunday = $today_dt->modify('sunday next week')->format('Y-m-d');
$month_start = $today_dt->modify('first day of this month')->format('Y-m-d');
$month_end   = $today_dt->modify('last day of this month')->format('Y-m-d');

function stat_counts(string $from, string $to, string $scope_where): array {
    $rows = db_fetch(
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN s.price ELSE 0 END),0) AS revenue
         FROM bookings b
         JOIN services s ON s.id = b.service_id
         WHERE b.booking_date BETWEEN ? AND ? {$scope_where}",
        [$from, $to]
    );
    return [
        'count' => (int)($rows['cnt'] ?? 0),
        'revenue' => (float)($rows['revenue'] ?? 0),
    ];
}

function dashboard_working_hour_range(?int $staff_id): array {
    $where = "st.is_active = 1 AND wh.is_off = 0";
    $params = [];
    if ($staff_id !== null) {
        $where .= " AND wh.staff_id = ?";
        $params[] = $staff_id;
    }

    $rows = db_all(
        "SELECT wh.start_time, wh.end_time
         FROM working_hours wh
         JOIN staff st ON st.id = wh.staff_id
         WHERE {$where}",
        $params
    );

    $start_hour = null;
    $end_hour = null;
    foreach ($rows as $row) {
        $start_min = time_to_minutes((string)$row['start_time']);
        $end_min = time_to_minutes((string)$row['end_time']);
        if ($start_min === 0 && $end_min === 0) {
            return [0, 24];
        }
        if ($end_min <= $start_min) {
            continue;
        }
        $start_hour = min($start_hour ?? intdiv($start_min, 60), intdiv($start_min, 60));
        $end_hour = max($end_hour ?? (int)ceil($end_min / 60), (int)ceil($end_min / 60));
    }

    if ($start_hour === null || $end_hour === null || $end_hour <= $start_hour) {
        return [0, 24];
    }
    return [max(0, $start_hour), min(24, $end_hour)];
}

$stats = [
    'today' => stat_counts($today, $today, $scope_where),
    'week'  => stat_counts($monday, $sunday, $scope_where),
    'month' => stat_counts($month_start, $month_end, $scope_where),
];

// Top 10 services (by booking count this month).
$top_services = db_all(
    "SELECT s.name, COUNT(*) AS cnt
     FROM bookings b JOIN services s ON s.id = b.service_id
     WHERE b.booking_date BETWEEN ? AND ? {$scope_where}
     GROUP BY s.id, s.name ORDER BY cnt DESC LIMIT 10",
    [$month_start, $month_end]
);

// Busiest hours (this month) — bar chart.
$busiest = db_all(
    "SELECT substr(start_time, 1, 2) AS hr, COUNT(*) AS cnt
     FROM bookings b
     WHERE b.booking_date BETWEEN ? AND ? {$scope_where}
     GROUP BY hr ORDER BY hr",
    [$month_start, $month_end]
);
$hour_map = array_fill(0, 24, 0);
foreach ($busiest as $r) $hour_map[(int)$r['hr']] = (int)$r['cnt'];
[$chart_hour_start, $chart_hour_end] = dashboard_working_hour_range($scope);
$hour_labels = [];
$hour_values = [];
for ($h = $chart_hour_start; $h < $chart_hour_end; $h++) {
    $hour_labels[] = format_time_display(sprintf('%02d:00', $h));
    $hour_values[] = $hour_map[$h] ?? 0;
}
$has_hour_data = array_sum($hour_values) > 0;

// Busiest days (this year) - contribution-style heatmap.
$heatmap_year = (int)$today_dt->format('Y');
$year_start = $today_dt->setDate($heatmap_year, 1, 1)->setTime(0, 0);
$year_end = $today_dt->setDate($heatmap_year, 12, 31)->setTime(0, 0);
$heatmap_start = $year_start->modify('monday this week');
$heatmap_end = $year_end->modify('sunday this week');
$heatmap_year_label = (string)$heatmap_year;
$heatmap_rows = db_all(
    "SELECT booking_date, COUNT(*) AS cnt
     FROM bookings b
     WHERE booking_date BETWEEN ? AND ? {$scope_where}
     GROUP BY booking_date",
    [$year_start->format('Y-m-d'), $year_end->format('Y-m-d')]
);
$heatmap_counts = [];
foreach ($heatmap_rows as $row) {
    $heatmap_counts[(string)$row['booking_date']] = (int)$row['cnt'];
}
$heatmap_max = max($heatmap_counts ?: [0]);
$heatmap_weeks = intdiv((int)$heatmap_start->diff($heatmap_end)->days, 7) + 1;
$heatmap_months = array_fill(0, $heatmap_weeks, '');
for ($m = 1; $m <= 12; $m++) {
    $month_dt = $year_start->setDate($heatmap_year, $m, 1);
    $week = intdiv((int)$heatmap_start->diff($month_dt)->days, 7);
    if ($week >= 0 && $week < $heatmap_weeks) {
        $heatmap_months[$week] = $month_dt->format('M');
    }
}

// Upcoming bookings use their own dashboard pagination.
$upcoming_page = max(1, (int)($_GET['upcoming_page'] ?? 1));
$upcoming_per_page = max(4, min(30, (int)($_GET['upcoming_per_page'] ?? 8)));
$upcoming_total = (int)db_scalar(
    "SELECT COUNT(*)
     FROM bookings b
     WHERE b.booking_date >= ? AND b.status IN ('pending','confirmed') {$scope_where}",
    [$today]
);
$upcoming_total_pages = max(1, (int)ceil($upcoming_total / $upcoming_per_page));
if ($upcoming_page > $upcoming_total_pages) $upcoming_page = $upcoming_total_pages;
$upcoming_offset = ($upcoming_page - 1) * $upcoming_per_page;
$upcoming = db_all(
    "SELECT b.id, b.booking_date, b.start_time, b.status, b.customer_name,
            s.name AS service_name, st.name AS staff_name
     FROM bookings b
     JOIN services s ON s.id = b.service_id
     JOIN staff st  ON st.id = b.staff_id
     WHERE b.booking_date >= ? AND b.status IN ('pending','confirmed') {$scope_where}
     ORDER BY b.booking_date, b.start_time LIMIT $upcoming_per_page OFFSET $upcoming_offset",
    [$today]
);

// Status breakdown (this month).
$status_rows = db_all(
    "SELECT status, COUNT(*) AS cnt FROM bookings
     WHERE booking_date BETWEEN ? AND ? {$scope_where}
     GROUP BY status",
    [$month_start, $month_end]
);
$status_map = ['confirmed'=>0,'pending'=>0,'cancelled'=>0,'completed'=>0,'no_show'=>0];
foreach ($status_rows as $r) $status_map[$r['status']] = (int)$r['cnt'];
$dashboard_status_colors = booking_status_colors();
$month_total = array_sum($status_map);
$active_month = $status_map['confirmed'] + $status_map['completed'];
$completion_rate = $month_total > 0 ? round(($active_month / $month_total) * 100) : 0;
$cancellation_rate = $month_total > 0 ? round(($status_map['cancelled'] / $month_total) * 100) : 0;
$no_show_rate = $month_total > 0 ? round(($status_map['no_show'] / $month_total) * 100) : 0;
$avg_booking_value = $active_month > 0 ? $stats['month']['revenue'] / $active_month : 0;
$open_count = (int)db_scalar(
    "SELECT COUNT(*) FROM bookings
     WHERE booking_date >= ? AND status IN ('pending','confirmed') {$scope_where}",
    [$today]
);
$pending_count = (int)db_scalar(
    "SELECT COUNT(*) FROM bookings
     WHERE booking_date >= ? AND status = 'pending' {$scope_where}",
    [$today]
);
$active_services = (int)db_scalar("SELECT COUNT(*) FROM services WHERE is_active = 1");
$active_staff = is_admin() ? (int)db_scalar("SELECT COUNT(*) FROM staff WHERE is_active = 1") : null;
$inactive_staff = null;
$blocked_now_staff = null;
if (is_admin()) {
    $inactive_staff = (int)db_scalar("SELECT COUNT(*) FROM staff WHERE is_active = 0");
    $now_time = business_now()->format('H:i');
    $blocked_now_ids = [];
    $blocked_now_rows = db_all(
        "SELECT bs.*
         FROM blocked_slots bs
         JOIN staff st ON st.id = bs.staff_id
         WHERE st.is_active = 1
           AND (bs.date = ?
                OR (COALESCE(bs.repeat_mode, '') = 'working_day'
                    AND bs.date <= ?
                    AND (bs.repeat_until IS NULL OR bs.repeat_until >= ?)))
           AND bs.start_time <= ?
           AND bs.end_time > ?",
        [$today, $today, $today, $now_time, $now_time]
    );
    foreach ($blocked_now_rows as $row) {
        if (blocked_slot_applies_on_date($row, $today)) {
            $blocked_now_ids[(int)$row['staff_id']] = true;
        }
    }
    $blocked_now_staff = count($blocked_now_ids);
}

// Staff performance (this month) — admin only.
$staff_perf = [];
if (is_admin()) {
    $staff_perf = db_all(
        "SELECT st.name, COUNT(b.id) AS cnt,
                COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN s.price ELSE 0 END),0) AS revenue
         FROM staff st
         LEFT JOIN bookings b ON b.staff_id = st.id AND b.booking_date BETWEEN ? AND ?
         LEFT JOIN services s ON s.id = b.service_id
         WHERE st.is_active = 1
         GROUP BY st.id, st.name ORDER BY cnt DESC",
        [$month_start, $month_end]
    );
}
$staff_perf_max = 0;
foreach ($staff_perf as $p) {
    $staff_perf_max = max($staff_perf_max, (int)$p['cnt']);
}

admin_header();
?>
<?= flash_render() ?>
<h1 class="text-2xl font-semibold mb-4">Dashboard</h1>

<div class="dashboard-grid grid gap-3">
<!-- Row 1: stat cards -->
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-9 gap-3">
  <?php foreach (['today'=>'Today','week'=>'This week','month'=>'This month'] as $k=>$lbl): ?>
    <div class="dashboard-stat-card bg-white border border-neutral-200 rounded-xl p-3">
      <div class="text-[10px] text-neutral-500 uppercase tracking-wide"><?= e($lbl) ?></div>
      <div class="flex items-baseline gap-2 mt-0.5">
        <div class="text-xl font-semibold"><?= $stats[$k]['count'] ?></div>
        <div class="text-[11px] text-neutral-500">bookings</div>
      </div>
      <div class="text-xs text-neutral-600 mt-0.5"><?= e(money_with_currency($stats[$k]['revenue'])) ?> <span class="text-neutral-400">revenue</span></div>
    </div>
  <?php endforeach; ?>
  <div class="dashboard-stat-card bg-white border border-neutral-200 rounded-xl p-3">
    <div class="text-[10px] text-neutral-500 uppercase tracking-wide">Open bookings</div>
    <div class="flex items-baseline gap-2 mt-0.5">
      <div class="text-xl font-semibold"><?= $open_count ?></div>
      <div class="text-[11px] text-neutral-500">upcoming</div>
    </div>
    <div class="text-xs text-neutral-600 mt-0.5">Pending and confirmed</div>
  </div>
  <div class="dashboard-stat-card bg-white border border-neutral-200 rounded-xl p-3">
    <div class="text-[10px] text-neutral-500 uppercase tracking-wide">Pending bookings</div>
    <div class="flex items-baseline gap-2 mt-0.5">
      <div class="text-xl font-semibold"><?= $pending_count ?></div>
      <div class="text-[11px] text-neutral-500">upcoming</div>
    </div>
    <div class="text-xs text-neutral-600 mt-0.5">Awaiting action</div>
  </div>
  <div class="dashboard-stat-card bg-white border border-neutral-200 rounded-xl p-3">
    <div class="text-[10px] text-neutral-500 uppercase tracking-wide">Completion rate</div>
    <div class="flex items-baseline gap-2 mt-0.5">
      <div class="text-xl font-semibold"><?= $completion_rate ?>%</div>
      <div class="text-[11px] text-neutral-500">this month</div>
    </div>
    <div class="text-xs text-neutral-600 mt-0.5"><?= $active_month ?> of <?= $month_total ?> bookings</div>
  </div>
  <div class="dashboard-stat-card bg-white border border-neutral-200 rounded-xl p-3">
    <div class="text-[10px] text-neutral-500 uppercase tracking-wide">Cancellation rate</div>
    <div class="flex items-baseline gap-2 mt-0.5">
      <div class="text-xl font-semibold"><?= $cancellation_rate ?>%</div>
      <div class="text-[11px] text-neutral-500">this month</div>
    </div>
    <div class="text-xs text-neutral-600 mt-0.5"><?= (int)$status_map['cancelled'] ?> of <?= $month_total ?> bookings</div>
  </div>
  <div class="dashboard-stat-card bg-white border border-neutral-200 rounded-xl p-3">
    <div class="text-[10px] text-neutral-500 uppercase tracking-wide">No-show rate</div>
    <div class="flex items-baseline gap-2 mt-0.5">
      <div class="text-xl font-semibold"><?= $no_show_rate ?>%</div>
      <div class="text-[11px] text-neutral-500">this month</div>
    </div>
    <div class="text-xs text-neutral-600 mt-0.5"><?= (int)$status_map['no_show'] ?> of <?= $month_total ?> bookings</div>
  </div>
  <div class="dashboard-stat-card bg-white border border-neutral-200 rounded-xl p-3">
    <div class="text-[10px] text-neutral-500 uppercase tracking-wide"><?= is_admin() ? 'Active staff' : 'Active services' ?></div>
    <div class="flex items-baseline gap-2 mt-0.5">
      <div class="text-xl font-semibold"><?= is_admin() ? (int)$active_staff : $active_services ?></div>
      <div class="text-[11px] text-neutral-500"><?= is_admin() ? 'people' : 'services' ?></div>
    </div>
    <?php if (is_admin()): ?>
      <div class="text-xs text-neutral-600 mt-0.5"><?= (int)$inactive_staff ?> <span class="text-neutral-400">inactive</span> &middot; <?= (int)$blocked_now_staff ?> <span class="text-neutral-400">on break</span></div>
    <?php else: ?>
      <div class="text-xs text-neutral-600 mt-0.5">Available to customers</div>
    <?php endif; ?>
  </div>
</div>

<!-- Row 2: charts -->
<div class="dashboard-row-charts grid lg:grid-cols-3 gap-3 min-h-0">
  <div class="dashboard-diagram-card bg-white border border-neutral-200 rounded-xl p-3 lg:col-span-2 flex flex-col min-h-0">
    <div class="dashboard-diagram-header">
      <div class="dashboard-diagram-title">Busiest hours</div>
      <div class="dashboard-diagram-chip">This month</div>
    </div>
    <?php if ($has_hour_data): ?>
      <div class="dashboard-chart-wrap flex-1 min-h-[190px] md:min-h-[260px]"><canvas id="hoursChart"></canvas></div>
    <?php else: ?>
      <div class="dashboard-empty flex-1 min-h-[140px] md:min-h-[260px] flex items-center justify-center rounded-lg border border-dashed border-neutral-200 text-xs text-neutral-500">No bookings this month yet.</div>
    <?php endif; ?>
  </div>
  <div class="dashboard-diagram-card bg-white border border-neutral-200 rounded-xl p-3 flex flex-col min-h-0">
    <div class="dashboard-diagram-header">
      <div class="dashboard-diagram-title">Status breakdown</div>
      <div class="dashboard-diagram-chip">This month</div>
    </div>
    <?php if ($month_total > 0): ?>
      <div class="dashboard-chart-wrap dashboard-donut-wrap flex-1 min-h-[190px] md:min-h-[260px] relative">
        <canvas id="statusChart"></canvas>
        <div class="dashboard-donut-center" aria-hidden="true">
          <span>Total</span>
          <strong><?= (int)$month_total ?></strong>
        </div>
      </div>
    <?php else: ?>
      <div class="dashboard-empty flex-1 min-h-[140px] md:min-h-[260px] flex items-center justify-center rounded-lg border border-dashed border-neutral-200 text-xs text-neutral-500">No status data yet.</div>
    <?php endif; ?>
  </div>
</div>

<!-- Row 3: lower dashboard -->
<div class="dashboard-row-lower grid lg:grid-cols-5 gap-3 min-h-0">
  <div class="dashboard-lower-left lg:col-span-3 grid gap-3 min-h-0">
<div class="dashboard-diagram-card dashboard-heatmap-card bg-white border border-neutral-200 rounded-xl p-3 min-h-0">
  <div class="dashboard-diagram-header">
    <div class="dashboard-diagram-title">Busiest days</div>
    <div class="dashboard-diagram-chip"><?= e($heatmap_year_label) ?></div>
  </div>
  <div class="dashboard-heatmap-scroll">
    <div class="dashboard-heatmap-grid" style="--heatmap-weeks: <?= (int)$heatmap_weeks ?>">
      <div class="dashboard-heatmap-corner"></div>
      <?php foreach ($heatmap_months as $month): ?>
        <div class="dashboard-heatmap-month"><?= e($month) ?></div>
      <?php endforeach; ?>
      <?php
        $weekday_labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        for ($dow = 1; $dow <= 7; $dow++):
      ?>
        <div class="dashboard-heatmap-weekday"><?= e($weekday_labels[$dow]) ?></div>
        <?php for ($week = 0; $week < $heatmap_weeks; $week++):
            $cell_dt = $heatmap_start->modify('+' . (($week * 7) + ($dow - 1)) . ' days');
            $date_key = $cell_dt->format('Y-m-d');
            $count = $heatmap_counts[$date_key] ?? 0;
            $outside = $cell_dt < $year_start || $cell_dt > $year_end;
            $level = 0;
            if (!$outside && $count > 0 && $heatmap_max > 0) {
                $level = max(1, min(6, (int)ceil(($count / $heatmap_max) * 6)));
            }
            $label = $outside
                ? ''
                : $cell_dt->format('M j, Y') . ': ' . $count . ' ' . ($count === 1 ? 'booking' : 'bookings');
        ?>
          <span class="dashboard-heatmap-cell level-<?= (int)$level ?> <?= $outside ? 'is-outside' : '' ?>" title="<?= e($label) ?>" aria-label="<?= e($label) ?>"></span>
        <?php endfor; ?>
      <?php endfor; ?>
    </div>
  </div>
  <div class="dashboard-heatmap-legend" aria-hidden="true">
    <span>Less</span>
    <i class="level-0"></i><i class="level-1"></i><i class="level-2"></i><i class="level-3"></i><i class="level-4"></i><i class="level-5"></i><i class="level-6"></i>
    <span>More</span>
  </div>
</div>

    <div class="dashboard-row-bottom grid md:grid-cols-2 gap-3 min-h-0">
      <div class="dashboard-diagram-card bg-white border border-neutral-200 rounded-xl p-3 min-h-0 flex flex-col"
           data-dashboard-upcoming-card
           data-upcoming-page="<?= (int)$upcoming_page ?>"
           data-upcoming-per-page="<?= (int)$upcoming_per_page ?>"
           data-upcoming-total="<?= (int)$upcoming_total ?>">
        <div class="dashboard-diagram-header">
          <div class="dashboard-diagram-title">Upcoming bookings</div>
          <div class="dashboard-diagram-chip"><?= $upcoming_total ? e(($upcoming_offset + 1) . '-' . min($upcoming_total, $upcoming_offset + count($upcoming)) . ' of ' . $upcoming_total) : '0' ?></div>
        </div>
        <?php if (!$upcoming): ?>
          <div class="text-xs text-neutral-500">Nothing coming up.</div>
        <?php else: ?>
          <div class="nice-scroll flex-1 min-h-0 overflow-y-auto pr-2" data-upcoming-list-viewport>
            <ul class="divide-y divide-neutral-100">
              <?php foreach ($upcoming as $u): ?>
                <li class="py-1.5 flex items-center justify-between gap-2" data-upcoming-item>
                  <div class="min-w-0 flex-1">
                    <div class="text-xs font-medium truncate"><?= e($u['customer_name']) ?> <span class="text-neutral-400">&middot;</span> <?= e($u['service_name']) ?></div>
                    <div class="text-[11px] text-neutral-500 truncate"><?= e($u['booking_date']) ?> &middot; <?= e(format_time_display($u['start_time'])) ?> &middot; <?= e($u['staff_name']) ?></div>
                  </div>
                  <a class="text-[11px] text-primary hover:underline flex-shrink-0" href="/admin/booking-edit.php?id=<?= (int)$u['id'] ?>">Open</a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="mt-auto pt-3 flex items-center justify-between text-[11px] text-neutral-500" data-upcoming-pager>
            <div>Page <?= (int)$upcoming_page ?> of <?= (int)$upcoming_total_pages ?></div>
            <div class="flex items-center gap-2">
              <?php $upcoming_query = $_GET; $upcoming_query['upcoming_page'] = max(1, $upcoming_page - 1); ?>
              <a data-upcoming-pagination-link class="px-2 py-1 rounded border border-neutral-200 bg-white <?= $upcoming_page <= 1 ? 'pointer-events-none opacity-50' : '' ?>" href="?<?= e(http_build_query($upcoming_query)) ?>">Prev</a>
              <?php $upcoming_query['upcoming_page'] = min($upcoming_total_pages, $upcoming_page + 1); ?>
              <a data-upcoming-pagination-link class="px-2 py-1 rounded border border-neutral-200 bg-white <?= $upcoming_page >= $upcoming_total_pages ? 'pointer-events-none opacity-50' : '' ?>" href="?<?= e(http_build_query($upcoming_query)) ?>">Next</a>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="dashboard-diagram-card bg-white border border-neutral-200 rounded-xl p-3 min-h-0 flex flex-col">
        <div class="dashboard-diagram-header">
          <div class="dashboard-diagram-title">Top services</div>
          <div class="dashboard-diagram-chip">Top 10</div>
        </div>
        <?php if (!$top_services): ?>
          <div class="text-xs text-neutral-500">No bookings yet.</div>
        <?php else: ?>
          <div class="nice-scroll flex-1 min-h-0 overflow-y-auto pr-2">
            <ol class="space-y-1.5">
              <?php foreach ($top_services as $i => $s): ?>
                <li class="flex items-center justify-between gap-2">
                  <span class="text-xs min-w-0 truncate"><span class="text-neutral-400 mr-2"><?= $i+1 ?>.</span><?= e($s['name']) ?></span>
                  <span class="text-[11px] text-neutral-500 flex-shrink-0"><?= (int)$s['cnt'] ?> <?= (int)$s['cnt'] === 1 ? 'booking' : 'bookings' ?></span>
                </li>
              <?php endforeach; ?>
            </ol>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (is_admin()): ?>
  <div class="dashboard-diagram-card dashboard-staff-performance-card bg-white border border-neutral-200 rounded-xl p-3 min-h-0 flex flex-col lg:col-span-2">
    <div class="dashboard-diagram-header">
      <div class="dashboard-diagram-title">Staff performance</div>
      <div class="dashboard-diagram-chip">This month</div>
    </div>
    <?php if (!$staff_perf): ?>
      <div class="text-xs text-neutral-500">No active staff yet.</div>
    <?php else: ?>
      <div class="nice-scroll dashboard-staff-table overflow-y-auto pr-3 flex-1 min-h-0">
        <table class="w-full text-xs">
          <thead class="text-neutral-500 text-left sticky top-0 bg-white">
            <tr><th class="py-1 font-normal w-8 text-center">#</th><th class="font-normal">Staff</th><th class="font-normal w-40">Bookings</th><th class="font-normal text-right">Revenue</th></tr>
          </thead>
          <tbody>
          <?php
            $staff_rank_classes = ['gold', 'silver', 'bronze'];
            foreach ($staff_perf as $i => $p):
              $rank_class = $staff_rank_classes[$i] ?? null;
          ?>
            <tr class="border-t border-neutral-100">
              <td class="py-1.5 text-center">
                <?php if ($rank_class): ?>
                  <span class="dashboard-staff-rank-badge dashboard-rank-<?= e($rank_class) ?>"><?= $i + 1 ?></span>
                <?php else: ?>
                  <span class="dashboard-staff-rank-number"><?= $i + 1 ?>.</span>
                <?php endif; ?>
              </td>
              <td class="py-1.5">
                <span class="truncate block"><?= e($p['name']) ?></span>
              </td>
              <?php $booking_pct = $staff_perf_max > 0 ? round(((int)$p['cnt'] / $staff_perf_max) * 100) : 0; ?>
              <td class="py-1.5">
                <span class="dashboard-staff-bookings-cell">
                  <span class="dashboard-staff-booking-meter" style="--booking-fill: <?= (int)$booking_pct ?>%"><span></span></span>
                  <span class="dashboard-staff-booking-count"><?= (int)$p['cnt'] ?></span>
                </span>
              </td>
              <td class="py-1.5 text-right"><?= e(money_with_currency((float)$p['revenue'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const hoursLabels = <?= json_encode($hour_labels) ?>;
const hoursData = <?= json_encode($hour_values) ?>;
const primary = '<?= e($primary = primary_color()) ?>';
const dashboardSmall = window.matchMedia('(max-width: 640px)').matches;
const dashboardDark = document.body.classList.contains('theme-dark');
const dashboardMuted = dashboardDark ? '#b6c2d2' : '#9ca3af';
const dashboardGrid = dashboardDark ? 'rgba(148, 163, 184, 0.14)' : 'rgba(148, 163, 184, 0.18)';
const dashboardPanel = dashboardDark ? '#151c2c' : '#ffffff';
const dashboardStatusColors = <?= json_encode(array_values(array_intersect_key($dashboard_status_colors, $status_map))) ?>;

function syncDashboardUpcomingPageSize() {
  const card = document.querySelector('[data-dashboard-upcoming-card]');
  if (!card) return;

  const total = Number(card.dataset.upcomingTotal || 0);
  const currentSize = Number(card.dataset.upcomingPerPage || 8);
  const currentPage = Number(card.dataset.upcomingPage || 1);
  const viewport = card.querySelector('[data-upcoming-list-viewport]');
  const firstItem = card.querySelector('[data-upcoming-item]');
  if (!viewport || !firstItem || !total || !currentSize) return;

  const rowHeight = firstItem.getBoundingClientRect().height;
  const visibleHeight = viewport.getBoundingClientRect().height;
  if (!rowHeight || !visibleHeight) return;

  const desiredSize = Math.max(4, Math.min(30, Math.floor(visibleHeight / rowHeight)));
  if (!desiredSize || desiredSize === currentSize) return;
  if (total <= desiredSize && currentSize >= total) return;

  const params = new URLSearchParams(window.location.search);
  const firstVisibleIndex = Math.max(0, (currentPage - 1) * currentSize);
  params.set('upcoming_per_page', String(desiredSize));
  params.set('upcoming_page', String(Math.floor(firstVisibleIndex / desiredSize) + 1));
  updateDashboardUpcomingCard(window.location.pathname + '?' + params.toString() + window.location.hash, false);
}

window.addEventListener('load', syncDashboardUpcomingPageSize, { once: true });

async function updateDashboardUpcomingCard(url, pushState = true) {
  const card = document.querySelector('[data-dashboard-upcoming-card]');
  if (!card) return false;

  card.setAttribute('aria-busy', 'true');
  card.style.opacity = '.62';
  try {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    });
    if (!response.ok) throw new Error('Request failed');

    const html = await response.text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const nextCard = doc.querySelector('[data-dashboard-upcoming-card]');
    if (!nextCard) throw new Error('Upcoming card missing');

    card.replaceWith(nextCard);
    if (pushState) window.history.pushState({ upcomingCard: true }, '', url);
    return true;
  } catch (error) {
    window.location.href = url;
    return false;
  } finally {
    const latestCard = document.querySelector('[data-dashboard-upcoming-card]');
    if (latestCard) {
      latestCard.removeAttribute('aria-busy');
      latestCard.style.opacity = '';
    }
  }
}

document.addEventListener('click', function (event) {
  const link = event.target.closest('[data-upcoming-pagination-link]');
  if (!link || link.classList.contains('pointer-events-none')) return;
  event.preventDefault();
  updateDashboardUpcomingCard(link.href);
});

window.addEventListener('popstate', function () {
  updateDashboardUpcomingCard(window.location.href, false);
});

function mixHexColor(hex, whiteAmount) {
  const clean = String(hex || '').replace('#', '').trim();
  if (!/^[0-9a-f]{6}$/i.test(clean)) return hex;
  const rgb = [0, 2, 4].map(i => parseInt(clean.slice(i, i + 2), 16));
  const mixed = rgb.map(channel => Math.round(channel + (255 - channel) * whiteAmount));
  return '#' + mixed.map(channel => channel.toString(16).padStart(2, '0')).join('');
}

function hexToRgba(hex, alpha) {
  const clean = String(hex || '').replace('#', '').trim();
  if (!/^[0-9a-f]{6}$/i.test(clean)) return `rgba(99, 102, 241, ${alpha})`;
  const rgb = [0, 2, 4].map(i => parseInt(clean.slice(i, i + 2), 16));
  return `rgba(${rgb[0]}, ${rgb[1]}, ${rgb[2]}, ${alpha})`;
}

const dashboardDoughnutGlow = {
  id: 'dashboardDoughnutGlow',
  afterDatasetsDraw(chart) {
    if (chart.config.type !== 'doughnut') return;
    const active = chart.getActiveElements();
    if (!active.length) return;
    const ctx = chart.ctx;
    active.forEach(({ datasetIndex, index }) => {
      const dataset = chart.data.datasets[datasetIndex];
      const arc = chart.getDatasetMeta(datasetIndex).data[index];
      const colors = dataset.backgroundColor || [];
      const color = Array.isArray(colors) ? colors[index] : colors;
      ctx.save();
      ctx.shadowColor = hexToRgba(color, dashboardDark ? 0.45 : 0.32);
      ctx.shadowBlur = dashboardSmall ? 16 : 24;
      ctx.shadowOffsetX = 0;
      ctx.shadowOffsetY = 0;
      arc.draw(ctx);
      ctx.restore();
    });
  }
};

const hourMax = Math.max(...hoursData, 0);
const hourBarColors = hoursData.map(value => {
  if (!value || hourMax <= 0) return mixHexColor(primary, 0.68);
  const ratio = value / hourMax;
  if (ratio >= 0.67) return primary;
  if (ratio >= 0.34) return mixHexColor(primary, 0.36);
  return mixHexColor(primary, 0.60);
});

function dashboardExternalTooltip(context) {
  const { chart, tooltip } = context;
  const parent = chart.canvas.parentNode;
  let el = parent.querySelector('.dashboard-chart-tooltip');
  if (!el) {
    el = document.createElement('div');
    el.className = 'dashboard-chart-tooltip';
    el.innerHTML = '<div class="dashboard-chart-tooltip-title"></div><div class="dashboard-chart-tooltip-row"><span></span><strong></strong></div>';
    parent.appendChild(el);
  }

  if (tooltip.opacity === 0) {
    el.classList.remove('is-visible');
    return;
  }

  const point = tooltip.dataPoints && tooltip.dataPoints[0];
  if (!point) return;

  const title = point.label || '';
  const value = chart.config.type === 'bar'
    ? `${point.parsed.y} ${point.parsed.y === 1 ? 'booking' : 'bookings'}`
    : `${point.parsed} ${point.parsed === 1 ? 'booking' : 'bookings'}`;
  const numericValue = chart.config.type === 'bar' ? point.parsed.y : point.parsed;
  if (!numericValue || numericValue <= 0) {
    el.classList.remove('is-visible', 'is-below', 'is-side-left', 'is-side-right');
    return;
  }
  const color = point.element.options.backgroundColor || point.dataset.backgroundColor || primary;

  el.querySelector('.dashboard-chart-tooltip-title').textContent = title;
  el.querySelector('.dashboard-chart-tooltip-row span').style.background = color;
  el.querySelector('.dashboard-chart-tooltip-row strong').textContent = value;
  const rawLeft = chart.canvas.offsetLeft + tooltip.caretX;
  const rawTop = chart.canvas.offsetTop + tooltip.caretY;
  const isBar = chart.config.type === 'bar';
  const placeLeft = rawLeft > parent.clientWidth * 0.55;
  el.classList.toggle('is-side-left', isBar && placeLeft);
  el.classList.toggle('is-side-right', isBar && !placeLeft);
  el.classList.toggle('is-below', !isBar && rawTop < 76);
  el.style.left = rawLeft + 'px';
  el.style.top = rawTop + 'px';
  el.classList.add('is-visible');
}

const hoursCanvas = document.getElementById('hoursChart');
if (hoursCanvas) {
  new Chart(hoursCanvas, {
    type: 'bar',
    data: { labels: hoursLabels, datasets: [{ data: hoursData, backgroundColor: hourBarColors, borderRadius: { topLeft: 999, topRight: 999, bottomLeft: 0, bottomRight: 0 }, borderSkipped: false, barPercentage: 0.58, categoryPercentage: 0.72 }] },
    options: {
      interaction: { intersect: false, mode: 'index' },
      responsive: true, maintainAspectRatio: false,
      layout: { padding: { top: 8, right: 8, bottom: 0, left: 0 } },
      plugins: {
        legend: { display: false },
        tooltip: { enabled: false, external: dashboardExternalTooltip }
      },
      scales: {
        x: {
          border: { display: false },
          grid: { display: false },
          ticks: {
            color: dashboardMuted,
            autoSkip: false,
            maxRotation: 0,
            font: { size: dashboardSmall ? 9 : 10, weight: '500' },
            callback: function(value, index) {
              return dashboardSmall && index % 4 !== 0 ? '' : this.getLabelForValue(value);
            }
          }
        },
        y: {
          border: { display: false },
          beginAtZero: true,
          grid: { color: dashboardGrid, drawTicks: false },
          ticks: { color: dashboardMuted, padding: 10, precision: 0, maxTicksLimit: dashboardSmall ? 3 : 5, font: { size: dashboardSmall ? 9 : 10, weight: '500' } }
        }
      }
    }
  });
}

const statusCanvas = document.getElementById('statusChart');
if (statusCanvas) {
  new Chart(statusCanvas, {
    type: 'doughnut',
    data: {
      labels: ['Confirmed','Pending','Cancelled','Completed','No-show'],
      datasets: [{
        data: [<?= $status_map['confirmed'] ?>, <?= $status_map['pending'] ?>, <?= $status_map['cancelled'] ?>, <?= $status_map['completed'] ?>, <?= $status_map['no_show'] ?>],
        backgroundColor: dashboardStatusColors,
        borderWidth: 0,
        borderColor: dashboardPanel,
        hoverBorderColor: dashboardPanel,
        hoverBorderWidth: 0,
        borderRadius: 999,
        hoverOffset: dashboardSmall ? 3 : 5,
        spacing: 2
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      layout: { padding: dashboardSmall ? 18 : 30 },
      cutout: dashboardSmall ? '60%' : '66%',
      plugins: {
        legend: {
          position: 'bottom',
          labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: dashboardSmall ? 7 : 8, padding: dashboardSmall ? 8 : 10, color: dashboardDark ? '#b6c2d2' : '#6b7280', font: { size: dashboardSmall ? 9 : 10, weight: '500' } }
        },
        tooltip: { enabled: false, external: dashboardExternalTooltip }
      }
    },
    plugins: [dashboardDoughnutGlow]
  });
}
</script>
<?php admin_footer(); ?>
