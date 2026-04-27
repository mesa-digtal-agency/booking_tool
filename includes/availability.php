<?php
/**
 * Availability / slot engine.
 *
 * compute_slots_for_staff($staff_id, $service_id, $date) → string[] of "HH:MM"
 * compute_slots_any($service_id, $date)                   → string[] union across staff
 * first_available_staff($service_id, $date, $time)        → int|null
 * is_slot_free($staff_id, $service_id, $date, $time)      → bool
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

function get_service(int $service_id): ?array {
    return db_fetch("SELECT * FROM services WHERE id = ? AND is_active = 1", [$service_id]);
}

function get_staff(int $staff_id): ?array {
    return db_fetch("SELECT id, name, email, role, avatar, is_active FROM staff WHERE id = ?", [$staff_id]);
}

/** Staff who perform a service (active only). */
function staff_for_service(int $service_id): array {
    return db_all(
        "SELECT s.id, s.name, s.avatar
         FROM staff s
         JOIN staff_services ss ON ss.staff_id = s.id
         WHERE ss.service_id = ? AND s.is_active = 1
         ORDER BY s.name ASC",
        [$service_id]
    );
}

function staff_performs_service(int $staff_id, int $service_id): bool {
    return (bool)db_scalar(
        "SELECT 1
         FROM staff_services ss
         JOIN staff s ON s.id = ss.staff_id
         WHERE ss.staff_id = ? AND ss.service_id = ? AND s.is_active = 1
         LIMIT 1",
        [$staff_id, $service_id]
    );
}

/** Working hours for staff on a given day_of_week (0=Sun..6=Sat). */
function working_hours(int $staff_id, int $dow): ?array {
    return db_fetch(
        "SELECT start_time, end_time, is_off FROM working_hours
         WHERE staff_id = ? AND day_of_week = ? LIMIT 1",
        [$staff_id, $dow]
    );
}

/** Busy intervals [start_min, end_min] for a staff member on a date. */
function busy_intervals(int $staff_id, string $date): array {
    $out = [];
    $rows = db_all(
        "SELECT start_time, end_time FROM bookings
         WHERE staff_id = ? AND booking_date = ? AND status IN ('pending','confirmed')",
        [$staff_id, $date]
    );
    foreach ($rows as $r) {
        $s = time_to_minutes($r['start_time']);
        $e = time_to_minutes($r['end_time']);
        if ($e > $s) $out[] = [$s, $e]; // skip degenerate ranges
    }
    $blocked = blocked_slot_candidates($staff_id, $date);
    foreach ($blocked as $r) {
        if (!blocked_slot_applies_on_date($r, $date)) continue;
        $s = time_to_minutes($r['start_time']);
        $e = time_to_minutes($r['end_time']);
        // Defensive sanity: ignore rows where the end isn't strictly after the
        // start (would otherwise either be a no-op or behave unpredictably).
        if ($e > $s) $out[] = [$s, $e];
    }
    return $out;
}

/** Compute available HH:MM slot starts for given staff/service/date. */
function compute_slots_for_staff(int $staff_id, int $service_id, string $date): array {
    $svc = get_service($service_id);
    if (!$svc) return [];
    if (!staff_performs_service($staff_id, $service_id)) return [];
    $duration = (int)$svc['duration_minutes'];
    if ($duration <= 0) return [];

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) return [];
    $dow = (int)$dt->format('w'); // 0..6

    $wh = working_hours($staff_id, $dow);
    if (!$wh || (int)$wh['is_off'] === 1) return [];

    $start_min = time_to_minutes($wh['start_time']);
    $end_min   = time_to_minutes($wh['end_time']);
    if ($end_min === $start_min && $start_min === 0) {
        $end_min = 24 * 60;
    } elseif ($end_min <= $start_min) {
        return [];
    }

    $step = (int)($GLOBALS['CONFIG']['slot_interval_minutes'] ?: 30);
    if ($step <= 0) $step = 30;

    // Don't generate past starts for today (in business timezone).
    $nowMinutes = PHP_INT_MIN;
    $today = business_today();
    if ($date === $today) {
        $now = business_now();
        $nowMinutes = (int)$now->format('G') * 60 + (int)$now->format('i');
    }

    $busy = busy_intervals($staff_id, $date);

    $slots = [];
    for ($s = $start_min; $s + $duration <= $end_min; $s += $step) {
        if ($s <= $nowMinutes) continue;
        $e = $s + $duration;
        $conflict = false;
        foreach ($busy as [$b_s, $b_e]) {
            if ($s < $b_e && $e > $b_s) { $conflict = true; break; }
        }
        if (!$conflict) $slots[] = minutes_to_time($s);
    }
    return $slots;
}

/** Union of slots across all staff for a service — excludes time strings already unavailable for all staff. */
function compute_slots_any(int $service_id, string $date): array {
    $staff = staff_for_service($service_id);
    $union = [];
    foreach ($staff as $m) {
        foreach (compute_slots_for_staff((int)$m['id'], $service_id, $date) as $t) {
            $union[$t] = true;
        }
    }
    $times = array_keys($union);
    sort($times);
    return $times;
}

/** Pick a staff member that can serve service at given date+time. */
function first_available_staff(int $service_id, string $date, string $time): ?int {
    $candidates = staff_for_service($service_id);
    shuffle($candidates);
    foreach ($candidates as $m) {
        $slots = compute_slots_for_staff((int)$m['id'], $service_id, $date);
        if (in_array($time, $slots, true)) return (int)$m['id'];
    }
    return null;
}

/** Quick single-slot availability check for a specific staff member. */
function is_slot_free(int $staff_id, int $service_id, string $date, string $time): bool {
    $slots = compute_slots_for_staff($staff_id, $service_id, $date);
    return in_array($time, $slots, true);
}

/** Compute end_time HH:MM for a start HH:MM + service duration. */
function compute_end_time(string $start, int $duration_minutes): string {
    return minutes_to_time(time_to_minutes($start) + $duration_minutes);
}

/** True if [start,end) overlaps any blocked slot for the staff on that date. */
function has_blocked_overlap(int $staff_id, string $date, string $start, string $end, string $lock_clause = ''): bool {
    $rows = db_all(
        "SELECT * FROM blocked_slots
         WHERE staff_id = ?
           AND (date = ?
                OR (COALESCE(repeat_mode, '') = 'working_day'
                    AND date <= ?
                    AND (repeat_until IS NULL OR repeat_until >= ?)))
         LIMIT 200" . $lock_clause,
        [$staff_id, $date, $date, $date]
    );
    foreach ($rows as $row) {
        if (!blocked_slot_applies_on_date($row, $date)) continue;
        if (!($row['end_time'] <= $start || $row['start_time'] >= $end)) return true;
    }
    return false;
}

/** True if [start,end) overlaps another active booking for the staff on that date. */
function has_booking_conflict(int $staff_id, string $date, string $start, string $end, int $exclude_booking_id = 0): bool {
    $row = db_scalar(
        "SELECT 1 FROM bookings
         WHERE staff_id = ? AND booking_date = ? AND status IN ('pending','confirmed')
           AND id <> ?
           AND NOT (end_time <= ? OR start_time >= ?)
         LIMIT 1",
        [$staff_id, $date, $exclude_booking_id, $start, $end]
    );
    return (bool)$row;
}

/** Candidate blocked-slot rows for a single date, including recurring ranges. */
function blocked_slot_candidates(int $staff_id, string $date): array {
    return db_all(
        "SELECT * FROM blocked_slots
         WHERE staff_id = ?
           AND (date = ?
                OR (COALESCE(repeat_mode, '') = 'working_day'
                    AND date <= ?
                    AND (repeat_until IS NULL OR repeat_until >= ?)))",
        [$staff_id, $date, $date, $date]
    );
}

/** True if a blocked-slot row should apply on a given date. */
function blocked_slot_applies_on_date(array $row, string $date): bool {
    if (($row['repeat_mode'] ?? '') !== 'working_day') {
        return ($row['date'] ?? '') === $date;
    }
    if ($date < (string)$row['date']) return false;
    if (!empty($row['repeat_until']) && $date > (string)$row['repeat_until']) return false;

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) return false;
    $wh = working_hours((int)$row['staff_id'], (int)$dt->format('w'));
    return $wh && (int)$wh['is_off'] !== 1;
}
