<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin-layout.php';

require_admin();
if (!settings_page_enabled()) {
    http_response_code(404);
    exit('Settings page is disabled.');
}

$page_title = 'Settings';
$active = 'settings';
$errors = [];
$config_path = APP_ROOT . '/config.json';
$raw = is_file($config_path) ? json_decode((string)file_get_contents($config_path), true) : [];
if (!is_array($raw)) $raw = [];
$cfg = $GLOBALS['CONFIG'];

function settings_string(array $cfg, string $key, string $default = ''): string {
    return (string)($cfg[$key] ?? $default);
}

function settings_bool(array $cfg, string $key, bool $default = false): bool {
    $v = $cfg[$key] ?? $default;
    if (is_bool($v)) return $v;
    if (is_string($v)) return !in_array(strtolower(trim($v)), ['0', 'false', 'no', 'off', ''], true);
    return (bool)$v;
}

function post_bool(string $key): bool {
    return isset($_POST[$key]);
}

function normalize_hex_color(string $value, string $fallback): string {
    $value = trim($value);
    return preg_match('/^#[0-9a-f]{6}$/i', $value) ? $value : $fallback;
}

function render_settings_color_picker(string $name, string $label, string $value, array $palette): void {
    $value = strtolower($value);
    $colors = $palette;
    if (preg_match('/^#[0-9a-f]{6}$/i', $value) && !in_array($value, $colors, true)) {
        array_unshift($colors, $value);
    }
    ?>
    <div class="settings-color-picker" data-color-picker>
      <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>" data-color-value>
      <div class="mb-2">
        <div><?= e($label) ?></div>
      </div>
      <div class="container-items" role="listbox" aria-label="<?= e($label) ?>">
        <?php foreach ($colors as $color): ?>
          <button type="button"
                  class="item-color <?= strtolower($color) === $value ? 'selected' : '' ?>"
                  style="--color: <?= e($color) ?>"
                  data-color="<?= e($color) ?>"
                  aria-label="<?= e($color) ?>"
                  aria-selected="<?= strtolower($color) === $value ? 'true' : 'false' ?>"></button>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $next = $raw;
    $next['business_name'] = str_in($_POST, 'business_name', 120);
    $next['business_timezone'] = str_in($_POST, 'business_timezone', 80);
    $next['app_url'] = str_in($_POST, 'app_url', 255);
    $next['business_logo_url'] = str_in($_POST, 'business_logo_url', 255);
    $next['logo_path'] = str_in($_POST, 'logo_path', 255);
    $next['logo_mode'] = in_array(($_POST['logo_mode'] ?? ''), ['logo_and_name', 'logo_only'], true) ? $_POST['logo_mode'] : 'logo_and_name';
    $next['primary_color'] = normalize_hex_color((string)($_POST['primary_color'] ?? ''), primary_color());
    $next['accent_color'] = normalize_hex_color((string)($_POST['accent_color'] ?? ''), $next['primary_color']);
    $next['dark_mode'] = post_bool('dark_mode');
    $next['show_import_export'] = post_bool('show_import_export');
    $next['show_settings_page'] = post_bool('show_settings_page');
    $next['currency_symbol'] = str_in($_POST, 'currency_symbol', 8);
    $next['default_phone_country_code'] = str_in($_POST, 'default_phone_country_code', 8);
    $next['slot_interval_minutes'] = max(5, min(240, (int)($_POST['slot_interval_minutes'] ?? 30)));
    $next['admin_rows_per_page'] = max(5, min(200, (int)($_POST['admin_rows_per_page'] ?? 25)));
    unset($next['bookings_per_page']);
    $next['time_format'] = ($_POST['time_format'] ?? '24h') === '12h' ? '12h' : '24h';
    $next['mail_driver'] = ($_POST['mail_driver'] ?? 'mail') === 'smtp' ? 'smtp' : 'mail';
    $next['smtp_host'] = str_in($_POST, 'smtp_host', 190);
    $next['smtp_port'] = max(1, min(65535, (int)($_POST['smtp_port'] ?? 587)));
    $next['smtp_username'] = str_in($_POST, 'smtp_username', 190);
    $next['smtp_password'] = str_in($_POST, 'smtp_password', 255);
    $next['smtp_encryption'] = in_array(($_POST['smtp_encryption'] ?? 'tls'), ['', 'tls', 'ssl'], true) ? $_POST['smtp_encryption'] : 'tls';
    $next['from_email'] = strtolower(str_in($_POST, 'from_email', 190));
    $next['from_name'] = str_in($_POST, 'from_name', 120);

    if ($next['business_name'] === '') $errors[] = 'Business name is required.';
    if (!in_array($next['business_timezone'], timezone_identifiers_list(), true)) $errors[] = 'Business timezone is invalid.';
    if ($next['app_url'] !== '' && !filter_var($next['app_url'], FILTER_VALIDATE_URL)) $errors[] = 'App URL must be a valid URL.';
    if ($next['business_logo_url'] !== '' && !filter_var($next['business_logo_url'], FILTER_VALIDATE_URL)) $errors[] = 'Logo URL must be a valid URL.';
    if ($next['currency_symbol'] === '') $errors[] = 'Currency symbol is required.';
    if (!preg_match('/^\+\d{1,4}$/', $next['default_phone_country_code'])) $errors[] = 'Default phone country code must look like +1.';
    if ($next['from_email'] === '' || !is_valid_email($next['from_email'])) $errors[] = 'From email is invalid.';
    if ($next['mail_driver'] === 'smtp' && $next['smtp_host'] === '') $errors[] = 'SMTP host is required when SMTP mail is selected.';

    if (!$errors) {
        $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        if (@file_put_contents($config_path, $json, LOCK_EX) === false) {
            $errors[] = 'Could not write config.json. Check file permissions.';
        } else {
            flash('ok', 'Settings saved.');
            redirect($next['show_settings_page'] ? '/admin/settings.php' : '/admin/index.php');
        }
    }

    $cfg = $next + $cfg;
}

$timezones = timezone_identifiers_list();
$current_tz = settings_string($cfg, 'business_timezone', 'UTC');
$settings_primary_color = preg_match('/^#[0-9a-f]{6}$/i', settings_string($cfg, 'primary_color')) ? settings_string($cfg, 'primary_color') : primary_color();
$settings_accent_color = preg_match('/^#[0-9a-f]{6}$/i', settings_string($cfg, 'accent_color')) ? settings_string($cfg, 'accent_color') : accent_color();
$settings_color_palette = ['#be123c', '#f05c4f', '#f97316', '#d97706', '#65a30d', '#059669', '#0284c7', '#2563eb', '#7c3aed', '#8b5cf6', '#374151', '#000000'];

admin_header();
?>
<?= flash_render() ?>
<?php if ($errors): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
  <?php foreach ($errors as $er): ?>window.toast && window.toast(<?= json_encode($er) ?>, { type: 'error', timeout: 7000 });
  <?php endforeach; ?>
});
</script>
<?php endif; ?>

<div class="flex items-center mb-4">
  <h1 class="text-2xl font-semibold mr-auto">Settings</h1>
</div>

<form method="post" class="settings-grid grid xl:grid-cols-2 gap-4 max-w-none">
  <?= csrf_field() ?>

  <section class="bg-white border border-neutral-200 rounded-xl p-5">
    <h2 class="font-semibold mb-4">Business</h2>
    <div class="grid md:grid-cols-2 gap-4 text-sm">
      <label>Business name
        <input name="business_name" required value="<?= e(settings_string($cfg, 'business_name', 'My Salon')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <label>Timezone
        <select name="business_timezone" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
          <?php foreach ($timezones as $tz): ?>
            <option value="<?= e($tz) ?>" <?= $tz === $current_tz ? 'selected' : '' ?>><?= e($tz) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>App URL
        <input name="app_url" type="url" value="<?= e(settings_string($cfg, 'app_url')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <label>Currency symbol
        <input name="currency_symbol" required value="<?= e(settings_string($cfg, 'currency_symbol', '$')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <label>Default phone country code
        <input name="default_phone_country_code" required value="<?= e(settings_string($cfg, 'default_phone_country_code', '+1')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <label>Time format
        <select name="time_format" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
          <option value="24h" <?= settings_string($cfg, 'time_format', '24h') === '24h' ? 'selected' : '' ?>>24-hour</option>
          <option value="12h" <?= settings_string($cfg, 'time_format', '24h') === '12h' ? 'selected' : '' ?>>12-hour</option>
        </select>
      </label>
    </div>
  </section>

  <section class="bg-white border border-neutral-200 rounded-xl p-5">
    <h2 class="font-semibold mb-4">Appearance</h2>
    <div class="grid md:grid-cols-2 gap-4 text-sm">
      <?php render_settings_color_picker('primary_color', 'Primary color', $settings_primary_color, $settings_color_palette); ?>
      <?php render_settings_color_picker('accent_color', 'Accent color', $settings_accent_color, $settings_color_palette); ?>
      <label>Logo URL
        <input name="business_logo_url" type="url" value="<?= e(settings_string($cfg, 'business_logo_url')) ?>" placeholder="https://example.com/logo.png" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <label>Logo path
        <input name="logo_path" value="<?= e(settings_string($cfg, 'logo_path')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <label>Logo mode
        <select name="logo_mode" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
          <option value="logo_and_name" <?= settings_string($cfg, 'logo_mode', 'logo_and_name') === 'logo_and_name' ? 'selected' : '' ?>>Logo and name</option>
          <option value="logo_only" <?= settings_string($cfg, 'logo_mode', 'logo_and_name') === 'logo_only' ? 'selected' : '' ?>>Logo only</option>
        </select>
      </label>
      <label class="flex items-center gap-2 mt-7">
        <input type="checkbox" name="dark_mode" <?= settings_bool($cfg, 'dark_mode') ? 'checked' : '' ?>>
        <span>Dark mode</span>
      </label>
    </div>
  </section>

  <section class="bg-white border border-neutral-200 rounded-xl p-5">
    <h2 class="font-semibold mb-4">Booking</h2>
    <div class="grid md:grid-cols-2 gap-4 text-sm">
      <label>Slot interval minutes
        <input name="slot_interval_minutes" type="number" min="5" max="240" step="5" value="<?= e((string)($cfg['slot_interval_minutes'] ?? 30)) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <label>Rows per page
        <input name="admin_rows_per_page" type="number" min="5" max="200" step="5" value="<?= e((string)($cfg['admin_rows_per_page'] ?? $cfg['bookings_per_page'] ?? 25)) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
    </div>
  </section>

  <section class="bg-white border border-neutral-200 rounded-xl p-5">
    <h2 class="font-semibold mb-4">Feature Visibility</h2>
    <div class="grid md:grid-cols-2 gap-3 text-sm">
      <label class="inline-flex items-center gap-2">
        <input type="checkbox" name="show_import_export" <?= settings_bool($cfg, 'show_import_export', true) ? 'checked' : '' ?>>
        <span>Show Import / export page</span>
      </label>
      <label class="inline-flex items-center gap-2">
        <input type="checkbox" name="show_settings_page" <?= settings_bool($cfg, 'show_settings_page', true) ? 'checked' : '' ?>>
        <span>Show Settings page</span>
      </label>
    </div>
  </section>

  <section class="bg-white border border-neutral-200 rounded-xl p-5 xl:col-span-2">
    <h2 class="font-semibold mb-4">Mail</h2>
    <div class="grid md:grid-cols-2 gap-4 text-sm">
      <label>Mail driver
        <select name="mail_driver" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
          <option value="mail" <?= settings_string($cfg, 'mail_driver', 'mail') === 'mail' ? 'selected' : '' ?>>PHP mail</option>
          <option value="smtp" <?= settings_string($cfg, 'mail_driver', 'mail') === 'smtp' ? 'selected' : '' ?>>SMTP</option>
        </select>
      </label>
      <label>From email
        <input name="from_email" type="email" value="<?= e(settings_string($cfg, 'from_email')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <label>From name
        <input name="from_name" value="<?= e(settings_string($cfg, 'from_name')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <label>SMTP host
        <input name="smtp_host" value="<?= e(settings_string($cfg, 'smtp_host')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <label>SMTP port
        <input name="smtp_port" type="number" min="1" max="65535" value="<?= e((string)($cfg['smtp_port'] ?? 587)) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <label>SMTP encryption
        <select name="smtp_encryption" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
          <?php foreach (['tls' => 'TLS', 'ssl' => 'SSL', '' => 'None'] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= settings_string($cfg, 'smtp_encryption', 'tls') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>SMTP username
        <input name="smtp_username" value="<?= e(settings_string($cfg, 'smtp_username')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
      <label>SMTP password
        <input name="smtp_password" type="password" value="<?= e(settings_string($cfg, 'smtp_password')) ?>" class="mt-1 w-full px-3 py-2 border border-neutral-200 rounded-md">
      </label>
    </div>
  </section>

  <div class="flex justify-end xl:col-span-2">
    <button class="px-5 py-2 rounded-lg text-white text-sm" style="background: <?= e(primary_color()) ?>">Save settings</button>
  </div>
</form>
<script>
(function(){
  const validHex = /^#[0-9a-f]{6}$/i;

  function setPickerValue(picker, color) {
    color = String(color || '').trim().toLowerCase();
    if (!validHex.test(color)) return;

    const hidden = picker.querySelector('[data-color-value]');
    if (hidden) hidden.value = color;

    picker.querySelectorAll('.item-color').forEach(function(btn) {
      const selected = (btn.dataset.color || '').toLowerCase() === color;
      btn.classList.toggle('selected', selected);
      btn.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
  }

  document.querySelectorAll('[data-color-picker]').forEach(function(picker) {
    picker.querySelectorAll('.item-color').forEach(function(btn) {
      btn.addEventListener('click', function() {
        setPickerValue(picker, btn.dataset.color);
        if (navigator.clipboard && btn.dataset.color) {
          navigator.clipboard.writeText(btn.dataset.color).catch(function(){});
        }
      });
    });
  });
})();
</script>
<?php admin_footer(); ?>
