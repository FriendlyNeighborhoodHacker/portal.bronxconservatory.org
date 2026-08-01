<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/LessonManagement.php';
require_once __DIR__ . '/ReservationManagement.php';
require_once __DIR__ . '/SemesterManagement.php';

// A person's semester as one chronological list: their lessons interleaved
// with the holidays that fall on the weekday they would otherwise have had a
// lesson. A month grid is mostly empty for a semester that meets once a week,
// so the teacher, student and parent calendars show this instead.
//
// Holidays come from the INACTIVE semester_location_dates at the locations
// where the person actually has a reservation, matched on that reservation's
// weekday — so a Saturday student is told about Saturday closures and not
// about a Tuesday one somewhere else.
class ScheduleTimeline {

    /**
     * Chronological entries for a teacher's semester. Each entry is either
     *   ['kind' => 'lesson',  'date', 'sort', 'lesson' => row]
     *   ['kind' => 'holiday', 'date', 'sort', 'title', 'location_name']
     */
    public static function forTeacher(int $teacherUserId, int $semesterId): array {
        return self::merge(
            LessonManagement::lessonsForTeacherInSemester($teacherUserId, $semesterId),
            ReservationManagement::reservationsForTeacher($teacherUserId, $semesterId),
            $semesterId
        );
    }

    /** The same, for one student or for all of a parent's children. */
    public static function forStudents(array $studentUserIds, int $semesterId): array {
        $ids = array_values(array_unique(array_map('intval', $studentUserIds)));
        if (!$ids) {
            return [];
        }
        $reservations = [];
        foreach ($ids as $studentUserId) {
            foreach (ReservationManagement::reservationsForStudent($studentUserId, $semesterId) as $reservation) {
                $reservations[] = $reservation;
            }
        }
        return self::merge(
            LessonManagement::lessonsForStudentsInSemester($ids, $semesterId),
            $reservations,
            $semesterId
        );
    }

    // ── internals ─────────────────────────────────────────────────────────

    private static function merge(array $lessons, array $reservations, int $semesterId): array {
        $entries = [];
        foreach ($lessons as $lesson) {
            $start = (string)$lesson['start_datetime'];
            $entries[] = [
                'kind' => 'lesson',
                'date' => substr($start, 0, 10),
                'sort' => $start,
                'lesson' => $lesson,
            ];
        }

        // One holiday notice per (date, location), however many reservations
        // or siblings happen to land on it.
        $seen = [];
        foreach ($reservations as $reservation) {
            $locationId = (int)$reservation['location_id'];
            $holidays = SemesterManagement::inactiveDatesForLocationWeekday(
                $semesterId, $locationId, (int)$reservation['day_of_week']
            );
            foreach ($holidays as $holiday) {
                $date = (string)$holiday['date'];
                $key = $date . ':' . $locationId;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $entries[] = [
                    'kind' => 'holiday',
                    'date' => $date,
                    // Sorts before any lesson that day (there should be none).
                    'sort' => $date . ' 00:00:00',
                    'title' => (string)($holiday['title'] ?: 'No lessons'),
                    'location_name' => (string)($reservation['location_name'] ?? ''),
                ];
            }
        }

        usort($entries, fn(array $a, array $b) => [$a['sort'], $a['kind']] <=> [$b['sort'], $b['kind']]);
        return $entries;
    }
}
