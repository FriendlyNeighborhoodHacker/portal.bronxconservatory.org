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

/**
 * Render the grid. $columns from SemesterManagement::locationTeachers().
 * $rows from schedule_row_slots(). $cellFn(array $column, array $row):
 * ?array — null for an empty-but-clickable cell, or
 * ['html' => ..., 'class' => ..., 'attrs' => [k=>v], 'rowspan' => n,
 *  'skip' => bool] (skip = covered by an earlier rowspan).
 */
function schedule_grid_html(array $columns, array $rows, callable $cellFn): string {
    if (!$columns) {
        return '<div class="card"><p>No teachers are assigned to locations for this semester yet. '
             . '<a href="/admin/semesters.php">Import location teachers</a> to build the grid.</p></div>';
    }

    // Location header cells (colspan per location, preserving column order).
    $locationSpans = [];
    foreach ($columns as $column) {
        $name = (string)$column['location_name'];
        if (!$locationSpans || $locationSpans[count($locationSpans) - 1]['name'] !== $name) {
            $locationSpans[] = ['name' => $name, 'span' => 0];
        }
        $locationSpans[count($locationSpans) - 1]['span']++;
    }

    $html = '<div class="schedule-scroll"><table class="schedule-grid">';
    $html .= '<thead><tr><th class="grid-time"></th>';
    foreach ($locationSpans as $span) {
        $html .= '<th class="grid-loc" colspan="' . (int)$span['span'] . '">' . h($span['name']) . '</th>';
    }
    $html .= '</tr><tr><th class="grid-time"></th>';
    foreach ($columns as $column) {
        $teacherName = trim((string)($column['teacher_preferred_name'] ?: $column['teacher_first_name']) . ' ' . $column['teacher_last_name']);
        $html .= '<th class="grid-teacher">' . h($teacherName) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr><th class="grid-time">' . h($row['label']) . '</th>';
        foreach ($columns as $column) {
            $cell = $cellFn($column, $row);
            if ($cell !== null && !empty($cell['skip'])) {
                continue; // covered by a rowspan above
            }
            $attrs = '';
            foreach (($cell['attrs'] ?? []) as $name => $value) {
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
    $html .= '</tbody></table></div>';
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
