<?php
// The Semester Schedule: a grid of (location, teacher) columns by timeslot
// rows for the selected semester. A cell holds either a student lesson
// reservation or a hold block (the teacher's lunch, an errand). Empty cells
// open the add modal; filled cells open the matching edit modal.
// Desktop-first (wide layout).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/schedule_grid.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
require_once __DIR__ . '/../lib/HoldBlockManagement.php';
require_once __DIR__ . '/../lib/ReservationUIManager.php';
require_once __DIR__ . '/../lib/Billing.php';
Application::init();
require_admin();

$semesterId = Application::adminSelectedSemesterId();
if ($semesterId === null) {
    header('Location: /admin/setup/index.php');
    exit;
}
$semester = SemesterManagement::find($semesterId);
$grid = ReservationManagement::gridDataForSemester($semesterId);
$columns = $grid['columns'];
$reservations = $grid['reservations'];
$balances = $grid['balances'];

// Both kinds of cell occupy the same weekly slot, so they share the row
// spine and the cell index; 'kind' tells $cellFn how to render each one.
$occupants = [];
foreach ($reservations as $r) {
    $r['kind'] = 'lesson';
    $occupants[] = $r;
}
foreach (HoldBlockManagement::holdBlockReservationsForSemester($semesterId) as $hold) {
    $hold['kind'] = 'hold';
    $occupants[] = $hold;
}

// Row spine: Saturday 9:00–4:30 by default; other days appear (and the time
// range widens) when the semester's dates or its occupants call for them.
$days = [];
foreach (SemesterManagement::locationDates($semesterId) as $dateRow) {
    $days[(int)date('w', strtotime((string)$dateRow['date']))] = true;
}
foreach ($occupants as $occupant) {
    $days[(int)$occupant['day_of_week']] = true;
}
if (!$days) {
    $days = [6 => true];
}
$days = array_keys($days);
sort($days);

$defaultBounds = [9 * 60, 16 * 60 + 30];
$bounds = [];
foreach ($days as $day) {
    $bounds[$day] = $defaultBounds;
}
foreach ($occupants as $occupant) {
    [$h, $m] = array_map('intval', explode(':', (string)$occupant['start_time']));
    $minutes = $h * 60 + $m;
    $day = (int)$occupant['day_of_week'];
    $slot = intdiv($minutes, 30) * 30; // snap to the half-hour row it lives in
    $bounds[$day][0] = min($bounds[$day][0], $slot);
    $bounds[$day][1] = max($bounds[$day][1], $slot);
}
$rows = schedule_row_slots($days, $bounds);

// Occupants that don't sit exactly on a 30-minute slot (e.g. 10:15) are
// keyed to their snapped row so they still render.
$cellIndex = [];
foreach ($occupants as $occupant) {
    [$h, $m] = array_map('intval', explode(':', (string)$occupant['start_time']));
    $slotMinutes = intdiv($h * 60 + $m, 30) * 30;
    $key = $occupant['location_id'] . ':' . $occupant['teacher_user_id'] . ':' . $occupant['day_of_week'] . ':' . $slotMinutes;
    $cellIndex[$key] = $occupant;
}

$cellFn = function (array $column, array $row) use ($cellIndex, $balances) {
    $columnKey = $column['location_id'] . ':' . $column['teacher_user_id'];
    $key = $columnKey . ':' . $row['day'] . ':' . $row['minutes'];
    $occupant = $cellIndex[$key] ?? null;

    $teacherLabel = ($column['teacher_preferred_name'] ?: $column['teacher_first_name'])
        . ' ' . $column['teacher_last_name'];

    if ($occupant === null) {
        // Covered by an earlier rowspan? Look back through prior slots.
        for ($back = 30; $back <= 210; $back += 30) {
            $prior = $cellIndex[$columnKey . ':' . $row['day'] . ':' . ($row['minutes'] - $back)] ?? null;
            if ($prior && (int)ceil((int)$prior['duration_minutes'] / 30) * 30 > $back) {
                return ['skip' => true];
            }
        }
        $context = schedule_day_prefix($row['day']) . ' ' . date('g:i a', mktime(intdiv($row['minutes'], 60), $row['minutes'] % 60))
            . ' · ' . $teacherLabel
            . ' · ' . $column['location_name'];
        return [
            'html' => '',
            'class' => '',
            'attrs' => [
                'data-location-id' => $column['location_id'],
                'data-teacher-id' => $column['teacher_user_id'],
                'data-day' => $row['day'],
                'data-time' => substr($row['time'], 0, 5),
                'data-context' => $context,
            ],
        ];
    }

    if ($occupant['kind'] === 'hold') {
        $context = schedule_day_prefix((int)$occupant['day_of_week']) . ' '
            . date('g:i a', strtotime((string)$occupant['start_time']))
            . ' · ' . ((int)$occupant['duration_minutes']) . ' min'
            . ' · ' . $teacherLabel
            . ' · ' . $column['location_name'];
        return [
            'html' => '<span class="cell-student">' . h((string)$occupant['title']) . '</span>'
                    . '<span class="cell-status">Held</span>',
            'class' => 'res-hold',
            'rowspan' => max(1, (int)ceil((int)$occupant['duration_minutes'] / 30)),
            'attrs' => [
                'data-hold-reservation-id' => $occupant['id'],
                'data-hold-title' => $occupant['title'],
                'data-duration' => (int)$occupant['duration_minutes'],
                'data-context' => $context,
            ],
        ];
    }

    $reservation = $occupant;
    $studentId = (int)$reservation['student_user_id'];
    $balance = $balances[$studentId] ?? null;
    $presentation = reservation_cell_presentation($reservation, $balance);
    $studentName = trim($reservation['student_first_name'] . ' ' . $reservation['student_last_name']);
    $context = schedule_day_prefix((int)$reservation['day_of_week']) . ' '
        . date('g:i a', strtotime((string)$reservation['start_time']))
        . ' · ' . ((int)$reservation['duration_minutes']) . ' min'
        . ' · ' . $teacherLabel
        . ' · ' . $column['location_name'];
    $balanceText = $balance && $balance['total_balance_cents'] > 0
        ? 'Outstanding balance: ' . Billing::formatCents((int)$balance['total_balance_cents'])
        : 'No outstanding balance.';

    return [
        'html' => '<span class="cell-student">' . h($studentName) . '</span>'
                . '<span class="cell-status">' . h($presentation['label']) . '</span>',
        'class' => $presentation['class'],
        'rowspan' => max(1, (int)ceil((int)$reservation['duration_minutes'] / 30)),
        'attrs' => [
            'data-reservation-id' => $reservation['id'],
            'data-status' => $reservation['status'],
            'data-student-id' => $studentId,
            'data-student-name' => $studentName,
            'data-context' => $context,
            'data-balance-text' => $balanceText,
        ],
    ];
};

header_html('Semester Schedule', ['wide' => true]);
?>

<div class="page-head">
  <h2>Semester Schedule — <?=h(SemesterManagement::label($semester))?></h2>
</div>

<div class="grid-legend">
  <span><span class="swatch" style="background:#fff;"></span><em class="small">Pending reach out</em></span>
  <span><span class="swatch" style="background:#fff;"></span>Pending confirmation</span>
  <span><span class="swatch" style="background:var(--res-paid-bg);"></span>Confirmed &middot; paid</span>
  <span><span class="swatch" style="background:var(--res-balance-full-bg);"></span>Full balance due</span>
  <span><span class="swatch" style="background:var(--res-balance-half-bg);"></span>Half balance due</span>
  <span><span class="swatch" style="background:var(--res-balance-custom-bg);"></span>Custom balance due</span>
  <span><span class="swatch" style="background:var(--res-hold-bg);"></span>Hold block</span>
</div>

<?=schedule_grid_html($columns, $rows, $cellFn)?>

<p class="small" style="margin-top:10px;">Click an empty cell to reserve it for a student or hold it
for the teacher; click a filled cell to change or remove it.</p>

<?php ReservationUIManager::renderModals($semesterId); ?>

<?php footer_html(); ?>
