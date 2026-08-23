<?php
declare(strict_types=1);

require_once __DIR__ . '/ReservationManagement.php';
require_once __DIR__ . '/HoldBlockManagement.php';
require_once __DIR__ . '/SemesterManagement.php';

/**
 * The weekly spine of the Semester Schedule: every commitment in a semester,
 * the days and time range they call for, and the index that lets a renderer
 * ask "what is in this cell?".
 *
 * Both the Semester Schedule and the lead-conversion slot picker draw the same
 * grid from this, so an admin choosing a time sees exactly what the schedule
 * shows. The rendering itself stays with each page — they want different cells.
 */
class ScheduleGridData {

    /** The half-hour row height every grid is laid out on. */
    public const SLOT_MINUTES = 30;

    /** Saturday 9:00–4:30 unless the semester's dates or bookings say otherwise. */
    public const DEFAULT_BOUNDS = [9 * 60, 16 * 60 + 30];
    public const DEFAULT_DAY = SemesterManagement::DEFAULT_TEACHING_DAY;

    /**
     * Returns:
     *   'columns'   => SemesterManagement::locationTeachers() rows
     *   'balances'  => per-student balance map (for the schedule's colouring)
     *   'occupants' => every lesson reservation and hold block, each tagged
     *                  'kind' => 'lesson' | 'hold'
     *   'days'      => the weekdays to show, sorted
     *   'bounds'    => [day => [firstMinute, lastMinute]]
     *   'bands'     => one entry per day, each with its OWN columns and
     *                  bounds — the grid draws a separate table per day
     *   'cellIndex' => ['locId:teacherId:day:slotMinutes' => [occupant, ...]]
     *
     * Callers turn 'bands' into stacked grids with schedule_day_grids_html();
     * 'days' and 'bounds' remain for the few callers that want the whole week
     * as one spine.
     */
    public static function semesterWeeklyGrid(int $semesterId): array {
        $grid = ReservationManagement::gridDataForSemester($semesterId);

        // Both kinds of cell occupy the same weekly slot, so they share the row
        // spine and the cell index; 'kind' tells a renderer how to draw each.
        $occupants = [];
        foreach ($grid['reservations'] as $reservation) {
            $reservation['kind'] = 'lesson';
            $occupants[] = $reservation;
        }
        foreach (HoldBlockManagement::holdBlockReservationsForSemester($semesterId) as $hold) {
            $hold['kind'] = 'hold';
            $occupants[] = $hold;
        }

        // Days come from the semester's declared weekdays and its class
        // calendar — a declared Tuesday gets its band before any dates are
        // imported — widened by anything already booked on a day neither
        // lists any more.
        $days = [];
        foreach (SemesterManagement::locationWeekdays($semesterId) as $weekdayRow) {
            $days[(int)$weekdayRow['day_of_week']] = true;
        }
        foreach (SemesterManagement::locationDates($semesterId) as $dateRow) {
            $days[(int)date('w', strtotime((string)$dateRow['date']))] = true;
        }
        foreach ($occupants as $occupant) {
            $days[(int)$occupant['day_of_week']] = true;
        }
        if (!$days) {
            $days = [self::DEFAULT_DAY => true];
        }
        $days = array_keys($days);
        sort($days);

        // Each day is drawn over the hours its buildings are actually open —
        // a Saturday running 9:00–5:00 and a Tuesday running 3:30–8:00 are
        // different spines, and a fixed window would cut one of them off.
        $hours = SemesterManagement::dayHoursForSemester($semesterId);
        $bounds = [];
        foreach ($days as $day) {
            $bounds[$day] = isset($hours[$day]) ? self::slotBounds($hours[$day]) : self::DEFAULT_BOUNDS;
        }
        foreach ($occupants as $occupant) {
            $day = (int)$occupant['day_of_week'];
            $slot = self::slotMinutes((string)$occupant['start_time']);
            $bounds[$day][0] = min($bounds[$day][0], $slot);
            $bounds[$day][1] = max($bounds[$day][1], $slot);
        }

        // Occupants that don't sit exactly on a slot boundary (e.g. 10:15) are
        // keyed to the row they live in so they still render. Each key holds a
        // LIST: conflicting bookings are prevented, but if one ever slips
        // through, the cell shows every commitment rather than hiding one.
        $cellIndex = [];
        foreach ($occupants as $occupant) {
            $key = $occupant['location_id'] . ':' . $occupant['teacher_user_id']
                . ':' . $occupant['day_of_week'] . ':' . self::slotMinutes((string)$occupant['start_time']);
            $cellIndex[$key][] = $occupant;
        }

        // One band per day, each with only the teachers who work that day. A
        // Saturday-only teacher has no Tuesday column at all, so nobody can
        // book them a lesson that would generate no lessons.
        //
        // Bands run Saturday-first — the main teaching day on top, then on
        // through the week — so the common case reads Saturdays, then
        // Tuesdays. (The weekly calendar orders its own bands by real date.)
        $bandDays = $days;
        usort($bandDays, fn(int $a, int $b): int =>
            (($a - SemesterManagement::DEFAULT_TEACHING_DAY + 7) % 7)
            <=> (($b - SemesterManagement::DEFAULT_TEACHING_DAY + 7) % 7));
        $bands = [];
        foreach ($bandDays as $day) {
            $bands[] = [
                'day' => $day,
                'label' => self::dayLabel($day),
                'columns' => self::columnsForDay($semesterId, $day, $occupants),
                'bounds' => $bounds[$day],
            ];
        }

        return [
            'columns' => $grid['columns'],
            'balances' => $grid['balances'],
            'occupants' => $occupants,
            'days' => $days,
            'bounds' => $bounds,
            'bands' => $bands,
            'cellIndex' => $cellIndex,
        ];
    }

    /**
     * The columns of one day's grid: the teachers assigned to that day,
     * widened by any pair already booked on it. The widening is what keeps a
     * booking visible after the assignment behind it is taken away — the same
     * guarantee locationTeachersIncluding() gives the weekly calendar.
     */
    public static function columnsForDay(int $semesterId, int $day, array $occupants): array {
        $pairs = [];
        foreach ($occupants as $occupant) {
            if ((int)$occupant['day_of_week'] === $day) {
                $pairs[] = [
                    'location_id' => (int)$occupant['location_id'],
                    'teacher_user_id' => (int)$occupant['teacher_user_id'],
                ];
            }
        }
        return SemesterManagement::locationTeachersIncluding(
            SemesterManagement::locationTeachers($semesterId, $day),
            $pairs
        );
    }

    /** "Saturdays" — the heading over one day's grid. */
    public static function dayLabel(int $day): string {
        $names = ['Sundays', 'Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays'];
        return $names[$day] ?? 'Day ' . $day;
    }

    /**
     * Opening hours -> the first and last slot a commitment may START in. A
     * building closing at 5:00pm has 4:30 as its last slot, matching the
     * window the grid has always drawn.
     */
    public static function slotBounds(array $hours): array {
        [$opens, $closes] = $hours;
        $last = max($opens, $closes - self::SLOT_MINUTES);
        return [
            intdiv($opens, self::SLOT_MINUTES) * self::SLOT_MINUTES,
            intdiv($last, self::SLOT_MINUTES) * self::SLOT_MINUTES,
        ];
    }

    /**
     * Is this empty-looking cell actually covered by an earlier occupant's
     * rowspan? A 60-minute lesson at 10:00 owns the 10:30 cell too, and the
     * renderer must not emit a <td> for it.
     */
    public static function coveredByEarlierOccupant(array $cellIndex, string $columnKey, int $day, int $minutes): bool {
        // 210 minutes back covers the longest commitment the app allows to be
        // drawn in a cell; anything longer would be a data problem of its own.
        for ($back = self::SLOT_MINUTES; $back <= 210; $back += self::SLOT_MINUTES) {
            foreach ($cellIndex[$columnKey . ':' . $day . ':' . ($minutes - $back)] ?? [] as $prior) {
                if ((int)ceil((int)$prior['duration_minutes'] / self::SLOT_MINUTES) * self::SLOT_MINUTES > $back) {
                    return true;
                }
            }
        }
        return false;
    }

    /** The occupants of one (column, row) cell. */
    public static function occupantsAt(array $cellIndex, array $column, array $row): array {
        $key = $column['location_id'] . ':' . $column['teacher_user_id'] . ':' . $row['day'] . ':' . $row['minutes'];
        return $cellIndex[$key] ?? [];
    }

    /** "10:15:00" -> 600, the start of the half-hour row it belongs to. */
    public static function slotMinutes(string $startTime): int {
        [$h, $m] = array_map('intval', explode(':', $startTime));
        return intdiv($h * 60 + $m, self::SLOT_MINUTES) * self::SLOT_MINUTES;
    }
}
