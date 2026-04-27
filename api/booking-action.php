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

$dt_start = DateTime::createFromFormat('Y-m-d H:i', $b['booking_date'] . ' ' . $b['start_time'], business_timezone_obj());
$secs_to_start = $dt_start ? ($dt_start->getTimestamp() - business_now()->getTimestamp()) : -1;

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

    $dt_new = DateTime::createFromFormat('Y-m-d H:i', $new_date . ' ' . $new_time, business_timezone_obj());
    if (!$dt_new || $dt_new->getTimestamp() <= business_now()->getTimestamp()) {
        json_error('New time is in the past.', 422);
    }
    $end_time = compute_end_time($new_time, (int)$service['duration_minutes']);
    $target_staff_id = null;

    try {
        db_begin_exclusive();
        $lock = db_for_update_clause();

        $locked = db_fetch(
            "SELECT * FROM bookings WHERE id = ? LIMIT 1" . $lock,
            [(int)$b['id']]
        );
        if (!$locked) {
            db_rollback();
            json_error('Booking not found.', 404);
        }
        if (!in_array($locked['status'], ['confirmed', 'pending'], true)) {
            db_rollback();
            json_error('Booking is already ' . $locked['status'] . '.', 409);
        }

        $candidates = [(int)$locked['staff_id']];
        foreach (staff_for_service((int)$locked['service_id']) as $member) {
            $sid = (int)$member['id'];
            if ($sid !== (int)$locked['staff_id']) $candidates[] = $sid;
        }

        foreach ($candidates as $candidate_staff_id) {
            if (!staff_can_take_slot($candidate_staff_id, (int)$locked['service_id'], $new_date, $new_time, $end_time)) {
                continue;
            }

            $conflict = db_scalar(
                "SELECT 1 FROM bookings
                 WHERE staff_id = ? AND booking_date = ? AND status IN ('pending','confirmed')
                   AND id <> ?
                   AND NOT (end_time <= ? OR start_time >= ?)
                 LIMIT 1" . $lock,
                [$candidate_staff_id, $new_date, (int)$locked['id'], $new_time, $end_time]
            );
            if ($conflict) continue;

            if (has_blocked_overlap($candidate_staff_id, $new_date, $new_time, $end_time, $lock)) continue;

            $target_staff_id = $candidate_staff_id;
            break;
        }

        if ($target_staff_id === null) {
            db_rollback();
            json_error('That time is not available.', 409);
        }

        db_exec(
            "UPDATE bookings SET booking_date = ?, start_time = ?, end_time = ?, staff_id = ?, status = 'confirmed'
             WHERE id = ?",
            [$new_date, $new_time, $end_time, $target_staff_id, (int)$locked['id']]
        );
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        error_log('Booking reschedule failed: ' . $e->getMessage());
        json_error('Could not reschedule booking. Please try again.', 500);
    }

    $b = db_fetch(
        "SELECT b.*, s.name AS service_name, s.duration_minutes, s.price,
                st.name AS staff_name
         FROM bookings b
         JOIN services s ON s.id = b.service_id
         JOIN staff st   ON st.id = b.staff_id
        WHERE b.id = ?",
        [(int)$b['id']]
    );
    if (!$b) json_error('Booking not found.', 404);

    send_action_email('reschedule', $b);

    json_response(['ok' => true, 'booking' => public_booking_shape($b)]);
}

if ($action === 'cancel') {
    $updated = db_exec(
        "UPDATE bookings SET status = 'cancelled' WHERE id = ? AND status IN ('confirmed','pending')",
        [(int)$b['id']]
    );
    if ($updated <= 0) json_error('Booking is already changed.', 409);
    $b = db_fetch(
        "SELECT b.*, s.name AS service_name, s.duration_minutes, s.price,
                st.name AS staff_name
         FROM bookings b
         JOIN services s ON s.id = b.service_id
         JOIN staff st   ON st.id = b.staff_id
        WHERE b.id = ?",
        [(int)$b['id']]
    );
    if (!$b) json_error('Booking not found.', 404);
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

function staff_can_take_slot(int $staff_id, int $service_id, string $date, string $start, string $end): bool {
    if (!staff_performs_service($staff_id, $service_id)) return false;

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) return false;

    $wh = working_hours($staff_id, (int)$dt->format('w'));
    if (!$wh || (int)$wh['is_off'] === 1) return false;

    $start_min = time_to_minutes($start);
    $end_min = time_to_minutes($end);
    $wh_start = time_to_minutes($wh['start_time']);
    $wh_end = time_to_minutes($wh['end_time']);
    if ($wh_end === $wh_start && $wh_start === 0) {
        $wh_end = 24 * 60;
    } elseif ($wh_end <= $wh_start) {
        return false;
    }

    return $start_min >= $wh_start && $end_min <= $wh_end;
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
