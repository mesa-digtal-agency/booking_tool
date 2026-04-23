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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin(); // only admins modify services
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'delete') {
        $did = (int)($_POST['id'] ?? 0);
        if ($did > 0) {
            db_exec("DELETE FROM staff_services WHERE service_id = ?", [$did]);
            db_exec("DELETE FROM services WHERE id = ?", [$did]);
            flash('ok', 'Service deleted.');
        }
        redirect('/admin/services.php');
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

    if (!$errors) {
        if ($editing) {
            db_exec("UPDATE services SET name=?, description=?, category=?, duration_minutes=?, price=?, is_active=? WHERE id=?",
                [$data['name'],$data['description'],$data['category'],$data['duration_minutes'],$data['price'],$data['is_active'],(int)$editing['id']]);
            $sid = (int)$editing['id'];
        } else {
            $sid = (int)db_insert(
                "INSERT INTO services (name, description, category, duration_minutes, price, is_active) VALUES (?,?,?,?,?,?)",
                [$data['name'],$data['description'],$data['category'],$data['duration_minutes'],$data['price'],$data['is_active']]
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
    $editing = array_merge($editing ?? [], $data);
}

$all = db_all("SELECT * FROM services ORDER BY is_active DESC, category, name");
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
  <form method="post" class="bg-white border border-neutral-200 rounded-xl p-5 space-y-3 mb-6 max-w-3xl">
    <?= csrf_field() ?>
    <?php if ($editing && !empty($editing['id'])): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <div class="grid md:grid-cols-2 gap-3">
      <label class="text-sm block">Name <input name="name" required value="<?= e($editing['name'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
      <label class="text-sm block">Category <input name="category" value="<?= e($editing['category'] ?? '') ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
      <label class="text-sm block">Duration (min) <input name="duration_minutes" type="number" min="5" step="5" required value="<?= e((string)($editing['duration_minutes'] ?? 30)) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
      <label class="text-sm block">Price <input name="price" type="number" min="0" step="0.01" required value="<?= e((string)($editing['price'] ?? 0)) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"></label>
    </div>
    <label class="text-sm block">Description
      <textarea name="description" rows="2" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md"><?= e($editing['description'] ?? '') ?></textarea>
    </label>
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
        <th class="px-3 py-2">Name</th>
        <th class="px-3 py-2">Category</th>
        <th class="px-3 py-2">Duration</th>
        <th class="px-3 py-2">Price</th>
        <th class="px-3 py-2">Active</th>
        <?php if (is_admin()): ?><th class="px-3 py-2"></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php if (!$all): ?><tr><td colspan="6" class="px-3 py-8 text-center text-neutral-500">No services yet.</td></tr><?php endif; ?>
    <?php foreach ($all as $s): ?>
      <tr class="border-t border-neutral-100">
        <td class="px-3 py-2 font-medium"><?= e($s['name']) ?></td>
        <td class="px-3 py-2"><?= e($s['category']) ?></td>
        <td class="px-3 py-2"><?= (int)$s['duration_minutes'] ?> min</td>
        <td class="px-3 py-2">$<?= format_money($s['price']) ?></td>
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
<?php admin_footer(); ?>
