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

    /** Saturday — the day assumed when a semester's class dates say nothing yet. */
    public const DEFAULT_TEACHING_DAY = 6;

    private static function pdo(): PDO {
        return pdo();
    }

    // ── Semesters ─────────────────────────────────────────────────────────

    public static function createSemester(?UserContext $ctx, string $season, int $year, string $startDate, string $endDate, array $pricing = []): int {
        self::assertAdmin($ctx);
        [$season, $year, $start, $end] = self::validateSemesterFields($season, $year, $startDate, $endDate);
        $pricing = self::validatePricingFields($pricing);
        try {
            self::pdo()->prepare(
                'INSERT INTO semesters (season, year, start_date, end_date, registration_fee, lesson_fee_30_minutes, lesson_fee_60_minutes, guitar_ensemble_fee, lesson_fee_30_minutes_nonresident, lesson_fee_60_minutes_nonresident, guitar_ensemble_fee_nonresident, recital_fee, installment_plan_fee, lessons_per_semester, created_by_user_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $season, $year, $start, $end,
                $pricing['registration_fee'], $pricing['lesson_fee_30_minutes'], $pricing['lesson_fee_60_minutes'],
                $pricing['guitar_ensemble_fee'], $pricing['lesson_fee_30_minutes_nonresident'],
                $pricing['lesson_fee_60_minutes_nonresident'], $pricing['guitar_ensemble_fee_nonresident'],
                $pricing['recital_fee'], $pricing['installment_plan_fee'],
                $pricing['lessons_per_semester'], $ctx?->id
            ]);
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
     * Edit a semester's identity, date range, and pricing. The dates here are
     * only used to resolve the "current" semester and to sanity-check imports —
     * the class calendar that actually drives lessons is semester_location_dates,
     * so nothing needs to be regenerated when they change.
     */
    public static function updateSemester(
        ?UserContext $ctx,
        int $semesterId,
        string $season,
        int $year,
        string $startDate,
        string $endDate,
        array $pricing = []
    ): void {
        self::assertAdmin($ctx);
        if (!self::find($semesterId)) {
            throw new InvalidArgumentException('Semester not found.');
        }
        [$season, $year, $start, $end] = self::validateSemesterFields($season, $year, $startDate, $endDate);
        $pricing = self::validatePricingFields($pricing);
        try {
            self::pdo()->prepare(
                'UPDATE semesters SET season=?, year=?, start_date=?, end_date=?, registration_fee=?, lesson_fee_30_minutes=?, lesson_fee_60_minutes=?, guitar_ensemble_fee=?, lesson_fee_30_minutes_nonresident=?, lesson_fee_60_minutes_nonresident=?, guitar_ensemble_fee_nonresident=?, recital_fee=?, installment_plan_fee=?, lessons_per_semester=? WHERE id=?'
            )->execute([
                $season, $year, $start, $end,
                $pricing['registration_fee'], $pricing['lesson_fee_30_minutes'], $pricing['lesson_fee_60_minutes'],
                $pricing['guitar_ensemble_fee'], $pricing['lesson_fee_30_minutes_nonresident'],
                $pricing['lesson_fee_60_minutes_nonresident'], $pricing['guitar_ensemble_fee_nonresident'],
                $pricing['recital_fee'], $pricing['installment_plan_fee'],
                $pricing['lessons_per_semester'], $semesterId
            ]);
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

    /** Pricing field validation. Returns array with normalized (string) decimal values. */
    private static function validatePricingFields(array $pricing): array {
        $out = [
            'registration_fee' => '0.00',
            'lesson_fee_30_minutes' => '0.00',
            'lesson_fee_60_minutes' => '0.00',
            'guitar_ensemble_fee' => '0.00',
            'lesson_fee_30_minutes_nonresident' => '0.00',
            'lesson_fee_60_minutes_nonresident' => '0.00',
            'guitar_ensemble_fee_nonresident' => '0.00',
            'recital_fee' => '0.00',
            'installment_plan_fee' => '0.00',
            'lessons_per_semester' => 15,
        ];
        foreach (['registration_fee', 'lesson_fee_30_minutes', 'lesson_fee_60_minutes', 'guitar_ensemble_fee',
                  'lesson_fee_30_minutes_nonresident', 'lesson_fee_60_minutes_nonresident',
                  'guitar_ensemble_fee_nonresident', 'recital_fee', 'installment_plan_fee'] as $key) {
            $val = (string)($pricing[$key] ?? '0');
            $val = trim(str_replace(['$', ','], '', $val));
            if ($val === '' || !is_numeric($val)) {
                $val = '0.00';
            }
            $float = (float)$val;
            if ($float < 0) {
                throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $key)) . ' cannot be negative.');
            }
            $out[$key] = number_format($float, 2);
        }
        $lessons = (int)($pricing['lessons_per_semester'] ?? 15);
        if ($lessons <= 0) {
            throw new InvalidArgumentException('Lessons per semester must be positive.');
        }
        $out['lessons_per_semester'] = $lessons;
        return $out;
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

    // ── Pricing (moved from global Settings) ──────────────────────────────

    public static function registrationFeeCents(array $semester): int {
        return (int)round((float)($semester['registration_fee'] ?? 0) * 100);
    }

    public static function lessonFeeCents(array $semester, int $durationMinutes): int {
        $fee30 = (float)($semester['lesson_fee_30_minutes'] ?? 0);
        $fee60 = (float)($semester['lesson_fee_60_minutes'] ?? 0);
        if ($durationMinutes === 30) {
            return (int)round($fee30 * 100);
        }
        if ($durationMinutes === 60) {
            return (int)round($fee60 * 100);
        }
        // Prorate off the 30-minute rate for other durations (90, 120 min, etc.)
        $perMinute = $fee30 / 30;
        return (int)round($perMinute * $durationMinutes * 100);
    }

    public static function guitarEnsembleFeeCents(array $semester): int {
        return (int)round((float)($semester['guitar_ensemble_fee'] ?? 0) * 100);
    }

    public static function recitalFeeCents(array $semester): int {
        return (int)round((float)($semester['recital_fee'] ?? 0) * 100);
    }

    public static function installmentPlanFeeCents(array $semester): int {
        return (int)round((float)($semester['installment_plan_fee'] ?? 0) * 100);
    }

    public static function lessonsPerSemester(array $semester): int {
        $weeks = (int)($semester['lessons_per_semester'] ?? 15);
        return $weeks > 0 ? $weeks : 15;
    }

    // ── Active locations (wizard step 2) ──────────────────────────────────

    /**
     * Replace the semester's active-location set (diff-and-apply). Removing a
     * location also drops its declared weekdays.
     *
     * $weekdaysByLocation, when given, replaces each listed location's
     * declared weekdays in the same transaction:
     * [locationId => [[dayOfWeek, 'HH:MM', 'HH:MM'], ...], ...].
     */
    public static function setActiveLocations(
        ?UserContext $ctx, int $semesterId, array $locationIds, ?array $weekdaysByLocation = null
    ): void {
        self::assertAdmin($ctx);
        $locationIds = array_values(array_unique(array_map('intval', $locationIds)));
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $current = array_map('intval', array_column(self::activeLocations($semesterId), 'id'));
            foreach (array_diff($current, $locationIds) as $removeId) {
                $pdo->prepare('DELETE FROM semester_locations WHERE semester_id=? AND location_id=?')
                    ->execute([$semesterId, $removeId]);
                $pdo->prepare('DELETE FROM semester_location_weekdays WHERE semester_id=? AND location_id=?')
                    ->execute([$semesterId, $removeId]);
            }
            foreach (array_diff($locationIds, $current) as $addId) {
                $pdo->prepare('INSERT IGNORE INTO semester_locations (semester_id, location_id) VALUES (?,?)')
                    ->execute([$semesterId, $addId]);
            }
            if ($weekdaysByLocation !== null) {
                foreach ($locationIds as $locationId) {
                    self::replaceLocationWeekdays($semesterId, $locationId, $weekdaysByLocation[$locationId] ?? []);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::log($ctx, 'semester.locations_set', ['semester_id' => $semesterId, 'location_ids' => $locationIds]);
    }

    /**
     * Replace one location's declared weekdays:
     * $days = [[dayOfWeek, 'HH:MM', 'HH:MM'], ...].
     */
    public static function setLocationWeekdays(?UserContext $ctx, int $semesterId, int $locationId, array $days): void {
        self::assertAdmin($ctx);
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            self::replaceLocationWeekdays($semesterId, $locationId, $days);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::log($ctx, 'semester.location_weekdays_set', [
            'semester_id' => $semesterId, 'location_id' => $locationId, 'days' => array_column($days, 0),
        ]);
    }

    /**
     * Upsert one declared weekday — the class-days CSV import's write path.
     * Existing (location, day) rows get the new hours.
     */
    public static function upsertLocationWeekday(
        ?UserContext $ctx, int $semesterId, int $locationId, int $dayOfWeek, string $startTime, string $endTime
    ): void {
        self::assertAdmin($ctx);
        $start = self::normalizeTimeOfDay($startTime, 'Start time');
        $end = self::normalizeTimeOfDay($endTime, 'End time');
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new InvalidArgumentException('Day of week must be 0 (Sunday) through 6 (Saturday).');
        }
        if ($end <= $start) {
            throw new InvalidArgumentException('End time must be after the start time.');
        }
        self::pdo()->prepare(
            'INSERT INTO semester_location_weekdays (semester_id, location_id, day_of_week, start_time, end_time)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time)'
        )->execute([$semesterId, $locationId, $dayOfWeek, $start, $end]);
        self::log($ctx, 'semester.location_weekday_upserted', [
            'semester_id' => $semesterId, 'location_id' => $locationId, 'day_of_week' => $dayOfWeek,
            'start_time' => $start, 'end_time' => $end,
        ]);
    }

    /** The delete-and-insert behind setLocationWeekdays; caller holds the transaction. */
    private static function replaceLocationWeekdays(int $semesterId, int $locationId, array $days): void {
        $pdo = self::pdo();
        $pdo->prepare('DELETE FROM semester_location_weekdays WHERE semester_id=? AND location_id=?')
            ->execute([$semesterId, $locationId]);
        $st = $pdo->prepare(
            'INSERT INTO semester_location_weekdays (semester_id, location_id, day_of_week, start_time, end_time)
             VALUES (?,?,?,?,?)'
        );
        foreach ($days as $day) {
            $dayOfWeek = (int)($day[0] ?? -1);
            $start = self::normalizeTimeOfDay((string)($day[1] ?? ''), 'Start time');
            $end = self::normalizeTimeOfDay((string)($day[2] ?? ''), 'End time');
            if ($dayOfWeek < 0 || $dayOfWeek > 6) {
                throw new InvalidArgumentException('Day of week must be 0 (Sunday) through 6 (Saturday).');
            }
            if ($end <= $start) {
                throw new InvalidArgumentException('End time must be after the start time.');
            }
            $st->execute([$semesterId, $locationId, $dayOfWeek, $start, $end]);
        }
    }

    /** "9:00" / "09:00:00" -> "09:00:00", or throws. */
    private static function normalizeTimeOfDay(string $time, string $label): string {
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($time), $m) || (int)$m[1] > 23 || (int)$m[2] > 59) {
            throw new InvalidArgumentException($label . ' is not a valid time.');
        }
        return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)($m[3] ?? 0));
    }

    /**
     * The declared weekdays — which days each location holds classes, with
     * that day's standard hours. Ordered by location name, then day.
     */
    public static function locationWeekdays(int $semesterId, ?int $locationId = null): array {
        $sql = 'SELECT slw.*, l.name AS location_name
                FROM semester_location_weekdays slw
                JOIN locations l ON l.id = slw.location_id
                WHERE slw.semester_id = ?';
        $args = [$semesterId];
        if ($locationId !== null) {
            $sql .= ' AND slw.location_id = ?';
            $args[] = $locationId;
        }
        $st = self::pdo()->prepare($sql . ' ORDER BY l.name, slw.day_of_week');
        $st->execute($args);
        return $st->fetchAll();
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

    /**
     * The weekdays a location holds classes on this semester, sorted: the
     * union of its declared weekdays and the weekdays of its actual class
     * dates. The union matters both ways — a declared day renders and accepts
     * assignments before its dates are imported, and a one-off date on an odd
     * weekday (which upsertLocationDate deliberately allows) keeps counting.
     * Both active and inactive dates count: a location that meets Tuesdays is
     * open Tuesdays even in the week it takes a break.
     */
    public static function weekdaysForLocation(int $semesterId, int $locationId): array {
        $st = self::pdo()->prepare(
            'SELECT DISTINCT dow FROM (
                 SELECT day_of_week AS dow FROM semester_location_weekdays
                 WHERE semester_id=? AND location_id=?
                 UNION
                 SELECT DAYOFWEEK(date) - 1 AS dow FROM semester_location_dates
                 WHERE semester_id=? AND location_id=?
             ) days ORDER BY dow'
        );
        $st->execute([$semesterId, $locationId, $semesterId, $locationId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * When each class day opens and closes, in minutes since midnight:
     * [day => [opensAt, closesAt]]. Days the semester does not meet on are
     * absent. Per location a day's hours come from its declaration, falling
     * back to the widest of its actual class dates (a location added to the
     * semester after the wizard ran has dates but no declaration). Locations
     * sharing a day are then unioned — one building opening early widens the
     * day rather than hiding its own early hours. Callers snap this to
     * whatever grid they draw on.
     */
    public static function dayHoursForSemester(int $semesterId): array {
        // Per (location, day): declared hours where present…
        $declared = [];
        $st = self::pdo()->prepare(
            'SELECT location_id, day_of_week AS dow, start_time, end_time
             FROM semester_location_weekdays WHERE semester_id=?'
        );
        $st->execute([$semesterId]);
        foreach ($st->fetchAll() as $row) {
            $declared[$row['location_id'] . ':' . $row['dow']] =
                [self::minutesOfDay((string)$row['start_time']), self::minutesOfDay((string)$row['end_time'])];
        }
        // …else the widest of that location's dates on that day.
        $st = self::pdo()->prepare(
            'SELECT location_id, DAYOFWEEK(date) - 1 AS dow, MIN(start_time) AS opens, MAX(end_time) AS closes
             FROM semester_location_dates WHERE semester_id=? GROUP BY location_id, dow'
        );
        $st->execute([$semesterId]);
        $perLocation = $declared;
        foreach ($st->fetchAll() as $row) {
            $key = $row['location_id'] . ':' . $row['dow'];
            if (!isset($perLocation[$key])) {
                $perLocation[$key] =
                    [self::minutesOfDay((string)$row['opens']), self::minutesOfDay((string)$row['closes'])];
            }
        }
        // Union across locations per day.
        $hours = [];
        foreach ($perLocation as $key => [$opens, $closes]) {
            $day = (int)explode(':', $key)[1];
            $hours[$day] = isset($hours[$day])
                ? [min($hours[$day][0], $opens), max($hours[$day][1], $closes)]
                : [$opens, $closes];
        }
        ksort($hours);
        return $hours;
    }

    /** "17:00:00" -> 1020. */
    private static function minutesOfDay(string $time): int {
        [$h, $m] = array_map('intval', array_pad(explode(':', $time), 2, '0'));
        return $h * 60 + $m;
    }

    // ── Location teachers (wizard step 4, CSV-imported) ───────────────────

    /**
     * Replace the semester's (location, teacher) assignments with the given
     * pairs: [[locationId, teacherUserId, dayOfWeek?], ...]. Column order
     * within a location follows the order pairs are given. A pair with no day
     * is assigned to every weekday its location meets on — see
     * daysForAssignment().
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
                   (semester_id, location_id, teacher_user_id, day_of_week, sort_order) VALUES (?,?,?,?,?)'
            );
            foreach ($pairs as $pair) {
                $locationId = (int)($pair[0] ?? 0);
                $teacherUserId = (int)($pair[1] ?? 0);
                if ($locationId <= 0 || $teacherUserId <= 0) {
                    throw new InvalidArgumentException('Each pair needs a location id and a teacher user id.');
                }
                $day = isset($pair[2]) ? (int)$pair[2] : null;
                // One sort_order per teacher, shared across their days, so a
                // teacher sits in the same column position on every day.
                $key = $locationId . ':' . $teacherUserId;
                if (!isset($sortOrder[$key])) {
                    $sortOrder[$key] = count(array_filter(
                        array_keys($sortOrder),
                        fn(string $k): bool => str_starts_with($k, $locationId . ':')
                    )) + 1;
                }
                foreach (self::daysForAssignment($semesterId, $locationId, $day) as $dayOfWeek) {
                    $st->execute([$semesterId, $locationId, $teacherUserId, $dayOfWeek, $sortOrder[$key]]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::log($ctx, 'semester.location_teachers_set', ['semester_id' => $semesterId, 'pairs' => count($pairs)]);
    }

    /**
     * Add one (location, teacher, day) assignment, keeping existing ones.
     * $dayOfWeek null means every weekday the location meets on, which is how
     * a location-teachers CSV with no Day column behaves.
     */
    public static function addLocationTeacher(
        ?UserContext $ctx, int $semesterId, int $locationId, int $teacherUserId, ?int $dayOfWeek = null
    ): void {
        self::assertAdmin($ctx);
        // A teacher already holding a column here keeps its position on their
        // new day; a new teacher goes to the end.
        $st = self::pdo()->prepare(
            'SELECT MIN(sort_order) FROM semester_location_teachers
             WHERE semester_id=? AND location_id=? AND teacher_user_id=?'
        );
        $st->execute([$semesterId, $locationId, $teacherUserId]);
        $existing = $st->fetchColumn();
        if ($existing === null || $existing === false) {
            $st = self::pdo()->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM semester_location_teachers
                 WHERE semester_id=? AND location_id=?'
            );
            $st->execute([$semesterId, $locationId]);
            $existing = (int)$st->fetchColumn();
        }
        $insert = self::pdo()->prepare(
            'INSERT IGNORE INTO semester_location_teachers
               (semester_id, location_id, teacher_user_id, day_of_week, sort_order) VALUES (?,?,?,?,?)'
        );
        $days = self::daysForAssignment($semesterId, $locationId, $dayOfWeek);
        foreach ($days as $day) {
            $insert->execute([$semesterId, $locationId, $teacherUserId, $day, (int)$existing]);
        }
        self::log($ctx, 'semester.location_teacher_added', [
            'semester_id' => $semesterId, 'location_id' => $locationId,
            'teacher_user_id' => $teacherUserId, 'days' => $days,
        ]);
    }

    /**
     * Which days one assignment covers. A named day is taken as given; no day
     * means every weekday the location meets on, falling back to Saturday for
     * a location whose class dates have not been imported yet — the same
     * fallback the schedule grid has always used.
     */
    public static function daysForAssignment(int $semesterId, int $locationId, ?int $dayOfWeek): array {
        if ($dayOfWeek !== null) {
            return [$dayOfWeek];
        }
        return self::weekdaysForLocation($semesterId, $locationId) ?: [self::DEFAULT_TEACHING_DAY];
    }

    /**
     * The semester's (location, teacher) pairs — the Semester Schedule grid's
     * column spine. Ordered by location name, then column sort order.
     *
     * With a $dayOfWeek, only the teachers working that day, which is what
     * each of the grid's per-day tables is drawn from. Without one, one row
     * per (location, teacher) however many days they work — the shape every
     * caller that does not care about days expects.
     */
    public static function locationTeachers(int $semesterId, ?int $dayOfWeek = null): array {
        $sql = 'SELECT slt.*, l.name AS location_name,
                    u.first_name AS teacher_first_name, u.last_name AS teacher_last_name,
                    u.preferred_name AS teacher_preferred_name
             FROM semester_location_teachers slt
             JOIN locations l ON l.id = slt.location_id
             JOIN users u ON u.id = slt.teacher_user_id
             WHERE slt.semester_id = ?';
        $args = [$semesterId];
        if ($dayOfWeek !== null) {
            $sql .= ' AND slt.day_of_week = ?';
            $args[] = $dayOfWeek;
        }
        $st = self::pdo()->prepare($sql . ' ORDER BY l.name, slt.sort_order, u.last_name');
        $st->execute($args);
        $rows = $st->fetchAll();
        if ($dayOfWeek !== null) {
            return $rows;
        }
        // Collapse a teacher's several days back into one column. Done here
        // rather than with GROUP BY because slt.* would trip ONLY_FULL_GROUP_BY.
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = $row['location_id'] . ':' . $row['teacher_user_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * The grid's columns, widened to cover pairs that are not part of the
     * semester's assignments. A substitute may teach a week at a location
     * they have no column in; without this the lesson would simply not be
     * drawn. $pairs: [['location_id' => n, 'teacher_user_id' => n], ...].
     *
     * Added columns are marked is_extra = true and are appended to their
     * location's group, so locations stay contiguous (the grid's location
     * header spans depend on that).
     */
    public static function locationTeachersIncluding(array $columns, array $pairs): array {
        $known = [];
        foreach ($columns as $column) {
            $known[$column['location_id'] . ':' . $column['teacher_user_id']] = true;
        }
        $missing = [];
        foreach ($pairs as $pair) {
            $locationId = (int)($pair['location_id'] ?? 0);
            $teacherUserId = (int)($pair['teacher_user_id'] ?? 0);
            $key = $locationId . ':' . $teacherUserId;
            if ($locationId <= 0 || $teacherUserId <= 0 || isset($known[$key]) || isset($missing[$key])) {
                continue;
            }
            $missing[$key] = ['location_id' => $locationId, 'teacher_user_id' => $teacherUserId];
        }
        if (!$missing) {
            return $columns;
        }

        // Group by location, keeping the order locations already appear in,
        // then any brand-new location by name.
        $groups = [];
        foreach ($columns as $column) {
            $groups[(int)$column['location_id']][] = $column;
        }
        $newLocationIds = [];
        foreach ($missing as $pair) {
            $row = self::extraColumnRow($pair['location_id'], $pair['teacher_user_id']);
            if (!$row) {
                continue; // location or user vanished
            }
            if (!isset($groups[$pair['location_id']])) {
                $newLocationIds[$pair['location_id']] = (string)$row['location_name'];
            }
            $groups[$pair['location_id']][] = $row;
        }
        asort($newLocationIds);

        $out = [];
        foreach (array_keys($groups) as $locationId) {
            if (isset($newLocationIds[$locationId])) {
                continue;
            }
            foreach ($groups[$locationId] as $column) {
                $out[] = $column;
            }
        }
        foreach (array_keys($newLocationIds) as $locationId) {
            foreach ($groups[$locationId] as $column) {
                $out[] = $column;
            }
        }
        return $out;
    }

    /** One synthetic grid column, shaped like a locationTeachers() row. */
    private static function extraColumnRow(int $locationId, int $teacherUserId): ?array {
        $st = self::pdo()->prepare(
            'SELECT l.id AS location_id, l.name AS location_name,
                    u.id AS teacher_user_id, u.first_name AS teacher_first_name,
                    u.last_name AS teacher_last_name, u.preferred_name AS teacher_preferred_name
             FROM locations l JOIN users u ON u.id = ?
             WHERE l.id = ? LIMIT 1'
        );
        $st->execute([$teacherUserId, $locationId]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $row['semester_id'] = null;
        $row['sort_order'] = null;
        $row['is_extra'] = true;
        return $row;
    }

    /**
     * Every assignment as a "locationId:teacherId:day" set — the cheap way to
     * test many (pair, day) combinations at once.
     */
    public static function locationTeacherDayKeys(int $semesterId): array {
        $st = self::pdo()->prepare(
            'SELECT location_id, teacher_user_id, day_of_week FROM semester_location_teachers WHERE semester_id=?'
        );
        $st->execute([$semesterId]);
        $keys = [];
        foreach ($st->fetchAll() as $row) {
            $keys[$row['location_id'] . ':' . $row['teacher_user_id'] . ':' . $row['day_of_week']] = true;
        }
        return $keys;
    }

    /**
     * Is this teacher assigned to this location for this semester — and, when
     * a day is given, does that assignment cover that day?
     */
    public static function isTeacherAtLocation(
        int $semesterId, int $locationId, int $teacherUserId, ?int $dayOfWeek = null
    ): bool {
        $sql = 'SELECT COUNT(*) FROM semester_location_teachers
             WHERE semester_id=? AND location_id=? AND teacher_user_id=?';
        $args = [$semesterId, $locationId, $teacherUserId];
        if ($dayOfWeek !== null) {
            $sql .= ' AND day_of_week=?';
            $args[] = $dayOfWeek;
        }
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
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
