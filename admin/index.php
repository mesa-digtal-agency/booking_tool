<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-layout.php';

require_login();

$page_title = 'Dashboard';
$active = 'dashboard';

// Scope: staff only sees their own stats.
$scope = scope_staff_id();
$scope_where = $scope ? " AND staff_id = " . (int)$scope : "";

$today = date('Y-m-d');
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));
if ($sunday < $monday) $sunday = date('Y-m-d', strtotime('sunday +1 week'));
$month_start = date('Y-m-01');
$month_end   = date('Y-m-t');

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
$status_map = ['confirmed'=>0,'pending'=>0,'cancelled'=>0,'completed'=>0];
foreach ($status_rows as $r) $status_map[$r['status']] = (int)$r['cnt'];

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

<!-- Row 1: stat cards (compact) -->
<div class="grid grid-cols-3 gap-3 mb-3">
  <?php foreach (['today'=>'Today','week'=>'This week','month'=>'This month'] as $k=>$lbl): ?>
    <div class="bg-white border border-neutral-200 rounded-xl p-3">
      <div class="text-[10px] text-neutral-500 uppercase tracking-wide"><?= e($lbl) ?></div>
      <div class="flex items-baseline gap-2 mt-0.5">
        <div class="text-xl font-semibold"><?= $stats[$k]['count'] ?></div>
        <div class="text-[11px] text-neutral-500">bookings</div>
      </div>
      <div class="text-xs text-neutral-600 mt-0.5">$<?= format_money($stats[$k]['revenue']) ?> <span class="text-neutral-400">revenue</span></div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Row 2: charts -->
<div class="grid lg:grid-cols-3 gap-3 mb-3">
  <div class="bg-white border border-neutral-200 rounded-xl p-3 lg:col-span-2">
    <div class="text-xs font-semibold mb-2 text-neutral-700">Busiest hours (this month)</div>
    <div style="height: 160px;"><canvas id="hoursChart"></canvas></div>
  </div>
  <div class="bg-white border border-neutral-200 rounded-xl p-3 flex flex-col">
    <div class="text-xs font-semibold mb-2 text-neutral-700">Status breakdown</div>
    <div style="height: 160px; position: relative;" class="flex-1"><canvas id="statusChart"></canvas></div>
  </div>
</div>

<!-- Row 3: upcoming + top services + staff performance -->
<div class="grid lg:grid-cols-3 gap-3">
  <div class="bg-white border border-neutral-200 rounded-xl p-3">
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

  <div class="bg-white border border-neutral-200 rounded-xl p-3">
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
  <div class="bg-white border border-neutral-200 rounded-xl p-3">
    <div class="text-xs font-semibold mb-2 text-neutral-700">Staff performance</div>
    <?php if (!$staff_perf): ?>
      <div class="text-xs text-neutral-500">No active staff yet.</div>
    <?php else: ?>
      <div class="overflow-y-auto" style="max-height: 160px;">
        <table class="w-full text-xs">
          <thead class="text-neutral-500 text-left sticky top-0 bg-white">
            <tr><th class="py-1 font-normal">Staff</th><th class="font-normal">Bkg</th><th class="font-normal text-right">Rev.</th></tr>
          </thead>
          <tbody>
          <?php foreach ($staff_perf as $p): ?>
            <tr class="border-t border-neutral-100">
              <td class="py-1.5 truncate max-w-[110px]"><?= e($p['name']) ?></td>
              <td class="py-1.5"><?= (int)$p['cnt'] ?></td>
              <td class="py-1.5 text-right">$<?= format_money((float)$p['revenue']) ?></td>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const hoursLabels = <?= json_encode(array_map(fn($h) => format_time_display(sprintf('%02d:00', $h)), range(0, 23))) ?>;
const hoursData = <?= json_encode(array_values($hour_map)) ?>;
const primary = '<?= e($primary = primary_color()) ?>';

new Chart(document.getElementById('hoursChart'), {
  type: 'bar',
  data: { labels: hoursLabels, datasets: [{ data: hoursData, backgroundColor: primary, borderRadius: 4 }] },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 10 } } },
      y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } } }
    }
  }
});

new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: {
    labels: ['Confirmed','Pending','Cancelled','Completed'],
    datasets: [{
      data: [<?= $status_map['confirmed'] ?>, <?= $status_map['pending'] ?>, <?= $status_map['cancelled'] ?>, <?= $status_map['completed'] ?>],
      backgroundColor: ['#10b981','#f59e0b','#ef4444','#6b7280'],
      borderWidth: 0
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    cutout: '65%',
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
  }
});
</script>
<?php admin_footer(); ?>
