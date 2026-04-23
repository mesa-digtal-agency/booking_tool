<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/availability.php';

require_method('GET');

$staff_id_raw = $_GET['staff_id'] ?? '';
$service_id   = (int)($_GET['service_id'] ?? 0);
$date         = (string)($_GET['date'] ?? '');

if ($service_id <= 0) json_error('service_id is required.', 422);
if (!is_valid_date($date)) json_error('date must be YYYY-MM-DD.', 422);

if ($staff_id_raw === '' || $staff_id_raw === 'any' || $staff_id_raw === '0') {
    $slots = compute_slots_any($service_id, $date);
    json_response(['slots' => $slots, 'staff_id' => 'any', 'date' => $date]);
}

$staff_id = (int)$staff_id_raw;
if ($staff_id <= 0) json_error('staff_id is invalid.', 422);

$slots = compute_slots_for_staff($staff_id, $service_id, $date);
json_response(['slots' => $slots, 'staff_id' => $staff_id, 'date' => $date]);
