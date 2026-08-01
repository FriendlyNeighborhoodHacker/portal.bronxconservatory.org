<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

// Semesters and their setup data: which locations are active for a semester,
// the per-location class-date calendar, and which teachers teach at which
// location (the columns of the Semester Schedule grid).
class SemesterManagement {

    public const SEASONS = ['fall', 'spring', 'summer', 'test'];

    private static function pdo(): PDO {
        return pdo();
    }

    // ── Semesters ─────────────────────────────────────────────────────────

    public static function createSemester(?UserContext $ctx, string $season, int $year, string $startDate, string $endDate): int {
        self::assertAdmin($ctx);
        [$season, $year, $start, $end] = self::validateSemesterFields($season, $year, $startDate, $endDate);
        try {
            self::pdo()->prepare(
                'INSERT INTO semesters (season, year, start_date, end_date, created_by_user_id) VALUES (?,?,?,?,?)'
            )->execute([$season, $year, $start, $end, $ctx?->id]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new InvalidArgumentException(ucfirst($season) . ' ' . $year . ' already exists.');
            }
            throw $e;
        }
        $id = (int)self::pdo()->lastInsertId();
        self::log($ctx, 'semester.created', ['semester_id' => $id, 'season' => $season, 'year' => $year]);
        return $id;
    }

    /**
     * Edit a semester's identity and date range. The dates here are only used
     * to resolve the "current" semester and to sanity-check imports — the
     * class calendar that actually drives lessons is semester_location_dates,
     * so nothing needs to be regenerated when they change.
     */
    public static function updateSemester(
        ?UserContext $ctx,
        int $semesterId,
        string $season,
        int $year,
        string $startDate,
        string $endDate
    ): void {
        self::assertAdmin($ctx);
        if (!self::find($semesterId)) {
            throw new InvalidArgumentException('Semester not found.');
        }
        [$season, $year, $start, $end] = self::validateSemesterFields($season, $year, $startDate, $endDate);
        try {
            self::pdo()->prepare('UPDATE semesters SET season=?, year=?, start_date=?, end_date=? WHERE id=?')
                ->execute([$season, $year, $start, $end, $semesterId]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new InvalidArgumentException(ucfirst($season) . ' ' . $year . ' already exists.');
            }
            throw $e;
        }
        self::log($ctx, 'semester.updated', [
            'semester_id' => $semesterId, 'season' => $season, 'year' => $year,
        ]);
    }

    /** How many of the semester's class dates fall outside the given range. */
    public static function countLocationDatesOutsideRange(int $semesterId, string $startDate, string $endDate): int {
        $st = self::pdo()->prepare(
            'SELECT COUNT(*) FROM semester_location_dates WHERE semester_id=? AND (date < ? OR date > ?)'
        );
        $st->execute([$semesterId, $startDate, $endDate]);
        return (int)$st->fetchColumn();
    }

    /** Shared create/update validation. Returns [season, year, start, end]. */
    private static function validateSemesterFields(string $season, int $year, string $startDate, string $endDate): array {
        $season = strtolower(trim($season));
        if (!in_array($season, self::SEASONS, true)) {
            throw new InvalidArgumentException('Season must be one of: ' . implode(', ', self::SEASONS) . '.');
        }
        if ($year < 2000 || $year > 2200) {
            throw new InvalidArgumentException('Year looks invalid.');
        }
        $start = self::normalizeDate($startDate, 'Start date');
        $end = self::normalizeDate($endDate, 'End date');
        if ($end < $start) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }
        return [$season, $year, $start, $end];
    }

    public static function find(int $semesterId): ?array {
        $st = self::pdo()->prepare('SELECT * FROM semesters WHERE id=? LIMIT 1');
        $st->execute([$semesterId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** All semesters, newest first (for the semester selector). */
    public static function listSemesters(): array {
        return self::pdo()->query('SELECT * FROM semesters ORDER BY start_date DESC, id DESC')->fetchAll();
    }

    /**
     * Semesters that have not finished yet — the one in progress plus every
     * one already planned — earliest first. The personal calendars show all
     * of these at once, so a family can see next semester as soon as its
     * dates are entered.
     */
    public static function currentAndFutureSemesters(?string $today = null): array {
        $st = self::pdo()->prepare(
            'SELECT * FROM semesters WHERE end_date >= ? ORDER BY start_date, id'
        );
        $st->execute([$today ?? date('Y-m-d')]);
        return $st->fetchAll();
    }

    /**
     * The semester immediately before this one — the most recent one that
     * started earlier. Used as the default source when carrying a schedule
     * forward into a new semester.
     */
    public static function previousSemester(int $semesterId): ?array {
        $semester = self::find($semesterId);
        if (!$semester) {
            return null;
        }
        $st = self::pdo()->prepare(
            'SELECT * FROM semesters
             WHERE id <> ? AND (start_date < ? OR (start_date = ? AND id < ?))
             ORDER BY start_date DESC, id DESC LIMIT 1'
        );
        $st->execute([$semesterId, $semester['start_date'], $semester['start_date'], $semesterId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function hasAnySemester(): bool {
        return (int)self::pdo()->query('SELECT COUNT(*) FROM semesters')->fetchColumn() > 0;
    }

    /**
     * The semester admin pages should default to: the semester containing
     * today; else the next one in the future; else the most recent past one.
     * 'test' semesters are ignored unless nothing else exists.
     */
    public static function resolveDefaultSemester(?string $today = null): ?array {
        $today = $today ?? date('Y-m-d');
        foreach ([true, false] as $excludeTest) {
            $where = $excludeTest ? " AND season <> 'test'" : '';

            $st = self::pdo()->prepare(
                "SELECT * FROM semesters WHERE start_date <= ? AND end_date >= ?{$where}
                 ORDER BY start_date LIMIT 1"
            );
            $st->execute([$today, $today]);
            if ($row = $st->fetch()) return $row;

            $st = self::pdo()->prepare(
                "SELECT * FROM semesters WHERE start_date > ?{$where} ORDER BY start_date ASC LIMIT 1"
            );
            $st->execute([$today]);
            if ($row = $st->fetch()) return $row;

            $st = self::pdo()->prepare(
                "SELECT * FROM semesters WHERE 1=1{$where} ORDER BY end_date DESC LIMIT 1"
            );
            $st->execute();
            if ($row = $st->fetch()) return $row;
        }
        return null;
    }

    /** "Fall 2026" — the display label used everywhere. */
    public static function label(array $semester): string {
        return ucfirst((string)$semester['season']) . ' ' . (int)$semester['year'];
    }

    // ── Active locations (wizard step 2) ──────────────────────────────────

    /** Replace the semester's active-location set (diff-and-apply). */
    public static function setActiveLocations(?UserContext $ctx, int $semesterId, array $locationIds): void {
        self::assertAdmin($ctx);
        $locationIds = array_values(array_unique(array_map('intval', $locationIds)));
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $current = array_map('intval', array_column(self::activeLocations($semesterId), 'id'));
            foreach (array_diff($current, $locationIds) as $removeId) {
                $pdo->prepare('DELETE FROM semester_locations WHERE semester_id=? AND location_id=?')
                    ->execute([$semesterId, $removeId]);
            }
            foreach (array_diff($locationIds, $current) as $addId) {
                $pdo->prepare('INSERT IGNORE INTO semester_locations (semester_id, location_id) VALUES (?,?)')
                    ->execute([$semesterId, $addId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::log($ctx, 'semester.locations_set', ['semester_id' => $semesterId, 'location_ids' => $locationIds]);
    }

    /** The semester's active locations, ordered by name. */
    public static function activeLocations(int $semesterId): array {
        $st = self::pdo()->prepare(
            'SELECT l.* FROM semester_locations sl
             JOIN locations l ON l.id = sl.location_id
             WHERE sl.semester_id = ?
             ORDER BY l.name'
        );
        $st->execute([$semesterId]);
        return $st->fetchAll();
    }

    // ── Location dates (wizard step 3, CSV-imported) ──────────────────────

    /**
     * Insert or update one class date for a location. Returns the row id.
     * Note: after bulk edits, callers should resync confirmed reservations'
     * lessons via ReservationManagement::resyncLessonsForLocation.
     */
    public static function upsertLocationDate(
        ?UserContext $ctx,
        int $semesterId,
        int $locationId,
        string $date,
        string $startTime,
        string $endTime,
        string $status,
        ?string $title
    ): int {
        self::assertAdmin($ctx);
        $date = self::normalizeDate($date, 'Date');
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Status must be active or inactive.');
        }
        $title = $title !== null && trim($title) !== '' ? trim($title) : null;
        self::pdo()->prepare(
            'INSERT INTO semester_location_dates
               (semester_id, location_id, date, start_time, end_time, status, title, created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               start_time=VALUES(start_time), end_time=VALUES(end_time),
               status=VALUES(status), title=VALUES(title)'
        )->execute([$semesterId, $locationId, $date, $startTime, $endTime, $status, $title, $ctx?->id]);

        $st = self::pdo()->prepare(
            'SELECT id FROM semester_location_dates WHERE semester_id=? AND location_id=? AND date=?'
        );
        $st->execute([$semesterId, $locationId, $date]);
        $id = (int)$st->fetchColumn();
        self::log($ctx, 'semester.location_date_upserted', [
            'semester_id' => $semesterId, 'location_id' => $locationId, 'date' => $date, 'status' => $status,
        ]);
        return $id;
    }

    public static function deleteLocationDate(?UserContext $ctx, int $locationDateId): void {
        self::assertAdmin($ctx);
        self::pdo()->prepare('DELETE FROM semester_location_dates WHERE id=?')->execute([$locationDateId]);
        self::log($ctx, 'semester.location_date_deleted', ['location_date_id' => $locationDateId]);
    }

    /** All class dates for a semester (optionally one location), date order. */
    public static function locationDates(int $semesterId, ?int $locationId = null): array {
        $sql = 'SELECT sld.*, l.name AS location_name
                FROM semester_location_dates sld
                JOIN locations l ON l.id = sld.location_id
                WHERE sld.semester_id = ?';
        $params = [$semesterId];
        if ($locationId !== null) {
            $sql .= ' AND sld.location_id = ?';
            $params[] = $locationId;
        }
        $st = self::pdo()->prepare($sql . ' ORDER BY sld.date, l.name');
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * The semester's class dates as a chronological list, for the
     * semester-wide view. Locations that share a date, status AND title are
     * consolidated into one entry; a date where locations disagree on either
     * (one is a holiday, or they call it something different) stays split, so
     * the difference is visible rather than averaged away.
     *
     * Each entry: date, status, title, locations [[name, start_time,
     * end_time], ...], and uniform_time (true when every location in the
     * group keeps the same hours, which lets the view show one time range).
     */
    public static function locationDatesGroupedForSemester(int $semesterId): array {
        $groups = [];
        foreach (self::locationDates($semesterId) as $row) {
            $title = (string)($row['title'] ?? '');
            $key = $row['date'] . '|' . $row['status'] . '|' . $title;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'date' => (string)$row['date'],
                    'status' => (string)$row['status'],
                    'title' => $title,
                    'locations' => [],
                    'uniform_time' => true,
                    'start_time' => (string)$row['start_time'],
                    'end_time' => (string)$row['end_time'],
                ];
            }
            if ((string)$row['start_time'] !== $groups[$key]['start_time']
                || (string)$row['end_time'] !== $groups[$key]['end_time']) {
                $groups[$key]['uniform_time'] = false;
            }
            $groups[$key]['locations'][] = [
                'name' => (string)$row['location_name'],
                'start_time' => (string)$row['start_time'],
                'end_time' => (string)$row['end_time'],
            ];
        }

        // locationDates() is already ordered by date; keep that, and put an
        // active listing before an inactive one when a date has both.
        $out = array_values($groups);
        usort($out, function (array $a, array $b): int {
            return [$a['date'], $a['status'], $a['title']] <=> [$b['date'], $b['status'], $b['title']];
        });
        return $out;
    }

    /**
     * The location's ACTIVE dates falling on the given weekday, date order —
     * the input to lesson generation. $dayOfWeek uses PHP date('w'): 0=Sunday.
     */
    public static function activeDatesForLocationWeekday(int $semesterId, int $locationId, int $dayOfWeek): array {
        return self::datesForLocationWeekday($semesterId, $locationId, $dayOfWeek, 'active');
    }

    /** The INACTIVE (break/holiday) dates on the given weekday — shown to students. */
    public static function inactiveDatesForLocationWeekday(int $semesterId, int $locationId, int $dayOfWeek): array {
        return self::datesForLocationWeekday($semesterId, $locationId, $dayOfWeek, 'inactive');
    }

    private static function datesForLocationWeekday(int $semesterId, int $locationId, int $dayOfWeek, string $status): array {
        // MySQL DAYOFWEEK() is 1=Sunday..7=Saturday; minus 1 matches PHP date('w').
        $st = self::pdo()->prepare(
            'SELECT * FROM semester_location_dates
             WHERE semester_id=? AND location_id=? AND status=? AND DAYOFWEEK(date) - 1 = ?
             ORDER BY date'
        );
        $st->execute([$semesterId, $locationId, $status, $dayOfWeek]);
        return $st->fetchAll();
    }

    // ── Location teachers (wizard step 4, CSV-imported) ───────────────────

    /**
     * Replace the semester's (location, teacher) assignments with the given
     * pairs: [[locationId, teacherUserId], ...]. Column order within a
     * location follows the order pairs are given.
     */
    public static function setLocationTeachers(?UserContext $ctx, int $semesterId, array $pairs): void {
        self::assertAdmin($ctx);
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM semester_location_teachers WHERE semester_id=?')->execute([$semesterId]);
            $sortOrder = [];
            $st = $pdo->prepare(
                'INSERT IGNORE INTO semester_location_teachers
                   (semester_id, location_id, teacher_user_id, sort_order) VALUES (?,?,?,?)'
            );
            foreach ($pairs as $pair) {
                $locationId = (int)($pair[0] ?? 0);
                $teacherUserId = (int)($pair[1] ?? 0);
                if ($locationId <= 0 || $teacherUserId <= 0) {
                    throw new InvalidArgumentException('Each pair needs a location id and a teacher user id.');
                }
                $sortOrder[$locationId] = ($sortOrder[$locationId] ?? 0) + 1;
                $st->execute([$semesterId, $locationId, $teacherUserId, $sortOrder[$locationId]]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::log($ctx, 'semester.location_teachers_set', ['semester_id' => $semesterId, 'pairs' => count($pairs)]);
    }

    /** Add one (location, teacher) assignment, keeping existing ones. */
    public static function addLocationTeacher(?UserContext $ctx, int $semesterId, int $locationId, int $teacherUserId): void {
        self::assertAdmin($ctx);
        $st = self::pdo()->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM semester_location_teachers
             WHERE semester_id=? AND location_id=?'
        );
        $st->execute([$semesterId, $locationId]);
        $next = (int)$st->fetchColumn();
        self::pdo()->prepare(
            'INSERT IGNORE INTO semester_location_teachers
               (semester_id, location_id, teacher_user_id, sort_order) VALUES (?,?,?,?)'
        )->execute([$semesterId, $locationId, $teacherUserId, $next]);
        self::log($ctx, 'semester.location_teacher_added', [
            'semester_id' => $semesterId, 'location_id' => $locationId, 'teacher_user_id' => $teacherUserId,
        ]);
    }

    /**
     * The semester's (location, teacher) pairs — the Semester Schedule grid's
     * column spine. Ordered by location name, then column sort order.
     */
    public static function locationTeachers(int $semesterId): array {
        $st = self::pdo()->prepare(
            'SELECT slt.*, l.name AS location_name,
                    u.first_name AS teacher_first_name, u.last_name AS teacher_last_name,
                    u.preferred_name AS teacher_preferred_name
             FROM semester_location_teachers slt
             JOIN locations l ON l.id = slt.location_id
             JOIN users u ON u.id = slt.teacher_user_id
             WHERE slt.semester_id = ?
             ORDER BY l.name, slt.sort_order, u.last_name'
        );
        $st->execute([$semesterId]);
        return $st->fetchAll();
    }

    /** Is this teacher assigned to this location for this semester? */
    public static function isTeacherAtLocation(int $semesterId, int $locationId, int $teacherUserId): bool {
        $st = self::pdo()->prepare(
            'SELECT COUNT(*) FROM semester_location_teachers
             WHERE semester_id=? AND location_id=? AND teacher_user_id=?'
        );
        $st->execute([$semesterId, $locationId, $teacherUserId]);
        return (int)$st->fetchColumn() > 0;
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private static function normalizeDate(string $date, string $label): string {
        $date = trim($date);
        $ts = strtotime($date);
        if ($date === '' || $ts === false) {
            throw new InvalidArgumentException($label . ' is not a valid date.');
        }
        return date('Y-m-d', $ts);
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
