<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/availability.php';

require_method('GET');

$token = (string)($_GET['token'] ?? '');
if (!preg_match('/^[0-9a-f-]{36}$/', $token)) {
    json_error('Invalid token.', 400);
}

$b = db_fetch(
    "SELECT b.*, s.name AS service_name, s.duration_minutes, s.price,
            st.name AS staff_name, st.avatar AS staff_avatar
     FROM bookings b
     JOIN services s ON s.id = b.service_id
     JOIN staff st   ON st.id = b.staff_id
     WHERE b.management_token = ? LIMIT 1",
    [$token]
);
if (!$b) json_error('Booking not found.', 404);

// Can reschedule only if booking is confirmed/pending AND is more than 24h away.
$can_reschedule = false;
if (in_array($b['status'], ['confirmed', 'pending'], true)) {
    $dt = DateTime::createFromFormat('Y-m-d H:i', $b['booking_date'] . ' ' . $b['start_time'], business_timezone_obj());
    if ($dt) {
        $secs = $dt->getTimestamp() - business_now()->getTimestamp();
        $can_reschedule = $secs >= 24 * 3600;
    }
}

json_response([
    'booking' => [
        'id' => (int)$b['id'],
        'service_id' => (int)$b['service_id'],
        'service_name' => $b['service_name'],
        'duration_minutes' => (int)$b['duration_minutes'],
        'price' => (float)$b['price'],
        'staff_id' => (int)$b['staff_id'],
        'staff_name' => $b['staff_name'],
        'staff_avatar' => $b['staff_avatar'] ? basename($b['staff_avatar']) : null,
        'customer_name' => $b['customer_name'],
        'customer_email' => $b['customer_email'],
        'customer_phone' => $b['customer_phone'],
        'notes' => $b['notes'],
        'booking_date' => $b['booking_date'],
        'start_time' => $b['start_time'],
        'end_time' => $b['end_time'],
        'status' => $b['status'],
    ],
    'can_reschedule' => $can_reschedule,
    'business_name' => business_name(),
]);
