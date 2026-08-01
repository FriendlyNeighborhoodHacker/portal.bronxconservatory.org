<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/SemesterManagement.php';
require_once __DIR__ . '/ScheduleConflicts.php';

// Hold blocks: a teacher's non-lesson time on the semester schedule — lunch,
// an errand, a standing break. Structurally these mirror lesson reservations
// (a weekly slot that materializes occurrences from the location's active
// dates) with two differences: they hold a title instead of a student, and
// there is no pending/confirmed state or billing, so their blocks are
// generated the moment the reservation is created.
class HoldBlockManagement {

    /** The reservation + its teacher/location names, for the grid and modals. */
    private const RESERVATION_SELECT = "
        SELECT hr.*,
               tu.first_name AS teacher_first_name, tu.last_name AS teacher_last_name,
               tu.preferred_name AS teacher_preferred_name,
               l.name AS location_name
        FROM semester_hold_block_reservations hr
        JOIN users tu ON tu.id = hr.teacher_user_id
        JOIN locations l ON l.id = hr.location_id
    ";

    /** A materialized block plus everything it inherits from its reservation. */
    private const BLOCK_SELECT = "
        SELECT b.*,
               hr.semester_id, hr.teacher_user_id, hr.location_id, hr.day_of_week,
               hr.status AS reservation_status, hr.title,
               COALESCE(b.title_override, hr.title) AS effective_title,
               tu.first_name AS teacher_first_name, tu.last_name AS teacher_last_name,
               tu.preferred_name AS teacher_preferred_name,
               l.name AS location_name
        FROM semester_hold_blocks b
        JOIN semester_hold_block_reservations hr ON hr.id = b.semester_hold_block_reservation_id
        JOIN users tu ON tu.id = hr.teacher_user_id
        JOIN locations l ON l.id = hr.location_id
    ";

    private static function pdo(): PDO {
        return pdo();
    }

    // ── CRUD ───────────────────────────────────────────────────────────────

    /**
     * Create a hold block reservation and immediately materialize its blocks.
     * $fields: semester_id, teacher_user_id, location_id, day_of_week
     * (0=Sun..6=Sat), start_time ("HH:MM"), duration_minutes, title.
     */
    public static function createHoldBlockReservation(?UserContext $ctx, array $fields): int {
        self::assertAdmin($ctx);

        $semesterId = (int)($fields['semester_id'] ?? 0);
        $teacherUserId = (int)($fields['teacher_user_id'] ?? 0);
        $locationId = (int)($fields['location_id'] ?? 0);
        $dayOfWeek = (int)($fields['day_of_week'] ?? -1);
        $startTime = self::normalizeTime((string)($fields['start_time'] ?? ''));
        $durationMinutes = (int)($fields['duration_minutes'] ?? 30);
        $title = self::normalizeTitle((string)($fields['title'] ?? ''));

        if ($semesterId <= 0 || !SemesterManagement::find($semesterId)) {
            throw new InvalidArgumentException('A valid semester is required.');
        }
        self::assertSlotIsValid($semesterId, $locationId, $teacherUserId, $dayOfWeek, $startTime, $durationMinutes, null);

        self::pdo()->prepare(
            'INSERT INTO semester_hold_block_reservations
               (semester_id, teacher_user_id, location_id, day_of_week, start_time,
                duration_minutes, title, created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $semesterId, $teacherUserId, $locationId, $dayOfWeek, $startTime,
            $durationMinutes, $title, $ctx?->id,
        ]);
        $id = (int)self::pdo()->lastInsertId();

        self::generateHoldBlocksForReservation($ctx, $id);

        self::log($ctx, 'hold_block_reservation.created', [
            'hold_block_reservation_id' => $id, 'semester_id' => $semesterId,
            'teacher_user_id' => $teacherUserId, 'title' => $title,
        ]);
        return $id;
    }

    /**
     * Move, resize, or retitle a hold block reservation. Future blocks follow
     * it in place (see moveFutureBlocksInPlace); a day-of-week change
     * reconciles them against the new date list instead. Past blocks stay put.
     */
    public static function updateHoldBlockReservation(?UserContext $ctx, int $reservationId, array $fields): void {
        self::assertAdmin($ctx);
        $r = self::requireHoldBlockReservation($reservationId);
        if ($r['status'] === 'deleted') {
            throw new RuntimeException('This hold block was deleted.');
        }

        $teacherUserId = (int)($fields['teacher_user_id'] ?? $r['teacher_user_id']);
        $locationId = (int)($fields['location_id'] ?? $r['location_id']);
        $dayOfWeek = (int)($fields['day_of_week'] ?? $r['day_of_week']);
        $startTime = self::normalizeTime((string)($fields['start_time'] ?? $r['start_time']));
        $durationMinutes = (int)($fields['duration_minutes'] ?? $r['duration_minutes']);
        $title = self::normalizeTitle((string)($fields['title'] ?? $r['title']));

        $semesterId = (int)$r['semester_id'];
        self::assertSlotIsValid($semesterId, $locationId, $teacherUserId, $dayOfWeek, $startTime, $durationMinutes, $reservationId);

        $previousStartTime = (string)$r['start_time'];
        $previousTitle = (string)$r['title'];
        $dayChanged = (int)$r['day_of_week'] !== $dayOfWeek;

        self::pdo()->prepare(
            'UPDATE semester_hold_block_reservations
             SET teacher_user_id=?, location_id=?, day_of_week=?, start_time=?, duration_minutes=?, title=?
             WHERE id=?'
        )->execute([$teacherUserId, $locationId, $dayOfWeek, $startTime, $durationMinutes, $title, $reservationId]);

        if ($dayChanged) {
            self::reconcileFutureBlocks($ctx, $reservationId);
        } else {
            self::moveFutureBlocksInPlace($ctx, $reservationId, $previousStartTime, $startTime, $durationMinutes);
        }

        // A renamed hold block renames its future blocks too, except any week
        // that was given its own title.
        if ($title !== $previousTitle) {
            self::pdo()->prepare(
                'UPDATE semester_hold_blocks SET title_override=NULL
                 WHERE semester_hold_block_reservation_id=? AND start_datetime > NOW()
                   AND (title_override IS NULL OR title_override = ?)'
            )->execute([$reservationId, $previousTitle]);
        }

        self::log($ctx, 'hold_block_reservation.updated', ['hold_block_reservation_id' => $reservationId]);
    }

    /**
     * Soft-delete a hold block reservation and remove its FUTURE blocks. Past
     * blocks stay as a record of how the teacher's day actually went.
     */
    public static function deleteHoldBlockReservation(?UserContext $ctx, int $reservationId): void {
        self::assertAdmin($ctx);
        $r = self::requireHoldBlockReservation($reservationId);
        if ($r['status'] === 'deleted') {
            return;
        }

        self::pdo()->prepare("UPDATE semester_hold_block_reservations SET status='deleted' WHERE id=?")
            ->execute([$reservationId]);
        self::deleteFutureBlocks($reservationId);

        self::log($ctx, 'hold_block_reservation.deleted', ['hold_block_reservation_id' => $reservationId]);
    }

    // ── Block generation ───────────────────────────────────────────────────

    /**
     * Materialize the reservation's blocks from the location's ACTIVE dates
     * matching its day_of_week. Idempotent — existing block dates are skipped.
     * Returns the number of blocks created.
     */
    public static function generateHoldBlocksForReservation(?UserContext $ctx, int $reservationId): int {
        self::assertAdmin($ctx);
        $r = self::requireHoldBlockReservation($reservationId);
        if ($r['status'] === 'deleted') {
            return 0;
        }

        $dates = SemesterManagement::activeDatesForLocationWeekday(
            (int)$r['semester_id'], (int)$r['location_id'], (int)$r['day_of_week']
        );
        if (!$dates) {
            return 0;
        }

        $st = self::pdo()->prepare(
            'SELECT DATE(start_datetime) AS d FROM semester_hold_blocks WHERE semester_hold_block_reservation_id=?'
        );
        $st->execute([$reservationId]);
        $existingSet = array_flip(array_column($st->fetchAll(), 'd'));

        $pdo = self::pdo();
        $created = 0;
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO semester_hold_blocks
                   (semester_hold_block_reservation_id, start_datetime, duration_minutes, created_by_user_id)
                 VALUES (?,?,?,?)'
            );
            foreach ($dates as $dateRow) {
                $date = (string)$dateRow['date'];
                if (isset($existingSet[$date])) {
                    continue;
                }
                $insert->execute([
                    $reservationId,
                    $date . ' ' . $r['start_time'],
                    (int)$r['duration_minutes'],
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
            self::log($ctx, 'hold_block_reservation.blocks_generated', [
                'hold_block_reservation_id' => $reservationId, 'created' => $created,
            ]);
        }
        return $created;
    }

    /**
     * After the location's date calendar changes, bring every active hold
     * block reservation at that location back in sync: drop FUTURE blocks on
     * dates that are no longer active, add newly-active ones.
     */
    public static function resyncHoldBlocksForLocation(?UserContext $ctx, int $semesterId, int $locationId): void {
        self::assertAdmin($ctx);
        $st = self::pdo()->prepare(
            "SELECT id FROM semester_hold_block_reservations
             WHERE semester_id=? AND location_id=? AND status='active'"
        );
        $st->execute([$semesterId, $locationId]);
        foreach ($st->fetchAll() as $r) {
            self::reconcileFutureBlocks($ctx, (int)$r['id']);
        }
        self::log($ctx, 'hold_block_reservation.blocks_resynced', [
            'semester_id' => $semesterId, 'location_id' => $locationId,
        ]);
    }

    // ── Queries ────────────────────────────────────────────────────────────

    public static function findHoldBlockReservation(int $reservationId): ?array {
        $st = self::pdo()->prepare(self::RESERVATION_SELECT . ' WHERE hr.id=? LIMIT 1');
        $st->execute([$reservationId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** The semester's live hold block reservations — the grid's hold cells. */
    public static function holdBlockReservationsForSemester(int $semesterId): array {
        $st = self::pdo()->prepare(
            self::RESERVATION_SELECT .
            " WHERE hr.semester_id=? AND hr.status='active'
              ORDER BY hr.day_of_week, hr.start_time"
        );
        $st->execute([$semesterId]);
        return $st->fetchAll();
    }

    /** Materialized blocks in a date range (for the calendar week views). */
    public static function holdBlocksBetween(string $fromDate, string $toDate, ?int $semesterId = null): array {
        $sql = self::BLOCK_SELECT . ' WHERE DATE(b.start_datetime) BETWEEN ? AND ?';
        $params = [$fromDate, $toDate];
        if ($semesterId !== null) {
            $sql .= ' AND hr.semester_id = ?';
            $params[] = $semesterId;
        }
        $st = self::pdo()->prepare($sql . ' ORDER BY b.start_datetime');
        $st->execute($params);
        return $st->fetchAll();
    }

    /** One teacher's blocks in a date range (their own week view). */
    public static function holdBlocksBetweenForTeacher(int $teacherUserId, string $fromDate, string $toDate): array {
        $st = self::pdo()->prepare(
            self::BLOCK_SELECT .
            ' WHERE DATE(b.start_datetime) BETWEEN ? AND ? AND hr.teacher_user_id = ?
              ORDER BY b.start_datetime'
        );
        $st->execute([$fromDate, $toDate, $teacherUserId]);
        return $st->fetchAll();
    }

    /** A teacher's blocks on one date, time order (slot-availability checks). */
    public static function holdBlocksForTeacherOnDate(int $teacherUserId, string $date): array {
        $st = self::pdo()->prepare(
            self::BLOCK_SELECT .
            ' WHERE DATE(b.start_datetime) = ? AND hr.teacher_user_id = ?
              ORDER BY b.start_datetime'
        );
        $st->execute([$date, $teacherUserId]);
        return $st->fetchAll();
    }

    public static function getBlock(int $blockId): ?array {
        $st = self::pdo()->prepare(self::BLOCK_SELECT . ' WHERE b.id=? LIMIT 1');
        $st->execute([$blockId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // ── Single occurrences ─────────────────────────────────────────────────
    //
    // One week of a standing hold block, edited on the weekly calendar. These
    // touch only that row; the reservation and its other weeks are untouched.
    // A retimed week stops following the standing schedule — the reservation's
    // in-place move deliberately leaves it alone afterwards.

    /** Move one block to another time on its own day. */
    public static function rescheduleBlockWithinDay(?UserContext $ctx, int $blockId, string $newStartTime): void {
        self::assertAdmin($ctx);
        $block = self::requireBlock($blockId);
        $newStartTime = self::normalizeTime($newStartTime);
        $newStart = date('Y-m-d', strtotime((string)$block['start_datetime'])) . ' ' . $newStartTime;

        $conflict = ScheduleConflicts::occurrenceConflict(
            (int)$block['teacher_user_id'], $newStart, (int)$block['duration_minutes'], null, $blockId
        );
        if ($conflict !== null) {
            throw new InvalidArgumentException($conflict);
        }

        self::pdo()->prepare('UPDATE semester_hold_blocks SET start_datetime=? WHERE id=?')
            ->execute([$newStart, $blockId]);
        self::log($ctx, 'hold_block.rescheduled', ['hold_block_id' => $blockId, 'start_datetime' => $newStart]);
    }

    /**
     * Give one week its own title, or clear the override so it follows the
     * reservation's standing title again. A title matching the standing one
     * is stored as no override at all.
     */
    public static function setBlockTitleOverride(?UserContext $ctx, int $blockId, ?string $title): void {
        self::assertAdmin($ctx);
        $block = self::requireBlock($blockId);

        $override = $title === null || trim($title) === '' ? null : self::normalizeTitle($title);
        if ($override === (string)$block['title']) {
            $override = null;
        }

        self::pdo()->prepare('UPDATE semester_hold_blocks SET title_override=? WHERE id=?')
            ->execute([$override, $blockId]);
        self::log($ctx, 'hold_block.title_overridden', [
            'hold_block_id' => $blockId, 'title_override' => $override,
        ]);
    }

    /** Drop a single week ("no lunch this week"); the reservation stays. */
    public static function deleteBlock(?UserContext $ctx, int $blockId): void {
        self::assertAdmin($ctx);
        self::requireBlock($blockId);
        self::pdo()->prepare('DELETE FROM semester_hold_blocks WHERE id=?')->execute([$blockId]);
        self::log($ctx, 'hold_block.deleted', ['hold_block_id' => $blockId]);
    }

    // ── internals ─────────────────────────────────────────────────────────

    private static function requireBlock(int $blockId): array {
        $block = self::getBlock($blockId);
        if (!$block) {
            throw new InvalidArgumentException('Hold block not found.');
        }
        return $block;
    }

    private static function requireHoldBlockReservation(int $reservationId): array {
        $st = self::pdo()->prepare('SELECT * FROM semester_hold_block_reservations WHERE id=? LIMIT 1');
        $st->execute([$reservationId]);
        $row = $st->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Hold block not found.');
        }
        return $row;
    }

    /** Shared validation for create and update. */
    private static function assertSlotIsValid(
        int $semesterId,
        int $locationId,
        int $teacherUserId,
        int $dayOfWeek,
        string $startTime,
        int $durationMinutes,
        ?int $excludeReservationId
    ): void {
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new InvalidArgumentException('Day of week must be 0 (Sunday) through 6 (Saturday).');
        }
        if ($durationMinutes <= 0 || $durationMinutes > 240) {
            throw new InvalidArgumentException('Duration looks invalid.');
        }
        if (!SemesterManagement::isTeacherAtLocation($semesterId, $locationId, $teacherUserId)) {
            throw new InvalidArgumentException('That teacher is not assigned to that location this semester.');
        }
        $conflict = ScheduleConflicts::weeklySlotConflict(
            $semesterId, $locationId, $teacherUserId, $dayOfWeek, $startTime, $durationMinutes,
            null, $excludeReservationId
        );
        if ($conflict !== null) {
            throw new InvalidArgumentException($conflict);
        }
    }

    /**
     * Retime the reservation's FUTURE blocks in place, preserving each week's
     * title_override. Two blocks are left where they are: one whose time no
     * longer matches the reservation's PREVIOUS time (someone moved that week
     * by hand), and one that would land on top of something else.
     */
    private static function moveFutureBlocksInPlace(
        ?UserContext $ctx,
        int $reservationId,
        string $previousStartTime,
        string $newStartTime,
        int $newDurationMinutes
    ): void {
        $st = self::pdo()->prepare(
            'SELECT b.id, DATE(b.start_datetime) AS d, TIME(b.start_datetime) AS t, hr.teacher_user_id
             FROM semester_hold_blocks b
             JOIN semester_hold_block_reservations hr ON hr.id = b.semester_hold_block_reservation_id
             WHERE b.semester_hold_block_reservation_id=? AND b.start_datetime > NOW()
             ORDER BY b.start_datetime'
        );
        $st->execute([$reservationId]);
        $blocks = $st->fetchAll();

        $update = self::pdo()->prepare(
            'UPDATE semester_hold_blocks SET start_datetime=?, duration_minutes=? WHERE id=?'
        );
        $moved = 0;
        $keptCustomized = 0;
        $keptConflicting = 0;

        foreach ($blocks as $block) {
            if ((string)$block['t'] !== $previousStartTime) {
                $keptCustomized++;
                continue;
            }
            $newStart = (string)$block['d'] . ' ' . $newStartTime;
            $conflict = ScheduleConflicts::occurrenceConflict(
                (int)$block['teacher_user_id'], $newStart, $newDurationMinutes, null, (int)$block['id']
            );
            if ($conflict !== null) {
                $keptConflicting++;
                continue;
            }
            $update->execute([$newStart, $newDurationMinutes, (int)$block['id']]);
            $moved++;
        }

        if ($moved || $keptCustomized || $keptConflicting) {
            self::log($ctx, 'hold_block_reservation.blocks_moved', [
                'hold_block_reservation_id' => $reservationId, 'moved' => $moved,
                'kept_customized' => $keptCustomized, 'kept_conflicting' => $keptConflicting,
            ]);
        }
    }

    /**
     * Drop FUTURE blocks whose date is no longer an active matching date,
     * then generate the ones that are missing. Used when the day of week
     * changes and when the location's calendar is re-imported.
     */
    private static function reconcileFutureBlocks(?UserContext $ctx, int $reservationId): void {
        $r = self::requireHoldBlockReservation($reservationId);
        $activeSet = array_flip(array_column(
            SemesterManagement::activeDatesForLocationWeekday(
                (int)$r['semester_id'], (int)$r['location_id'], (int)$r['day_of_week']
            ),
            'date'
        ));

        $st = self::pdo()->prepare(
            'SELECT id, DATE(start_datetime) AS d FROM semester_hold_blocks
             WHERE semester_hold_block_reservation_id=? AND start_datetime > NOW()'
        );
        $st->execute([$reservationId]);
        foreach ($st->fetchAll() as $block) {
            if (!isset($activeSet[$block['d']])) {
                self::pdo()->prepare('DELETE FROM semester_hold_blocks WHERE id=?')->execute([(int)$block['id']]);
            }
        }

        self::generateHoldBlocksForReservation($ctx, $reservationId);
    }

    private static function deleteFutureBlocks(int $reservationId): void {
        self::pdo()->prepare(
            'DELETE FROM semester_hold_blocks
             WHERE semester_hold_block_reservation_id=? AND start_datetime > NOW()'
        )->execute([$reservationId]);
    }

    private static function normalizeTitle(string $title): string {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('A title is required (for example "Lunch").');
        }
        if (mb_strlen($title) > 200) {
            throw new InvalidArgumentException('That title is too long.');
        }
        return $title;
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
