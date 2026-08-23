<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/SemesterManagement.php';
require_once __DIR__ . '/ReservationManagement.php';
require_once __DIR__ . '/HoldBlockManagement.php';

// The location-dates CSV (semester wizard step 3c): one row per class date
// per location. $context requires ['semester_id' => n].
class LocationDatesCsvImport {

    public static function targetFields(): array {
        return [
            'location_name' => 'Location Name',
            'date' => 'Date',
            'start_time' => 'Start Time',
            'end_time' => 'End Time',
            'status' => 'Status',
            'notes' => 'Notes',
        ];
    }

    public static function validateRows(array $mappedRows, array $context = []): array {
        $semesterId = (int)($context['semester_id'] ?? 0);
        $semester = SemesterManagement::find($semesterId);
        if (!$semester) {
            throw new InvalidArgumentException('A semester is required for a location-dates import.');
        }
        $locationsByName = self::activeLocationsByNormalizedName($semesterId);
        $existing = self::existingDateKeys($semesterId);
        // Declared weekdays per location — the days each location is open,
        // and the hours blank times inherit.
        $declaredHours = [];
        $declaredDays = [];
        foreach (SemesterManagement::locationWeekdays($semesterId) as $weekdayRow) {
            $declaredHours[$weekdayRow['location_id'] . ':' . $weekdayRow['day_of_week']] =
                [(string)$weekdayRow['start_time'], (string)$weekdayRow['end_time']];
            $declaredDays[(int)$weekdayRow['location_id']][] = (int)$weekdayRow['day_of_week'];
        }

        $out = [];
        $seen = [];
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

            $date = self::parseDate((string)($row['date'] ?? ''));
            if ($date === null) {
                $status = 'error';
                $messages[] = 'Invalid date "' . (string)($row['date'] ?? '') . '".';
            } elseif ($date < $semester['start_date'] || $date > $semester['end_date']) {
                $messages[] = 'Outside the semester (' . $semester['start_date'] . ' – ' . $semester['end_date'] . ').';
            }

            // A location with declared weekdays only accepts dates on them —
            // a date on any other weekday is almost certainly a typo. A
            // location with no declarations (predating them) is unchecked.
            $dayOfWeek = $date !== null ? (int)date('w', strtotime($date)) : null;
            $declared = $location ? ($declaredDays[(int)$location['id']] ?? []) : [];
            if ($location && $dayOfWeek !== null && $declared && !in_array($dayOfWeek, $declared, true)) {
                $status = 'error';
                $messages[] = $location['name'] . ' is not open on ' . self::dayLabel($dayOfWeek)
                    . 's this semester (declared days: ' . implode(', ', array_map([self::class, 'dayLabel'], $declared))
                    . ') — fix the date, or import a class-days row for it.';
            }

            // Blank times inherit the declared hours for that weekday.
            $startTime = self::parseTime((string)($row['start_time'] ?? ''));
            $endTime = self::parseTime((string)($row['end_time'] ?? ''));
            $inherited = $location !== null && $dayOfWeek !== null
                ? ($declaredHours[$location['id'] . ':' . $dayOfWeek] ?? null)
                : null;
            if ($startTime === null && trim((string)($row['start_time'] ?? '')) === '' && $inherited) {
                $startTime = $inherited[0];
            }
            if ($endTime === null && trim((string)($row['end_time'] ?? '')) === '' && $inherited) {
                $endTime = $inherited[1];
            }
            if ($startTime === null || $endTime === null) {
                $status = 'error';
                $messages[] = $inherited === null && ($row['start_time'] ?? '') === '' && ($row['end_time'] ?? '') === ''
                    ? 'Start and end time are required (e.g. "9:00 am") — or import the day\'s hours in the class-days CSV and leave them blank.'
                    : 'Start and end time are required (e.g. "9:00 am").';
            }

            $rowStatus = strtolower(trim((string)($row['status'] ?? '')));
            if ($rowStatus === '') {
                $rowStatus = 'active';
            }
            if (!in_array($rowStatus, ['active', 'inactive'], true)) {
                $status = 'error';
                $messages[] = 'Status must be "active" or "inactive".';
            }

            if ($status !== 'error' && $location && $date) {
                $key = $location['id'] . ':' . $date;
                if (isset($seen[$key])) {
                    $status = 'error';
                    $messages[] = 'Duplicate row for this location and date (row ' . $seen[$key] . ').';
                } else {
                    $seen[$key] = $i + 1;
                    $changes = isset($existing[$key]) ? 'Update existing date' : 'Add date';
                    $row['_location_id'] = (int)$location['id'];
                    $row['_date'] = $date;
                    $row['_start_time'] = $startTime;
                    $row['_end_time'] = $endTime;
                    $row['_status'] = $rowStatus;
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

    /** Upsert the valid rows and resync affected locations' lessons. */
    public static function commit(?UserContext $ctx, array $validatedRows, array $context = []): array {
        $semesterId = (int)($context['semester_id'] ?? 0);
        $added = 0;
        $updated = 0;
        $skipped = 0;
        $touchedLocations = [];

        foreach ($validatedRows as $entry) {
            if (($entry['status'] ?? '') !== 'valid') {
                $skipped++;
                continue;
            }
            $row = $entry['data'];
            SemesterManagement::upsertLocationDate(
                $ctx,
                $semesterId,
                (int)$row['_location_id'],
                (string)$row['_date'],
                (string)$row['_start_time'],
                (string)$row['_end_time'],
                (string)$row['_status'],
                trim((string)($row['notes'] ?? '')) ?: null
            );
            $touchedLocations[(int)$row['_location_id']] = true;
            if (($entry['changes'] ?? '') === 'Update existing date') {
                $updated++;
            } else {
                $added++;
            }
        }

        // Confirmed reservations and hold blocks track calendar edits (once
        // per location).
        foreach (array_keys($touchedLocations) as $locationId) {
            ReservationManagement::resyncLessonsForLocation($ctx, $semesterId, $locationId);
            HoldBlockManagement::resyncHoldBlocksForLocation($ctx, $semesterId, $locationId);
        }

        return ['created' => $added, 'updated' => $updated, 'skipped' => $skipped];
    }

    // ── internals ─────────────────────────────────────────────────────────

    private static function activeLocationsByNormalizedName(int $semesterId): array {
        $out = [];
        foreach (SemesterManagement::activeLocations($semesterId) as $location) {
            $out[self::norm((string)$location['name'])] = $location;
        }
        return $out;
    }

    private static function existingDateKeys(int $semesterId): array {
        $out = [];
        foreach (SemesterManagement::locationDates($semesterId) as $row) {
            $out[$row['location_id'] . ':' . $row['date']] = true;
        }
        return $out;
    }

    /** 6 -> "Saturday". */
    private static function dayLabel(int $dayOfWeek): string {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$dayOfWeek] ?? (string)$dayOfWeek;
    }

    private static function norm(string $name): string {
        return preg_replace('/\s+/', ' ', strtolower(trim($name))) ?? '';
    }

    /** Accepts "2026-09-12" and "9/12/2026"; returns Y-m-d or null. */
    public static function parseDate(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
            if (!checkdate((int)$m[1], (int)$m[2], (int)$m[3])) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[1], (int)$m[2]);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            [$y, $mo, $d] = array_map('intval', explode('-', $raw));
            return checkdate($mo, $d, $y) ? $raw : null;
        }
        return null;
    }

    /** Accepts "9:00", "14:30", "9:00 am", "4:30 PM"; returns HH:MM:SS or null. */
    public static function parseTime(string $raw): ?string {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(am|pm)?$/', $raw, $m)) {
            return null;
        }
        $h = (int)$m[1];
        $minutes = (int)$m[2];
        $meridiem = $m[3] ?? '';
        if ($minutes > 59) {
            return null;
        }
        if ($meridiem === 'pm' && $h < 12) {
            $h += 12;
        } elseif ($meridiem === 'am' && $h === 12) {
            $h = 0;
        }
        if ($h > 23) {
            return null;
        }
        return sprintf('%02d:%02d:00', $h, $minutes);
    }
}
