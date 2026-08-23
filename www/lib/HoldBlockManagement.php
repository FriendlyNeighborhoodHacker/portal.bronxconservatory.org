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

    /**
     * Hold blocks sit on the schedule grid's half-hour rows, so their length
     * is always a whole number of rows. These are the durations the modal
     * offers; anything else divisible by 30 is still accepted (the CSV import
     * derives it from a start/end time).
     */
    public const DURATION_OPTIONS = [30, 60, 90, 120];
    public const MAX_DURATION_MINUTES = 240;

    /**
     * What kind of time the block holds. 'hold' is the teacher's own unpaid
     * time (lunch, an errand); the others are paid group classes taught in
     * the slot — the distinction accounting and attendance care about, and
     * each type has its own color on the schedule grids.
     */
    public const BLOCK_TYPES = [
        'hold' => 'Hold — unpaid (lunch, errand)',
        'guitar_ensemble' => 'Guitar Ensemble — paid class',
        'musicianship' => 'Musicianship Skills — paid class',
    ];

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

    /**
     * A materialized block plus everything it inherits from its reservation.
     *
     * A one-off hold has no reservation, so its semester, teacher and location
     * are read off the block itself and its title lives in title_override. The
     * reservation still wins where there is one, so existing rows are
     * unaffected. `is_ad_hoc` lets callers tell the two apart.
     */
    private const BLOCK_SELECT = "
        SELECT b.*,
               COALESCE(hr.semester_id, b.semester_id) AS semester_id,
               COALESCE(hr.teacher_user_id, b.teacher_user_id) AS teacher_user_id,
               COALESCE(hr.location_id, b.location_id) AS location_id,
               hr.day_of_week, hr.status AS reservation_status, hr.title,
               (b.semester_hold_block_reservation_id IS NULL) AS is_ad_hoc,
               COALESCE(b.title_override, hr.title) AS effective_title,
               COALESCE(hr.block_type, b.block_type, 'hold') AS effective_block_type,
               tu.first_name AS teacher_first_name, tu.last_name AS teacher_last_name,
               tu.preferred_name AS teacher_preferred_name,
               l.name AS location_name
        FROM semester_hold_blocks b
        LEFT JOIN semester_hold_block_reservations hr ON hr.id = b.semester_hold_block_reservation_id
        JOIN users tu ON tu.id = COALESCE(hr.teacher_user_id, b.teacher_user_id)
        JOIN locations l ON l.id = COALESCE(hr.location_id, b.location_id)
    ";

    // Filters cannot use the SELECT's aliases, so the same COALESCEs are
    // named here and reused verbatim.
    private const F_TEACHER = 'COALESCE(hr.teacher_user_id, b.teacher_user_id)';
    private const F_SEMESTER = 'COALESCE(hr.semester_id, b.semester_id)';

    private static function pdo(): PDO {
        return pdo();
    }

    // ── CRUD ───────────────────────────────────────────────────────────────

    /**
     * Create a hold block reservation and immediately materialize its blocks.
     * $fields: semester_id, teacher_user_id, location_id, day_of_week
     * (0=Sun..6=Sat), start_time ("HH:MM"), duration_minutes, title,
     * block_type (optional, defaults to 'hold').
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
        $blockType = self::normalizeBlockType((string)($fields['block_type'] ?? 'hold'));

        if ($semesterId <= 0 || !SemesterManagement::find($semesterId)) {
            throw new InvalidArgumentException('A valid semester is required.');
        }
        self::assertSlotIsValid($semesterId, $locationId, $teacherUserId, $dayOfWeek, $startTime, $durationMinutes, null);

        self::pdo()->prepare(
            'INSERT INTO semester_hold_block_reservations
               (semester_id, teacher_user_id, location_id, day_of_week, start_time,
                duration_minutes, title, block_type, created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $semesterId, $teacherUserId, $locationId, $dayOfWeek, $startTime,
            $durationMinutes, $title, $blockType, $ctx?->id,
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
        $blockType = self::normalizeBlockType((string)($fields['block_type'] ?? $r['block_type']));

        $semesterId = (int)$r['semester_id'];
        $previousStartTime = (string)$r['start_time'];
        $previousTitle = (string)$r['title'];
        $dayChanged = (int)$r['day_of_week'] !== $dayOfWeek;

        // Validate the WHOLE move before touching anything: only the blocks
        // that will actually move need their new moments to be free.
        $movingDates = $dayChanged ? null : array_map(
            fn(array $b) => (string)$b['d'],
            self::futureBlocksFollowingSchedule($reservationId, $previousStartTime)
        );
        self::assertSlotIsValid(
            $semesterId, $locationId, $teacherUserId, $dayOfWeek,
            $startTime, $durationMinutes, $reservationId, $movingDates
        );

        self::pdo()->prepare(
            'UPDATE semester_hold_block_reservations
             SET teacher_user_id=?, location_id=?, day_of_week=?, start_time=?, duration_minutes=?, title=?, block_type=?
             WHERE id=?'
        )->execute([$teacherUserId, $locationId, $dayOfWeek, $startTime, $durationMinutes, $title, $blockType, $reservationId]);

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
            $sql .= ' AND ' . self::F_SEMESTER . ' = ?';
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
            ' WHERE DATE(b.start_datetime) BETWEEN ? AND ? AND ' . self::F_TEACHER . ' = ?
              ORDER BY b.start_datetime'
        );
        $st->execute([$fromDate, $toDate, $teacherUserId]);
        return $st->fetchAll();
    }

    /** A teacher's blocks on one date, time order (slot-availability checks). */
    public static function holdBlocksForTeacherOnDate(int $teacherUserId, string $date): array {
        $st = self::pdo()->prepare(
            self::BLOCK_SELECT .
            ' WHERE DATE(b.start_datetime) = ? AND ' . self::F_TEACHER . ' = ?
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

    /**
     * Hold one slot on the calendar without a standing weekly reservation
     * behind it — the single afternoon out, not the every-week lunch.
     *
     * $fields: semester_id, teacher_user_id, location_id, start_datetime,
     * duration_minutes, title, block_type (optional, defaults to 'hold').
     * Returns the new block id.
     */
    public static function createAdHocHoldBlock(?UserContext $ctx, array $fields): int {
        self::assertAdmin($ctx);

        $semesterId = (int)($fields['semester_id'] ?? 0);
        $teacherUserId = (int)($fields['teacher_user_id'] ?? 0);
        $locationId = (int)($fields['location_id'] ?? 0);
        $duration = (int)($fields['duration_minutes'] ?? 30);
        $title = trim((string)($fields['title'] ?? ''));
        $blockType = self::normalizeBlockType((string)($fields['block_type'] ?? 'hold'));

        $start = strtotime((string)($fields['start_datetime'] ?? ''));
        if ($start === false) {
            throw new InvalidArgumentException('That is not a time we understand.');
        }
        $startDatetime = date('Y-m-d H:i:s', $start);

        if ($semesterId <= 0 || $teacherUserId <= 0 || $locationId <= 0) {
            throw new InvalidArgumentException('A one-off hold needs a semester, a teacher and a location.');
        }
        if ($title === '') {
            throw new InvalidArgumentException('Please say what this time is for.');
        }
        if ($duration <= 0 || $duration > 240) {
            throw new InvalidArgumentException('That length looks wrong.');
        }

        $conflict = ScheduleConflicts::occurrenceConflict($teacherUserId, $startDatetime, $duration);
        if ($conflict !== null) {
            throw new InvalidArgumentException($conflict);
        }

        // The title lives in title_override, which is where a block's own
        // title has always lived when it differs from its reservation's.
        self::pdo()->prepare(
            'INSERT INTO semester_hold_blocks
               (semester_hold_block_reservation_id, semester_id, teacher_user_id, location_id,
                start_datetime, duration_minutes, title_override, block_type, created_by_user_id)
             VALUES (NULL,?,?,?,?,?,?,?,?)'
        )->execute([
            $semesterId, $teacherUserId, $locationId,
            $startDatetime, $duration, $title, $blockType, $ctx->id,
        ]);
        $blockId = (int)self::pdo()->lastInsertId();

        self::log($ctx, 'hold_block.ad_hoc_created', [
            'hold_block_id' => $blockId, 'semester_id' => $semesterId,
            'teacher_user_id' => $teacherUserId, 'start_datetime' => $startDatetime,
        ]);
        return $blockId;
    }

    /** Was this block booked on its own, with no standing reservation? */
    public static function isAdHoc(array $block): bool {
        return empty($block['semester_hold_block_reservation_id']);
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
            (int)$block['teacher_user_id'], $newStart, (int)$block['duration_minutes'],
            ['hold_block_id' => $blockId]
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

    /**
     * Shared validation for create and update. The slot must be free for this
     * teacher both as a standing weekly slot and on every future date it
     * would occupy — across every location, since a teacher cannot be in two
     * places at once. $onlyDates limits the per-date sweep to the blocks that
     * will actually move (null = every active date for the weekday).
     */
    private static function assertSlotIsValid(
        int $semesterId,
        int $locationId,
        int $teacherUserId,
        int $dayOfWeek,
        string $startTime,
        int $durationMinutes,
        ?int $excludeReservationId,
        ?array $onlyDates = null
    ): void {
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new InvalidArgumentException('Day of week must be 0 (Sunday) through 6 (Saturday).');
        }
        if ($durationMinutes <= 0 || $durationMinutes > self::MAX_DURATION_MINUTES) {
            throw new InvalidArgumentException(
                'Duration must be between 30 minutes and ' . (self::MAX_DURATION_MINUTES / 60) . ' hours.'
            );
        }
        if ($durationMinutes % 30 !== 0) {
            throw new InvalidArgumentException('Duration must be a whole number of half hours (30, 60, 90, 120).');
        }
        if (!SemesterManagement::isTeacherAtLocation($semesterId, $locationId, $teacherUserId)) {
            throw new InvalidArgumentException('That teacher is not assigned to that location this semester.');
        }

        $exclude = $excludeReservationId !== null
            ? ['hold_block_reservation_id' => $excludeReservationId]
            : [];

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
        $blocks = self::futureBlocksFollowingSchedule($reservationId, $previousStartTime);
        $update = self::pdo()->prepare(
            'UPDATE semester_hold_blocks SET start_datetime=?, duration_minutes=? WHERE id=?'
        );
        foreach ($blocks as $block) {
            $update->execute([
                (string)$block['d'] . ' ' . $newStartTime,
                $newDurationMinutes,
                (int)$block['id'],
            ]);
        }
        if ($blocks) {
            self::log($ctx, 'hold_block_reservation.blocks_moved', [
                'hold_block_reservation_id' => $reservationId, 'moved' => count($blocks),
            ]);
        }
    }

    /**
     * The reservation's future blocks still sitting at its standing time —
     * the ones a schedule-wide move carries along. A block at any other time
     * was moved by hand and is left alone.
     */
    private static function futureBlocksFollowingSchedule(int $reservationId, string $standingStartTime): array {
        $st = self::pdo()->prepare(
            'SELECT b.id, DATE(b.start_datetime) AS d
             FROM semester_hold_blocks b
             WHERE b.semester_hold_block_reservation_id=? AND b.start_datetime > NOW()
               AND TIME(b.start_datetime) = ?
             ORDER BY b.start_datetime'
        );
        $st->execute([$reservationId, $standingStartTime]);
        return $st->fetchAll();
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

    /**
     * The block type a title implies — how the CSV import and the migration
     * backfill classify blocks that only say what they are in words.
     */
    public static function blockTypeFromTitle(string $title): string {
        if (stripos($title, 'ensemble') !== false) {
            return 'guitar_ensemble';
        }
        if (stripos($title, 'musicianship') !== false) {
            return 'musicianship';
        }
        return 'hold';
    }

    private static function normalizeBlockType(string $blockType): string {
        $blockType = trim($blockType);
        if ($blockType === '') {
            return 'hold';
        }
        if (!isset(self::BLOCK_TYPES[$blockType])) {
            throw new InvalidArgumentException('That is not a kind of hold block we know.');
        }
        return $blockType;
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
