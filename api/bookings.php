<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/availability.php';

require_method('POST');
csrf_verify();

// Support JSON and form-encoded bodies.
$in = read_json_body();
if (!$in) $in = $_POST;

$service_id = (int)($in['service_id'] ?? 0);
$staff_raw  = $in['staff_id'] ?? 'any';
$date       = (string)($in['date'] ?? '');
$time_raw   = (string)($in['time'] ?? '');
$time       = normalize_time($time_raw) ?? '';
$name       = str_in($in, 'customer_name', 120);
$email      = strtolower(str_in($in, 'customer_email', 190));
$phone      = str_in($in, 'customer_phone', 40);
$notes      = str_in($in, 'notes', 1000);

// Validate
$errs = [];
if ($service_id <= 0)       $errs[] = 'service_id is required.';
if (!is_valid_date($date))  $errs[] = 'date must be YYYY-MM-DD.';
if (!is_valid_time($time))  $errs[] = 'time must be HH:MM.';
if ($name === '')           $errs[] = 'customer_name is required.';
if (!is_valid_email($email))$errs[] = 'customer_email is invalid.';
if (!is_valid_phone($phone))$errs[] = 'customer_phone is invalid.';
if ($errs) json_error(implode(' ', $errs), 422);

$service = get_service($service_id);
if (!$service) json_error('Service not found.', 404);

// Do not allow bookings in the past (business timezone).
$dt_start = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
if (!$dt_start || $dt_start->getTimestamp() <= time()) {
    json_error('Selected time is in the past.', 422);
}

// Basic rate-limit: max 5 bookings/hour per email.
$cutoff = date('Y-m-d H:i:s', time() - 3600);
$recent = (int)db_scalar(
    "SELECT COUNT(*) FROM bookings WHERE customer_email = ? AND created_at >= ?",
    [$email, $cutoff]
);
if ($recent >= 5) {
    json_error('Too many booking attempts. Please try again later.', 429);
}

// Resolve staff.
if ($staff_raw === '' || $staff_raw === 'any' || (int)$staff_raw === 0) {
    $staff_id = first_available_staff($service_id, $date, $time);
    if (!$staff_id) json_error('No professional is available for that time.', 409);
} else {
    $staff_id = (int)$staff_raw;
    if (!is_slot_free($staff_id, $service_id, $date, $time)) {
        json_error('That time is no longer available.', 409);
    }
}

$duration = (int)$service['duration_minutes'];
$end_time = compute_end_time($time, $duration);
$token = uuid_v4();

try {
    db()->beginTransaction();

    // Re-check inside the transaction to close the race window — both
    // existing bookings AND blocked slots must be conflict-free.
    if (has_booking_conflict($staff_id, $date, $time, $end_time)) {
        db()->rollBack();
        json_error('That time is no longer available.', 409);
    }
    if (has_blocked_overlap($staff_id, $date, $time, $end_time)) {
        db()->rollBack();
        json_error('That time is blocked off and cannot be booked.', 409);
    }

    $id = db_insert(
        "INSERT INTO bookings
         (customer_name, customer_email, customer_phone, notes,
          service_id, staff_id, booking_date, start_time, end_time,
          status, management_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?)",
        [$name, $email, $phone, $notes, $service_id, $staff_id, $date, $time, $end_time, $token]
    );
    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('Booking insert failed: ' . $e->getMessage());
    json_error('Could not save booking. Please try again.', 500);
}

$booking = db_fetch(
    "SELECT b.*, s.name AS service_name, s.duration_minutes, s.price,
            st.name AS staff_name, st.avatar AS staff_avatar
     FROM bookings b
     JOIN services s ON s.id = b.service_id
     JOIN staff st   ON st.id = b.staff_id
     WHERE b.id = ?",
    [$id]
);

// Email the confirmation with management link.
$management_url = app_url('/manage-booking.php?token=' . $token);
$subject = 'Your booking at ' . business_name();
ob_start();
$tmpl = [
    'booking' => $booking,
    'management_url' => $management_url,
];
extract($tmpl);
include APP_ROOT . '/includes/email-templates/confirmation.php';
$html = ob_get_clean();
@send_mail($email, $subject, $html);

json_response([
    'booking' => format_booking_for_api($booking),
    'management_url' => $management_url,
], 201);

function format_booking_for_api(array $b): array {
    return [
        'id' => (int)$b['id'],
        'service_id' => (int)$b['service_id'],
        'service_name' => $b['service_name'],
        'staff_id' => (int)$b['staff_id'],
        'staff_name' => $b['staff_name'],
        'staff_avatar' => $b['staff_avatar'],
        'customer_name' => $b['customer_name'],
        'customer_email' => $b['customer_email'],
        'customer_phone' => $b['customer_phone'],
        'notes' => $b['notes'],
        'booking_date' => $b['booking_date'],
        'start_time' => $b['start_time'],
        'end_time' => $b['end_time'],
        'duration_minutes' => (int)$b['duration_minutes'],
        'price' => (float)$b['price'],
        'status' => $b['status'],
        'management_token' => $b['management_token'],
    ];
}
