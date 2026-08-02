<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/ScheduleConflicts.php';

// Lesson occurrences. Lessons are only ever created by
// ReservationManagement::generateLessonsForReservation; this class reads
// them and manages per-occurrence facts: moving one week, attendance
// ("missed" = attended 0), substitutes, location overrides, and cancellation.
//
// The "effective teacher" of a lesson is the substitute when one is set,
// otherwise the reservation's teacher. The "effective location" is the
// override when set, otherwise the reservation's location.
class LessonManagement {

    private static function pdo(): PDO {
        return pdo();
    }

    /**
     * The SELECT every lesson query shares: lesson + reservation + names.
     *
     * A one-off lesson has no reservation, so the semester, teacher, student
     * and location it would have inherited are read off the lesson itself.
     * The reservation still wins where there is one, which keeps every
     * existing row behaving exactly as before. `is_ad_hoc` lets callers tell
     * the two apart without knowing how it is stored.
     */
    private const LESSON_SELECT = "
        SELECT l.*,
               COALESCE(r.semester_id, l.semester_id) AS semester_id,
               COALESCE(r.teacher_user_id, l.teacher_user_id) AS teacher_user_id,
               COALESCE(r.location_id, l.location_id) AS location_id,
               COALESCE(r.student_user_id, l.student_user_id) AS student_user_id,
               r.day_of_week, r.status AS reservation_status,
               r.start_time AS reservation_start_time,
               r.duration_minutes AS reservation_duration_minutes,
               (l.semester_lesson_reservation_id IS NULL) AS is_ad_hoc,
               COALESCE(l.substitute_teacher_user_id, r.teacher_user_id, l.teacher_user_id) AS effective_teacher_user_id,
               COALESCE(l.location_id_override, r.location_id, l.location_id) AS effective_location_id,
               su.first_name AS student_first_name, su.last_name AS student_last_name,
               su.preferred_name AS student_preferred_name,
               tu.first_name AS teacher_first_name, tu.last_name AS teacher_last_name,
               stu.first_name AS substitute_first_name, stu.last_name AS substitute_last_name,
               loc.name AS location_name
        FROM lessons l
        LEFT JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
        JOIN users su ON su.id = COALESCE(r.student_user_id, l.student_user_id)
        JOIN users tu ON tu.id = COALESCE(r.teacher_user_id, l.teacher_user_id)
        LEFT JOIN users stu ON stu.id = l.substitute_teacher_user_id
        JOIN locations loc ON loc.id = COALESCE(l.location_id_override, r.location_id, l.location_id)
    ";

    // Filters cannot use the SELECT's aliases (MySQL resolves WHERE before
    // them), so the same COALESCEs are named here and reused verbatim.
    private const F_TEACHER = 'COALESCE(l.substitute_teacher_user_id, r.teacher_user_id, l.teacher_user_id)';
    private const F_SEMESTER = 'COALESCE(r.semester_id, l.semester_id)';
    private const F_STUDENT = 'COALESCE(r.student_user_id, l.student_user_id)';

    // ── Queries ────────────────────────────────────────────────────────────

    public static function getLesson(int $lessonId): ?array {
        $st = self::pdo()->prepare(self::LESSON_SELECT . ' WHERE l.id=? LIMIT 1');
        $st->execute([$lessonId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * A teacher's lessons on one date (as effective teacher), time order.
     *
     * Cancelled lessons are included by default because the teacher still
     * wants to see that the lesson was called off. Anything asking "is this
     * teacher free?" must pass false — a cancelled lesson holds nothing.
     */
    public static function lessonsForTeacherOnDate(int $teacherUserId, string $date, bool $includeCancelled = true): array {
        $st = self::pdo()->prepare(
            self::LESSON_SELECT .
            ' WHERE DATE(l.start_datetime) = ?
                AND ' . self::F_TEACHER . ' = ?'
            . ($includeCancelled ? '' : ' AND l.cancelled_at IS NULL') .
            ' ORDER BY l.start_datetime'
        );
        $st->execute([$date, $teacherUserId]);
        return $st->fetchAll();
    }

    /** The teacher's next date with lessons strictly after $afterDate, or null. */
    public static function nextTeachingDateForTeacher(int $teacherUserId, string $afterDate): ?string {
        $st = self::pdo()->prepare(
            'SELECT DATE(l.start_datetime) AS d
             FROM lessons l
             LEFT JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             WHERE DATE(l.start_datetime) > ?
               AND ' . self::F_TEACHER . ' = ?
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
             LEFT JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             WHERE DATE(l.start_datetime) < ?
               AND ' . self::F_TEACHER . ' = ?
             ORDER BY l.start_datetime DESC LIMIT 1'
        );
        $st->execute([$beforeDate, $teacherUserId]);
        $d = $st->fetchColumn();
        return $d !== false ? (string)$d : null;
    }

    /**
     * Lessons in a date range (inclusive), optionally one semester — calendars.
     * Pass false for $includeCancelled to leave out lessons that were called
     * off, which is what the admin calendar wants: the slot is free again and
     * showing it would suggest otherwise.
     */
    public static function lessonsBetween(string $fromDate, string $toDate, ?int $semesterId = null, bool $includeCancelled = true): array {
        $sql = self::LESSON_SELECT . ' WHERE DATE(l.start_datetime) BETWEEN ? AND ?';
        $params = [$fromDate, $toDate];
        if ($semesterId !== null) {
            $sql .= ' AND ' . self::F_SEMESTER . ' = ?';
            $params[] = $semesterId;
        }
        if (!$includeCancelled) {
            $sql .= ' AND l.cancelled_at IS NULL';
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
                AND ' . self::F_TEACHER . ' = ?
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
            ' WHERE ' . self::F_SEMESTER . " IN ($placeholders)
                AND " . self::F_TEACHER . " = ?
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
            ' WHERE ' . self::F_SEMESTER . " IN ($semesterPlaceholders) AND " . self::F_STUDENT . " IN ($studentPlaceholders)
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
            " WHERE DATE(l.start_datetime) BETWEEN ? AND ? AND " . self::F_STUDENT . " IN ($placeholders)
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
            ' WHERE ' . self::F_STUDENT . " = ? AND DATE(l.start_datetime) >= ?
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
     * Book a single lesson straight onto the calendar, with no standing weekly
     * reservation behind it — the one-off make-up or trial that does not
     * belong to a pattern.
     *
     * It carries no charge. Money follows a confirmed reservation, and there
     * is deliberately no reservation here.
     *
     * $fields: semester_id, teacher_user_id, student_user_id, location_id,
     * start_datetime, duration_minutes. Returns the new lesson id.
     */
    public static function createAdHocLesson(?UserContext $ctx, array $fields): int {
        self::assertAdmin($ctx);

        $semesterId = (int)($fields['semester_id'] ?? 0);
        $teacherUserId = (int)($fields['teacher_user_id'] ?? 0);
        $studentUserId = (int)($fields['student_user_id'] ?? 0);
        $locationId = (int)($fields['location_id'] ?? 0);
        $duration = (int)($fields['duration_minutes'] ?? 30);

        $start = strtotime((string)($fields['start_datetime'] ?? ''));
        if ($start === false) {
            throw new InvalidArgumentException('That is not a time we understand.');
        }
        $startDatetime = date('Y-m-d H:i:s', $start);

        if ($semesterId <= 0 || $locationId <= 0) {
            throw new InvalidArgumentException('A one-off lesson needs a semester and a location.');
        }
        if ($studentUserId <= 0) {
            throw new InvalidArgumentException('Please pick a student.');
        }
        if ($duration <= 0 || $duration > 240) {
            throw new InvalidArgumentException('Lesson length looks wrong.');
        }
        $st = self::pdo()->prepare('SELECT 1 FROM teacher_profiles WHERE user_id=?');
        $st->execute([$teacherUserId]);
        if (!$st->fetchColumn()) {
            throw new InvalidArgumentException('That is not a teacher.');
        }

        // The teacher has to actually be free — a one-off is a real commitment.
        $conflict = ScheduleConflicts::occurrenceConflict($teacherUserId, $startDatetime, $duration);
        if ($conflict !== null) {
            throw new InvalidArgumentException($conflict);
        }

        // lesson_number 0: this is not the Nth of anything.
        self::pdo()->prepare(
            'INSERT INTO lessons
               (semester_lesson_reservation_id, semester_id, teacher_user_id, student_user_id, location_id,
                start_datetime, duration_minutes, lesson_number, created_by_user_id)
             VALUES (NULL,?,?,?,?,?,?,0,?)'
        )->execute([
            $semesterId, $teacherUserId, $studentUserId, $locationId,
            $startDatetime, $duration, $ctx->id,
        ]);
        $lessonId = (int)self::pdo()->lastInsertId();

        self::log($ctx, 'lesson.ad_hoc_created', [
            'lesson_id' => $lessonId, 'semester_id' => $semesterId,
            'teacher_user_id' => $teacherUserId, 'student_user_id' => $studentUserId,
            'start_datetime' => $startDatetime,
        ]);
        return $lessonId;
    }

    /** Was this lesson booked on its own, with no standing reservation? */
    public static function isAdHoc(array $lesson): bool {
        return empty($lesson['semester_lesson_reservation_id']);
    }

    /**
     * Remove a one-off lesson outright. A lesson that belongs to a reservation
     * is never deleted here — cancel it instead, so the family keeps the record
     * of a lesson that was promised.
     */
    public static function deleteAdHocLesson(?UserContext $ctx, int $lessonId): void {
        self::assertAdmin($ctx);
        $lesson = self::requireLesson($lessonId);
        if (!self::isAdHoc($lesson)) {
            throw new InvalidArgumentException('This lesson belongs to a weekly booking — cancel it instead.');
        }
        self::pdo()->prepare('DELETE FROM lessons WHERE id=?')->execute([$lessonId]);
        self::log($ctx, 'lesson.ad_hoc_deleted', ['lesson_id' => $lessonId]);
    }

    /**
     * Move one lesson to another moment, and/or hand it to another teacher.
     *
     * This is the calendar's drag-and-drop, so a single gesture can change the
     * date, the time, the teacher and the location at once. The teacher is
     * expressed the way this app already expresses "someone else is covering
     * this week": as a substitute on the occurrence, never by touching the
     * standing reservation. Dropping a lesson back on its own teacher's column
     * clears the substitute again, which is the only way to undo it by drag.
     *
     * $teacherUserId and $locationId are the column dropped on; pass null to
     * leave either as it is. Throws if the moment is not free for whoever ends
     * up teaching it, so nothing commits on a clash.
     */
    public static function moveLesson(
        ?UserContext $ctx,
        int $lessonId,
        string $newStartDatetime,
        ?int $teacherUserId = null,
        ?int $locationId = null
    ): void {
        self::assertAdmin($ctx);
        $lesson = self::requireLesson($lessonId);
        if (!empty($lesson['cancelled_at'])) {
            throw new InvalidArgumentException('This lesson was cancelled.');
        }

        $start = strtotime($newStartDatetime);
        if ($start === false) {
            throw new InvalidArgumentException('That is not a time we understand.');
        }
        $newStart = date('Y-m-d H:i:s', $start);

        // Who teaches it after the move, and therefore whose diary has to be
        // free: the substitute when one is being set, otherwise the
        // reservation's own teacher.
        $ownTeacherId = (int)$lesson['teacher_user_id'];
        $newTeacherId = $teacherUserId ?? (int)$lesson['effective_teacher_user_id'];
        $adHoc = self::isAdHoc($lesson);
        // A one-off has no standing teacher to substitute FOR, so dropping it
        // on another column simply changes whose lesson it is.
        $substituteId = ($adHoc || $newTeacherId === $ownTeacherId) ? null : $newTeacherId;

        if ($newTeacherId !== $ownTeacherId) {
            $st = self::pdo()->prepare('SELECT 1 FROM teacher_profiles WHERE user_id=?');
            $st->execute([$newTeacherId]);
            if (!$st->fetchColumn()) {
                throw new InvalidArgumentException(
                    $adHoc ? 'That is not a teacher.' : 'The substitute must be a teacher.'
                );
            }
        }

        $conflict = ScheduleConflicts::occurrenceConflict(
            $newTeacherId, $newStart, (int)$lesson['duration_minutes'], ['lesson_id' => $lessonId]
        );
        if ($conflict !== null) {
            throw new InvalidArgumentException($conflict);
        }

        $newLocationId = $locationId ?? (int)$lesson['effective_location_id'];

        if ($adHoc) {
            // Nothing to override against: the one-off owns these outright.
            self::pdo()->prepare(
                'UPDATE lessons SET start_datetime=?, teacher_user_id=?, location_id=? WHERE id=?'
            )->execute([$newStart, $newTeacherId, $newLocationId, $lessonId]);
        } else {
            // An override is only worth storing when it actually differs from
            // the reservation's own location.
            $locationOverride = $newLocationId === (int)$lesson['location_id'] ? null : $newLocationId;
            self::pdo()->prepare(
                'UPDATE lessons SET start_datetime=?, substitute_teacher_user_id=?, location_id_override=? WHERE id=?'
            )->execute([$newStart, $substituteId, $locationOverride, $lessonId]);
        }

        self::log($ctx, 'lesson.moved', [
            'lesson_id' => $lessonId,
            'start_datetime' => $newStart,
            'ad_hoc' => $adHoc,
            'teacher_user_id' => $newTeacherId,
            'location_id' => $newLocationId,
        ]);
    }

    /**
     * Change how long one lesson runs, without touching its standing booking.
     *
     * Lengthening can run into whatever comes next, so the new span is checked
     * against the effective teacher's other commitments before anything is
     * written.
     */
    public static function setLessonDuration(?UserContext $ctx, int $lessonId, int $durationMinutes): void {
        self::assertAdmin($ctx);
        $lesson = self::requireLesson($lessonId);

        if ($durationMinutes <= 0 || $durationMinutes > 240) {
            throw new InvalidArgumentException('Lesson length looks wrong.');
        }
        if ($durationMinutes === (int)$lesson['duration_minutes']) {
            return;
        }

        $conflict = ScheduleConflicts::occurrenceConflict(
            (int)$lesson['effective_teacher_user_id'],
            (string)$lesson['start_datetime'],
            $durationMinutes,
            ['lesson_id' => $lessonId]
        );
        if ($conflict !== null) {
            throw new InvalidArgumentException($conflict);
        }

        self::pdo()->prepare('UPDATE lessons SET duration_minutes=? WHERE id=?')
            ->execute([$durationMinutes, $lessonId]);
        self::log($ctx, 'lesson.duration_changed', [
            'lesson_id' => $lessonId, 'duration_minutes' => $durationMinutes,
        ]);
    }

    /**
     * Call a lesson off. The row stays — the family and the teacher still need
     * to see that it was scheduled and then cancelled — but it drops off the
     * admin calendar and stops holding the teacher's slot, so the time can be
     * used for something else.
     */
    public static function cancelLesson(?UserContext $ctx, int $lessonId): void {
        self::assertAdmin($ctx);
        $lesson = self::requireLesson($lessonId);
        if (!empty($lesson['cancelled_at'])) {
            return; // Already cancelled; saying so twice helps nobody.
        }
        self::pdo()->prepare('UPDATE lessons SET cancelled_at=NOW(), cancelled_by_user_id=? WHERE id=?')
            ->execute([$ctx->id, $lessonId]);
        self::log($ctx, 'lesson.cancelled', ['lesson_id' => $lessonId]);
    }

    /** Is this lesson called off? */
    public static function isCancelled(array $lesson): bool {
        return !empty($lesson['cancelled_at']);
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

    /**
     * Hand one lesson to another teacher for that week, or pass null to give
     * it back to the reservation's own teacher.
     *
     * A substitute who is already busy at that moment is refused: the whole
     * point of naming a cover is that they can actually be there.
     */
    public static function setSubstituteTeacher(?UserContext $ctx, int $lessonId, ?int $teacherUserId): void {
        self::assertAdmin($ctx);
        $lesson = self::requireLesson($lessonId);
        if ($teacherUserId !== null) {
            $st = self::pdo()->prepare('SELECT 1 FROM teacher_profiles WHERE user_id=?');
            $st->execute([$teacherUserId]);
            if (!$st->fetchColumn()) {
                throw new InvalidArgumentException('The substitute must be a teacher.');
            }
            $conflict = ScheduleConflicts::occurrenceConflict(
                $teacherUserId,
                (string)$lesson['start_datetime'],
                (int)$lesson['duration_minutes'],
                ['lesson_id' => $lessonId]
            );
            if ($conflict !== null) {
                throw new InvalidArgumentException($conflict);
            }
        }
        self::pdo()->prepare('UPDATE lessons SET substitute_teacher_user_id=? WHERE id=?')
            ->execute([$teacherUserId, $lessonId]);
        self::log($ctx, 'lesson.substitute_set', ['lesson_id' => $lessonId, 'substitute_teacher_user_id' => $teacherUserId]);
    }

    /**
     * Hold one week somewhere other than the reservation's usual room — most
     * often because whoever is covering it works at the other building.
     *
     * Passing the reservation's own location (or null) clears the override
     * rather than storing one that says nothing, so "this lesson was moved"
     * always means what it looks like.
     */
    public static function setLocationOverride(?UserContext $ctx, int $lessonId, ?int $locationId): void {
        self::assertAdmin($ctx);
        $lesson = self::requireLesson($lessonId);

        if ($locationId !== null && $locationId > 0) {
            require_once __DIR__ . '/LocationManagement.php';
            if (!LocationManagement::find($locationId)) {
                throw new InvalidArgumentException('That location does not exist.');
            }
        }
        $override = ($locationId === null || $locationId <= 0 || $locationId === (int)$lesson['location_id'])
            ? null
            : $locationId;

        self::pdo()->prepare('UPDATE lessons SET location_id_override=? WHERE id=?')
            ->execute([$override, $lessonId]);
        self::log($ctx, 'lesson.location_override_set', ['lesson_id' => $lessonId, 'location_id' => $override]);
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

    /**
     * Has this one occurrence drifted off the standing weekly slot — a
     * different time of day, or a different length, than its reservation?
     * That is what moveLesson leaves behind, and the calendars flag
     * it so nobody turns up at the usual hour. Needs a row from a query that
     * carries reservation_start_time / reservation_duration_minutes.
     */
    public static function isTimeMoved(array $lesson): bool {
        if (!isset($lesson['reservation_start_time'], $lesson['reservation_duration_minutes'])) {
            return false;
        }
        $lessonTime = substr((string)$lesson['start_datetime'], 11, 8);
        $reservationTime = substr((string)$lesson['reservation_start_time'], 0, 8);
        return $lessonTime !== $reservationTime
            || (int)$lesson['duration_minutes'] !== (int)$lesson['reservation_duration_minutes'];
    }

    /** "Substitute teacher: Grace Lin", or "" when the regular teacher has it. */
    public static function substituteNote(array $lesson): string {
        if (empty($lesson['substitute_teacher_user_id'])) {
            return '';
        }
        $name = trim(((string)($lesson['substitute_first_name'] ?? '')) . ' ' . ((string)($lesson['substitute_last_name'] ?? '')));
        return $name === '' ? '' : 'Substitute teacher: ' . $name;
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
