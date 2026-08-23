<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/SemesterManagement.php';
require_once __DIR__ . '/ScheduleConflicts.php';
require_once __DIR__ . '/Billing.php';

// Semester lesson reservations: a weekly slot (teacher + location + day +
// time) reserved for a student for a whole semester. Confirming a reservation
// materializes its lessons from the location's active dates and posts the
// semester's charges; unconfirming deletes only FUTURE lessons (attended
// history is never erased) and reverses charges when Billing allows it.
class ReservationManagement {

    public const STATUSES = ['pending_reach_out', 'pending_confirmation', 'confirmed'];

    public const DAY_LABELS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /**
     * The lesson lengths the pickers offer, matching the hold block ones.
     * Only the dropdowns are limited to these — an import or an older row may
     * carry any length up to 240 minutes, and nothing here rewrites it.
     */
    public const DURATION_OPTIONS = [30, 60, 90, 120];

    private static function pdo(): PDO {
        return pdo();
    }

    // ── CRUD ───────────────────────────────────────────────────────────────

    /**
     * Create a reservation. $fields: semester_id, teacher_user_id,
     * location_id, student_user_id, day_of_week (0=Sun..6=Sat), start_time
     * ("HH:MM"), duration_minutes, status? (defaults pending_reach_out).
     *
     * $options['post_charges'] (default true) controls whether confirming
     * here also posts the semester's charges. Bulk loads that describe a
     * schedule which already exists — the schedule CSV import, carrying a
     * semester forward — pass false: the money for those students was
     * settled outside this system, so their balances are loaded separately
     * rather than invented from the schedule.
     */
    public static function createReservation(?UserContext $ctx, array $fields, array $options = []): int {
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
        self::assertSlotIsFree(
            $semesterId, $locationId, $teacherUserId, $dayOfWeek, $startTime, $durationMinutes, [], null
        );

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
            if ($options['post_charges'] ?? true) {
                Billing::postSemesterConfirmationCharges($ctx, $studentUserId, $semesterId, $durationMinutes);
            }
        }

        self::log($ctx, 'reservation.created', [
            'reservation_id' => $id, 'semester_id' => $semesterId, 'student_user_id' => $studentUserId,
            'teacher_user_id' => $teacherUserId, 'status' => $status,
        ]);
        return $id;
    }

    /**
     * Move or resize a reservation (day/time/duration/teacher/location).
     *
     * A confirmed reservation's FUTURE lessons follow it. When only the time
     * or duration changes they are moved IN PLACE, so notes, resources,
     * attendance marks, substitutes and location overrides survive — see
     * moveFutureLessonsInPlace() for the two occurrences it deliberately
     * leaves behind. When the day of week changes the calendar dates no
     * longer correspond at all, so future lessons are reconciled against the
     * new date list instead (which does discard their per-occurrence data).
     * Past lessons always stay where they were taught.
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
        // The reservation's time BEFORE the update: how we recognise which
        // lessons are still following the standing schedule.
        $previousStartTime = (string)$r['start_time'];
        $dayChanged = (int)$r['day_of_week'] !== $dayOfWeek;

        // Validate the WHOLE move before touching anything: only the lessons
        // that will actually move need their new moments to be free. A week
        // that was customized stays where it is, so its date is not checked.
        $movingDates = null;
        if ($r['status'] === 'confirmed' && !$dayChanged) {
            $movingDates = array_map(
                fn(array $l) => (string)$l['d'],
                self::futureLessonsFollowingSchedule($reservationId, $previousStartTime)
            );
        }
        self::assertSlotIsFree(
            (int)$r['semester_id'], $locationId, $teacherUserId, $dayOfWeek,
            $startTime, $durationMinutes,
            ['reservation_id' => $reservationId],
            $movingDates
        );

        self::pdo()->prepare(
            'UPDATE semester_lesson_reservations
             SET teacher_user_id=?, location_id=?, day_of_week=?, start_time=?, duration_minutes=?
             WHERE id=?'
        )->execute([$teacherUserId, $locationId, $dayOfWeek, $startTime, $durationMinutes, $reservationId]);

        if ($r['status'] === 'confirmed') {
            if ($dayChanged) {
                self::reconcileFutureLessons($ctx, $reservationId);
            } else {
                self::moveFutureLessonsInPlace($ctx, $reservationId, $previousStartTime, $startTime, $durationMinutes);
            }
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

        // Confirming materializes lessons, so the dates must be free — another
        // reservation's week may have been hand-moved into this slot while
        // this one sat pending.
        if ($newStatus === 'confirmed') {
            self::assertSlotIsFree(
                (int)$r['semester_id'], (int)$r['location_id'], (int)$r['teacher_user_id'],
                (int)$r['day_of_week'], (string)$r['start_time'], (int)$r['duration_minutes'],
                ['reservation_id' => $reservationId], null
            );
        }

        self::pdo()->prepare('UPDATE semester_lesson_reservations SET status=? WHERE id=?')
            ->execute([$newStatus, $reservationId]);

        if ($newStatus === 'confirmed') {
            self::generateLessonsForReservation($ctx, $reservationId);
            Billing::postSemesterConfirmationCharges($ctx, (int)$r['student_user_id'], (int)$r['semester_id'], (int)$r['duration_minutes']);
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

    // ── Carrying a semester forward ────────────────────────────────────────

    /**
     * Preview carrying $sourceSemesterId's schedule into $targetSemesterId:
     * one entry per source reservation, in the same shape the CSV imports use
     * (status / changes / messages), so the admin sees exactly what will
     * happen before anything is written.
     *
     * Everything carried over lands as pending_reach_out regardless of what it
     * was: the organization starts from last semester's roster and then calls
     * each family to confirm. Nothing is charged and no lessons are generated
     * until someone actually confirms the reservation.
     */
    public static function carryForwardPreview(int $targetSemesterId, int $sourceSemesterId): array {
        if (!SemesterManagement::find($targetSemesterId) || !SemesterManagement::find($sourceSemesterId)) {
            throw new InvalidArgumentException('Both semesters must exist.');
        }
        if ($targetSemesterId === $sourceSemesterId) {
            throw new InvalidArgumentException('Pick a different semester to carry forward from.');
        }

        // The target's grid columns: a pair that no longer teaches there this
        // semester — or not on that day — has nowhere to put the reservation.
        $columns = SemesterManagement::locationTeacherDayKeys($targetSemesterId);
        // Already carried over? Keyed by student+teacher+location rather than
        // by slot, so re-running after an admin has moved someone's time does
        // not create a second reservation for them.
        $existing = [];
        foreach (self::reservationsForSemester($targetSemesterId) as $r) {
            $existing[$r['student_user_id'] . ':' . $r['teacher_user_id'] . ':' . $r['location_id']] = true;
        }

        $out = [];
        $claimed = [];
        foreach (self::sourceReservationsForCarryForward($sourceSemesterId) as $i => $r) {
            $messages = [];
            $status = 'valid';
            $changes = '';

            $locationId = (int)$r['location_id'];
            $teacherUserId = (int)$r['teacher_user_id'];
            $studentUserId = (int)$r['student_user_id'];
            $dayOfWeek = (int)$r['day_of_week'];
            $startTime = (string)$r['start_time'];
            $duration = (int)$r['duration_minutes'];

            if (!isset($columns[$locationId . ':' . $teacherUserId . ':' . $dayOfWeek])) {
                $status = 'error';
                $messages[] = trim((string)$r['teacher_first_name'] . ' ' . (string)$r['teacher_last_name'])
                    . ' does not teach at ' . (string)$r['location_name'] . ' on '
                    . self::DAY_LABELS[$dayOfWeek] . 's this semester.';
            } elseif (isset($existing[$studentUserId . ':' . $teacherUserId . ':' . $locationId])) {
                $changes = 'Already carried over (no change)';
            } else {
                $conflict = self::carryForwardClaimConflict($claimed, $teacherUserId, $dayOfWeek, $startTime, $duration)
                    ?? ScheduleConflicts::weeklySlotConflict(
                        $targetSemesterId, $teacherUserId, $dayOfWeek, $startTime, $duration
                    );
                if ($conflict !== null) {
                    $status = 'error';
                    $messages[] = $conflict;
                } else {
                    $claimed[] = [
                        'teacher_user_id' => $teacherUserId,
                        'day_of_week' => $dayOfWeek,
                        'start_time' => $startTime,
                        'duration_minutes' => $duration,
                    ];
                    $changes = 'Reserve as pending reach out';
                }
            }

            $out[] = [
                'row' => $i + 1,
                'data' => [
                    'student_name' => trim((string)$r['student_first_name'] . ' ' . (string)$r['student_last_name']),
                    'teacher_name' => trim((string)$r['teacher_first_name'] . ' ' . (string)$r['teacher_last_name']),
                    'location_name' => (string)$r['location_name'],
                    'day' => self::DAY_LABELS[$dayOfWeek],
                    'start_time' => date('g:i a', strtotime('1970-01-01 ' . $startTime)),
                    'duration_minutes' => (string)$duration,
                    'status' => (string)$r['status'],
                ],
                '_fields' => [
                    'semester_id' => $targetSemesterId,
                    'teacher_user_id' => $teacherUserId,
                    'location_id' => $locationId,
                    'student_user_id' => $studentUserId,
                    'day_of_week' => $dayOfWeek,
                    'start_time' => substr($startTime, 0, 5),
                    'duration_minutes' => $duration,
                    'status' => 'pending_reach_out',
                ],
                'status' => $status,
                'changes' => $changes,
                'messages' => $messages,
            ];
        }
        return $out;
    }

    /**
     * Carry $sourceSemesterId's schedule into $targetSemesterId as
     * pending_reach_out reservations. Re-runnable: rows that already exist or
     * cannot be placed are counted as skipped, never duplicated.
     * Returns ['created' => n, 'skipped' => n].
     */
    public static function carryForwardFromSemester(?UserContext $ctx, int $targetSemesterId, int $sourceSemesterId): array {
        self::assertAdmin($ctx);
        $created = 0;
        $skipped = 0;
        foreach (self::carryForwardPreview($targetSemesterId, $sourceSemesterId) as $entry) {
            if ($entry['status'] !== 'valid' || $entry['changes'] === 'Already carried over (no change)') {
                $skipped++;
                continue;
            }
            try {
                // The preview simulated the conflict checks; createReservation
                // runs them for real against a database that is changing under
                // us, so a late clash is skipped rather than fatal.
                self::createReservation($ctx, $entry['_fields'], ['post_charges' => false]);
                $created++;
            } catch (InvalidArgumentException $e) {
                $skipped++;
            }
        }
        self::log($ctx, 'reservation.carried_forward', [
            'semester_id' => $targetSemesterId, 'from_semester_id' => $sourceSemesterId,
            'created' => $created, 'skipped' => $skipped,
        ]);
        return ['created' => $created, 'skipped' => $skipped];
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
            "SELECT id FROM semester_lesson_reservations
             WHERE semester_id=? AND location_id=? AND status='confirmed'"
        );
        $st->execute([$semesterId, $locationId]);
        foreach ($st->fetchAll() as $r) {
            self::reconcileFutureLessons($ctx, (int)$r['id']);
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

    /** A teacher's non-deleted reservations (optionally one semester). */
    public static function reservationsForTeacher(int $teacherUserId, ?int $semesterId = null): array {
        $sql = "SELECT r.*,
                       su.first_name AS student_first_name, su.last_name AS student_last_name,
                       l.name AS location_name
                FROM semester_lesson_reservations r
                JOIN users su ON su.id = r.student_user_id
                JOIN locations l ON l.id = r.location_id
                WHERE r.teacher_user_id=? AND r.status <> 'deleted'";
        $params = [$teacherUserId];
        if ($semesterId !== null) {
            $sql .= ' AND r.semester_id=?';
            $params[] = $semesterId;
        }
        $st = self::pdo()->prepare($sql . ' ORDER BY r.day_of_week, r.start_time');
        $st->execute($params);
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

    /**
     * The source semester's live reservations, with the names the preview
     * shows. Deleted ones are left behind — the point is who is enrolled now.
     */
    private static function sourceReservationsForCarryForward(int $semesterId): array {
        $st = self::pdo()->prepare(
            "SELECT r.*,
                    su.first_name AS student_first_name, su.last_name AS student_last_name,
                    tu.first_name AS teacher_first_name, tu.last_name AS teacher_last_name,
                    l.name AS location_name
             FROM semester_lesson_reservations r
             JOIN users su ON su.id = r.student_user_id
             JOIN users tu ON tu.id = r.teacher_user_id
             JOIN locations l ON l.id = r.location_id
             WHERE r.semester_id=? AND r.status <> 'deleted'
               AND su.is_deleted = 0 AND tu.is_deleted = 0
             ORDER BY l.name, tu.last_name, r.day_of_week, r.start_time"
        );
        $st->execute([$semesterId]);
        return $st->fetchAll();
    }

    /** Overlap with a slot an earlier preview row already claimed for this teacher. */
    private static function carryForwardClaimConflict(
        array $claimed,
        int $teacherUserId,
        int $dayOfWeek,
        string $startTime,
        int $durationMinutes
    ): ?string {
        $start = strtotime('1970-01-01 ' . $startTime);
        $end = $start + $durationMinutes * 60;
        foreach ($claimed as $other) {
            if ($other['teacher_user_id'] !== $teacherUserId || $other['day_of_week'] !== $dayOfWeek) {
                continue;
            }
            $otherStart = strtotime('1970-01-01 ' . $other['start_time']);
            $otherEnd = $otherStart + $other['duration_minutes'] * 60;
            if ($start < $otherEnd && $otherStart < $end) {
                return 'Another reservation being carried forward already takes this teacher\'s '
                    . date('g:i a', $otherStart) . '–' . date('g:i a', $otherEnd) . ' slot.';
            }
        }
        return null;
    }

    private static function requireReservation(int $reservationId): array {
        $st = self::pdo()->prepare('SELECT * FROM semester_lesson_reservations WHERE id=? LIMIT 1');
        $st->execute([$reservationId]);
        $row = $st->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Reservation not found.');
        }
        return $row;
    }

    /**
     * The reservation's future lessons that are still sitting at its standing
     * time — i.e. the ones a schedule-wide move should carry along. A lesson
     * at any other time was moved by hand
     * (LessonManagement::moveLesson) and is left alone.
     */
    private static function futureLessonsFollowingSchedule(int $reservationId, string $standingStartTime): array {
        $st = self::pdo()->prepare(
            'SELECT l.id, DATE(l.start_datetime) AS d,
                    COALESCE(l.substitute_teacher_user_id, r.teacher_user_id) AS effective_teacher_user_id
             FROM lessons l
             JOIN semester_lesson_reservations r ON r.id = l.semester_lesson_reservation_id
             WHERE l.semester_lesson_reservation_id=? AND l.start_datetime > NOW()
               AND TIME(l.start_datetime) = ?
             ORDER BY l.start_datetime'
        );
        $st->execute([$reservationId, $standingStartTime]);
        return $st->fetchAll();
    }

    /**
     * Retime the reservation's FUTURE lessons without deleting them, so their
     * notes, resources, attendance and overrides survive. Hand-customized
     * weeks are left where they are. Conflicts cannot occur here — the caller
     * validated every destination before any write.
     */
    private static function moveFutureLessonsInPlace(
        ?UserContext $ctx,
        int $reservationId,
        string $previousStartTime,
        string $newStartTime,
        int $newDurationMinutes
    ): void {
        $lessons = self::futureLessonsFollowingSchedule($reservationId, $previousStartTime);
        $update = self::pdo()->prepare('UPDATE lessons SET start_datetime=?, duration_minutes=? WHERE id=?');
        foreach ($lessons as $lesson) {
            $update->execute([
                (string)$lesson['d'] . ' ' . $newStartTime,
                $newDurationMinutes,
                (int)$lesson['id'],
            ]);
        }
        if ($lessons) {
            self::log($ctx, 'reservation.lessons_moved', [
                'reservation_id' => $reservationId, 'moved' => count($lessons),
            ]);
        }
    }

    /**
     * Refuse the slot unless it is free for this teacher, both as a standing
     * weekly slot and on every future date it would occupy. A teacher may
     * never hold two commitments at the same moment, so this spans every
     * location, not just the one being booked.
     *
     * $onlyDates limits the per-date sweep to the occurrences that will
     * actually move (null = every active date for the weekday).
     */
    private static function assertSlotIsFree(
        int $semesterId,
        int $locationId,
        int $teacherUserId,
        int $dayOfWeek,
        string $startTime,
        int $durationMinutes,
        array $exclude,
        ?array $onlyDates
    ): void {
        $conflict = ScheduleConflicts::weeklySlotConflict(
            $semesterId, $teacherUserId, $dayOfWeek, $startTime, $durationMinutes, $exclude
        );
        if ($conflict !== null) {
            throw new InvalidArgumentException($conflict);
        }

        $dates = $onlyDates ?? array_column(
            SemesterManagement::activeDatesForLocationWeekday($semesterId, $locationId, $dayOfWeek),
            'date'
        );
        $conflict = ScheduleConflicts::futureOccurrenceConflict(
            $teacherUserId, $dates, $startTime, $durationMinutes, $exclude
        );
        if ($conflict !== null) {
            throw new InvalidArgumentException($conflict);
        }
    }

    /**
     * The reservation's day of week changed, so its future lessons are on the
     * wrong dates entirely: drop the ones that no longer match, generate the
     * new dates, and renumber. Per-occurrence data on the dropped lessons is
     * lost — there is no corresponding date to carry it to.
     */
    private static function reconcileFutureLessons(?UserContext $ctx, int $reservationId): void {
        $r = self::requireReservation($reservationId);
        $activeDates = array_column(
            SemesterManagement::activeDatesForLocationWeekday(
                (int)$r['semester_id'], (int)$r['location_id'], (int)$r['day_of_week']
            ),
            'date'
        );
        $activeSet = array_flip($activeDates);

        $st = self::pdo()->prepare(
            'SELECT id, DATE(start_datetime) AS d FROM lessons
             WHERE semester_lesson_reservation_id=? AND start_datetime > NOW()'
        );
        $st->execute([$reservationId]);
        foreach ($st->fetchAll() as $lesson) {
            if (!isset($activeSet[$lesson['d']])) {
                self::pdo()->prepare('DELETE FROM lessons WHERE id=?')->execute([(int)$lesson['id']]);
            }
        }

        self::generateLessonsForReservation($ctx, $reservationId);
        self::renumberLessons($reservationId, $activeDates);
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
