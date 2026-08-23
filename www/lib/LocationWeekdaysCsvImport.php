<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/SemesterManagement.php';
require_once __DIR__ . '/LocationDatesCsvImport.php';
require_once __DIR__ . '/HoldBlocksCsvImport.php';

// The class-days CSV (semester wizard step 3): one row per weekday each
// location holds classes on, with that day's standard hours. These
// declarations are what the class-dates import is validated against, what
// blank times there inherit, and what the schedule grid draws each day's
// band over. Re-importing updates hours in place; like the other semester
// imports it never deletes — a day is removed by re-declaring without it via
// SemesterManagement::setLocationWeekdays (no UI yet, matching how
// location_dates has no delete flow). $context requires ['semester_id' => n].
class LocationWeekdaysCsvImport {

    public static function targetFields(): array {
        return [
            'location_name' => 'Location Name',
            'day' => 'Day',
            'start_time' => 'Start Time',
            'end_time' => 'End Time',
        ];
    }

    public static function validateRows(array $mappedRows, array $context = []): array {
        $semesterId = (int)($context['semester_id'] ?? 0);
        if (!SemesterManagement::find($semesterId)) {
            throw new InvalidArgumentException('A semester is required for a class-days import.');
        }
        $locationsByName = [];
        foreach (SemesterManagement::activeLocations($semesterId) as $location) {
            $locationsByName[self::norm((string)$location['name'])] = $location;
        }
        $existing = [];
        foreach (SemesterManagement::locationWeekdays($semesterId) as $row) {
            $existing[$row['location_id'] . ':' . $row['day_of_week']] = true;
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

            $dayRaw = trim((string)($row['day'] ?? ''));
            $dayOfWeek = HoldBlocksCsvImport::parseDayOfWeek($dayRaw);
            if ($dayOfWeek === null) {
                $status = 'error';
                $messages[] = $dayRaw === ''
                    ? 'Day is required (e.g. "Saturday").'
                    : 'Unknown day "' . $dayRaw . '" — use a weekday name like "Saturday".';
            }

            $startTime = LocationDatesCsvImport::parseTime((string)($row['start_time'] ?? ''));
            $endTime = LocationDatesCsvImport::parseTime((string)($row['end_time'] ?? ''));
            if ($startTime === null || $endTime === null) {
                $status = 'error';
                $messages[] = 'Start and end time are required (e.g. "9:00 am").';
            } elseif ($endTime <= $startTime) {
                $status = 'error';
                $messages[] = 'End time must be after the start time.';
            }

            if ($status !== 'error' && $location && $dayOfWeek !== null) {
                $key = $location['id'] . ':' . $dayOfWeek;
                if (isset($seen[$key])) {
                    $status = 'error';
                    $messages[] = 'Duplicate row for this location and day (row ' . $seen[$key] . ').';
                } else {
                    $seen[$key] = $i + 1;
                    $changes = isset($existing[$key])
                        ? 'Update ' . self::dayLabel($dayOfWeek) . ' hours'
                        : 'Open ' . $location['name'] . ' on ' . self::dayLabel($dayOfWeek) . 's, '
                            . date('g:i a', strtotime($startTime)) . '–' . date('g:i a', strtotime($endTime));
                    $row['_location_id'] = (int)$location['id'];
                    $row['_day_of_week'] = $dayOfWeek;
                    $row['_start_time'] = $startTime;
                    $row['_end_time'] = $endTime;
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

    /** Upsert the valid rows; existing (location, day) rows get new hours. */
    public static function commit(?UserContext $ctx, array $validatedRows, array $context = []): array {
        $semesterId = (int)($context['semester_id'] ?? 0);
        if (!SemesterManagement::find($semesterId)) {
            throw new InvalidArgumentException('A semester is required for a class-days import.');
        }
        $created = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($validatedRows as $entry) {
            if (($entry['status'] ?? '') !== 'valid') {
                $skipped++;
                continue;
            }
            $row = $entry['data'];
            $wasUpdate = str_starts_with((string)($entry['changes'] ?? ''), 'Update');
            SemesterManagement::upsertLocationWeekday(
                $ctx,
                $semesterId,
                (int)$row['_location_id'],
                (int)$row['_day_of_week'],
                (string)$row['_start_time'],
                (string)$row['_end_time']
            );
            $wasUpdate ? $updated++ : $created++;
        }
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** 6 -> "Saturday". */
    private static function dayLabel(int $dayOfWeek): string {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$dayOfWeek] ?? (string)$dayOfWeek;
    }

    private static function norm(string $name): string {
        return preg_replace('/\s+/', ' ', strtolower(trim($name))) ?? '';
    }
}
