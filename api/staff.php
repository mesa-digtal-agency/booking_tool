<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/availability.php';

require_method('GET');

$service_id = (int)($_GET['service_id'] ?? 0);
if ($service_id <= 0) json_error('service_id is required.', 422);
if (!get_service($service_id)) json_error('Service not found.', 404);

$staff = staff_for_service($service_id);
$out = [];
foreach ($staff as $s) {
    $out[] = [
        'id' => (int)$s['id'],
        'name' => $s['name'],
        'avatar' => $s['avatar'] ? basename($s['avatar']) : null,
    ];
}
json_response(['staff' => $out]);
