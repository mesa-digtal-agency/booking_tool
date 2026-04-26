<?php
/**
 * CSV import / export for services, staff, and bookings.
 *
 * Each entity has its own export link and upload form. Imports are
 * upserts (update existing row when a natural key matches — email for
 * staff, name for services, id for bookings), and skip rows with
 * validation errors rather than aborting the whole file.
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
unset($_SESSION['import_staff_reset_links']);

function read_upload_csv(string $field): ?array {
    if (empty($_FILES[$field]['name'])) return null;
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) return null;
    $fp = fopen($_FILES[$field]['tmp_name'], 'r');
    if (!$fp) return null;
    $header = fgetcsv($fp);
    if (!$header) { fclose($fp); return ['header' => [], 'rows' => []]; }
    // Normalize column names (lowercase, underscores).
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
    $rows = [];
    while (($r = fgetcsv($fp)) !== false) {
        if (count($r) === 1 && trim($r[0]) === '') continue;
        $rows[] = array_combine($header, array_pad($r, count($header), ''));
    }
    fclose($fp);
    return ['header' => $header, 'rows' => $rows];
}

function flash_import_result(array $result): void {
    $msg = "Imported: {$result['inserted']} new, {$result['updated']} updated";
    if (!empty($result['errors'])) {
        $msg .= '. Skipped ' . count($result['errors']) . ' row(s) with errors.';
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
        $reset_links = [];
        foreach ($csv['rows'] as $i => $r) {
            $name = trim((string)($r['name'] ?? ''));
            if ($name === '') { $errors[] = "row " . ($i + 2) . ": missing name"; continue; }
            $duration = (int)($r['duration_minutes'] ?? 0);
            if ($duration < 5) { $errors[] = "row " . ($i + 2) . ": invalid duration"; continue; }
            $data = [
                $name,
                (string)($r['description'] ?? ''),
                (string)($r['category'] ?? ''),
                $duration,
                (float)($r['price'] ?? 0),
                (int)(!empty($r['is_active']) && $r['is_active'] !== '0' ? 1 : 0),
                trim((string)($r['image'] ?? '')) ?: null,
            ];
            // Upsert by name (case-insensitive).
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
        foreach ($csv['rows'] as $i => $r) {
            $email = strtolower(trim((string)($r['email'] ?? '')));
            $name  = trim((string)($r['name'] ?? ''));
            if ($name === '' || !is_valid_email($email)) {
                $errors[] = "row " . ($i + 2) . ": missing name or invalid email";
                continue;
            }
            $phone = trim((string)($r['phone'] ?? ''));
            $role  = (($r['role'] ?? 'staff') === 'admin') ? 'admin' : 'staff';
            $is_active = (int)(!empty($r['is_active']) && $r['is_active'] !== '0' ? 1 : 0);
            $existing = db_fetch("SELECT id FROM staff WHERE email = ?", [$email]);
            if ($existing) {
                // Can't demote/deactivate the last active admin via import either.
                if (is_last_active_admin((int)$existing['id']) && ($role !== 'admin' || $is_active === 0)) {
                    $errors[] = "row " . ($i + 2) . ": refused to demote/deactivate the only active admin ($email)";
                    continue;
                }
                db_exec("UPDATE staff SET name=?, phone=?, role=?, is_active=? WHERE id=?",
                    [$name, $phone, $role, $is_active, (int)$existing['id']]);
                $updated++;
            } else {
                // New staff needs a password. Generate a random one — the admin
                // should hand them the password-reset flow afterwards.
                $tmp_pwd = bin2hex(random_bytes(8));
                db_insert("INSERT INTO staff (name, email, phone, role, password_hash, is_active) VALUES (?,?,?,?,?,?)",
                    [$name, $email, $phone, $role, password_hash($tmp_pwd, PASSWORD_DEFAULT), $is_active]);
                // Seed default working hours for the new user.
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

        // Build name → id maps once.
        $svc_map = []; foreach (db_all("SELECT id, name FROM services") as $s) $svc_map[strtolower($s['name'])] = (int)$s['id'];
        $staff_map = []; foreach (db_all("SELECT id, name FROM staff") as $s) $staff_map[strtolower($s['name'])] = (int)$s['id'];

        foreach ($csv['rows'] as $i => $r) {
            $svc_name = strtolower(trim((string)($r['service_name'] ?? '')));
            $st_name  = strtolower(trim((string)($r['staff_name'] ?? '')));
            $date     = trim((string)($r['booking_date'] ?? ''));
            $start    = normalize_time(trim((string)($r['start_time'] ?? ''))) ?? '';
            $end      = normalize_time(trim((string)($r['end_time'] ?? ''))) ?? '';
            $status   = in_array(($r['status'] ?? 'pending'), booking_all_statuses(), true)
                        ? $r['status'] : 'pending';
            $name     = trim((string)($r['customer_name'] ?? ''));
            $email    = strtolower(trim((string)($r['customer_email'] ?? '')));
            $phone    = trim((string)($r['customer_phone'] ?? ''));
            $notes    = (string)($r['notes'] ?? '');

            if (!isset($svc_map[$svc_name]))   { $errors[] = "row " . ($i + 2) . ": unknown service '$svc_name'"; continue; }
            if (!isset($staff_map[$st_name]))  { $errors[] = "row " . ($i + 2) . ": unknown staff '$st_name'"; continue; }
            if (!is_valid_date($date))         { $errors[] = "row " . ($i + 2) . ": invalid date"; continue; }
            if (!is_valid_time($start) || !is_valid_time($end)) { $errors[] = "row " . ($i + 2) . ": invalid times"; continue; }
            if ($name === '')                  { $errors[] = "row " . ($i + 2) . ": missing customer_name"; continue; }

            $token = uuid_v4();

            $id = (int)($r['id'] ?? 0);
            if ($id > 0 && db_fetch("SELECT 1 FROM bookings WHERE id = ?", [$id])) {
                db_exec(
                    "UPDATE bookings SET customer_name=?, customer_email=?, customer_phone=?, notes=?,
                     service_id=?, staff_id=?, booking_date=?, start_time=?, end_time=?, status=?
                     WHERE id=?",
                    [$name, $email, $phone, $notes, $svc_map[$svc_name], $staff_map[$st_name], $date, $start, $end, $status, $id]
                );
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

admin_header();
?>
<?= flash_render() ?>
<h1 class="text-2xl font-semibold mb-4">Import / export</h1>
<p class="text-sm text-neutral-500 mb-6">Back up your data or move it between installs. Imports are upserts: rows with a matching natural key (service name, staff email, booking id) update the existing row; the rest are inserted.</p>

<?php if ($new_staff_reset_links): ?>
  <div class="bg-white border border-neutral-200 rounded-xl p-4 mb-6">
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

<div class="grid md:grid-cols-3 gap-4">
  <?php foreach ([
    ['services', 'Services', 'Upsert by service name. Required columns: <code>name</code>, <code>duration_minutes</code>. Optional: description, category, price, is_active, image.'],
    ['staff',    'Staff',    'Upsert by email. Required: <code>name</code>, <code>email</code>. Optional: phone, role (admin/staff), is_active. New staff get a password-reset link after import so you can send them access immediately.'],
    ['bookings', 'Bookings', 'Upsert by id (when present), else insert. Required: <code>service_name</code>, <code>staff_name</code>, <code>booking_date</code>, <code>start_time</code>, <code>end_time</code>, <code>customer_name</code>. Unknown service/staff names are rejected.'],
  ] as [$kind, $title, $desc]): ?>
    <div class="bg-white border border-neutral-200 rounded-xl p-4 flex flex-col h-full">
      <div class="flex items-center justify-between mb-2">
        <h2 class="font-semibold"><?= e($title) ?></h2>
        <a class="text-xs text-primary hover:underline" href="?export=<?= e($kind) ?>">Export CSV ↓</a>
      </div>
      <p class="text-xs text-neutral-500 mb-3 leading-snug"><?= $desc ?></p>
      <form method="post" enctype="multipart/form-data" class="mt-auto space-y-2">
        <?= csrf_field() ?>
        <input type="hidden" name="import_kind" value="<?= e($kind) ?>">
        <input type="file" name="file" accept=".csv,text/csv" required class="w-full text-sm">
        <button class="w-full px-3 py-1.5 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">Import <?= e($title) ?> CSV</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php admin_footer(); ?>
