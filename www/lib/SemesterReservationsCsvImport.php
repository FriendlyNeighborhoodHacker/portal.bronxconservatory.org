<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/SemesterManagement.php';
require_once __DIR__ . '/ReservationManagement.php';
require_once __DIR__ . '/ScheduleConflicts.php';
require_once __DIR__ . '/LocationDatesCsvImport.php';
require_once __DIR__ . '/HoldBlocksCsvImport.php';

// The schedule CSV (semester wizard step 5): one row per weekly lesson slot —
// which student sits with which teacher, where, and when. This is how an
// existing schedule is moved INTO the portal, so committing never posts
// charges: those students' balances came from wherever they were kept before
// and are loaded separately. $context requires ['semester_id' => n].
class SemesterReservationsCsvImport {

    /** Friendly spellings admins actually type, mapped to the stored status. */
    private const STATUS_ALIASES = [
        'pending_reach_out' => 'pending_reach_out',
        'pending reach out' => 'pending_reach_out',
        'reach out' => 'pending_reach_out',
        'pending' => 'pending_reach_out',
        'pending_confirmation' => 'pending_confirmation',
        'pending confirmation' => 'pending_confirmation',
        'unconfirmed' => 'pending_confirmation',
        'confirmed' => 'confirmed',
    ];

    public static function targetFields(): array {
        return [
            'student_name' => 'Student Name',
            'teacher_name' => 'Teacher Name',
            'location_name' => 'Location Name',
            'day' => 'Day',
            'start_time' => 'Start Time',
            'duration_minutes' => 'Duration Minutes',
            'status' => 'Status',
        ];
    }

    public static function validateRows(array $mappedRows, array $context = []): array {
        $semesterId = (int)($context['semester_id'] ?? 0);
        if (!SemesterManagement::find($semesterId)) {
            throw new InvalidArgumentException('A semester is required for a schedule import.');
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
        // Slots already reserved for the same student, so a re-import reports
        // "no change" instead of colliding with the row it created last time.
        $existing = [];
        $studentSlots = [];
        foreach (ReservationManagement::reservationsForSemester($semesterId) as $r) {
            $key = self::slotKey(
                (int)$r['location_id'], (int)$r['teacher_user_id'], (int)$r['day_of_week'], (string)$r['start_time']
            );
            $existing[$key] = (int)$r['student_user_id'];
            $studentSlots[] = [
                'student_user_id' => (int)$r['student_user_id'],
                'day_of_week' => (int)$r['day_of_week'],
                'start_time' => (string)$r['start_time'],
                'duration_minutes' => (int)$r['duration_minutes'],
            ];
        }

        $out = [];
        $seen = [];
        // Slots this file has already claimed. The database cannot see them
        // yet, but two rows in one file must not double-book anyone either.
        $claimedByTeacher = [];
        $claimedByStudent = [];

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
                $matches = self::matchTeachers($teacherName);
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

            $studentName = trim((string)($row['student_name'] ?? ''));
            $student = null;
            if ($studentName === '') {
                $status = 'error';
                $messages[] = 'Student name is required.';
            } else {
                $matches = self::matchStudents($studentName);
                if (count($matches) === 0) {
                    $status = 'error';
                    $messages[] = 'No match found for student "' . $studentName . '" — upload students first.';
                } elseif (count($matches) > 1) {
                    $status = 'error';
                    $messages[] = 'Multiple students match "' . $studentName . '" — use their email instead.';
                } else {
                    $student = $matches[0];
                }
            }

            $dayRaw = trim((string)($row['day'] ?? ''));
            $dayOfWeek = HoldBlocksCsvImport::parseDayOfWeek($dayRaw);
            if ($dayOfWeek === null) {
                $status = 'error';
                $messages[] = $dayRaw === ''
                    ? 'Day is required (e.g. "Saturday").'
                    : 'Unknown day "' . $dayRaw . '" — use a weekday name like "Saturday".';
            }

            $startTime = LocationDatesCsvImport::parseTime((string)($row['start_time'] ?? ''));
            if ($startTime === null) {
                $status = 'error';
                $messages[] = 'Start time is required (e.g. "10:00 am").';
            }

            $durationRaw = trim((string)($row['duration_minutes'] ?? ''));
            $duration = 30;
            if ($durationRaw !== '') {
                if (!preg_match('/^\d+$/', $durationRaw)) {
                    $status = 'error';
                    $messages[] = 'Duration must be a whole number of minutes (e.g. 30).';
                } else {
                    $duration = (int)$durationRaw;
                    if ($duration <= 0 || $duration > 240) {
                        $status = 'error';
                        $messages[] = 'Duration must be between 1 and 240 minutes.';
                    }
                }
            }

            $statusRaw = trim((string)($row['status'] ?? ''));
            $reservationStatus = 'pending_reach_out';
            if ($statusRaw !== '') {
                $alias = self::STATUS_ALIASES[self::norm($statusRaw)] ?? null;
                if ($alias === null) {
                    $status = 'error';
                    $messages[] = 'Unknown status "' . $statusRaw
                        . '" — use pending reach out, pending confirmation or confirmed.';
                } else {
                    $reservationStatus = $alias;
                }
            }

            $activeDates = [];
            if ($status !== 'error' && $location && $dayOfWeek !== null) {
                // A reservation only produces lessons on class dates that fall
                // on its weekday. None means the day is almost certainly wrong.
                $activeDates = array_column(
                    SemesterManagement::activeDatesForLocationWeekday($semesterId, (int)$location['id'], $dayOfWeek),
                    'date'
                );
                if (!$activeDates) {
                    $status = 'error';
                    $messages[] = $location['name'] . ' has no active class dates on '
                        . self::dayLabel($dayOfWeek) . 's this semester — import class dates first, '
                        . 'or check the day.';
                }
            }
            if ($status !== 'error' && $location && $teacher && $dayOfWeek !== null
                && !SemesterManagement::isTeacherAtLocation($semesterId, (int)$location['id'], (int)$teacher['id'], $dayOfWeek)) {
                $status = 'error';
                $messages[] = ($teacher['first_name'] . ' ' . $teacher['last_name']) . ' does not teach at '
                    . $location['name'] . ' on ' . self::dayLabel($dayOfWeek)
                    . 's — check the day, or import a location-teachers row for it.';
            }

            if ($status !== 'error' && $location && $teacher && $student && $dayOfWeek !== null && $startTime !== null) {
                $key = self::slotKey((int)$location['id'], (int)$teacher['id'], $dayOfWeek, $startTime);
                if (isset($seen[$key])) {
                    $status = 'error';
                    $messages[] = 'Duplicate row for this teacher, location, day and time (row ' . $seen[$key] . ').';
                } elseif (($existing[$key] ?? null) === (int)$student['id']) {
                    $seen[$key] = $i + 1;
                    $changes = 'Already reserved (no change)';
                } else {
                    $seen[$key] = $i + 1;
                    $conflict = self::claimConflict(
                        $claimedByTeacher, 'teacher_user_id', (int)$teacher['id'], $dayOfWeek, $startTime, $duration,
                        'An earlier row in this file already books this teacher at '
                    )
                        ?? self::claimConflict(
                            $claimedByStudent, 'student_user_id', (int)$student['id'], $dayOfWeek, $startTime, $duration,
                            'An earlier row in this file already books this student at '
                        )
                        // The same rules the schedule grid enforces: the
                        // teacher must be free at the weekly slot and on every
                        // future date it would occupy, in EVERY location.
                        ?? ScheduleConflicts::weeklySlotConflict(
                            $semesterId, (int)$teacher['id'], $dayOfWeek, $startTime, $duration
                        )
                        ?? ScheduleConflicts::futureOccurrenceConflict(
                            (int)$teacher['id'], $activeDates, $startTime, $duration
                        )
                        ?? self::studentSlotConflict($studentSlots, (int)$student['id'], $dayOfWeek, $startTime, $duration);

                    if ($conflict !== null) {
                        $status = 'error';
                        $messages[] = $conflict;
                    } else {
                        $claim = [
                            'teacher_user_id' => (int)$teacher['id'],
                            'student_user_id' => (int)$student['id'],
                            'day_of_week' => $dayOfWeek,
                            'start_time' => $startTime,
                            'duration_minutes' => $duration,
                        ];
                        $claimedByTeacher[] = $claim;
                        $claimedByStudent[] = $claim;
                        $changes = self::describe($student, $teacher, (string)$location['name'], $dayOfWeek, $startTime, $duration, $reservationStatus);
                        $row['_location_id'] = (int)$location['id'];
                        $row['_teacher_user_id'] = (int)$teacher['id'];
                        $row['_student_user_id'] = (int)$student['id'];
                        $row['_day_of_week'] = $dayOfWeek;
                        $row['_start_time'] = $startTime;
                        $row['_duration_minutes'] = $duration;
                        $row['_status'] = $reservationStatus;
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

    /**
     * Create the reservations. Confirmed rows materialize their lessons the
     * same way the grid does, but no charges are posted — see the class note.
     */
    public static function commit(?UserContext $ctx, array $validatedRows, array $context = []): array {
        $semesterId = (int)($context['semester_id'] ?? 0);
        $added = 0;
        $skipped = 0;

        foreach ($validatedRows as $entry) {
            if (($entry['status'] ?? '') !== 'valid' || ($entry['changes'] ?? '') === 'Already reserved (no change)') {
                $skipped++;
                continue;
            }
            $row = $entry['data'];
            ReservationManagement::createReservation($ctx, [
                'semester_id' => $semesterId,
                'teacher_user_id' => (int)$row['_teacher_user_id'],
                'location_id' => (int)$row['_location_id'],
                'student_user_id' => (int)$row['_student_user_id'],
                'day_of_week' => (int)$row['_day_of_week'],
                'start_time' => substr((string)$row['_start_time'], 0, 5),
                'duration_minutes' => (int)$row['_duration_minutes'],
                'status' => (string)$row['_status'],
            ], ['post_charges' => false]);
            $added++;
        }

        return ['created' => $added, 'updated' => 0, 'skipped' => $skipped];
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** Does this row overlap a slot an earlier row in the same file claimed? */
    private static function claimConflict(
        array $claimed,
        string $field,
        int $personId,
        int $dayOfWeek,
        string $startTime,
        int $durationMinutes,
        string $prefix
    ): ?string {
        $start = strtotime('1970-01-01 ' . $startTime);
        $end = $start + $durationMinutes * 60;
        foreach ($claimed as $other) {
            if ($other[$field] !== $personId || $other['day_of_week'] !== $dayOfWeek) {
                continue;
            }
            $otherStart = strtotime('1970-01-01 ' . $other['start_time']);
            $otherEnd = $otherStart + $other['duration_minutes'] * 60;
            if ($start < $otherEnd && $otherStart < $end) {
                return $prefix . date('g:i a', $otherStart) . '–' . date('g:i a', $otherEnd)
                    . ' — nobody can be in two places at once.';
            }
        }
        return null;
    }

    /**
     * Is the student already sitting in another lesson at this moment? The
     * grid leaves this to the admin's eyes, but a CSV has no eyes on it.
     */
    private static function studentSlotConflict(
        array $studentSlots,
        int $studentUserId,
        int $dayOfWeek,
        string $startTime,
        int $durationMinutes
    ): ?string {
        $start = strtotime('1970-01-01 ' . $startTime);
        $end = $start + $durationMinutes * 60;
        foreach ($studentSlots as $slot) {
            if ($slot['student_user_id'] !== $studentUserId || $slot['day_of_week'] !== $dayOfWeek) {
                continue;
            }
            $otherStart = strtotime('1970-01-01 ' . $slot['start_time']);
            $otherEnd = $otherStart + $slot['duration_minutes'] * 60;
            if ($start < $otherEnd && $otherStart < $end) {
                return 'This student already has a weekly slot at '
                    . date('g:i a', $otherStart) . '–' . date('g:i a', $otherEnd) . '.';
            }
        }
        return null;
    }

    private static function describe(
        array $student,
        array $teacher,
        string $locationName,
        int $dayOfWeek,
        string $startTime,
        int $durationMinutes,
        string $reservationStatus
    ): string {
        $start = strtotime('1970-01-01 ' . $startTime);
        return 'Reserve ' . $student['first_name'] . ' ' . $student['last_name']
            . ' with ' . $teacher['first_name'] . ' ' . $teacher['last_name']
            . ' at ' . $locationName . ' on ' . self::dayLabel($dayOfWeek) . 's, '
            . date('g:i a', $start) . '–' . date('g:i a', $start + $durationMinutes * 60)
            . ' (' . str_replace('_', ' ', $reservationStatus) . ')';
    }

    private static function dayLabel(int $dayOfWeek): string {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$dayOfWeek];
    }

    private static function slotKey(int $locationId, int $teacherUserId, int $dayOfWeek, string $startTime): string {
        return $locationId . ':' . $teacherUserId . ':' . $dayOfWeek . ':' . substr($startTime, 0, 5);
    }

    /** Teachers whose "first last" / "preferred last" / email matches. */
    private static function matchTeachers(string $identifier): array {
        return self::matchPeople('teacher_profiles', $identifier);
    }

    /** Students whose "first last" / "preferred last" / email matches. */
    private static function matchStudents(string $identifier): array {
        return self::matchPeople('student_profiles', $identifier);
    }

    private static function matchPeople(string $profileTable, string $identifier): array {
        $norm = self::norm($identifier);
        if (str_contains($norm, '@')) {
            $st = pdo()->prepare(
                "SELECT u.id, u.first_name, u.last_name
                 FROM {$profileTable} p
                 JOIN users u ON u.id = p.user_id
                 WHERE u.is_deleted = 0
                   AND (LOWER(u.email) = ? OR LOWER(COALESCE(u.secondary_email, '')) = ?)"
            );
            $st->execute([$norm, $norm]);
            return $st->fetchAll();
        }
        $st = pdo()->prepare(
            "SELECT u.id, u.first_name, u.last_name
             FROM {$profileTable} p
             JOIN users u ON u.id = p.user_id
             WHERE u.is_deleted = 0
               AND (LOWER(CONCAT(u.first_name, ' ', u.last_name)) = ?
                    OR LOWER(CONCAT(COALESCE(u.preferred_name, ''), ' ', u.last_name)) = ?)"
        );
        $st->execute([$norm, $norm]);
        return $st->fetchAll();
    }

    private static function norm(string $value): string {
        return preg_replace('/\s+/', ' ', strtolower(trim($value))) ?? '';
    }
}
