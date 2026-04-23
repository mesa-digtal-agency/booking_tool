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

// Upcoming bookings list (next 5).
$upcoming = db_all(
    "SELECT b.id, b.booking_date, b.start_time, b.status, b.customer_name,
            s.name AS service_name, st.name AS staff_name
     FROM bookings b
     JOIN services s ON s.id = b.service_id
     JOIN staff st  ON st.id = b.staff_id
     WHERE b.booking_date >= ? AND b.status IN ('pending','confirmed') {$scope_where}
     ORDER BY b.booking_date, b.start_time LIMIT 5",
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
<h1 class="text-2xl font-semibold mb-6">Dashboard</h1>

<div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
  <?php foreach (['today'=>'Today','week'=>'This week','month'=>'This month'] as $k=>$lbl): ?>
    <div class="bg-white border border-neutral-200 rounded-xl p-4">
      <div class="text-xs text-neutral-500 uppercase tracking-wide"><?= e($lbl) ?></div>
      <div class="flex items-baseline gap-2 mt-1">
        <div class="text-2xl font-semibold"><?= $stats[$k]['count'] ?></div>
        <div class="text-xs text-neutral-500">bookings</div>
      </div>
      <div class="text-sm text-neutral-700 mt-1">$<?= format_money($stats[$k]['revenue']) ?> <span class="text-xs text-neutral-500">revenue</span></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-3 gap-4 mb-6">
  <div class="bg-white border border-neutral-200 rounded-xl p-4 lg:col-span-2">
    <div class="font-semibold mb-3">Busiest hours (this month)</div>
    <canvas id="hoursChart" height="120"></canvas>
  </div>
  <div class="bg-white border border-neutral-200 rounded-xl p-4">
    <div class="font-semibold mb-3">Status breakdown</div>
    <canvas id="statusChart" height="160"></canvas>
  </div>
</div>

<div class="grid lg:grid-cols-2 gap-4 mb-6">
  <div class="bg-white border border-neutral-200 rounded-xl p-4">
    <div class="font-semibold mb-3">Upcoming bookings</div>
    <?php if (!$upcoming): ?>
      <div class="text-sm text-neutral-500">Nothing coming up.</div>
    <?php else: ?>
      <ul class="divide-y divide-neutral-100">
        <?php foreach ($upcoming as $u): ?>
          <li class="py-2 flex items-center justify-between gap-2">
            <div>
              <div class="font-medium text-sm"><?= e($u['service_name']) ?> <span class="text-neutral-400">·</span> <?= e($u['customer_name']) ?></div>
              <div class="text-xs text-neutral-500"><?= e($u['booking_date']) ?> at <?= e(format_time_display($u['start_time'])) ?> · <?= e($u['staff_name']) ?></div>
            </div>
            <a class="text-xs text-primary hover:underline" href="/admin/booking-edit.php?id=<?= (int)$u['id'] ?>">Open</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="bg-white border border-neutral-200 rounded-xl p-4">
    <div class="font-semibold mb-3">Top services (this month)</div>
    <?php if (!$top_services): ?>
      <div class="text-sm text-neutral-500">No bookings yet.</div>
    <?php else: ?>
      <ol class="space-y-2">
        <?php foreach ($top_services as $i => $s): ?>
          <li class="flex items-center justify-between">
            <span class="text-sm"><span class="text-neutral-400 mr-2"><?= $i+1 ?>.</span><?= e($s['name']) ?></span>
            <span class="text-xs text-neutral-500"><?= (int)$s['cnt'] ?> bookings</span>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</div>

<?php if (is_admin() && $staff_perf): ?>
<div class="bg-white border border-neutral-200 rounded-xl p-4 mb-6">
  <div class="font-semibold mb-3">Staff performance (this month)</div>
  <table class="w-full text-sm">
    <thead><tr class="text-neutral-500 text-left"><th class="py-1 font-normal">Staff</th><th class="font-normal">Bookings</th><th class="font-normal">Revenue</th></tr></thead>
    <tbody>
    <?php foreach ($staff_perf as $p): ?>
      <tr class="border-t border-neutral-100">
        <td class="py-2"><?= e($p['name']) ?></td>
        <td><?= (int)$p['cnt'] ?></td>
        <td>$<?= format_money((float)$p['revenue']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const hoursLabels = Array.from({length:24}, (_,i) => String(i).padStart(2,'0') + ':00');
const hoursData = <?= json_encode(array_values($hour_map)) ?>;
const primary = '<?= e($primary = primary_color()) ?>';

new Chart(document.getElementById('hoursChart'), {
  type: 'bar',
  data: { labels: hoursLabels, datasets: [{ data: hoursData, backgroundColor: primary, borderRadius: 6 }] },
  options: { plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } } }
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
  options: { cutout: '65%', plugins: { legend: { position: 'bottom' } } }
});
</script>
<?php admin_footer(); ?>
