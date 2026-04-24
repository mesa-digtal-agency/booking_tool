<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-layout.php';

require_admin();
$page_title = 'Staff';
$active = 'staff';

$edit_id = (int)($_GET['edit'] ?? 0);
$editing = $edit_id > 0 ? db_fetch("SELECT * FROM staff WHERE id = ?", [$edit_id]) : null;
if ($edit_id && !$editing) { http_response_code(404); exit('Not found.'); }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'delete') {
        $did = (int)($_POST['id'] ?? 0);
        if ($did > 0 && $did !== (int)current_user()['id']) {
            if (is_last_active_admin($did)) {
                flash('error', 'Cannot delete the only active admin — the business would be locked out.');
            } else {
                db_exec("DELETE FROM staff WHERE id = ?", [$did]);
                flash('ok', 'Staff member deleted.');
            }
        }
        redirect('/admin/staff.php');
    }

    $data = [
        'name'      => str_in($_POST, 'name', 120),
        'email'     => strtolower(str_in($_POST, 'email', 190)),
        'phone'     => str_in($_POST, 'phone', 40),
        'role'      => (($_POST['role'] ?? 'staff') === 'admin') ? 'admin' : 'staff',
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    $password = (string)($_POST['password'] ?? '');
    if ($data['name'] === '') $errors[] = 'Name is required.';
    if (!is_valid_email($data['email'])) $errors[] = 'Email is invalid.';

    // Last-admin guard: block edits that demote or deactivate the sole
    // active admin, regardless of who is performing the edit.
    if (!$errors && $editing) {
        $changing_role   = ($data['role'] !== $editing['role']);
        $changing_active = ((int)$data['is_active'] !== (int)$editing['is_active']);
        if (($changing_role || $changing_active) && is_last_active_admin((int)$editing['id'])) {
            if ($data['role'] !== 'admin') {
                $errors[] = 'Cannot demote the only active admin — promote someone else first.';
            }
            if ((int)$data['is_active'] === 0) {
                $errors[] = 'Cannot deactivate the only active admin — the business would be locked out.';
            }
        }
    }

    // Uniqueness
    if (!$errors) {
        $existing = db_fetch("SELECT id FROM staff WHERE email = ?", [$data['email']]);
        if ($existing && (int)$existing['id'] !== (int)($editing['id'] ?? 0)) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    if (!$editing && strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

    $avatar_filename = $editing['avatar'] ?? null;
    if (!$errors && !empty($_FILES['avatar']['name'])) {
        $file = $_FILES['avatar'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Avatar upload failed.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Avatar must be under 5 MB.';
        } else {
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
            $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : mime_content_type($file['tmp_name']);
            if ($finfo) finfo_close($finfo);
            $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($ext_map[$mime])) {
                $errors[] = 'Avatar must be JPG, PNG, or WEBP.';
            } else {
                $ext = $ext_map[$mime];
                $new = 'staff_' . ($editing['id'] ?? 'new') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest = APP_ROOT . '/assets/avatars/' . $new;
                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                    $errors[] = 'Could not save avatar.';
                } else {
                    // Remove old avatar if any.
                    if (!empty($editing['avatar'])) {
                        $old = APP_ROOT . '/assets/avatars/' . basename($editing['avatar']);
                        if (is_file($old)) @unlink($old);
                    }
                    $avatar_filename = $new;
                }
            }
        }
    }

    if (!$errors) {
        if ($editing) {
            $fields = 'name=?, email=?, phone=?, role=?, is_active=?, avatar=?';
            $params = [$data['name'], $data['email'], $data['phone'], $data['role'], $data['is_active'], $avatar_filename];
            if ($password !== '') {
                if (strlen($password) < 8) { $errors[] = 'Password must be at least 8 characters.'; }
                if (!$errors) {
                    $fields .= ', password_hash=?';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }
            }
            if (!$errors) {
                $params[] = (int)$editing['id'];
                db_exec("UPDATE staff SET {$fields} WHERE id = ?", $params);
                flash('ok', 'Staff updated.');
                redirect('/admin/staff.php');
            }
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $new_id = (int)db_insert(
                "INSERT INTO staff (name, email, phone, role, password_hash, avatar, is_active)
                 VALUES (?,?,?,?,?,?,?)",
                [$data['name'],$data['email'],$data['phone'],$data['role'],$hash,$avatar_filename,$data['is_active']]
            );
            // Default working hours.
            for ($dow = 0; $dow <= 6; $dow++) {
                $off = ($dow === 0 || $dow === 6) ? 1 : 0;
                db_insert("INSERT INTO working_hours (staff_id, day_of_week, start_time, end_time, is_off) VALUES (?,?,?,?,?)",
                    [$new_id, $dow, '09:00', '17:00', $off]);
            }
            flash('ok', 'Staff created.');
            redirect('/admin/staff.php');
        }
    }
    // rehydrate on errors
    $editing = array_merge($editing ?? [], $data, ['avatar' => $avatar_filename]);
}

$all = db_all("SELECT s.*, (SELECT COUNT(*) FROM staff_services ss WHERE ss.staff_id = s.id) AS svc_count FROM staff s ORDER BY is_active DESC, name");

admin_header();
?>
<?= flash_render() ?>
<div class="flex items-center mb-4">
  <h1 class="text-2xl font-semibold mr-auto">Staff</h1>
  <a href="?new=1" class="px-3 py-1.5 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">+ New staff</a>
</div>

<?php if ($editing || isset($_GET['new'])): ?>
  <?php if ($errors): ?>
    <script>window.addEventListener('DOMContentLoaded', function(){ <?php foreach ($errors as $er) echo 'window.toast && window.toast(' . json_encode($er) . ', { type: "error", timeout: 7000 });'; ?> });</script>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="bg-white border border-neutral-200 rounded-xl p-5 space-y-3 mb-6 max-w-2xl">
    <?= csrf_field() ?>
    <?php if ($editing && !empty($editing['id'])): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <div class="grid md:grid-cols-2 gap-3">
      <label class="text-sm block">Name <input name="name" required value="<?= e($editing['name'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
      <label class="text-sm block">Email <input type="email" name="email" required value="<?= e($editing['email'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
      <label class="text-sm block">Phone <input name="phone" value="<?= e($editing['phone'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
      <label class="text-sm block">Role
        <select name="role" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
          <option value="staff" <?= ($editing['role'] ?? 'staff')==='staff'?'selected':'' ?>>Staff</option>
          <option value="admin" <?= ($editing['role'] ?? '')==='admin'?'selected':'' ?>>Admin</option>
        </select>
      </label>
      <label class="text-sm block">Password <?= $editing ? '<span class="text-neutral-400">(leave blank to keep)</span>' : '' ?>
        <input type="password" name="password" minlength="8" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md" <?= $editing ? '' : 'required' ?>>
      </label>
      <label class="text-sm block">Avatar
        <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" class="mt-1 w-full text-sm">
        <?php if (!empty($editing['avatar'])): ?><div class="mt-2"><img src="/assets/avatars/<?= e($editing['avatar']) ?>" class="w-14 h-14 rounded-full object-cover"></div><?php endif; ?>
      </label>
    </div>
    <label class="text-sm inline-flex items-center gap-2"><input type="checkbox" name="is_active" <?= (int)($editing['is_active'] ?? 1)===1?'checked':'' ?>> Active</label>
    <div class="flex gap-2">
      <button class="px-4 py-2 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">Save</button>
      <a href="/admin/staff.php" class="px-4 py-2 rounded-lg border border-neutral-200 text-sm bg-white">Cancel</a>
    </div>
  </form>
<?php endif; ?>

<div class="bg-white border border-neutral-200 rounded-xl overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-neutral-50 text-left text-neutral-500 text-xs uppercase">
      <tr>
        <th class="px-3 py-2"></th>
        <th class="px-3 py-2">Name</th>
        <th class="px-3 py-2">Email</th>
        <th class="px-3 py-2">Role</th>
        <th class="px-3 py-2">Services</th>
        <th class="px-3 py-2">Active</th>
        <th class="px-3 py-2"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($all as $s): ?>
      <tr class="border-t border-neutral-100">
        <td class="px-3 py-2">
          <?php if (!empty($s['avatar'])): ?>
            <img src="/assets/avatars/<?= e($s['avatar']) ?>" class="w-8 h-8 rounded-full object-cover">
          <?php else: ?>
            <div class="w-8 h-8 rounded-full bg-neutral-100 flex items-center justify-center text-xs"><?= e(strtoupper(substr($s['name'],0,2))) ?></div>
          <?php endif; ?>
        </td>
        <td class="px-3 py-2 font-medium"><?= e($s['name']) ?></td>
        <td class="px-3 py-2 text-neutral-500"><?= e($s['email']) ?></td>
        <td class="px-3 py-2"><?= e($s['role']) ?></td>
        <td class="px-3 py-2"><?= (int)$s['svc_count'] ?></td>
        <td class="px-3 py-2"><?= (int)$s['is_active']?'Yes':'No' ?></td>
        <td class="px-3 py-2 text-right">
          <a class="text-primary hover:underline mr-3" href="?edit=<?= (int)$s['id'] ?>">Edit</a>
          <?php if ((int)$s['id'] !== (int)current_user()['id']): ?>
          <form method="post" class="inline" onsubmit="return confirm('Delete this staff member?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="text-red-600 hover:underline">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php admin_footer(); ?>
