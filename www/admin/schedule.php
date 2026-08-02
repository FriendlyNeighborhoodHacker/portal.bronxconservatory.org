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
require_once __DIR__ . '/../lib/ScheduleGridData.php';
require_once __DIR__ . '/../lib/Billing.php';
Application::init();
require_admin();

$semesterId = Application::adminSelectedSemesterId();
if ($semesterId === null) {
    header('Location: /admin/setup/index.php');
    exit;
}
$semester = SemesterManagement::find($semesterId);

// The weekly spine — the same one the lead-conversion slot picker draws from.
$grid = ScheduleGridData::semesterWeeklyGrid($semesterId);
$columns = $grid['columns'];
$balances = $grid['balances'];
$cellIndex = $grid['cellIndex'];
$rows = schedule_row_slots($grid['days'], $grid['bounds']);

$cellFn = function (array $column, array $row) use ($cellIndex, $balances) {
    $columnKey = $column['location_id'] . ':' . $column['teacher_user_id'];
    $cellOccupants = ScheduleGridData::occupantsAt($cellIndex, $column, $row);

    $teacherLabel = ($column['teacher_preferred_name'] ?: $column['teacher_first_name'])
        . ' ' . $column['teacher_last_name'];

    if (!$cellOccupants) {
        if (ScheduleGridData::coveredByEarlierOccupant($cellIndex, $columnKey, (int)$row['day'], (int)$row['minutes'])) {
            return ['skip' => true];
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

    // A cell normally holds one commitment; a double-booked teacher shows all
    // of them, and the row grows to fit.
    $multi = count($cellOccupants) > 1;
    $items = '';
    $rowspan = 1;
    $contexts = [];

    foreach ($cellOccupants as $occupant) {
        $duration = (int)$occupant['duration_minutes'];
        $rowspan = max($rowspan, (int)ceil($duration / 30));
        $context = schedule_day_prefix((int)$occupant['day_of_week']) . ' '
            . date('g:i a', strtotime((string)$occupant['start_time']))
            . ' · ' . $duration . ' min'
            . ' · ' . $teacherLabel
            . ' · ' . $column['location_name'];
        $contexts[] = $context;

        if ($occupant['kind'] === 'hold') {
            $items .= schedule_cell_item_html((string)$occupant['title'], 'Held', [
                'data-hold-reservation-id' => $occupant['id'],
                'data-hold-title' => $occupant['title'],
                'data-duration' => $duration,
                'data-context' => $context,
            ], $multi ? 'res-hold' : '');
            continue;
        }

        $studentId = (int)$occupant['student_user_id'];
        $balance = $balances[$studentId] ?? null;
        $presentation = reservation_cell_presentation($occupant, $balance);
        $studentName = trim($occupant['student_first_name'] . ' ' . $occupant['student_last_name']);
        $balanceText = $balance && $balance['total_balance_cents'] > 0
            ? 'Outstanding balance: ' . Billing::formatCents((int)$balance['total_balance_cents'])
            : 'No outstanding balance.';

        $items .= schedule_cell_item_html($studentName, $presentation['label'], [
            'data-reservation-id' => $occupant['id'],
            'data-status' => $occupant['status'],
            'data-student-id' => $studentId,
            'data-student-name' => $studentName,
            'data-context' => $context,
            'data-balance-text' => $balanceText,
        ], $multi ? $presentation['class'] : '');
    }

    if ($multi) {
        return [
            'html' => $items,
            'class' => 'res-multi',
            'rowspan' => $rowspan,
            'attrs' => ['data-context' => count($cellOccupants) . ' bookings · ' . implode(' / ', $contexts)],
        ];
    }

    $only = $cellOccupants[0];
    $stateClass = $only['kind'] === 'hold'
        ? 'res-hold'
        : reservation_cell_presentation($only, $balances[(int)$only['student_user_id']] ?? null)['class'];

    return [
        'html' => $items,
        'class' => $stateClass,
        'rowspan' => $rowspan,
        'attrs' => ['data-context' => $contexts[0]],
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
