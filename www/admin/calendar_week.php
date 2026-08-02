<?php
// Admin Calendar — weekly view: the Semester Schedule's column structure,
// but with the real lessons of one week. Clicking a lesson opens the lesson
// modal (reschedule / missed / substitute / note).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/schedule_grid.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/HoldBlockManagement.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
require_once __DIR__ . '/../lib/LessonUIManager.php';
require_once __DIR__ . '/../lib/HoldBlockUIManager.php';
require_once __DIR__ . '/schedule_edit_mode.php';
Application::init();
require_admin();

$semesterId = Application::adminSelectedSemesterId();
if ($semesterId === null) {
    header('Location: /admin/setup/index.php');
    exit;
}
$semester = SemesterManagement::find($semesterId);

$date = (string)($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$anchorTs = strtotime($date);
$weekStartTs = strtotime('-' . date('w', $anchorTs) . ' days', $anchorTs); // Sunday
$weekStart = date('Y-m-d', $weekStartTs);
$weekEnd = date('Y-m-d', strtotime('+6 days', $weekStartTs));

// Cancelled lessons are left out: the slot is free again, and showing it here
// would suggest otherwise. The family and the teacher still see it on theirs.
$lessons = LessonManagement::lessonsBetween($weekStart, $weekEnd, $semesterId, false);
$holdBlocks = HoldBlockManagement::holdBlocksBetween($weekStart, $weekEnd, $semesterId);

// Notes per lesson (for prefilling the modal's note box with MY note).
$myUserId = (int)current_user()['id'];
$notesByLesson = [];
foreach ($lessons as $lesson) {
    $note = NotesManagement::lessonNoteFor((int)$lesson['id'], $myUserId);
    if ($note) {
        $notesByLesson[(int)$lesson['id']] = (string)$note['body'];
    }
}

// Days shown: any weekday this week having lessons, hold blocks, or
// scheduled class dates.
$days = [];
foreach (array_merge($lessons, $holdBlocks) as $entry) {
    $days[(int)date('w', strtotime((string)$entry['start_datetime']))] = true;
}
foreach (SemesterManagement::locationDates($semesterId) as $dateRow) {
    if ($dateRow['date'] >= $weekStart && $dateRow['date'] <= $weekEnd) {
        $days[(int)date('w', strtotime((string)$dateRow['date']))] = true;
    }
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
// Lessons and hold blocks share the grid: both are things occupying a
// teacher's column at a moment in the week.
$occupants = [];
foreach ($lessons as $lesson) {
    $lesson['kind'] = 'lesson';
    $lesson['_teacher_user_id'] = (int)$lesson['effective_teacher_user_id'];
    $occupants[] = $lesson;
}
foreach ($holdBlocks as $hold) {
    $hold['kind'] = 'hold';
    $hold['_teacher_user_id'] = (int)$hold['teacher_user_id'];
    $occupants[] = $hold;
}

// Columns follow the EFFECTIVE teacher, so a lesson someone is substituting
// shows up in their column — including when they have no column at that
// location this semester, which is exactly when it would otherwise vanish.
$columns = SemesterManagement::locationTeachersIncluding(
    SemesterManagement::locationTeachers($semesterId),
    array_map(
        fn(array $o): array => ['location_id' => (int)$o['location_id'], 'teacher_user_id' => $o['_teacher_user_id']],
        $occupants
    )
);

// Each key holds a LIST: double-booking is prevented, but if one ever slips
// through, the cell shows every commitment rather than hiding one.
$cellIndex = [];
foreach ($occupants as $occupant) {
    $ts = strtotime((string)$occupant['start_datetime']);
    $day = (int)date('w', $ts);
    $minutes = (int)date('G', $ts) * 60 + (int)date('i', $ts);
    $slot = intdiv($minutes, 30) * 30;
    $bounds[$day][0] = min($bounds[$day][0] ?? $slot, $slot);
    $bounds[$day][1] = max($bounds[$day][1] ?? $slot, $slot);
    $key = $occupant['location_id'] . ':' . $occupant['_teacher_user_id'] . ':' . $day . ':' . $slot;
    $cellIndex[$key][] = $occupant;
}
$rows = schedule_row_slots($days, $bounds);

$cellFn = function (array $column, array $row) use ($cellIndex, $notesByLesson, $weekStartTs) {
    $columnKey = $column['location_id'] . ':' . $column['teacher_user_id'];
    $slotKey = $columnKey . ':' . $row['day'] . ':' . $row['minutes'];
    $cellOccupants = $cellIndex[$slotKey] ?? [];
    if (!$cellOccupants) {
        for ($back = 30; $back <= 210; $back += 30) {
            foreach ($cellIndex[$columnKey . ':' . $row['day'] . ':' . ($row['minutes'] - $back)] ?? [] as $prior) {
                if ((int)ceil((int)$prior['duration_minutes'] / 30) * 30 > $back) {
                    return ['skip' => true];
                }
            }
        }
        // Empty cells carry the real date as well as the slot, because a drag
        // here moves one dated lesson, not a weekly pattern.
        return [
            'html' => '',
            'class' => '',
            'attrs' => [
                'data-slot-free' => '1',
                'data-slot-key' => $slotKey,
                'data-location-id' => $column['location_id'],
                'data-teacher-id' => $column['teacher_user_id'],
                'data-date' => date('Y-m-d', strtotime('+' . (int)$row['day'] . ' days', $weekStartTs)),
                'data-time' => substr($row['time'], 0, 5),
            ],
        ];
    }

    $multi = count($cellOccupants) > 1;
    $items = '';
    $rowspan = 1;
    $contexts = [];
    $soleClass = '';

    foreach ($cellOccupants as $occupant) {
        $duration = (int)$occupant['duration_minutes'];
        $rowspan = max($rowspan, (int)ceil($duration / 30));

        if ($occupant['kind'] === 'hold') {
            // data-hold-block-id (not data-lesson-id) opens HoldBlockUIManager's
            // modal, which edits this ONE week.
            $context = date('D M j · g:i a', strtotime((string)$occupant['start_datetime']))
                . ' · ' . $duration . ' min'
                . ' · ' . $occupant['location_name'];
            $contexts[] = $context;
            $soleClass = 'res-hold';
            $items .= schedule_cell_item_html((string)$occupant['effective_title'], 'Held', [
                'data-hold-block-id' => $occupant['id'],
                'data-hold-title' => $occupant['effective_title'],
                'data-context' => $context,
            ], $multi ? 'res-hold' : '');
            continue;
        }

        $lesson = $occupant;
        $studentName = trim($lesson['student_first_name'] . ' ' . $lesson['student_last_name']);
        $missed = $lesson['attended'] !== null && (int)$lesson['attended'] === 0;
        $attendedValue = $lesson['attended'] === null ? '' : (string)(int)$lesson['attended'];
        $substituteName = $lesson['substitute_teacher_user_id']
            ? trim(($lesson['substitute_first_name'] ?? '') . ' ' . ($lesson['substitute_last_name'] ?? ''))
            : '';
        $timeMoved = LessonManagement::isTimeMoved($lesson);
        $statusBits = [];
        if ($missed) {
            $statusBits[] = 'Missed';
        } elseif ($lesson['attended'] !== null) {
            $statusBits[] = 'Attended';
        }
        if ($timeMoved) {
            $statusBits[] = 'Time moved';
        }
        if ($substituteName !== '') {
            $statusBits[] = LessonManagement::substituteNote($lesson);
        }
        $context = date('D M j · g:i a', strtotime((string)$lesson['start_datetime']))
            . ' · Lesson #' . (int)$lesson['lesson_number']
            . ' · ' . $lesson['location_name']
            . ($timeMoved ? ' · Time moved from ' . date('g:i a', strtotime((string)$lesson['reservation_start_time'])) : '')
            . ($substituteName !== '' ? ' · ' . LessonManagement::substituteNote($lesson) : '');
        $contexts[] = $context;
        // A covered week reads as its own thing: pastel orange says "this is
        // not the usual teacher" at a glance, without opening anything.
        $soleClass = $substituteName !== ''
            ? 'res-substitute'
            : ($missed ? 'res-reach-out' : 'res-paid');

        $items .= schedule_cell_item_html($studentName, implode(' · ', $statusBits), [
            'data-lesson-id' => $lesson['id'],
            'data-student-name' => $studentName,
            'data-attended' => $attendedValue,
            'data-substitute-name' => $substituteName,
            'data-duration' => $duration,
            'data-note' => $notesByLesson[(int)$lesson['id']] ?? '',
            'data-context' => $context,
        ], $multi ? $soleClass : ($missed ? 'lesson-cancelled' : ''));
    }

    return [
        'html' => $items,
        'class' => $multi ? 'res-multi' : $soleClass,
        'rowspan' => $rowspan,
        'attrs' => [
            'data-context' => $multi
                ? count($cellOccupants) . ' bookings · ' . implode(' / ', $contexts)
                : $contexts[0],
        ],
    ];
};

$prevWeek = date('Y-m-d', strtotime('-7 days', $weekStartTs));
$nextWeek = date('Y-m-d', strtotime('+7 days', $weekStartTs));

header_html('Calendar Week', ['wide' => true]);
?>

<div class="page-head">
  <h2>Week of <?=h(date('M j', $weekStartTs))?>–<?=h(date('M j, Y', strtotime($weekEnd)))?>
    — <?=h(SemesterManagement::label($semester))?></h2>
  <span class="actions">
    <a class="button" href="/admin/calendar_week.php?date=<?=h($prevWeek)?>">&larr; Previous</a>
    <a class="button" href="/admin/calendar_week.php?date=<?=h($nextWeek)?>">Next &rarr;</a>
    <?=schedule_edit_toggle_html()?>
  </span>
</div>

<?php if (!$lessons && !$holdBlocks): ?>
  <div class="card"><p>Nothing scheduled this week.</p></div>
<?php else: ?>
  <?=schedule_grid_html($columns, $rows, $cellFn)?>
  <div class="grid-legend" style="margin-top:10px;">
    <span><span class="swatch" style="background:var(--res-paid-bg);"></span>Scheduled</span>
    <span><span class="swatch" style="background:var(--res-substitute-bg);"></span>Substitute teacher</span>
    <span><span class="swatch" style="background:#fff;"></span><em class="small">Missed</em></span>
    <span><span class="swatch" style="background:var(--res-hold-bg);"></span>Hold block</span>
  </div>
  <p class="small" style="margin-top:10px;">Click a lesson to reschedule it, mark it missed,
  assign a substitute, cancel it, or add a note. Click a grey hold block to change just that
  week. Press <strong>Edit</strong> to drag a lesson to another time — dropping it on another
  teacher makes them the substitute for that week.</p>
<?php endif; ?>

<?php LessonUIManager::renderModal(); ?>
<?php HoldBlockUIManager::renderModal(); ?>
<?php render_schedule_edit_mode([
    'endpoint' => '/admin/lesson_move.php',
    'item_attr' => 'lessonId',
    'id_field' => 'lesson_id',
    'fields' => [
        'location_id' => 'locationId',
        'teacher_user_id' => 'teacherId',
        'date' => 'date',
        'start_time' => 'time',
    ],
    'noun' => 'lesson',
]); ?>

<?php footer_html(); ?>
