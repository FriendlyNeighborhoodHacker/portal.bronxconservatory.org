<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/SemesterManagement.php';
require_once __DIR__ . '/Billing.php';

// Semester lesson reservations: a weekly slot (teacher + location + day +
// time) reserved for a student for a whole semester. Confirming a reservation
// materializes its lessons from the location's active dates and posts the
// semester's charges; unconfirming deletes only FUTURE lessons (attended
// history is never erased) and reverses charges when Billing allows it.
class ReservationManagement {

    public const STATUSES = ['pending_reach_out', 'pending_confirmation', 'confirmed'];

    private static function pdo(): PDO {
        return pdo();
    }

    // ── CRUD ───────────────────────────────────────────────────────────────

    /**
     * Create a reservation. $fields: semester_id, teacher_user_id,
     * location_id, student_user_id, day_of_week (0=Sun..6=Sat), start_time
     * ("HH:MM"), duration_minutes, status? (defaults pending_reach_out).
     */
    public static function createReservation(?UserContext $ctx, array $fields): int {
        self::assertAdmin($ctx);

        $semesterId = (int)($fields['semester_id'] ?? 0);
        $teacherUserId = (int)($fields['teacher_user_id'] ?? 0);
        $locationId = (int)($fields['location_id'] ?? 0);
        $studentUserId = (int)($fields['student_user_id'] ?? 0);
        $dayOfWeek = (int)($fields['day_of_week'] ?? -1);
        $startTime = self::normalizeTime((string)($fields['start_time'] ?? ''));
        $durationMinutes = (int)($fields['duration_minutes'] ?? 30);
        $status = (string)($fields['status'] ?? 'pending_reach_out');

        if ($semesterId <= 0 || !SemesterManagement::find($semesterId)) {
            throw new InvalidArgumentException('A valid semester is required.');
        }
        if ($studentUserId <= 0) {
            throw new InvalidArgumentException('A student is required.');
        }
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new InvalidArgumentException('Day of week must be 0 (Sunday) through 6 (Saturday).');
        }
        if ($durationMinutes <= 0 || $durationMinutes > 240) {
            throw new InvalidArgumentException('Duration looks invalid.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown reservation status.');
        }
        if (!SemesterManagement::isTeacherAtLocation($semesterId, $locationId, $teacherUserId)) {
            throw new InvalidArgumentException('That teacher is not assigned to that location this semester.');
        }
        if (self::cellIsTaken($semesterId, $locationId, $teacherUserId, $dayOfWeek, $startTime, null)) {
            throw new InvalidArgumentException('That time slot is already reserved for this teacher.');
        }

        self::pdo()->prepare(
            'INSERT INTO semester_lesson_reservations
               (semester_id, teacher_user_id, location_id, student_user_id, status,
                day_of_week, start_time, duration_minutes, created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $semesterId, $teacherUserId, $locationId, $studentUserId, $status,
            $dayOfWeek, $startTime, $durationMinutes, $ctx?->id,
        ]);
        $id = (int)self::pdo()->lastInsertId();

        if ($status === 'confirmed') {
            self::generateLessonsForReservation($ctx, $id);
            Billing::postSemesterConfirmationCharges($ctx, $studentUserId, $semesterId);
        }

        self::log($ctx, 'reservation.created', [
            'reservation_id' => $id, 'semester_id' => $semesterId, 'student_user_id' => $studentUserId,
            'teacher_user_id' => $teacherUserId, 'status' => $status,
        ]);
        return $id;
    }

    /**
     * Move or resize a reservation (day/time/duration/teacher/location).
     * A confirmed reservation's lessons are regenerated to match: future
     * lessons move; past lessons stay where they were taught.
     */
    public static function updateReservation(?UserContext $ctx, int $reservationId, array $fields): void {
        self::assertAdmin($ctx);
        $r = self::requireReservation($reservationId);

        $teacherUserId = (int)($fields['teacher_user_id'] ?? $r['teacher_user_id']);
        $locationId = (int)($fields['location_id'] ?? $r['location_id']);
        $dayOfWeek = (int)($fields['day_of_week'] ?? $r['day_of_week']);
        $startTime = self::normalizeTime((string)($fields['start_time'] ?? $r['start_time']));
        $durationMinutes = (int)($fields['duration_minutes'] ?? $r['duration_minutes']);

        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new InvalidArgumentException('Day of week must be 0 (Sunday) through 6 (Saturday).');
        }
        if ($durationMinutes <= 0 || $durationMinutes > 240) {
            throw new InvalidArgumentException('Duration looks invalid.');
        }
        if (!SemesterManagement::isTeacherAtLocation((int)$r['semester_id'], $locationId, $teacherUserId)) {
            throw new InvalidArgumentException('That teacher is not assigned to that location this semester.');
        }
        if (self::cellIsTaken((int)$r['semester_id'], $locationId, $teacherUserId, $dayOfWeek, $startTime, $reservationId)) {
            throw new InvalidArgumentException('That time slot is already reserved for this teacher.');
        }

        self::pdo()->prepare(
            'UPDATE semester_lesson_reservations
             SET teacher_user_id=?, location_id=?, day_of_week=?, start_time=?, duration_minutes=?
             WHERE id=?'
        )->execute([$teacherUserId, $locationId, $dayOfWeek, $startTime, $durationMinutes, $reservationId]);

        if ($r['status'] === 'confirmed') {
            self::deleteFutureLessons($reservationId);
            self::generateLessonsForReservation($ctx, $reservationId);
        }

        self::log($ctx, 'reservation.updated', ['reservation_id' => $reservationId]);
    }

    /**
     * Change a reservation's status along the allowed graph
     * (pending_reach_out <-> pending_confirmation <-> confirmed).
     *   -> confirmed: generates the semester's lessons + posts charges
     *   confirmed -> pending_*: deletes FUTURE lessons + reverses charges
     *     when the student hasn't had a lesson yet (Billing decides)
     */
    public static function setStatus(?UserContext $ctx, int $reservationId, string $newStatus): void {
        self::assertAdmin($ctx);
        $r = self::requireReservation($reservationId);
        $oldStatus = (string)$r['status'];

        if ($oldStatus === 'deleted') {
            throw new RuntimeException('This reservation was deleted.');
        }
        if (!in_array($newStatus, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown reservation status.');
        }
        if ($newStatus === $oldStatus) {
            return;
        }

        self::pdo()->prepare('UPDATE semester_lesson_reservations SET status=? WHERE id=?')
            ->execute([$newStatus, $reservationId]);

        if ($newStatus === 'confirmed') {
            self::generateLessonsForReservation($ctx, $reservationId);
            Billing::postSemesterConfirmationCharges($ctx, (int)$r['student_user_id'], (int)$r['semester_id']);
        } elseif ($oldStatus === 'confirmed') {
            self::deleteFutureLessons($reservationId);
            // Status is already updated, so "another confirmed reservation"
            // checks exclude this one; Billing skips the reversal if the
            // student already had a lesson this semester.
            Billing::reverseSemesterConfirmationCharges($ctx, (int)$r['student_user_id'], (int)$r['semester_id']);
        }

        self::log($ctx, 'reservation.status_changed', [
            'reservation_id' => $reservationId, 'from' => $oldStatus, 'to' => $newStatus,
        ]);
    }

    /**
     * Soft-delete a reservation and remove its FUTURE lessons. Past lessons
     * (and their notes/resources/attendance) remain unchanged.
     */
    public static function deleteReservation(?UserContext $ctx, int $reservationId): void {
        self::assertAdmin($ctx);
        $r = self::requireReservation($reservationId);
        if ($r['status'] === 'deleted') {
            return;
        }

        self::pdo()->prepare("UPDATE semester_lesson_reservations SET status='deleted' WHERE id=?")
            ->execute([$reservationId]);
        self::deleteFutureLessons($reservationId);

        if ($r['status'] === 'confirmed') {
            Billing::reverseSemesterConfirmationCharges($ctx, (int)$r['student_user_id'], (int)$r['semester_id']);
        }

        self::log($ctx, 'reservation.deleted', ['reservation_id' => $reservationId]);
    }

    // ── Lesson generation ──────────────────────────────────────────────────

    /**
     * Materialize the reservation's lessons from the location's ACTIVE dates
     * matching its day_of_week. lesson_number is the 1-based ordinal of the
     * date in that calendar, so it is stable across regeneration: if past
     * lessons were kept from an earlier confirmation, new rows slot in around
     * them with consistent numbering. Idempotent — existing lesson dates are
     * skipped. Returns the number of lessons created.
     */
    public static function generateLessonsForReservation(?UserContext $ctx, int $reservationId): int {
        self::assertAdmin($ctx);
        $r = self::requireReservation($reservationId);

        $dates = SemesterManagement::activeDatesForLocationWeekday(
            (int)$r['semester_id'], (int)$r['location_id'], (int)$r['day_of_week']
        );
        if (!$dates) {
            return 0;
        }

        $pdo = self::pdo();
        $st = $pdo->prepare('SELECT DATE(start_datetime) AS d, lesson_number FROM lessons WHERE semester_lesson_reservation_id=?');
        $st->execute([$reservationId]);
        $existingSet = [];
        $usedNumbers = [];
        $maxNumber = 0;
        foreach ($st->fetchAll() as $row) {
            $existingSet[(string)$row['d']] = true;
            $usedNumbers[(int)$row['lesson_number']] = true;
            $maxNumber = max($maxNumber, (int)$row['lesson_number']);
        }

        $created = 0;
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO lessons
                   (semester_lesson_reservation_id, start_datetime, duration_minutes, lesson_number, created_by_user_id)
                 VALUES (?,?,?,?,?)'
            );
            foreach ($dates as $i => $dateRow) {
                $date = (string)$dateRow['date'];
                if (isset($existingSet[$date])) {
                    continue;
                }
                // The date's ordinal in the calendar; if a kept past lesson
                // (e.g. from before the reservation's day changed) already
                // holds that number, append after the current maximum instead
                // of violating the (reservation, number) unique key.
                $number = $i + 1;
                if (isset($usedNumbers[$number])) {
                    $number = ++$maxNumber;
                }
                $usedNumbers[$number] = true;
                $maxNumber = max($maxNumber, $number);
                $insert->execute([
                    $reservationId,
                    $date . ' ' . $r['start_time'],
                    (int)$r['duration_minutes'],
                    $number,
                    $ctx?->id,
                ]);
                $created++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($created > 0) {
            self::log($ctx, 'reservation.lessons_generated', [
                'reservation_id' => $reservationId, 'created' => $created,
            ]);
        }
        return $created;
    }

    /**
     * After the location's date calendar changes, bring every confirmed
     * reservation at that location back in sync: drop FUTURE lessons whose
     * date is no longer an active matching date, add newly-active dates, and
     * renumber each reservation's lessons to their date ordinals.
     */
    public static function resyncLessonsForLocation(?UserContext $ctx, int $semesterId, int $locationId): void {
        self::assertAdmin($ctx);
        $st = self::pdo()->prepare(
            "SELECT id, day_of_week FROM semester_lesson_reservations
             WHERE semester_id=? AND location_id=? AND status='confirmed'"
        );
        $st->execute([$semesterId, $locationId]);
        foreach ($st->fetchAll() as $r) {
            $reservationId = (int)$r['id'];
            $activeDates = array_column(
                SemesterManagement::activeDatesForLocationWeekday($semesterId, $locationId, (int)$r['day_of_week']),
                'date'
            );
            $activeSet = array_flip($activeDates);

            // Future lessons on dates that are no longer active go away.
            $lessons = self::pdo()->prepare(
                'SELECT id, DATE(start_datetime) AS d FROM lessons
                 WHERE semester_lesson_reservation_id=? AND start_datetime > NOW()'
            );
            $lessons->execute([$reservationId]);
            foreach ($lessons->fetchAll() as $lesson) {
                if (!isset($activeSet[$lesson['d']])) {
                    self::pdo()->prepare('DELETE FROM lessons WHERE id=?')->execute([(int)$lesson['id']]);
                }
            }

            self::generateLessonsForReservation($ctx, $reservationId);
            self::renumberLessons($reservationId, $activeDates);
        }
        self::log($ctx, 'reservation.lessons_resynced', ['semester_id' => $semesterId, 'location_id' => $locationId]);
    }

    // ── Queries ────────────────────────────────────────────────────────────

    public static function findReservation(int $reservationId): ?array {
        $st = self::pdo()->prepare(
            'SELECT r.*,
                    su.first_name AS student_first_name, su.last_name AS student_last_name,
                    tu.first_name AS teacher_first_name, tu.last_name AS teacher_last_name,
                    l.name AS location_name
             FROM semester_lesson_reservations r
             JOIN users su ON su.id = r.student_user_id
             JOIN users tu ON tu.id = r.teacher_user_id
             JOIN locations l ON l.id = r.location_id
             WHERE r.id=? LIMIT 1'
        );
        $st->execute([$reservationId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** All non-deleted reservations for the semester, with student names. */
    public static function reservationsForSemester(int $semesterId): array {
        $st = self::pdo()->prepare(
            "SELECT r.*,
                    su.first_name AS student_first_name, su.last_name AS student_last_name
             FROM semester_lesson_reservations r
             JOIN users su ON su.id = r.student_user_id
             WHERE r.semester_id=? AND r.status <> 'deleted'
             ORDER BY r.day_of_week, r.start_time"
        );
        $st->execute([$semesterId]);
        return $st->fetchAll();
    }

    /** A student's non-deleted reservations (optionally one semester). */
    public static function reservationsForStudent(int $studentUserId, ?int $semesterId = null): array {
        $sql = "SELECT r.*,
                       tu.first_name AS teacher_first_name, tu.last_name AS teacher_last_name,
                       l.name AS location_name
                FROM semester_lesson_reservations r
                JOIN users tu ON tu.id = r.teacher_user_id
                JOIN locations l ON l.id = r.location_id
                WHERE r.student_user_id=? AND r.status <> 'deleted'";
        $params = [$studentUserId];
        if ($semesterId !== null) {
            $sql .= ' AND r.semester_id=?';
            $params[] = $semesterId;
        }
        $st = self::pdo()->prepare($sql . ' ORDER BY r.day_of_week, r.start_time');
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * Everything the Semester Schedule grid needs, in three queries:
     *   columns      — SemesterManagement::locationTeachers (the column spine)
     *   reservations — keyed "locationId:teacherId:dayOfWeek:HH:MM:SS"
     *   balances     — Billing::semesterBalancesByStudent for the color coding
     */
    public static function gridDataForSemester(int $semesterId): array {
        $columns = SemesterManagement::locationTeachers($semesterId);
        $reservations = [];
        $studentIds = [];
        foreach (self::reservationsForSemester($semesterId) as $r) {
            $key = $r['location_id'] . ':' . $r['teacher_user_id'] . ':' . $r['day_of_week'] . ':' . $r['start_time'];
            $reservations[$key] = $r;
            $studentIds[(int)$r['student_user_id']] = true;
        }
        $balances = Billing::semesterBalancesByStudent($semesterId, array_keys($studentIds));
        return ['columns' => $columns, 'reservations' => $reservations, 'balances' => $balances];
    }

    // ── internals ─────────────────────────────────────────────────────────

    private static function requireReservation(int $reservationId): array {
        $st = self::pdo()->prepare('SELECT * FROM semester_lesson_reservations WHERE id=? LIMIT 1');
        $st->execute([$reservationId]);
        $row = $st->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Reservation not found.');
        }
        return $row;
    }

    private static function cellIsTaken(int $semesterId, int $locationId, int $teacherUserId, int $dayOfWeek, string $startTime, ?int $excludeReservationId): bool {
        $sql = "SELECT 1 FROM semester_lesson_reservations
                WHERE semester_id=? AND location_id=? AND teacher_user_id=? AND day_of_week=? AND start_time=?
                  AND status <> 'deleted'";
        $params = [$semesterId, $locationId, $teacherUserId, $dayOfWeek, $startTime];
        if ($excludeReservationId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeReservationId;
        }
        $st = self::pdo()->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        return (bool)$st->fetchColumn();
    }

    private static function deleteFutureLessons(int $reservationId): void {
        self::pdo()->prepare(
            'DELETE FROM lessons WHERE semester_lesson_reservation_id=? AND start_datetime > NOW()'
        )->execute([$reservationId]);
    }

    /** Renumber a reservation's lessons to their ordinal in the active-date list. */
    private static function renumberLessons(int $reservationId, array $activeDates): void {
        $ordinals = [];
        foreach (array_values($activeDates) as $i => $date) {
            $ordinals[$date] = $i + 1;
        }
        $st = self::pdo()->prepare(
            'SELECT id, DATE(start_datetime) AS d, lesson_number FROM lessons WHERE semester_lesson_reservation_id=?'
        );
        $st->execute([$reservationId]);
        $rows = $st->fetchAll();

        // Two passes so renumbering never trips the (reservation, number)
        // unique key mid-update: park everything at a negative number first.
        $park = self::pdo()->prepare('UPDATE lessons SET lesson_number=? WHERE id=?');
        foreach ($rows as $i => $lesson) {
            $park->execute([-($i + 1), (int)$lesson['id']]);
        }
        $set = self::pdo()->prepare('UPDATE lessons SET lesson_number=? WHERE id=?');
        $fallback = count($ordinals);
        foreach ($rows as $lesson) {
            $number = $ordinals[$lesson['d']] ?? ++$fallback; // kept past lessons on now-inactive dates
            $set->execute([$number, (int)$lesson['id']]);
        }
    }

    private static function normalizeTime(string $time): string {
        $time = trim($time);
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) {
            throw new InvalidArgumentException('Start time must look like "9:00" or "14:30".');
        }
        $parts = explode(':', $time);
        $h = (int)$parts[0];
        $m = (int)$parts[1];
        if ($h > 23 || $m > 59) {
            throw new InvalidArgumentException('Start time is out of range.');
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
