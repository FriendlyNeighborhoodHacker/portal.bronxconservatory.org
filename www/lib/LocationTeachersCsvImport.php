<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/SemesterManagement.php';

// The location-teachers CSV (semester wizard step 3d): which teachers teach
// at which location this semester. $context requires ['semester_id' => n].
class LocationTeachersCsvImport {

    public static function targetFields(): array {
        return [
            'teacher_name' => 'Teacher Name',
            'location_name' => 'Location Name',
        ];
    }

    public static function validateRows(array $mappedRows, array $context = []): array {
        $semesterId = (int)($context['semester_id'] ?? 0);
        if (!SemesterManagement::find($semesterId)) {
            throw new InvalidArgumentException('A semester is required for a location-teachers import.');
        }
        $locationsByName = [];
        foreach (SemesterManagement::activeLocations($semesterId) as $location) {
            $locationsByName[self::norm((string)$location['name'])] = $location;
        }
        $existingPairs = [];
        foreach (SemesterManagement::locationTeachers($semesterId) as $pair) {
            $existingPairs[$pair['location_id'] . ':' . $pair['teacher_user_id']] = true;
        }

        $out = [];
        $seen = [];
        foreach ($mappedRows as $i => $row) {
            $messages = [];
            $status = 'valid';
            $changes = '';

            $teacherName = trim((string)($row['teacher_name'] ?? ''));
            $locationName = trim((string)($row['location_name'] ?? ''));

            $location = $locationsByName[self::norm($locationName)] ?? null;
            if (!$location) {
                $status = 'error';
                $messages[] = $locationName === ''
                    ? 'Location name is required.'
                    : 'No match found for location "' . $locationName . '" among this semester\'s active locations.';
            }

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

            if ($status !== 'error' && $location && $teacher) {
                $key = $location['id'] . ':' . $teacher['id'];
                if (isset($seen[$key]) || isset($existingPairs[$key])) {
                    $changes = 'Already assigned (no change)';
                } else {
                    $changes = 'Assign ' . $teacher['first_name'] . ' ' . $teacher['last_name'] . ' to ' . $location['name'];
                }
                $seen[$key] = true;
                $row['_location_id'] = (int)$location['id'];
                $row['_teacher_user_id'] = (int)$teacher['id'];
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

    /** Add the valid pairs (keeping existing assignments). */
    public static function commit(?UserContext $ctx, array $validatedRows, array $context = []): array {
        $semesterId = (int)($context['semester_id'] ?? 0);
        $added = 0;
        $skipped = 0;
        foreach ($validatedRows as $entry) {
            if (($entry['status'] ?? '') !== 'valid') {
                $skipped++;
                continue;
            }
            if (($entry['changes'] ?? '') === 'Already assigned (no change)') {
                $skipped++;
                continue;
            }
            $row = $entry['data'];
            SemesterManagement::addLocationTeacher($ctx, $semesterId, (int)$row['_location_id'], (int)$row['_teacher_user_id']);
            $added++;
        }
        return ['created' => $added, 'updated' => 0, 'skipped' => $skipped];
    }

    // ── internals ─────────────────────────────────────────────────────────

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
