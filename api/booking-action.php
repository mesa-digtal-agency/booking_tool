<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/availability.php';

require_method('POST');
csrf_verify();

$in = read_json_body();
if (!$in) $in = $_POST;

$token  = (string)($in['token'] ?? '');
$action = (string)($in['action'] ?? '');

if (!preg_match('/^[0-9a-f-]{36}$/', $token)) json_error('Invalid token.', 400);
if (!in_array($action, ['cancel', 'reschedule'], true)) json_error('Unknown action.', 400);

$b = db_fetch("SELECT * FROM bookings WHERE management_token = ? LIMIT 1", [$token]);
if (!$b) json_error('Booking not found.', 404);

if (!in_array($b['status'], ['confirmed', 'pending'], true)) {
    json_error('Booking is already ' . $b['status'] . '.', 409);
}

$dt_start = DateTime::createFromFormat('Y-m-d H:i', $b['booking_date'] . ' ' . $b['start_time']);
$secs_to_start = $dt_start ? ($dt_start->getTimestamp() - time()) : -1;

if ($action === 'reschedule') {
    if ($secs_to_start < 24 * 3600) {
        json_error('Rescheduling is only allowed more than 24 hours before the appointment.', 403);
    }
    $new_date = (string)($in['new_date'] ?? '');
    $new_time = normalize_time((string)($in['new_time'] ?? '')) ?? '';
    if (!is_valid_date($new_date)) json_error('new_date must be YYYY-MM-DD.', 422);
    if (!is_valid_time($new_time)) json_error('new_time must be HH:MM.', 422);

    $service = get_service((int)$b['service_id']);
    if (!$service) json_error('Service no longer available.', 409);

    // Make sure the new slot is free for the same staff.
    $dt_new = DateTime::createFromFormat('Y-m-d H:i', $new_date . ' ' . $new_time);
    if (!$dt_new || $dt_new->getTimestamp() <= time()) {
        json_error('New time is in the past.', 422);
    }

    if (!is_slot_free((int)$b['staff_id'], (int)$b['service_id'], $new_date, $new_time)) {
        // try finding any staff
        $alt = first_available_staff((int)$b['service_id'], $new_date, $new_time);
        if (!$alt) json_error('That time is not available.', 409);
        $b['staff_id'] = $alt;
    }

    $end_time = compute_end_time($new_time, (int)$service['duration_minutes']);

    db_exec(
        "UPDATE bookings SET booking_date = ?, start_time = ?, end_time = ?, staff_id = ?, status = 'confirmed'
         WHERE id = ?",
        [$new_date, $new_time, $end_time, (int)$b['staff_id'], (int)$b['id']]
    );

    $b = db_fetch(
        "SELECT b.*, s.name AS service_name, s.duration_minutes, s.price,
                st.name AS staff_name
         FROM bookings b
         JOIN services s ON s.id = b.service_id
         JOIN staff st   ON st.id = b.staff_id
         WHERE b.id = ?",
        [(int)$b['id']]
    );

    send_action_email('reschedule', $b);

    json_response(['ok' => true, 'booking' => public_booking_shape($b)]);
}

if ($action === 'cancel') {
    db_exec("UPDATE bookings SET status = 'cancelled' WHERE id = ?", [(int)$b['id']]);
    $b = db_fetch(
        "SELECT b.*, s.name AS service_name, s.duration_minutes, s.price,
                st.name AS staff_name
         FROM bookings b
         JOIN services s ON s.id = b.service_id
         JOIN staff st   ON st.id = b.staff_id
         WHERE b.id = ?",
        [(int)$b['id']]
    );
    send_action_email('cancel', $b);
    json_response(['ok' => true, 'booking' => public_booking_shape($b)]);
}

function send_action_email(string $action, array $b): void {
    $management_url = app_url('/manage-booking.php?token=' . $b['management_token']);
    $subject = $action === 'cancel'
        ? 'Your booking has been cancelled'
        : 'Your booking has been rescheduled';
    $tmpl_file = $action === 'cancel' ? 'cancellation.php' : 'reschedule.php';

    ob_start();
    $booking = $b;
    include APP_ROOT . '/includes/email-templates/' . $tmpl_file;
    $html = ob_get_clean();
    @send_mail($b['customer_email'], $subject, $html);
}

function public_booking_shape(array $b): array {
    return [
        'id' => (int)$b['id'],
        'service_name' => $b['service_name'],
        'staff_name' => $b['staff_name'],
        'booking_date' => $b['booking_date'],
        'start_time' => $b['start_time'],
        'end_time' => $b['end_time'],
        'duration_minutes' => (int)$b['duration_minutes'],
        'price' => (float)$b['price'],
        'status' => $b['status'],
    ];
}
