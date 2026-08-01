<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// Double-booking checks for a teacher's schedule.
//
// A teacher's time is shared by two kinds of thing — student lesson
// reservations and hold blocks (lunch, errands) — so neither manager class
// can own the check without depending on the other; both call in here.
//
// The rule: a teacher may never hold two commitments at the same moment.
// That is enforced at both levels, and BOTH are teacher-wide across
// locations, because a teacher cannot be in two places at once:
//
//   weeklySlotConflict()     the standing weekly slot (Semester Schedule)
//   occurrenceConflict()     one materialized moment (the real calendar)
//
// The weekly check alone is not enough: a single week can be moved by hand
// (LessonManagement::rescheduleWithinDay), so a slot that looks free at the
// weekly level can still be taken on a particular date. Callers creating or
// moving a weekly reservation therefore also run occurrenceConflict() over
// every future date the reservation would occupy — see
// futureOccurrenceConflict().
//
// All comparisons are true interval overlap ([start, start+duration)), not
// equal start times, so a 60-minute item at 10:00 does block a new one
// at 10:30.
class ScheduleConflicts {

    private static function pdo(): PDO {
        return pdo();
    }

    /**
     * Is the standing weekly slot free for this teacher anywhere in the
     * semester? Returns a user-facing sentence describing the clash, or null.
     *
     * $exclude may carry 'reservation_id' and/or 'hold_block_reservation_id'
     * — the row being moved, so it does not collide with itself.
     */
    public static function weeklySlotConflict(
        int $semesterId,
        int $teacherUserId,
        int $dayOfWeek,
        string $startTime,
        int $durationMinutes,
        array $exclude = []
    ): ?string {
        $sql = "SELECT su.first_name, su.last_name, r.start_time, r.duration_minutes, l.name AS location_name
                FROM semester_lesson_reservations r
                JOIN users su ON su.id = r.student_user_id
                JOIN locations l ON l.id = r.location_id
                WHERE r.semester_id=? AND r.teacher_user_id=? AND r.day_of_week=?
                  AND r.status <> 'deleted'
                  AND r.start_time < ADDTIME(?, SEC_TO_TIME(? * 60))
                  AND ADDTIME(r.start_time, SEC_TO_TIME(r.duration_minutes * 60)) > ?";
        $params = [$semesterId, $teacherUserId, $dayOfWeek, $startTime, $durationMinutes, $startTime];
        if (isset($exclude['reservation_id'])) {
            $sql .= ' AND r.id <> ?';
            $params[] = (int)$exclude['reservation_id'];
        }
        $st = self::pdo()->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        if ($row = $st->fetch()) {
            $name = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
            return 'This teacher already has ' . $name . "'s weekly slot at "
                . self::timeRange((string)$row['start_time'], (int)$row['duration_minutes'])
                . ' (' . (string)$row['location_name'] . ').';
        }

        $sql = "SELECT hr.title, hr.start_time, hr.duration_minutes, l.name AS location_name
                FROM semester_hold_block_reservations hr
                JOIN locations l ON l.id = hr.location_id
                WHERE hr.semester_id=? AND hr.teacher_user_id=? AND hr.day_of_week=?
                  AND hr.status = 'active'
                  AND hr.start_time < ADDTIME(?, SEC_TO_TIME(? * 60))
                  AND ADDTIME(hr.start_time, SEC_TO_TIME(hr.duration_minutes * 60)) > ?";
        $params = [$semesterId, $teacherUserId, $dayOfWeek, $startTime, $durationMinutes, $startTime];
        if (isset($exclude['hold_block_reservation_id'])) {
            $sql .= ' AND hr.id <> ?';
            $params[] = (int)$exclude['hold_block_reservation_id'];
        }
        $st = self::pdo()->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        if ($row = $st->fetch()) {
            return 'This teacher already has a weekly hold block ("' . (string)$row['title'] . '") at '
                . self::timeRange((string)$row['start_time'], (int)$row['duration_minutes'])
                . ' (' . (string)$row['location_name'] . ').';
        }

        return null;
    }

    /**
     * Is this exact moment free for the teacher? Returns a user-facing
     * sentence describing the clash, or null. $teacherUserId is the EFFECTIVE
     * teacher (the substitute when a lesson has one).
     *
     * $exclude may carry 'lesson_id', 'hold_block_id', 'reservation_id' and
     * 'hold_block_reservation_id'. The *_id forms skip a single occurrence
     * (the one being moved); the *reservation_id forms skip every occurrence
     * of that reservation, which is what a whole-reservation move needs.
     */
    public static function occurrenceConflict(
        int $teacherUserId,
        string $startDatetime,
        int $durationMinutes,
        array $exclude = []
    ): ?string {
        $sql = "SELECT su.first_name, su.last_name, l.start_datetime, l.duration_minutes
                FROM lessons l
                JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
                JOIN users su ON su.id = r.student_user_id
                WHERE COALESCE(l.substitute_teacher_user_id, r.teacher_user_id) = ?
                  AND l.start_datetime < DATE_ADD(?, INTERVAL ? MINUTE)
                  AND DATE_ADD(l.start_datetime, INTERVAL l.duration_minutes MINUTE) > ?";
        $params = [$teacherUserId, $startDatetime, $durationMinutes, $startDatetime];
        if (isset($exclude['lesson_id'])) {
            $sql .= ' AND l.id <> ?';
            $params[] = (int)$exclude['lesson_id'];
        }
        if (isset($exclude['reservation_id'])) {
            $sql .= ' AND l.semester_lesson_reservation_id <> ?';
            $params[] = (int)$exclude['reservation_id'];
        }
        $st = self::pdo()->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        if ($row = $st->fetch()) {
            $name = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
            return $name . "'s lesson on " . self::moment((string)$row['start_datetime'], (int)$row['duration_minutes'])
                . ' is already booked for this teacher.';
        }

        $sql = "SELECT COALESCE(b.title_override, hr.title) AS effective_title,
                       b.start_datetime, b.duration_minutes
                FROM semester_hold_blocks b
                JOIN semester_hold_block_reservations hr ON hr.id = b.semester_hold_block_reservation_id
                WHERE hr.teacher_user_id = ?
                  AND b.start_datetime < DATE_ADD(?, INTERVAL ? MINUTE)
                  AND DATE_ADD(b.start_datetime, INTERVAL b.duration_minutes MINUTE) > ?";
        $params = [$teacherUserId, $startDatetime, $durationMinutes, $startDatetime];
        if (isset($exclude['hold_block_id'])) {
            $sql .= ' AND b.id <> ?';
            $params[] = (int)$exclude['hold_block_id'];
        }
        if (isset($exclude['hold_block_reservation_id'])) {
            $sql .= ' AND b.semester_hold_block_reservation_id <> ?';
            $params[] = (int)$exclude['hold_block_reservation_id'];
        }
        $st = self::pdo()->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        if ($row = $st->fetch()) {
            return 'This teacher\'s hold block ("' . (string)$row['effective_title'] . '") on '
                . self::moment((string)$row['start_datetime'], (int)$row['duration_minutes'])
                . ' is already booked.';
        }

        return null;
    }

    /**
     * Would any of these moments clash for the teacher? $dates are Y-m-d
     * strings; each is checked at $startTime for $durationMinutes. Only dates
     * in the future are considered — the past is history and is never moved.
     * Returns the first clash as a user-facing sentence, or null.
     */
    public static function futureOccurrenceConflict(
        int $teacherUserId,
        array $dates,
        string $startTime,
        int $durationMinutes,
        array $exclude = []
    ): ?string {
        $now = date('Y-m-d H:i:s');
        foreach ($dates as $date) {
            $startDatetime = (string)$date . ' ' . $startTime;
            if ($startDatetime <= $now) {
                continue;
            }
            $conflict = self::occurrenceConflict($teacherUserId, $startDatetime, $durationMinutes, $exclude);
            if ($conflict !== null) {
                return $conflict;
            }
        }
        return null;
    }

    // ── message helpers ───────────────────────────────────────────────────

    /** "12:00 pm–1:30 pm" */
    private static function timeRange(string $startTime, int $durationMinutes): string {
        $start = strtotime('1970-01-01 ' . $startTime);
        return date('g:i a', $start) . '–' . date('g:i a', $start + $durationMinutes * 60);
    }

    /** "Sat Sep 12, 12:00 pm–1:30 pm" */
    private static function moment(string $startDatetime, int $durationMinutes): string {
        $start = strtotime($startDatetime);
        return date('D M j', $start) . ', '
            . date('g:i a', $start) . '–' . date('g:i a', $start + $durationMinutes * 60);
    }
}
