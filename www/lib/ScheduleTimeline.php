<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/LessonManagement.php';
require_once __DIR__ . '/ReservationManagement.php';
require_once __DIR__ . '/SemesterManagement.php';

// A person's schedule as one chronological list, spanning however many
// semesters are handed to it: their lessons interleaved with the holidays
// that fall on the weekday they would otherwise have had a lesson. A month
// grid is mostly empty for a semester that meets once a week, so the teacher,
// student and parent calendars show this instead — over every semester that
// has not finished yet, so next semester appears as soon as it is planned.
//
// Holidays come from the INACTIVE semester_location_dates at the locations
// where the person actually has a reservation, matched on that reservation's
// weekday — so a Saturday student is told about Saturday closures and not
// about a Tuesday one somewhere else.
class ScheduleTimeline {

    /**
     * Chronological entries for a teacher across $semesters (rows from
     * SemesterManagement). Each entry is either
     *   ['kind' => 'lesson',  'date', 'sort', 'semester_id', 'semester_label', 'lesson' => row]
     *   ['kind' => 'holiday', 'date', 'sort', 'semester_id', 'semester_label', 'title', 'location_name']
     */
    public static function forTeacher(int $teacherUserId, array $semesters): array {
        return self::merge(
            LessonManagement::lessonsForTeacherInSemesters($teacherUserId, array_column($semesters, 'id')),
            fn(int $semesterId): array => ReservationManagement::reservationsForTeacher($teacherUserId, $semesterId),
            $semesters
        );
    }

    /** The same, for one student or for all of a parent's children. */
    public static function forStudents(array $studentUserIds, array $semesters): array {
        $ids = array_values(array_unique(array_map('intval', $studentUserIds)));
        if (!$ids) {
            return [];
        }
        return self::merge(
            LessonManagement::lessonsForStudentsInSemesters($ids, array_column($semesters, 'id')),
            function (int $semesterId) use ($ids): array {
                $out = [];
                foreach ($ids as $studentUserId) {
                    foreach (ReservationManagement::reservationsForStudent($studentUserId, $semesterId) as $reservation) {
                        $out[] = $reservation;
                    }
                }
                return $out;
            },
            $semesters
        );
    }

    /** The distinct semesters these entries actually cover, in date order. */
    public static function semesterLabels(array $entries): array {
        $labels = [];
        foreach ($entries as $entry) {
            $labels[(int)$entry['semester_id']] = (string)$entry['semester_label'];
        }
        return array_values($labels);
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** @param callable $reservationsFn fn(int $semesterId): array */
    private static function merge(array $lessons, callable $reservationsFn, array $semesters): array {
        $labels = [];
        foreach ($semesters as $semester) {
            $labels[(int)$semester['id']] = SemesterManagement::label($semester);
        }

        $entries = [];
        foreach ($lessons as $lesson) {
            $start = (string)$lesson['start_datetime'];
            $semesterId = (int)$lesson['semester_id'];
            $entries[] = [
                'kind' => 'lesson',
                'date' => substr($start, 0, 10),
                'sort' => $start,
                'semester_id' => $semesterId,
                'semester_label' => $labels[$semesterId] ?? '',
                'lesson' => $lesson,
            ];
        }

        // One holiday notice per (date, location), however many reservations
        // or siblings happen to land on it.
        $seen = [];
        foreach ($semesters as $semester) {
            $semesterId = (int)$semester['id'];
            foreach ($reservationsFn($semesterId) as $reservation) {
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
                        'semester_id' => $semesterId,
                        'semester_label' => $labels[$semesterId] ?? '',
                        'title' => (string)($holiday['title'] ?: 'No lessons'),
                        'location_name' => (string)($reservation['location_name'] ?? ''),
                    ];
                }
            }
        }

        usort($entries, fn(array $a, array $b) => [$a['sort'], $a['kind']] <=> [$b['sort'], $b['kind']]);
        return $entries;
    }
}
