<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// Double-booking checks for a teacher's schedule. A teacher's time is shared
// by two kinds of thing — student lesson reservations and hold blocks (lunch,
// errands) — so neither manager class can own the check without depending on
// the other; both call in here instead.
//
// Two levels, mirroring the reservation -> occurrence split:
//   weeklySlotConflict()  — the abstract weekly slot on the Semester Schedule
//   occurrenceConflict()  — a materialized lesson / hold block at a datetime
//
// Both compare true intervals ([start, start+duration) overlap), not equal
// start times, so a 60-minute item at 10:00 does block a new one at 10:30.
class ScheduleConflicts {

    private static function pdo(): PDO {
        return pdo();
    }

    /**
     * Is the weekly slot free for this teacher? Returns a user-facing sentence
     * describing the clash, or null when the slot is available. Excludes are
     * the row being moved (pass its id so it doesn't collide with itself).
     */
    public static function weeklySlotConflict(
        int $semesterId,
        int $locationId,
        int $teacherUserId,
        int $dayOfWeek,
        string $startTime,
        int $durationMinutes,
        ?int $excludeReservationId = null,
        ?int $excludeHoldBlockReservationId = null
    ): ?string {
        // Scoped to the (location, teacher) column the grid draws, matching
        // how the schedule is laid out; a teacher at two locations on the same
        // day is a data problem the grid can't express anyway.
        $sql = "SELECT su.first_name, su.last_name
                FROM semester_lesson_reservations r
                JOIN users su ON su.id = r.student_user_id
                WHERE r.semester_id=? AND r.location_id=? AND r.teacher_user_id=? AND r.day_of_week=?
                  AND r.status <> 'deleted'
                  AND r.start_time < ADDTIME(?, SEC_TO_TIME(? * 60))
                  AND ADDTIME(r.start_time, SEC_TO_TIME(r.duration_minutes * 60)) > ?";
        $params = [$semesterId, $locationId, $teacherUserId, $dayOfWeek, $startTime, $durationMinutes, $startTime];
        if ($excludeReservationId !== null) {
            $sql .= ' AND r.id <> ?';
            $params[] = $excludeReservationId;
        }
        $st = self::pdo()->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        if ($row = $st->fetch()) {
            $name = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
            return 'That time overlaps ' . $name . "'s reserved slot for this teacher.";
        }

        $sql = "SELECT title FROM semester_hold_block_reservations
                WHERE semester_id=? AND location_id=? AND teacher_user_id=? AND day_of_week=?
                  AND status = 'active'
                  AND start_time < ADDTIME(?, SEC_TO_TIME(? * 60))
                  AND ADDTIME(start_time, SEC_TO_TIME(duration_minutes * 60)) > ?";
        $params = [$semesterId, $locationId, $teacherUserId, $dayOfWeek, $startTime, $durationMinutes, $startTime];
        if ($excludeHoldBlockReservationId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeHoldBlockReservationId;
        }
        $st = self::pdo()->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        if ($row = $st->fetch()) {
            return 'That time overlaps this teacher\'s hold block (' . (string)$row['title'] . ').';
        }

        return null;
    }

    /**
     * Is this exact datetime free for the teacher? Returns a user-facing
     * sentence describing the clash, or null. $teacherUserId is the EFFECTIVE
     * teacher (the substitute when a lesson has one).
     */
    public static function occurrenceConflict(
        int $teacherUserId,
        string $startDatetime,
        int $durationMinutes,
        ?int $excludeLessonId = null,
        ?int $excludeHoldBlockId = null
    ): ?string {
        $sql = "SELECT su.first_name, su.last_name
                FROM lessons l
                JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
                JOIN users su ON su.id = r.student_user_id
                WHERE COALESCE(l.substitute_teacher_user_id, r.teacher_user_id) = ?
                  AND l.start_datetime < DATE_ADD(?, INTERVAL ? MINUTE)
                  AND DATE_ADD(l.start_datetime, INTERVAL l.duration_minutes MINUTE) > ?";
        $params = [$teacherUserId, $startDatetime, $durationMinutes, $startDatetime];
        if ($excludeLessonId !== null) {
            $sql .= ' AND l.id <> ?';
            $params[] = $excludeLessonId;
        }
        $st = self::pdo()->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        if ($row = $st->fetch()) {
            $name = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
            return 'That time overlaps ' . $name . "'s lesson for this teacher.";
        }

        $sql = "SELECT COALESCE(b.title_override, hr.title) AS effective_title
                FROM semester_hold_blocks b
                JOIN semester_hold_block_reservations hr ON hr.id = b.semester_hold_block_reservation_id
                WHERE hr.teacher_user_id = ?
                  AND b.start_datetime < DATE_ADD(?, INTERVAL ? MINUTE)
                  AND DATE_ADD(b.start_datetime, INTERVAL b.duration_minutes MINUTE) > ?";
        $params = [$teacherUserId, $startDatetime, $durationMinutes, $startDatetime];
        if ($excludeHoldBlockId !== null) {
            $sql .= ' AND b.id <> ?';
            $params[] = $excludeHoldBlockId;
        }
        $st = self::pdo()->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        if ($row = $st->fetch()) {
            return 'That time overlaps this teacher\'s hold block (' . (string)$row['effective_title'] . ').';
        }

        return null;
    }
}
