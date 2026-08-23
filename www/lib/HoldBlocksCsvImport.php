<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/SemesterManagement.php';
require_once __DIR__ . '/HoldBlockManagement.php';
require_once __DIR__ . '/ScheduleConflicts.php';
require_once __DIR__ . '/LocationDatesCsvImport.php';

// The hold-blocks CSV: a teacher's standing non-lesson time (lunch, an
// errand) at a location, one row per weekly slot. Creating each one
// materializes its blocks across the semester, exactly as the schedule grid's
// Hold Block tab does. $context requires ['semester_id' => n].
class HoldBlocksCsvImport {

    private const DAY_NAMES = [
        'sunday' => 0, 'sun' => 0,
        'monday' => 1, 'mon' => 1,
        'tuesday' => 2, 'tue' => 2, 'tues' => 2,
        'wednesday' => 3, 'wed' => 3,
        'thursday' => 4, 'thu' => 4, 'thur' => 4, 'thurs' => 4,
        'friday' => 5, 'fri' => 5,
        'saturday' => 6, 'sat' => 6,
    ];

    public static function targetFields(): array {
        return [
            'teacher_name' => 'Teacher Name',
            'location_name' => 'Location Name',
            'day' => 'Day',
            'start_time' => 'Start Time',
            'end_time' => 'End Time',
            'title' => 'Title',
        ];
    }

    public static function validateRows(array $mappedRows, array $context = []): array {
        $semesterId = (int)($context['semester_id'] ?? 0);
        if (!SemesterManagement::find($semesterId)) {
            throw new InvalidArgumentException('A semester is required for a hold-blocks import.');
        }

        $locationsByName = [];
        foreach (SemesterManagement::activeLocations($semesterId) as $location) {
            $locationsByName[self::norm((string)$location['name'])] = $location;
        }
        // (location, teacher) pairs that exist as grid columns this semester.
        $columns = [];
        foreach (SemesterManagement::locationTeachers($semesterId) as $pair) {
            $columns[$pair['location_id'] . ':' . $pair['teacher_user_id']] = true;
        }
        // Slots already taken, so a re-import reports "no change" rather than
        // erroring out on the conflict check.
        $existing = [];
        foreach (HoldBlockManagement::holdBlockReservationsForSemester($semesterId) as $hold) {
            $existing[self::slotKey((int)$hold['location_id'], (int)$hold['teacher_user_id'], (int)$hold['day_of_week'], (string)$hold['start_time'])] = true;
        }

        $out = [];
        $seen = [];
        // Slots this file has already claimed. The database can't see them
        // yet, but two rows in one file must not double-book a teacher either.
        $claimed = [];
        foreach ($mappedRows as $i => $row) {
            $messages = [];
            $status = 'valid';
            $changes = '';

            $locationName = trim((string)($row['location_name'] ?? ''));
            $location = $locationsByName[self::norm($locationName)] ?? null;
            if (!$location) {
                $status = 'error';
                $messages[] = $locationName === ''
                    ? 'Location name is required.'
                    : 'No match found for location "' . $locationName . '" among this semester\'s active locations.';
            }

            $teacherName = trim((string)($row['teacher_name'] ?? ''));
            $teacher = null;
            if ($teacherName === '') {
                $status = 'error';
                $messages[] = 'Teacher name is required.';
            } else {
                $matches = self::matchTeachersByName($teacherName);
                if (count($matches) === 0) {
                    $status = 'error';
                    $messages[] = 'No match found for teacher "' . $teacherName . '" — upload teachers first.';
                } elseif (count($matches) > 1) {
                    $status = 'error';
                    $messages[] = 'Multiple teachers match "' . $teacherName . '".';
                } else {
                    $teacher = $matches[0];
                }
            }

            if ($location && $teacher && !isset($columns[$location['id'] . ':' . $teacher['id']])) {
                $status = 'error';
                $messages[] = $teacherName . ' is not assigned to ' . $location['name']
                    . ' this semester — import location teachers first.';
            }

            $dayRaw = trim((string)($row['day'] ?? ''));
            $dayOfWeek = self::parseDayOfWeek($dayRaw);
            if ($dayOfWeek === null) {
                $status = 'error';
                $messages[] = $dayRaw === ''
                    ? 'Day is required (e.g. "Saturday").'
                    : 'Unknown day "' . $dayRaw . '" — use a weekday name like "Saturday".';
            }

            $startTime = LocationDatesCsvImport::parseTime((string)($row['start_time'] ?? ''));
            $endTime = LocationDatesCsvImport::parseTime((string)($row['end_time'] ?? ''));
            $duration = null;
            if ($startTime === null || $endTime === null) {
                $status = 'error';
                $messages[] = 'Start and end time are required (e.g. "12:00 pm").';
            } else {
                $duration = intdiv(strtotime('1970-01-01 ' . $endTime) - strtotime('1970-01-01 ' . $startTime), 60);
                if ($duration <= 0) {
                    $status = 'error';
                    $messages[] = 'End time must be after the start time.';
                } elseif ($duration > 240) {
                    $status = 'error';
                    $messages[] = 'A hold block cannot be longer than 4 hours.';
                }
            }

            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                $status = 'error';
                $messages[] = 'Title is required (e.g. "Lunch").';
            }

            if ($status !== 'error' && $location && $teacher && $dayOfWeek !== null
                && !SemesterManagement::isTeacherAtLocation($semesterId, (int)$location['id'], (int)$teacher['id'], $dayOfWeek)) {
                $status = 'error';
                $messages[] = ($teacher['first_name'] . ' ' . $teacher['last_name']) . ' does not teach at '
                    . $location['name'] . ' on ' . self::dayLabel($dayOfWeek)
                    . 's — check the day, or import a location-teachers row for it.';
            }

            if ($status !== 'error' && $location && $teacher && $dayOfWeek !== null && $duration !== null) {
                $key = self::slotKey((int)$location['id'], (int)$teacher['id'], $dayOfWeek, $startTime);
                if (isset($seen[$key])) {
                    $status = 'error';
                    $messages[] = 'Duplicate row for this teacher, location, day and time (row ' . $seen[$key] . ').';
                } elseif (isset($existing[$key])) {
                    $seen[$key] = $i + 1;
                    $changes = 'Already held (no change)';
                } else {
                    $seen[$key] = $i + 1;
                    // The same rule the schedule grid enforces: this teacher
                    // must be free, in every location and on every future
                    // date this row would occupy. Surfaced here so the
                    // validation table explains it before anything is written.
                    $conflict = self::fileSlotConflict($claimed, (int)$teacher['id'], $dayOfWeek, $startTime, $duration)
                        ?? ScheduleConflicts::weeklySlotConflict(
                            $semesterId, (int)$teacher['id'], $dayOfWeek, $startTime, $duration
                        )
                        ?? ScheduleConflicts::futureOccurrenceConflict(
                            (int)$teacher['id'],
                            array_column(
                                SemesterManagement::activeDatesForLocationWeekday($semesterId, (int)$location['id'], $dayOfWeek),
                                'date'
                            ),
                            $startTime,
                            $duration
                        );
                    if ($conflict !== null) {
                        $status = 'error';
                        $messages[] = $conflict;
                    } else {
                        $claimed[] = [
                            'teacher_user_id' => (int)$teacher['id'],
                            'day_of_week' => $dayOfWeek,
                            'start_time' => $startTime,
                            'duration_minutes' => $duration,
                            'title' => $title,
                        ];
                        $changes = 'Hold ' . $title . ' for ' . $teacher['first_name'] . ' ' . $teacher['last_name']
                            . ' on ' . self::dayLabel($dayOfWeek) . 's, '
                            . date('g:i a', strtotime($startTime)) . '–' . date('g:i a', strtotime($endTime));
                        $row['_location_id'] = (int)$location['id'];
                        $row['_teacher_user_id'] = (int)$teacher['id'];
                        $row['_day_of_week'] = $dayOfWeek;
                        $row['_start_time'] = $startTime;
                        $row['_duration_minutes'] = $duration;
                        $row['_title'] = $title;
                    }
                }
            }

            $out[] = [
                'row' => $i + 1,
                'data' => $row,
                'status' => $status,
                'changes' => $changes,
                'messages' => $messages,
            ];
        }
        return $out;
    }

    /** Create the new hold block reservations (each generates its blocks). */
    public static function commit(?UserContext $ctx, array $validatedRows, array $context = []): array {
        $semesterId = (int)($context['semester_id'] ?? 0);
        $added = 0;
        $skipped = 0;

        foreach ($validatedRows as $entry) {
            if (($entry['status'] ?? '') !== 'valid' || ($entry['changes'] ?? '') === 'Already held (no change)') {
                $skipped++;
                continue;
            }
            $row = $entry['data'];
            HoldBlockManagement::createHoldBlockReservation($ctx, [
                'semester_id' => $semesterId,
                'teacher_user_id' => (int)$row['_teacher_user_id'],
                'location_id' => (int)$row['_location_id'],
                'day_of_week' => (int)$row['_day_of_week'],
                'start_time' => substr((string)$row['_start_time'], 0, 5),
                'duration_minutes' => (int)$row['_duration_minutes'],
                'title' => (string)$row['_title'],
            ]);
            $added++;
        }

        return ['created' => $added, 'updated' => 0, 'skipped' => $skipped];
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * Does this row overlap a slot an earlier row in the same file already
     * claimed for the same teacher? The database cannot answer this — those
     * rows are not written yet.
     */
    private static function fileSlotConflict(
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
                return 'An earlier row in this file already holds "' . $other['title'] . '" for this teacher at '
                    . date('g:i a', $otherStart) . '–' . date('g:i a', $otherEnd)
                    . ' — a teacher cannot be in two places at once.';
            }
        }
        return null;
    }

    /** "Saturday", "Sat", "6" -> 6; null when unrecognised. */
    public static function parseDayOfWeek(string $raw): ?int {
        $raw = self::norm($raw);
        if ($raw === '') {
            return null;
        }
        if (isset(self::DAY_NAMES[$raw])) {
            return self::DAY_NAMES[$raw];
        }
        if (preg_match('/^[0-6]$/', $raw)) {
            return (int)$raw;
        }
        return null;
    }

    private static function dayLabel(int $dayOfWeek): string {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$dayOfWeek];
    }

    private static function slotKey(int $locationId, int $teacherUserId, int $dayOfWeek, string $startTime): string {
        return $locationId . ':' . $teacherUserId . ':' . $dayOfWeek . ':' . substr($startTime, 0, 5);
    }

    /** Teachers whose "first last" or "preferred last" equals $name (case-insensitive). */
    private static function matchTeachersByName(string $name): array {
        $st = pdo()->prepare(
            "SELECT u.id, u.first_name, u.last_name
             FROM teacher_profiles tp
             JOIN users u ON u.id = tp.user_id
             WHERE u.is_deleted = 0
               AND (LOWER(CONCAT(u.first_name, ' ', u.last_name)) = ?
                    OR LOWER(CONCAT(COALESCE(u.preferred_name, ''), ' ', u.last_name)) = ?)"
        );
        $norm = self::norm($name);
        $st->execute([$norm, $norm]);
        return $st->fetchAll();
    }

    private static function norm(string $name): string {
        return preg_replace('/\s+/', ' ', strtolower(trim($name))) ?? '';
    }
}
