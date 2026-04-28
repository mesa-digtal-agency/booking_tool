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

$stats = [
    'today' => stat_counts($today, $today, $scope_where),
    'week'  => stat_counts($monday, $sunday, $scope_where),
    'month' => stat_counts($month_start, $month_end, $scope_where),
];

// Top 3 services (by booking count this month).
$top_services = db_all(
    "SELECT s.name, COUNT(*) AS cnt
     FROM bookings b JOIN services s ON s.id = b.service_id
     WHERE b.booking_date BETWEEN ? AND ? {$scope_where}
     GROUP BY s.id, s.name ORDER BY cnt DESC LIMIT 3",
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
$has_hour_data = array_sum($hour_map) > 0;

// Limit upcoming to 4 — keeps the dashboard compact.
$upcoming = db_all(
    "SELECT b.id, b.booking_date, b.start_time, b.status, b.customer_name,
            s.name AS service_name, st.name AS staff_name
     FROM bookings b
     JOIN services s ON s.id = b.service_id
     JOIN staff st  ON st.id = b.staff_id
     WHERE b.booking_date >= ? AND b.status IN ('pending','confirmed') {$scope_where}
     ORDER BY b.booking_date, b.start_time LIMIT 4",
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
$month_total = array_sum($status_map);
$active_month = $status_map['confirmed'] + $status_map['completed'];
$completion_rate = $month_total > 0 ? round(($active_month / $month_total) * 100) : 0;
$avg_booking_value = $active_month > 0 ? $stats['month']['revenue'] / $active_month : 0;
$open_count = (int)db_scalar(
    "SELECT COUNT(*) FROM bookings
     WHERE booking_date >= ? AND status IN ('pending','confirmed') {$scope_where}",
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

admin_header();
?>
<?= flash_render() ?>
<h1 class="text-xl font-semibold mb-4">Dashboard</h1>

<div class="dashboard-grid grid gap-3 md:min-h-[calc(100vh-7rem)] md:grid-rows-[auto_minmax(280px,1.25fr)_minmax(260px,1fr)]">
<!-- Row 1: stat cards -->
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
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
    <div class="text-[10px] text-neutral-500 uppercase tracking-wide">Completion rate</div>
    <div class="flex items-baseline gap-2 mt-0.5">
      <div class="text-xl font-semibold"><?= $completion_rate ?>%</div>
      <div class="text-[11px] text-neutral-500">this month</div>
    </div>
    <div class="text-xs text-neutral-600 mt-0.5"><?= $active_month ?> of <?= $month_total ?> bookings</div>
  </div>
  <div class="dashboard-stat-card bg-white border border-neutral-200 rounded-xl p-3">
    <div class="text-[10px] text-neutral-500 uppercase tracking-wide"><?= is_admin() ? 'Active staff' : 'Active services' ?></div>
    <div class="flex items-baseline gap-2 mt-0.5">
      <div class="text-xl font-semibold"><?= is_admin() ? (int)$active_staff : $active_services ?></div>
      <div class="text-[11px] text-neutral-500"><?= is_admin() ? 'people' : 'services' ?></div>
    </div>
    <?php if (is_admin()): ?>
      <div class="text-xs text-neutral-600 mt-0.5"><?= (int)$inactive_staff ?> <span class="text-neutral-400">inactive</span> &middot; <?= (int)$blocked_now_staff ?> <span class="text-neutral-400">blocked now</span></div>
    <?php else: ?>
      <div class="text-xs text-neutral-600 mt-0.5">Available to customers</div>
    <?php endif; ?>
  </div>
</div>

<!-- Row 2: charts -->
<div class="grid lg:grid-cols-3 gap-3 min-h-0">
  <div class="dashboard-diagram-card bg-white border border-neutral-200 rounded-xl p-3 lg:col-span-2 flex flex-col min-h-0">
    <div class="dashboard-diagram-header">
      <div class="dashboard-diagram-title">Busiest hours</div>
      <div class="dashboard-diagram-chip">This month</div>
    </div>
    <?php if ($has_hour_data): ?>
      <div class="dashboard-chart-wrap flex-1 min-h-[170px] md:min-h-[220px]"><canvas id="hoursChart"></canvas></div>
    <?php else: ?>
      <div class="dashboard-empty flex-1 min-h-[120px] md:min-h-[220px] flex items-center justify-center rounded-lg border border-dashed border-neutral-200 text-xs text-neutral-500">No bookings this month yet.</div>
    <?php endif; ?>
  </div>
  <div class="dashboard-diagram-card bg-white border border-neutral-200 rounded-xl p-3 flex flex-col min-h-0">
    <div class="dashboard-diagram-header">
      <div class="dashboard-diagram-title">Status breakdown</div>
      <div class="dashboard-diagram-chip">This month</div>
    </div>
    <?php if ($month_total > 0): ?>
      <div class="dashboard-chart-wrap dashboard-donut-wrap flex-1 min-h-[180px] md:min-h-[220px] relative">
        <canvas id="statusChart"></canvas>
        <div class="dashboard-donut-center" aria-hidden="true">
          <span>Total</span>
          <strong><?= (int)$month_total ?></strong>
        </div>
      </div>
    <?php else: ?>
      <div class="dashboard-empty flex-1 min-h-[120px] md:min-h-[220px] flex items-center justify-center rounded-lg border border-dashed border-neutral-200 text-xs text-neutral-500">No status data yet.</div>
    <?php endif; ?>
  </div>
</div>

<!-- Row 3: upcoming + top services + staff performance -->
<div class="grid lg:grid-cols-3 gap-3 min-h-0">
  <div class="bg-white border border-neutral-200 rounded-xl p-3 min-h-0 flex flex-col">
    <div class="text-xs font-semibold mb-2 text-neutral-700">Upcoming bookings</div>
    <?php if (!$upcoming): ?>
      <div class="text-xs text-neutral-500">Nothing coming up.</div>
    <?php else: ?>
      <ul class="divide-y divide-neutral-100">
        <?php foreach ($upcoming as $u): ?>
          <li class="py-1.5 flex items-center justify-between gap-2">
            <div class="min-w-0 flex-1">
              <div class="text-xs font-medium truncate"><?= e($u['customer_name']) ?> <span class="text-neutral-400">·</span> <?= e($u['service_name']) ?></div>
              <div class="text-[11px] text-neutral-500 truncate"><?= e($u['booking_date']) ?> · <?= e(format_time_display($u['start_time'])) ?> · <?= e($u['staff_name']) ?></div>
            </div>
            <a class="text-[11px] text-primary hover:underline flex-shrink-0" href="/admin/booking-edit.php?id=<?= (int)$u['id'] ?>">Open</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="bg-white border border-neutral-200 rounded-xl p-3 min-h-0">
    <div class="text-xs font-semibold mb-2 text-neutral-700">Top services (this month)</div>
    <?php if (!$top_services): ?>
      <div class="text-xs text-neutral-500">No bookings yet.</div>
    <?php else: ?>
      <ol class="space-y-1.5">
        <?php foreach ($top_services as $i => $s): ?>
          <li class="flex items-center justify-between gap-2">
            <span class="text-xs min-w-0 truncate"><span class="text-neutral-400 mr-2"><?= $i+1 ?>.</span><?= e($s['name']) ?></span>
            <span class="text-[11px] text-neutral-500 flex-shrink-0"><?= (int)$s['cnt'] ?> bookings</span>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>

  <?php if (is_admin()): ?>
  <div class="bg-white border border-neutral-200 rounded-xl p-3 min-h-0">
    <div class="text-xs font-semibold mb-2 text-neutral-700">Staff performance</div>
    <?php if (!$staff_perf): ?>
      <div class="text-xs text-neutral-500">No active staff yet.</div>
    <?php else: ?>
      <div class="nice-scroll dashboard-staff-table overflow-y-auto pr-3 flex-1 min-h-0">
        <table class="w-full text-xs">
          <thead class="text-neutral-500 text-left sticky top-0 bg-white">
            <tr><th class="py-1 font-normal">Staff</th><th class="font-normal">Bookings</th><th class="font-normal text-right">Revenue</th></tr>
          </thead>
          <tbody>
          <?php foreach ($staff_perf as $p): ?>
            <tr class="border-t border-neutral-100">
              <td class="py-1.5 truncate max-w-[110px]"><?= e($p['name']) ?></td>
              <td class="py-1.5"><?= (int)$p['cnt'] ?></td>
              <td class="py-1.5 text-right"><?= e(money_with_currency((float)$p['revenue'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <!-- Placeholder keeps the 3-col grid balanced for staff role. -->
  <div></div>
  <?php endif; ?>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const hoursLabels = <?= json_encode(array_map(fn($h) => format_time_display(sprintf('%02d:00', $h)), range(0, 23))) ?>;
const hoursData = <?= json_encode(array_values($hour_map)) ?>;
const primary = '<?= e($primary = primary_color()) ?>';
const dashboardSmall = window.matchMedia('(max-width: 640px)').matches;
const dashboardDark = document.body.classList.contains('theme-dark');
const dashboardMuted = dashboardDark ? '#b6c2d2' : '#9ca3af';
const dashboardGrid = dashboardDark ? 'rgba(148, 163, 184, 0.14)' : 'rgba(148, 163, 184, 0.18)';
const dashboardPanel = dashboardDark ? '#151c2c' : '#ffffff';

const hoursCanvas = document.getElementById('hoursChart');
if (hoursCanvas) {
  new Chart(hoursCanvas, {
    type: 'bar',
    data: { labels: hoursLabels, datasets: [{ data: hoursData, backgroundColor: primary, borderRadius: 8, borderSkipped: false, barPercentage: 0.58, categoryPercentage: 0.72 }] },
    options: {
      responsive: true, maintainAspectRatio: false,
      layout: { padding: { top: 8, right: 8, bottom: 0, left: 0 } },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#111827',
          padding: 10,
          displayColors: false,
          callbacks: { label: ctx => `${ctx.parsed.y} bookings` }
        }
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
        backgroundColor: ['#60a5fa','#fbbf24','#f87171','#4ade80','#c084fc'],
        borderWidth: dashboardSmall ? 4 : 6,
        borderColor: dashboardPanel,
        hoverOffset: 6,
        spacing: 2
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      cutout: dashboardSmall ? '60%' : '66%',
      plugins: {
        legend: {
          position: 'bottom',
          labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: dashboardSmall ? 7 : 8, padding: dashboardSmall ? 8 : 10, color: dashboardDark ? '#b6c2d2' : '#6b7280', font: { size: dashboardSmall ? 9 : 10, weight: '500' } }
        },
        tooltip: { backgroundColor: '#111827', padding: 10 }
      }
    }
  });
}
</script>
<?php admin_footer(); ?>
