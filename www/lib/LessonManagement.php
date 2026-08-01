<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/ScheduleConflicts.php';

// Lesson occurrences. Lessons are only ever created by
// ReservationManagement::generateLessonsForReservation; this class reads
// them and manages per-occurrence facts: reschedules within a day,
// attendance ("missed" = attended 0), substitutes, and location overrides.
//
// The "effective teacher" of a lesson is the substitute when one is set,
// otherwise the reservation's teacher. The "effective location" is the
// override when set, otherwise the reservation's location.
class LessonManagement {

    private static function pdo(): PDO {
        return pdo();
    }

    /** The SELECT every lesson query shares: lesson + reservation + names. */
    private const LESSON_SELECT = "
        SELECT l.*,
               r.semester_id, r.teacher_user_id, r.location_id, r.student_user_id,
               r.day_of_week, r.status AS reservation_status,
               r.start_time AS reservation_start_time,
               r.duration_minutes AS reservation_duration_minutes,
               COALESCE(l.substitute_teacher_user_id, r.teacher_user_id) AS effective_teacher_user_id,
               COALESCE(l.location_id_override, r.location_id) AS effective_location_id,
               su.first_name AS student_first_name, su.last_name AS student_last_name,
               su.preferred_name AS student_preferred_name,
               tu.first_name AS teacher_first_name, tu.last_name AS teacher_last_name,
               stu.first_name AS substitute_first_name, stu.last_name AS substitute_last_name,
               loc.name AS location_name
        FROM lessons l
        JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
        JOIN users su ON su.id = r.student_user_id
        JOIN users tu ON tu.id = r.teacher_user_id
        LEFT JOIN users stu ON stu.id = l.substitute_teacher_user_id
        JOIN locations loc ON loc.id = COALESCE(l.location_id_override, r.location_id)
    ";

    // ── Queries ────────────────────────────────────────────────────────────

    public static function getLesson(int $lessonId): ?array {
        $st = self::pdo()->prepare(self::LESSON_SELECT . ' WHERE l.id=? LIMIT 1');
        $st->execute([$lessonId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** A teacher's lessons on one date (as effective teacher), time order. */
    public static function lessonsForTeacherOnDate(int $teacherUserId, string $date): array {
        $st = self::pdo()->prepare(
            self::LESSON_SELECT .
            ' WHERE DATE(l.start_datetime) = ?
                AND COALESCE(l.substitute_teacher_user_id, r.teacher_user_id) = ?
              ORDER BY l.start_datetime'
        );
        $st->execute([$date, $teacherUserId]);
        return $st->fetchAll();
    }

    /** The teacher's next date with lessons strictly after $afterDate, or null. */
    public static function nextTeachingDateForTeacher(int $teacherUserId, string $afterDate): ?string {
        $st = self::pdo()->prepare(
            'SELECT DATE(l.start_datetime) AS d
             FROM lessons l
             JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             WHERE DATE(l.start_datetime) > ?
               AND COALESCE(l.substitute_teacher_user_id, r.teacher_user_id) = ?
             ORDER BY l.start_datetime LIMIT 1'
        );
        $st->execute([$afterDate, $teacherUserId]);
        $d = $st->fetchColumn();
        return $d !== false ? (string)$d : null;
    }

    /** The teacher's previous date with lessons strictly before $beforeDate, or null. */
    public static function previousTeachingDateForTeacher(int $teacherUserId, string $beforeDate): ?string {
        $st = self::pdo()->prepare(
            'SELECT DATE(l.start_datetime) AS d
             FROM lessons l
             JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             WHERE DATE(l.start_datetime) < ?
               AND COALESCE(l.substitute_teacher_user_id, r.teacher_user_id) = ?
             ORDER BY l.start_datetime DESC LIMIT 1'
        );
        $st->execute([$beforeDate, $teacherUserId]);
        $d = $st->fetchColumn();
        return $d !== false ? (string)$d : null;
    }

    /** Lessons in a date range (inclusive), optionally one semester — calendars. */
    public static function lessonsBetween(string $fromDate, string $toDate, ?int $semesterId = null): array {
        $sql = self::LESSON_SELECT . ' WHERE DATE(l.start_datetime) BETWEEN ? AND ?';
        $params = [$fromDate, $toDate];
        if ($semesterId !== null) {
            $sql .= ' AND r.semester_id = ?';
            $params[] = $semesterId;
        }
        $st = self::pdo()->prepare($sql . ' ORDER BY l.start_datetime');
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Lessons in a date range for one teacher (as effective teacher). */
    public static function lessonsBetweenForTeacher(int $teacherUserId, string $fromDate, string $toDate): array {
        $st = self::pdo()->prepare(
            self::LESSON_SELECT .
            ' WHERE DATE(l.start_datetime) BETWEEN ? AND ?
                AND COALESCE(l.substitute_teacher_user_id, r.teacher_user_id) = ?
              ORDER BY l.start_datetime'
        );
        $st->execute([$fromDate, $toDate, $teacherUserId]);
        return $st->fetchAll();
    }

    /** Every lesson a teacher teaches across these semesters (as effective teacher). */
    public static function lessonsForTeacherInSemesters(int $teacherUserId, array $semesterIds): array {
        $semesterIds = array_values(array_unique(array_map('intval', $semesterIds)));
        if (!$semesterIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($semesterIds), '?'));
        $st = self::pdo()->prepare(
            self::LESSON_SELECT .
            " WHERE r.semester_id IN ($placeholders)
                AND COALESCE(l.substitute_teacher_user_id, r.teacher_user_id) = ?
              ORDER BY l.start_datetime"
        );
        $st->execute(array_merge($semesterIds, [$teacherUserId]));
        return $st->fetchAll();
    }

    /** Every lesson a set of students has across these semesters. */
    public static function lessonsForStudentsInSemesters(array $studentUserIds, array $semesterIds): array {
        $ids = array_values(array_unique(array_map('intval', $studentUserIds)));
        $semesterIds = array_values(array_unique(array_map('intval', $semesterIds)));
        if (!$ids || !$semesterIds) {
            return [];
        }
        $studentPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $semesterPlaceholders = implode(',', array_fill(0, count($semesterIds), '?'));
        $st = self::pdo()->prepare(
            self::LESSON_SELECT .
            " WHERE r.semester_id IN ($semesterPlaceholders) AND r.student_user_id IN ($studentPlaceholders)
              ORDER BY l.start_datetime"
        );
        $st->execute(array_merge($semesterIds, $ids));
        return $st->fetchAll();
    }

    /** Lessons in a date range for a set of students (parent/student calendars). */
    public static function lessonsBetweenForStudents(array $studentUserIds, string $fromDate, string $toDate): array {
        $ids = array_values(array_map('intval', $studentUserIds));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = self::pdo()->prepare(
            self::LESSON_SELECT .
            " WHERE DATE(l.start_datetime) BETWEEN ? AND ? AND r.student_user_id IN ($placeholders)
              ORDER BY l.start_datetime"
        );
        $st->execute(array_merge([$fromDate, $toDate], $ids));
        return $st->fetchAll();
    }

    /** A student's upcoming lessons from $fromDate on, soonest first. */
    public static function upcomingLessonsForStudent(int $studentUserId, string $fromDate, int $limit = 20): array {
        $limit = max(1, min(100, $limit));
        $st = self::pdo()->prepare(
            self::LESSON_SELECT .
            " WHERE r.student_user_id = ? AND DATE(l.start_datetime) >= ?
              ORDER BY l.start_datetime LIMIT $limit"
        );
        $st->execute([$studentUserId, $fromDate]);
        return $st->fetchAll();
    }

    /** The student ids a lesson involves (the reservation's student). */
    public static function lessonStudentIds(int $lessonId): array {
        $lesson = self::getLesson($lessonId);
        return $lesson ? [(int)$lesson['student_user_id']] : [];
    }

    // ── Mutations ──────────────────────────────────────────────────────────

    /**
     * Move a lesson to another time on the SAME day. Throws if the new time
     * conflicts with another lesson of the same effective teacher that day.
     */
    public static function rescheduleWithinDay(?UserContext $ctx, int $lessonId, string $newStartTime): void {
        self::assertAdmin($ctx);
        $lesson = self::requireLesson($lessonId);
        $newStartTime = self::normalizeTime($newStartTime);
        $date = date('Y-m-d', strtotime((string)$lesson['start_datetime']));
        $newStart = $date . ' ' . $newStartTime;

        // Conflict check against the effective teacher's other lessons AND
        // hold blocks at that moment.
        $conflict = ScheduleConflicts::occurrenceConflict(
            (int)$lesson['effective_teacher_user_id'], $newStart, (int)$lesson['duration_minutes'],
            ['lesson_id' => $lessonId]
        );
        if ($conflict !== null) {
            throw new InvalidArgumentException($conflict);
        }

        self::pdo()->prepare('UPDATE lessons SET start_datetime=? WHERE id=?')
            ->execute([$newStart, $lessonId]);
        self::log($ctx, 'lesson.rescheduled', ['lesson_id' => $lessonId, 'start_datetime' => $newStart]);
    }

    /**
     * Attendance tri-state: true = attended, false = missed, null = unmarked.
     * Allowed for admins and the lesson's effective teacher.
     */
    public static function markAttendance(?UserContext $ctx, int $lessonId, ?bool $attended): void {
        $lesson = self::requireLesson($lessonId);
        self::assertAdminOrEffectiveTeacher($ctx, $lesson);

        $value = $attended === null ? null : ($attended ? 1 : 0);
        self::pdo()->prepare('UPDATE lessons SET attended=? WHERE id=?')->execute([$value, $lessonId]);
        self::log($ctx, 'lesson.attendance_marked', ['lesson_id' => $lessonId, 'attended' => $value]);
    }

    public static function setSubstituteTeacher(?UserContext $ctx, int $lessonId, ?int $teacherUserId): void {
        self::assertAdmin($ctx);
        self::requireLesson($lessonId);
        if ($teacherUserId !== null) {
            $st = self::pdo()->prepare('SELECT 1 FROM teacher_profiles WHERE user_id=?');
            $st->execute([$teacherUserId]);
            if (!$st->fetchColumn()) {
                throw new InvalidArgumentException('The substitute must be a teacher.');
            }
        }
        self::pdo()->prepare('UPDATE lessons SET substitute_teacher_user_id=? WHERE id=?')
            ->execute([$teacherUserId, $lessonId]);
        self::log($ctx, 'lesson.substitute_set', ['lesson_id' => $lessonId, 'substitute_teacher_user_id' => $teacherUserId]);
    }

    public static function setLocationOverride(?UserContext $ctx, int $lessonId, ?int $locationId): void {
        self::assertAdmin($ctx);
        self::requireLesson($lessonId);
        self::pdo()->prepare('UPDATE lessons SET location_id_override=? WHERE id=?')
            ->execute([$locationId, $lessonId]);
        self::log($ctx, 'lesson.location_override_set', ['lesson_id' => $lessonId, 'location_id' => $locationId]);
    }

    // ── Authorization ──────────────────────────────────────────────────────

    /** Admins, the effective teacher, the student, and the student's parents. */
    public static function canUserViewLesson(int $userId, int $lessonId): bool {
        $lesson = self::getLesson($lessonId);
        if (!$lesson) {
            return false;
        }
        $st = self::pdo()->prepare('SELECT is_admin FROM users WHERE id=? AND is_deleted=0');
        $st->execute([$userId]);
        $user = $st->fetch();
        if (!$user) {
            return false;
        }
        if (!empty($user['is_admin'])) {
            return true;
        }
        if (self::isEffectiveTeacher($userId, $lesson)) {
            return true;
        }
        if ((int)$lesson['student_user_id'] === $userId) {
            return true;
        }
        $st = self::pdo()->prepare('SELECT 1 FROM parenthood WHERE parent_user_id=? AND child_user_id=?');
        $st->execute([$userId, (int)$lesson['student_user_id']]);
        return (bool)$st->fetchColumn();
    }

    /** Is $userId the lesson's effective teacher (substitute wins)? */
    public static function isEffectiveTeacher(int $userId, array $lesson): bool {
        $effective = $lesson['effective_teacher_user_id']
            ?? $lesson['substitute_teacher_user_id']
            ?? $lesson['teacher_user_id'] ?? 0;
        return (int)$effective === $userId;
    }

    // ── internals ─────────────────────────────────────────────────────────

    private static function requireLesson(int $lessonId): array {
        $lesson = self::getLesson($lessonId);
        if (!$lesson) {
            throw new InvalidArgumentException('Lesson not found.');
        }
        return $lesson;
    }

    private static function assertAdminOrEffectiveTeacher(?UserContext $ctx, array $lesson): void {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        if ($ctx->admin || self::isEffectiveTeacher($ctx->id, $lesson)) {
            return;
        }
        throw new RuntimeException("Only the lesson's teacher or an admin may do that.");
    }

    private static function normalizeTime(string $time): string {
        $time = trim($time);
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) {
            throw new InvalidArgumentException('Time must look like "9:00" or "14:30".');
        }
        $parts = explode(':', $time);
        $h = (int)$parts[0];
        $m = (int)$parts[1];
        if ($h > 23 || $m > 59) {
            throw new InvalidArgumentException('Time is out of range.');
        }
        return sprintf('%02d:%02d:00', $h, $m);
    }

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
