<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-layout.php';

require_login();
$page_title = 'Services';
$active = 'services';

$edit_id = (int)($_GET['edit'] ?? 0);
$editing = $edit_id > 0 ? db_fetch("SELECT * FROM services WHERE id = ?", [$edit_id]) : null;
if ($edit_id && !$editing) { http_response_code(404); exit('Service not found.'); }
$errors = [];
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = admin_rows_per_page();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); // only admins modify services
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'delete') {
        $did = (int)($_POST['id'] ?? 0);
        if ($did > 0) {
            $row = db_fetch("SELECT image FROM services WHERE id = ?", [$did]);
            if ((int)db_scalar("SELECT COUNT(*) FROM bookings WHERE service_id = ?", [$did]) > 0) {
                db_exec("UPDATE services SET is_active = 0 WHERE id = ?", [$did]);
                db_exec("DELETE FROM staff_services WHERE service_id = ?", [$did]);
                flash('warn', 'Service archived instead of deleted because existing bookings still reference it.');
            } else {
                db_exec("DELETE FROM staff_services WHERE service_id = ?", [$did]);
                db_exec("DELETE FROM services WHERE id = ?", [$did]);
                // Clean up image file if any.
                if ($row && !empty($row['image'])) {
                    $p = APP_ROOT . '/assets/services/' . basename($row['image']);
                    if (is_file($p)) @unlink($p);
                }
                flash('ok', 'Service deleted.');
            }
        }
        redirect('/admin/services.php');
    }

    if ($action === 'remove_image' && $editing) {
        if (!empty($editing['image'])) {
            $p = APP_ROOT . '/assets/services/' . basename($editing['image']);
            if (is_file($p)) @unlink($p);
        }
        db_exec("UPDATE services SET image = NULL WHERE id = ?", [(int)$editing['id']]);
        flash('ok', 'Service image removed.');
        redirect('/admin/services.php?edit=' . (int)$editing['id']);
    }

    $data = [
        'name'             => str_in($_POST, 'name', 120),
        'description'      => str_in($_POST, 'description', 1000),
        'category'         => str_in($_POST, 'category', 80),
        'duration_minutes' => max(5, min(600, (int)($_POST['duration_minutes'] ?? 0))),
        'price'            => max(0, (float)($_POST['price'] ?? 0)),
        'is_active'        => isset($_POST['is_active']) ? 1 : 0,
    ];
    if ($data['name'] === '') $errors[] = 'Name is required.';
    if ($data['duration_minutes'] <= 0) $errors[] = 'Duration must be > 0 minutes.';

    $assigned = isset($_POST['staff_ids']) && is_array($_POST['staff_ids']) ? array_map('intval', $_POST['staff_ids']) : [];

    // Image upload (optional) — validate MIME + size, rename, replace old file.
    $image_filename = $editing['image'] ?? null;
    if (!$errors && !empty($_FILES['image']['name'])) {
        $file = $_FILES['image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Image upload failed.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Image must be under 5 MB.';
        } else {
            $mime = uploaded_file_mime($file);
            $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($ext_map[$mime])) {
                $errors[] = 'Image must be JPG, PNG, or WEBP.';
            } else {
                $ext = $ext_map[$mime];
                $new = 'service_' . ($editing['id'] ?? 'new') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest_dir = APP_ROOT . '/assets/services';
                if (!is_dir($dest_dir)) @mkdir($dest_dir, 0775, true);
                if (!save_uploaded_square_image($file, $dest_dir, $new, 160)) {
                    $errors[] = 'Could not save image.';
                } else {
                    if (!empty($editing['image'])) {
                        $old = APP_ROOT . '/assets/services/' . basename($editing['image']);
                        if (is_file($old)) @unlink($old);
                    }
                    $image_filename = $new;
                }
            }
        }
    }

    if (!$errors) {
        if ($editing) {
            db_exec(
                "UPDATE services SET name=?, description=?, category=?, duration_minutes=?, price=?, is_active=?, image=? WHERE id=?",
                [$data['name'],$data['description'],$data['category'],$data['duration_minutes'],$data['price'],$data['is_active'],$image_filename,(int)$editing['id']]
            );
            $sid = (int)$editing['id'];
        } else {
            $sid = (int)db_insert(
                "INSERT INTO services (name, description, category, duration_minutes, price, is_active, image) VALUES (?,?,?,?,?,?,?)",
                [$data['name'],$data['description'],$data['category'],$data['duration_minutes'],$data['price'],$data['is_active'],$image_filename]
            );
        }
        // Resync staff_services for this service.
        db_exec("DELETE FROM staff_services WHERE service_id = ?", [$sid]);
        foreach ($assigned as $stid) {
            if ($stid > 0) {
                try { db_insert("INSERT INTO staff_services (staff_id, service_id) VALUES (?, ?)", [$stid, $sid]); } catch (Throwable $e) {}
            }
        }
        flash('ok', $editing ? 'Service updated.' : 'Service created.');
        redirect('/admin/services.php');
    }
    // rehydrate
    $editing = array_merge($editing ?? [], $data, ['image' => $image_filename]);
}

$total_rows = (int)db_scalar("SELECT COUNT(*) FROM services");
$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;
$all = db_all("SELECT * FROM services ORDER BY is_active DESC, category, name LIMIT $per_page OFFSET $offset");
$staff_list = db_all("SELECT id, name FROM staff WHERE is_active = 1 ORDER BY name");
$assigned_ids = [];
if ($editing && !empty($editing['id'])) {
    foreach (db_all("SELECT staff_id FROM staff_services WHERE service_id = ?", [(int)$editing['id']]) as $r) {
        $assigned_ids[(int)$r['staff_id']] = true;
    }
}

admin_header();
?>
<?= flash_render() ?>
<div class="flex items-center mb-4">
  <h1 class="text-2xl font-semibold mr-auto">Services</h1>
  <?php if (is_admin()): ?>
    <a href="?edit=0&new=1" class="px-3 py-1.5 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">+ New service</a>
  <?php endif; ?>
</div>

<?php if (is_admin() && ($editing || isset($_GET['new']))): ?>
  <?php if ($errors): ?>
    <script>window.addEventListener('DOMContentLoaded', function(){ <?php foreach ($errors as $er) echo 'window.toast && window.toast(' . json_encode($er) . ', { type: "error", timeout: 7000 });'; ?> });</script>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="bg-white border border-neutral-200 rounded-xl p-5 space-y-3 mb-6 max-w-3xl">
    <?= csrf_field() ?>
    <?php if ($editing && !empty($editing['id'])): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <div class="grid md:grid-cols-2 gap-3">
      <label class="text-sm block">Name <input name="name" required value="<?= e($editing['name'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
      <label class="text-sm block">Category <input name="category" value="<?= e($editing['category'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
      <label class="text-sm block">Duration (min) <input name="duration_minutes" type="number" min="5" step="5" required value="<?= e((string)($editing['duration_minutes'] ?? 30)) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
      <label class="text-sm block">Price (<?= e(currency_symbol()) ?>) <input name="price" type="number" min="0" step="0.01" required value="<?= e((string)($editing['price'] ?? 0)) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
    </div>
    <label class="text-sm block">Description
      <textarea name="description" rows="2" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"><?= e($editing['description'] ?? '') ?></textarea>
    </label>
    <div class="text-sm">
      <div class="text-neutral-500 mb-1">Image <span class="text-neutral-400">(optional, shown on the booking page, max 160×160)</span></div>
      <div class="flex items-start gap-3">
        <?php if (!empty($editing['image'])): ?>
          <img src="/assets/services/<?= e(basename($editing['image'])) ?>" alt="" class="w-20 h-20 rounded-lg object-cover border border-neutral-200">
        <?php else: ?>
          <div class="w-20 h-20 rounded-lg bg-neutral-100 border border-neutral-200 flex items-center justify-center text-neutral-400 text-xs">No image</div>
        <?php endif; ?>
        <div class="flex-1">
          <input type="file" name="image" accept="image/png,image/jpeg,image/webp" class="w-full text-sm">
          <?php if (!empty($editing['image']) && !empty($editing['id'])): ?>
            <button type="submit" name="action" value="remove_image" class="mt-2 text-xs text-red-600 hover:underline">Remove image</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <label class="text-sm inline-flex items-center gap-2"><input type="checkbox" name="is_active" <?= (int)($editing['is_active'] ?? 1)===1?'checked':'' ?>> Active</label>
    <div class="text-sm">
      <div class="text-neutral-500 mb-1">Assigned staff</div>
      <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
        <?php foreach ($staff_list as $s): ?>
          <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="staff_ids[]" value="<?= (int)$s['id'] ?>" <?= isset($assigned_ids[(int)$s['id']]) ? 'checked' : '' ?>>
            <?= e($s['name']) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <?php if (!$staff_list): ?><p class="text-xs text-neutral-500 mt-1">No active staff yet. <a href="/admin/staff.php" class="underline">Add staff</a>.</p><?php endif; ?>
    </div>
    <div class="flex gap-2">
      <button class="px-4 py-2 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">Save service</button>
      <a href="/admin/services.php" class="px-4 py-2 rounded-lg border border-neutral-200 text-sm bg-white">Cancel</a>
    </div>
  </form>
<?php endif; ?>

<div class="bg-white border border-neutral-200 rounded-xl overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-neutral-50 text-left text-neutral-500 text-xs uppercase">
      <tr>
        <th class="px-3 py-2"></th>
        <th class="px-3 py-2">Name</th>
        <th class="px-3 py-2">Category</th>
        <th class="px-3 py-2">Duration</th>
        <th class="px-3 py-2">Price</th>
        <th class="px-3 py-2">Active</th>
        <?php if (is_admin()): ?><th class="px-3 py-2"></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php if (!$all): ?><tr><td colspan="7" class="px-3 py-8 text-center text-neutral-500">No services yet.</td></tr><?php endif; ?>
    <?php foreach ($all as $s): ?>
      <tr class="border-t border-neutral-100">
        <td class="px-3 py-2">
          <?php if (!empty($s['image'])): ?>
            <img src="/assets/services/<?= e(basename($s['image'])) ?>" alt="" class="w-10 h-10 rounded-lg object-cover">
          <?php else: ?>
            <div class="w-10 h-10 rounded-lg bg-neutral-100"></div>
          <?php endif; ?>
        </td>
        <td class="px-3 py-2 font-medium"><?= e($s['name']) ?></td>
        <td class="px-3 py-2"><?= e($s['category']) ?></td>
        <td class="px-3 py-2"><?= (int)$s['duration_minutes'] ?> min</td>
        <td class="px-3 py-2"><?= e(money_with_currency($s['price'])) ?></td>
        <td class="px-3 py-2"><?= (int)$s['is_active'] ? 'Yes' : 'No' ?></td>
        <?php if (is_admin()): ?>
        <td class="px-3 py-2 text-right">
          <a class="text-primary hover:underline mr-3" href="?edit=<?= (int)$s['id'] ?>">Edit</a>
          <form method="post" class="inline" onsubmit="return confirm('Delete this service?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="text-red-600 hover:underline">Delete</button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="mt-3 flex items-center justify-between text-xs text-neutral-500">
  <div>Page <?= $page ?> of <?= $total_pages ?> · <?= $total_rows ?> service(s)</div>
  <div class="flex items-center gap-2">
    <?php $query = $_GET; $query['page'] = max(1, $page - 1); ?>
    <a class="px-2 py-1 rounded border border-neutral-200 bg-white <?= $page <= 1 ? 'pointer-events-none opacity-50' : '' ?>" href="?<?= e(http_build_query($query)) ?>">Prev</a>
    <?php $query['page'] = min($total_pages, $page + 1); ?>
    <a class="px-2 py-1 rounded border border-neutral-200 bg-white <?= $page >= $total_pages ? 'pointer-events-none opacity-50' : '' ?>" href="?<?= e(http_build_query($query)) ?>">Next</a>
  </div>
</div>
<?php admin_footer(); ?>
