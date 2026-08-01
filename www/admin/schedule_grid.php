<?php
// The Semester Schedule grid renderer, shared by the schedule page (abstract
// weekly slots -> reservations) and the weekly calendar (real week -> lessons
// via its own $cellFn). Columns are (location, teacher) pairs grouped under
// location headers; rows are timeslots per day of week.
require_once __DIR__ . '/../partials.php';

/** "SA 9:00 am" row labels. */
function schedule_day_prefix(int $dayOfWeek): string {
    return ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'][$dayOfWeek] ?? '?';
}

/**
 * Build the row spine: for each day, 30-minute slots between the given
 * bounds. $days: sorted day_of_week ints. $bounds: [day => [minMinutes,
 * maxMinutes]] (minutes since midnight; max is the last slot's start).
 * Returns [['day' => d, 'minutes' => m, 'time' => 'HH:MM:SS', 'label' => ...], ...]
 */
function schedule_row_slots(array $days, array $bounds): array {
    $rows = [];
    foreach ($days as $day) {
        [$min, $max] = $bounds[$day] ?? [9 * 60, 16 * 60 + 30];
        for ($m = $min; $m <= $max; $m += 30) {
            $rows[] = [
                'day' => $day,
                'minutes' => $m,
                'time' => sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60),
                'label' => schedule_day_prefix($day) . ' ' . date('g:i a', mktime(intdiv($m, 60), $m % 60)),
            ];
        }
    }
    return $rows;
}

/** "Lucia Ramos" -> "Lucia R.", single word unchanged. */
function schedule_short_person_name(string $full): string {
    $parts = preg_split('/\s+/', trim($full)) ?: [];
    if (count($parts) < 2) {
        return trim($full);
    }
    $last = array_pop($parts);
    return $parts[0] . ' ' . mb_substr($last, 0, 1) . '.';
}

/** "Marisol Vega" -> "M. Vega" (condensed column header). */
function schedule_short_teacher_name(string $full): string {
    $parts = preg_split('/\s+/', trim($full)) ?: [];
    if (count($parts) < 2) {
        return trim($full);
    }
    $first = array_shift($parts);
    return mb_substr($first, 0, 1) . '. ' . implode(' ', $parts);
}

/**
 * One commitment inside a grid cell: a reservation, a lesson, or a hold
 * block. A cell normally holds exactly one, but a teacher who somehow ended
 * up double-booked shows all of them stacked, which grows the row rather than
 * hiding anything.
 *
 * $attrs are the data-* the delegated click handlers read to open the right
 * modal. $stateClass is the res-* colour, applied to the item only when the
 * cell holds more than one (a lone item is coloured by its <td>, so the whole
 * cell tints exactly as it always has).
 */
function schedule_cell_item_html(string $title, string $subtitle, array $attrs, string $stateClass = ''): string {
    $attrs['data-short'] = schedule_short_person_name($title);
    $html = '<div class="cell-item' . ($stateClass !== '' ? ' ' . h($stateClass) : '') . '"';
    foreach ($attrs as $name => $value) {
        $html .= ' ' . $name . '="' . h((string)$value) . '"';
    }
    $html .= '><span class="cell-student">' . h($title) . '</span>';
    if ($subtitle !== '') {
        $html .= '<span class="cell-status">' . h($subtitle) . '</span>';
    }
    return $html . '</div>';
}

/**
 * Render the grid. $columns from SemesterManagement::locationTeachers().
 * $rows from schedule_row_slots(). $cellFn(array $column, array $row):
 * ?array — null for an empty-but-clickable cell, or
 * ['html' => ..., 'class' => ..., 'attrs' => [k=>v], 'rowspan' => n,
 *  'skip' => bool] (skip = covered by an earlier rowspan).
 *
 * The markup carries everything main.js's setupScheduleGrid() needs to
 * condense and paginate columns client-side: data-col on every teacher
 * header and body cell, data-loc-key + data-label on location headers,
 * data-short / data-student-short for the condensed name swaps, and the
 * column spine as data-columns JSON on the table. The wrapper includes the
 * (initially hidden) pager mount.
 */
function schedule_grid_html(array $columns, array $rows, callable $cellFn): string {
    if (!$columns) {
        return '<div class="card"><p>No teachers are assigned to locations for this semester yet. '
             . '<a href="/admin/semesters.php">Import location teachers</a> to build the grid.</p></div>';
    }

    // Location header cells (colspan per location, preserving column order),
    // and the machine-readable column spine.
    $locationSpans = [];
    $spine = [];
    foreach ($columns as $column) {
        $name = (string)$column['location_name'];
        $locKey = (string)$column['location_id'];
        if (!$locationSpans || $locationSpans[count($locationSpans) - 1]['key'] !== $locKey) {
            $locationSpans[] = ['key' => $locKey, 'name' => $name, 'span' => 0];
        }
        $locationSpans[count($locationSpans) - 1]['span']++;

        $teacherName = trim((string)($column['teacher_preferred_name'] ?: $column['teacher_first_name']) . ' ' . $column['teacher_last_name']);
        $spine[] = ['loc' => $locKey, 'locName' => $name, 'teacher' => $teacherName];
    }

    $html = '<div class="schedule-widget">';
    $html .= '<div class="schedule-pager hidden"></div>';
    $html .= '<div class="schedule-scroll"><table class="schedule-grid" data-columns="' . h(json_encode($spine)) . '">';
    $html .= '<thead><tr><th class="grid-time"></th>';
    foreach ($locationSpans as $span) {
        $html .= '<th class="grid-loc" colspan="' . (int)$span['span'] . '"'
            . ' data-loc-key="' . h($span['key']) . '" data-label="' . h($span['name']) . '">'
            . h($span['name']) . '</th>';
    }
    $html .= '</tr><tr><th class="grid-time"></th>';
    foreach ($columns as $i => $column) {
        $teacherName = $spine[$i]['teacher'];
        $html .= '<th class="grid-teacher" data-col="' . $i . '"'
            . ' data-loc-key="' . h($spine[$i]['loc']) . '"'
            . ' data-short="' . h(schedule_short_teacher_name($teacherName)) . '">'
            . h($teacherName) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr><th class="grid-time">' . h($row['label']) . '</th>';
        foreach ($columns as $i => $column) {
            $cell = $cellFn($column, $row);
            if ($cell !== null && !empty($cell['skip'])) {
                continue; // covered by a rowspan above
            }
            $cellAttrs = (array)($cell['attrs'] ?? []);
            $cellAttrs['data-col'] = $i;
            // Condensed cells lose their status line, so the full context
            // rides along as a hover tooltip and a short-name swap target.
            if (!empty($cellAttrs['data-context']) && !isset($cellAttrs['title'])) {
                $cellAttrs['title'] = $cellAttrs['data-context'];
            }
            if (!empty($cellAttrs['data-student-name']) && !isset($cellAttrs['data-student-short'])) {
                $cellAttrs['data-student-short'] = schedule_short_person_name((string)$cellAttrs['data-student-name']);
            }
            $attrs = '';
            foreach ($cellAttrs as $name => $value) {
                $attrs .= ' ' . $name . '="' . h((string)$value) . '"';
            }
            $rowspan = (int)($cell['rowspan'] ?? 1);
            $html .= '<td class="grid-cell ' . h($cell['class'] ?? '') . '"'
                . ($rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '')
                . $attrs . '>'
                . ($cell['html'] ?? '')
                . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div></div>';
    return $html;
}

/**
 * The reservation status class + label for a grid cell.
 * $balance from Billing::semesterBalancesByStudent (or null when unknown).
 */
function reservation_cell_presentation(array $reservation, ?array $balance): array {
    $status = (string)$reservation['status'];
    if ($status === 'pending_reach_out') {
        return ['class' => 'res-reach-out', 'label' => 'Pending reach out'];
    }
    if ($status === 'pending_confirmation') {
        return ['class' => 'res-pending', 'label' => 'Pending confirmation'];
    }
    // Confirmed: color depends on the outstanding balance.
    $total = (int)($balance['total_balance_cents'] ?? 0);
    $semesterDebit = (int)($balance['semester_debit_cents'] ?? 0);
    if ($total <= 0) {
        return ['class' => 'res-paid', 'label' => 'Confirmed'];
    }
    if ($semesterDebit > 0 && $total >= $semesterDebit) {
        return ['class' => 'res-balance-full', 'label' => 'Balance Due'];
    }
    if ($semesterDebit > 0 && abs($total - intdiv($semesterDebit, 2)) <= 50) {
        return ['class' => 'res-balance-half', 'label' => 'Balance Due'];
    }
    return ['class' => 'res-balance-custom', 'label' => 'Balance Due'];
}
