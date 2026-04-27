<?php
/**
 * CSV import / export for services, staff, and bookings.
 *
 * Imports are upserts. Matching rows update existing records; the rest
 * are inserted. Rows with validation issues are skipped.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-layout.php';

require_admin();
if (!import_export_enabled()) {
    http_response_code(404);
    exit('Import / export is disabled.');
}
ensure_migrations();
$page_title = 'Import / export';
$active = 'data';

// ---------------------------------------------------------------------
// Exports
// ---------------------------------------------------------------------
$export = $_GET['export'] ?? '';

function stream_csv(string $filename, array $header, iterable $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

if ($export === 'services') {
    $rows = db_all("SELECT id, name, description, category, duration_minutes, price, is_active, image FROM services ORDER BY id");
    stream_csv('services-' . date('Ymd-His') . '.csv',
        ['id','name','description','category','duration_minutes','price','is_active','image'],
        array_map(fn($r) => [$r['id'],$r['name'],$r['description'],$r['category'],$r['duration_minutes'],$r['price'],$r['is_active'],$r['image']], $rows));
}
if ($export === 'staff') {
    $rows = db_all("SELECT id, name, email, phone, role, is_active FROM staff ORDER BY id");
    stream_csv('staff-' . date('Ymd-His') . '.csv',
        ['id','name','email','phone','role','is_active'],
        array_map(fn($r) => [$r['id'],$r['name'],$r['email'],$r['phone'],$r['role'],$r['is_active']], $rows));
}
if ($export === 'bookings') {
    $rows = db_all(
        "SELECT b.id, b.booking_date, b.start_time, b.end_time, b.status,
                b.customer_name, b.customer_email, b.customer_phone, b.notes,
                s.name AS service_name, st.name AS staff_name,
                s.price, b.created_at
         FROM bookings b
         JOIN services s ON s.id = b.service_id
         JOIN staff st ON st.id = b.staff_id
         ORDER BY b.id"
    );
    stream_csv('bookings-' . date('Ymd-His') . '.csv',
        ['id','booking_date','start_time','end_time','status','customer_name','customer_email','customer_phone','notes','service_name','staff_name','price','created_at'],
        array_map(fn($r) => [$r['id'],$r['booking_date'],$r['start_time'],$r['end_time'],$r['status'],$r['customer_name'],$r['customer_email'],$r['customer_phone'],$r['notes'],$r['service_name'],$r['staff_name'],$r['price'],$r['created_at']], $rows));
}

// ---------------------------------------------------------------------
// Imports
// ---------------------------------------------------------------------
$import_result = null;
$new_staff_reset_links = $_SESSION['import_staff_reset_links'] ?? [];
$recent_import_errors = $_SESSION['import_errors'] ?? [];
$recent_import_error_count = (int)($_SESSION['import_error_count'] ?? 0);
unset($_SESSION['import_staff_reset_links'], $_SESSION['import_errors'], $_SESSION['import_error_count']);

function read_upload_csv(string $field): ?array {
    if (empty($_FILES[$field]['name'])) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) return null;
    if (!is_uploaded_file((string)$_FILES[$field]['tmp_name'])) return null;
    $ext = strtolower((string)pathinfo((string)$_FILES[$field]['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') return null;
    $fp = fopen($_FILES[$field]['tmp_name'], 'r');
    if (!$fp) return null;
    $header = fgetcsv($fp);
    if (!$header) { fclose($fp); return ['header' => [], 'rows' => []]; }
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
    $rows = [];
    while (($r = fgetcsv($fp)) !== false) {
        if (count($r) === 1 && trim($r[0]) === '') continue;
        if (count($rows) >= 10000) break;
        $rows[] = array_combine($header, array_slice(array_pad($r, count($header), ''), 0, count($header)));
    }
    fclose($fp);
    return ['header' => $header, 'rows' => $rows];
}

function flash_import_result(array $result): void {
    $msg = "Imported: {$result['inserted']} new, {$result['updated']} updated";
    if (!empty($result['errors'])) {
        $msg .= '. Skipped ' . count($result['errors']) . ' row(s) with errors.';
        $_SESSION['import_errors'] = array_slice($result['errors'], 0, 8);
        $_SESSION['import_error_count'] = count($result['errors']);
    }
    flash(empty($result['errors']) ? 'ok' : 'warn', $msg);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $kind = $_POST['import_kind'] ?? '';

    if ($kind === 'services') {
        $csv = read_upload_csv('file');
        if (!$csv) {
            flash('error', 'Could not read the uploaded file (must be a CSV under 5 MB).');
            redirect('/admin/import-export.php');
        }
        $inserted = 0; $updated = 0; $errors = [];
        foreach ($csv['rows'] as $i => $r) {
            $name = str_in($r, 'name', 120);
            if ($name === '') { $errors[] = "row " . ($i + 2) . ": missing name"; continue; }
            $duration = (int)($r['duration_minutes'] ?? 0);
            if ($duration < 5) { $errors[] = "row " . ($i + 2) . ": invalid duration"; continue; }
            $image = trim((string)($r['image'] ?? ''));
            if ($image !== '') {
                $image = basename(str_replace('\\', '/', $image));
                if (!preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i', $image)) {
                    $errors[] = "row " . ($i + 2) . ": invalid image filename";
                    continue;
                }
            }
            $data = [
                $name,
                str_in($r, 'description', 1000),
                str_in($r, 'category', 80),
                $duration,
                max(0, (float)($r['price'] ?? 0)),
                (int)(!empty($r['is_active']) && $r['is_active'] !== '0' ? 1 : 0),
                $image !== '' ? $image : null,
            ];
            $existing = db_fetch("SELECT id FROM services WHERE LOWER(name) = LOWER(?)", [$name]);
            if ($existing) {
                db_exec("UPDATE services SET name=?, description=?, category=?, duration_minutes=?, price=?, is_active=?, image=? WHERE id=?",
                    array_merge($data, [(int)$existing['id']]));
                $updated++;
            } else {
                db_insert("INSERT INTO services (name, description, category, duration_minutes, price, is_active, image) VALUES (?,?,?,?,?,?,?)", $data);
                $inserted++;
            }
        }
        flash_import_result(compact('inserted','updated','errors'));
        redirect('/admin/import-export.php');
    }

    if ($kind === 'staff') {
        $csv = read_upload_csv('file');
        if (!$csv) { flash('error', 'Could not read the uploaded file.'); redirect('/admin/import-export.php'); }
        $inserted = 0; $updated = 0; $errors = [];
        $reset_links = [];
        foreach ($csv['rows'] as $i => $r) {
            $email = strtolower(str_in($r, 'email', 190));
            $name  = str_in($r, 'name', 120);
            if ($name === '' || !is_valid_email($email)) {
                $errors[] = "row " . ($i + 2) . ": missing name or invalid email";
                continue;
            }
            $phone = str_in($r, 'phone', 40);
            if ($phone !== '' && !is_valid_phone($phone)) {
                $errors[] = "row " . ($i + 2) . ": invalid phone";
                continue;
            }
            $role  = (($r['role'] ?? 'staff') === 'admin') ? 'admin' : 'staff';
            $is_active = (int)(!empty($r['is_active']) && $r['is_active'] !== '0' ? 1 : 0);
            $existing = db_fetch("SELECT id FROM staff WHERE email = ?", [$email]);
            if ($existing) {
                if (is_last_active_admin((int)$existing['id']) && ($role !== 'admin' || $is_active === 0)) {
                    $errors[] = "row " . ($i + 2) . ": refused to demote/deactivate the only active admin ($email)";
                    continue;
                }
                db_exec("UPDATE staff SET name=?, phone=?, role=?, is_active=? WHERE id=?",
                    [$name, $phone, $role, $is_active, (int)$existing['id']]);
                $updated++;
            } else {
                $tmp_pwd = bin2hex(random_bytes(8));
                db_insert("INSERT INTO staff (name, email, phone, role, password_hash, is_active) VALUES (?,?,?,?,?,?)",
                    [$name, $email, $phone, $role, password_hash($tmp_pwd, PASSWORD_DEFAULT), $is_active]);
                $nid = (int)db()->lastInsertId();
                for ($dow = 0; $dow <= 6; $dow++) {
                    $off = ($dow === 0 || $dow === 6) ? 1 : 0;
                    db_insert("INSERT INTO working_hours (staff_id, day_of_week, start_time, end_time, is_off) VALUES (?,?,?,?,?)",
                        [$nid, $dow, '09:00', '17:00', $off]);
                }
                $raw = issue_password_reset_token($nid, 7 * 24 * 3600);
                if ($raw) {
                    $reset_links[] = [
                        'name' => $name,
                        'email' => $email,
                        'reset_url' => app_url('/admin/reset-password.php?token=' . $raw),
                    ];
                }
                $inserted++;
            }
        }
        $_SESSION['import_staff_reset_links'] = $reset_links;
        flash_import_result(compact('inserted','updated','errors'));
        redirect('/admin/import-export.php');
    }

    if ($kind === 'bookings') {
        $csv = read_upload_csv('file');
        if (!$csv) { flash('error', 'Could not read the uploaded file.'); redirect('/admin/import-export.php'); }
        $inserted = 0; $updated = 0; $errors = [];

        $svc_map = []; foreach (db_all("SELECT id, name FROM services") as $s) $svc_map[strtolower($s['name'])] = (int)$s['id'];
        $staff_map = []; foreach (db_all("SELECT id, name FROM staff") as $s) $staff_map[strtolower($s['name'])] = (int)$s['id'];

        foreach ($csv['rows'] as $i => $r) {
            $svc_name = strtolower(trim((string)($r['service_name'] ?? '')));
            $st_name  = strtolower(trim((string)($r['staff_name'] ?? '')));
            $date     = str_in($r, 'booking_date', 10);
            $start    = normalize_time(str_in($r, 'start_time', 8)) ?? '';
            $end      = normalize_time(str_in($r, 'end_time', 8)) ?? '';
            $status   = in_array(($r['status'] ?? 'pending'), booking_all_statuses(), true)
                        ? $r['status'] : 'pending';
            $name     = str_in($r, 'customer_name', 120);
            $email    = strtolower(str_in($r, 'customer_email', 190));
            $phone    = str_in($r, 'customer_phone', 40);
            $notes    = str_in($r, 'notes', 1000);

            if (!isset($svc_map[$svc_name]))   { $errors[] = "row " . ($i + 2) . ": unknown service '$svc_name'"; continue; }
            if (!isset($staff_map[$st_name]))  { $errors[] = "row " . ($i + 2) . ": unknown staff '$st_name'"; continue; }
            if (!is_valid_date($date))         { $errors[] = "row " . ($i + 2) . ": invalid date"; continue; }
            if (!is_valid_time($start) || !is_valid_time($end)) { $errors[] = "row " . ($i + 2) . ": invalid times"; continue; }
            if (time_to_minutes($end) <= time_to_minutes($start)) { $errors[] = "row " . ($i + 2) . ": end_time must be after start_time"; continue; }
            if ($name === '')                  { $errors[] = "row " . ($i + 2) . ": missing customer_name"; continue; }
            if ($email !== '' && !is_valid_email($email)) { $errors[] = "row " . ($i + 2) . ": invalid customer_email"; continue; }
            if ($phone !== '' && !is_valid_phone($phone)) { $errors[] = "row " . ($i + 2) . ": invalid customer_phone"; continue; }

            $token = uuid_v4();
            $id = (int)($r['id'] ?? 0);
            $existing_booking = $id > 0 ? db_fetch("SELECT status FROM bookings WHERE id = ?", [$id]) : null;
            if ($existing_booking) {
                $old_status = (string)$existing_booking['status'];
                db_exec(
                    "UPDATE bookings SET customer_name=?, customer_email=?, customer_phone=?, notes=?,
                     service_id=?, staff_id=?, booking_date=?, start_time=?, end_time=?, status=?
                     WHERE id=?",
                    [$name, $email, $phone, $notes, $svc_map[$svc_name], $staff_map[$st_name], $date, $start, $end, $status, $id]
                );
                if ($old_status !== $status) {
                    send_booking_status_change_email($id, $old_status, $status);
                }
                $updated++;
            } else {
                db_insert(
                    "INSERT INTO bookings (customer_name, customer_email, customer_phone, notes,
                     service_id, staff_id, booking_date, start_time, end_time, status, management_token)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                    [$name, $email, $phone, $notes, $svc_map[$svc_name], $staff_map[$st_name], $date, $start, $end, $status, $token]
                );
                $inserted++;
            }
        }
        flash_import_result(compact('inserted','updated','errors'));
        redirect('/admin/import-export.php');
    }
}

$import_cards = [
    [
        'kind' => 'services',
        'title' => 'Services',
        'summary' => 'Update service names, duration, pricing, categories, visibility, and images.',
        'key' => 'Matched by service name',
        'required' => ['name', 'duration_minutes'],
        'optional' => ['description', 'category', 'price', 'is_active', 'image'],
    ],
    [
        'kind' => 'staff',
        'title' => 'Staff',
        'summary' => 'Add or update staff profiles. Newly inserted staff receive reset links after import.',
        'key' => 'Matched by email',
        'required' => ['name', 'email'],
        'optional' => ['phone', 'role', 'is_active'],
    ],
    [
        'kind' => 'bookings',
        'title' => 'Bookings',
        'summary' => 'Move appointment history and notes between installs. Unknown service or staff names are skipped.',
        'key' => 'Matched by id when present',
        'required' => ['service_name', 'staff_name', 'booking_date', 'start_time', 'end_time', 'customer_name'],
        'optional' => ['id', 'status', 'customer_email', 'customer_phone', 'notes'],
    ],
];

admin_header();
?>
<?= flash_render() ?>
<div class="max-w-6xl space-y-6">
  <div>
    <h1 class="text-2xl font-semibold">Import / export</h1>
    <p class="mt-1 text-sm text-neutral-500 max-w-2xl">Back up your data or move it between installs. Imports update matching rows and insert new rows; rows with validation issues are skipped.</p>
  </div>

  <div class="bg-white border border-neutral-200 rounded-xl p-4">
    <div class="font-semibold mb-2">Import behavior</div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
      <div class="border-l-2 border-neutral-200 pl-3">
        <div class="text-xs font-semibold uppercase text-neutral-500">Update</div>
        <div class="mt-1">Matching CSV rows update existing records.</div>
      </div>
      <div class="border-l-2 border-neutral-200 pl-3">
        <div class="text-xs font-semibold uppercase text-neutral-500">Insert</div>
        <div class="mt-1">Rows without a match are created as new records.</div>
      </div>
      <div class="border-l-2 border-neutral-200 pl-3">
        <div class="text-xs font-semibold uppercase text-neutral-500">Validate</div>
        <div class="mt-1">Problem rows are skipped without stopping the file.</div>
      </div>
      <div class="border-l-2 border-neutral-200 pl-3">
        <div class="text-xs font-semibold uppercase text-neutral-500">Constraints</div>
        <div class="mt-1">CSV files only, 5 MB max, case-insensitive column names.</div>
      </div>
    </div>
  </div>

  <?php if ($recent_import_errors): ?>
    <div class="bg-amber-100 border border-amber-200 rounded-xl p-4">
      <div class="font-semibold text-amber-700">Skipped rows from the last import</div>
      <p class="text-sm text-amber-700 mt-1">Showing <?= count($recent_import_errors) ?> of <?= $recent_import_error_count ?> issue(s).</p>
      <ul class="mt-3 space-y-1 text-sm text-amber-700">
        <?php foreach ($recent_import_errors as $er): ?>
          <li><?= e($er) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($new_staff_reset_links): ?>
    <div class="bg-white border border-neutral-200 rounded-xl p-4">
      <div class="font-semibold mb-2">New staff reset links</div>
      <p class="text-sm text-neutral-500 mb-3">Staff imported in the last CSV upload were created with password-reset links. Send each person their link instead of trying to share a generated password.</p>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-left text-neutral-500 text-xs uppercase">
            <tr><th class="py-2 pr-3">Name</th><th class="py-2 pr-3">Email</th><th class="py-2">Reset link</th></tr>
          </thead>
          <tbody>
          <?php foreach ($new_staff_reset_links as $row): ?>
            <tr class="border-t border-neutral-100">
              <td class="py-2 pr-3"><?= e($row['name']) ?></td>
              <td class="py-2 pr-3"><?= e($row['email']) ?></td>
              <td class="py-2 break-all"><a class="text-primary hover:underline" href="<?= e($row['reset_url']) ?>"><?= e($row['reset_url']) ?></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="grid xl:grid-cols-3 md:grid-cols-2 gap-4">
    <?php foreach ($import_cards as $card): ?>
      <div class="bg-white border border-neutral-200 rounded-xl p-4 flex flex-col h-full">
        <div class="flex items-start justify-between gap-3 mb-3">
          <div>
            <h2 class="font-semibold text-lg"><?= e($card['title']) ?></h2>
            <p class="text-xs text-neutral-500 mt-1"><?= e($card['key']) ?></p>
          </div>
          <a class="shrink-0 rounded-lg border border-neutral-200 px-3 py-1.5 text-xs hover:bg-neutral-50" href="?export=<?= e($card['kind']) ?>" data-no-loader="true">Export CSV</a>
        </div>
        <p class="text-sm text-neutral-600 leading-snug mb-4"><?= e($card['summary']) ?></p>

        <div class="space-y-3 mb-4">
          <div>
            <div class="text-xs font-semibold uppercase text-neutral-500 mb-1">Required columns</div>
            <div class="flex flex-wrap gap-1.5">
              <?php foreach ($card['required'] as $col): ?>
                <code class="rounded-md bg-neutral-100 px-2 py-1 text-xs"><?= e($col) ?></code>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <div class="text-xs font-semibold uppercase text-neutral-500 mb-1">Optional columns</div>
            <div class="flex flex-wrap gap-1.5">
              <?php foreach ($card['optional'] as $col): ?>
                <code class="rounded-md bg-neutral-100 px-2 py-1 text-xs"><?= e($col) ?></code>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <form method="post" enctype="multipart/form-data" class="mt-auto space-y-3" data-import-form>
          <?= csrf_field() ?>
          <input type="hidden" name="import_kind" value="<?= e($card['kind']) ?>">
          <input id="importFile<?= e(ucfirst($card['kind'])) ?>" type="file" name="file" accept=".csv,text/csv" required class="input import-file-input" data-import-file>
          <label for="importFile<?= e(ucfirst($card['kind'])) ?>" class="labelFile import-upload" data-import-dropzone>
            <span class="import-upload-icon" aria-hidden="true">
              <svg viewBox="0 0 184.69 184.69" xmlns="http://www.w3.org/2000/svg" width="60" height="60">
                <path d="M149.968,50.186c-8.017-14.308-23.796-22.515-40.717-19.813C102.609,16.43,88.713,7.576,73.087,7.576c-22.117,0-40.112,17.994-40.112,40.115c0,0.913,0.036,1.854,0.118,2.834C14.004,54.875,0,72.11,0,91.959c0,23.456,19.082,42.535,42.538,42.535h33.623v-7.025H42.538c-19.583,0-35.509-15.929-35.509-35.509c0-17.526,13.084-32.621,30.442-35.105c0.931-0.132,1.768-0.633,2.326-1.392c0.555-0.755,0.795-1.704,0.644-2.63c-0.297-1.904-0.447-3.582-0.447-5.139c0-18.249,14.852-33.094,33.094-33.094c13.703,0,25.789,8.26,30.803,21.04c0.63,1.621,2.351,2.534,4.058,2.14c15.425-3.568,29.919,3.883,36.604,17.168c0.508,1.027,1.503,1.736,2.641,1.897c17.368,2.473,30.481,17.569,30.481,35.112c0,19.58-15.937,35.509-35.52,35.509H97.391v7.025h44.761c23.459,0,42.538-19.079,42.538-42.535C184.69,71.545,169.884,53.901,149.968,50.186z"/>
                <path d="M108.586,90.201c1.406-1.403,1.406-3.672,0-5.075L88.541,65.078c-0.701-0.698-1.614-1.045-2.534-1.045l-0.064,0.011c-0.018,0-0.036-0.011-0.054-0.011c-0.931,0-1.85,0.361-2.534,1.045L63.31,85.127c-1.403,1.403-1.403,3.672,0,5.075c1.403,1.406,3.672,1.406,5.075,0L82.296,76.29v97.227c0,1.99,1.603,3.597,3.593,3.597c1.979,0,3.59-1.607,3.59-3.597V76.165l14.033,14.036C104.91,91.608,107.183,91.608,108.586,90.201z"/>
              </svg>
            </span>
            <p>Drag and drop your CSV here or click to select a file.</p>
            <span class="import-file-name" data-file-name>No file selected</span>
          </label>
          <button class="w-full px-3 py-2 rounded-lg text-white text-sm disabled:opacity-45 disabled:cursor-not-allowed" style="background: <?= e(primary_color()) ?>">Import <?= e($card['title']) ?></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<script>
(function(){
  document.querySelectorAll('[data-import-form]').forEach(function(form) {
    const file = form.querySelector('[data-import-file]');
    const name = form.querySelector('[data-file-name]');
    const button = form.querySelector('button');
    const dropzone = form.querySelector('[data-import-dropzone]');
    if (!file || !name || !button || !dropzone) return;
    button.disabled = true;

    function syncFileName() {
      const selected = file.files && file.files.length ? file.files[0].name : '';
      name.textContent = selected || 'No file selected';
      button.disabled = !selected;
      dropzone.classList.toggle('has-file', !!selected);
    }

    file.addEventListener('change', syncFileName);
    ['dragenter', 'dragover'].forEach(function(eventName) {
      dropzone.addEventListener(eventName, function(event) {
        event.preventDefault();
        dropzone.classList.add('is-dragging');
      });
    });
    ['dragleave', 'drop'].forEach(function(eventName) {
      dropzone.addEventListener(eventName, function(event) {
        event.preventDefault();
        dropzone.classList.remove('is-dragging');
      });
    });
    dropzone.addEventListener('drop', function(event) {
      const dropped = event.dataTransfer && event.dataTransfer.files;
      if (!dropped || !dropped.length) return;
      try {
        file.files = dropped;
      } catch (error) {
        window.toast && window.toast('Click the upload box to choose the CSV file.', { type: 'warn' });
        return;
      }
      syncFileName();
    });
  });
})();
</script>
<?php admin_footer(); ?>
