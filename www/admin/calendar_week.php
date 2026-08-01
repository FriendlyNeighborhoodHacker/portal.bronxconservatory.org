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

$columns = SemesterManagement::locationTeachers($semesterId);
$lessons = LessonManagement::lessonsBetween($weekStart, $weekEnd, $semesterId);
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

$cellFn = function (array $column, array $row) use ($cellIndex, $notesByLesson) {
    $columnKey = $column['location_id'] . ':' . $column['teacher_user_id'];
    $cellOccupants = $cellIndex[$columnKey . ':' . $row['day'] . ':' . $row['minutes']] ?? [];
    if (!$cellOccupants) {
        for ($back = 30; $back <= 210; $back += 30) {
            foreach ($cellIndex[$columnKey . ':' . $row['day'] . ':' . ($row['minutes'] - $back)] ?? [] as $prior) {
                if ((int)ceil((int)$prior['duration_minutes'] / 30) * 30 > $back) {
                    return ['skip' => true];
                }
            }
        }
        return ['html' => '', 'class' => '', 'attrs' => []];
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
        $statusBits = [];
        if ($missed) {
            $statusBits[] = 'Missed';
        } elseif ($lesson['attended'] !== null) {
            $statusBits[] = 'Attended';
        }
        if ($substituteName !== '') {
            $statusBits[] = 'Sub: ' . $substituteName;
        }
        $context = date('D M j · g:i a', strtotime((string)$lesson['start_datetime']))
            . ' · Lesson #' . (int)$lesson['lesson_number']
            . ' · ' . $lesson['location_name'];
        $contexts[] = $context;
        $soleClass = $missed ? 'res-reach-out' : 'res-paid';

        $items .= schedule_cell_item_html($studentName, implode(' · ', $statusBits), [
            'data-lesson-id' => $lesson['id'],
            'data-student-name' => $studentName,
            'data-attended' => $attendedValue,
            'data-substitute-name' => $substituteName,
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
  </span>
</div>

<?php if (!$lessons && !$holdBlocks): ?>
  <div class="card"><p>Nothing scheduled this week.</p></div>
<?php else: ?>
  <?=schedule_grid_html($columns, $rows, $cellFn)?>
  <p class="small" style="margin-top:10px;">Click a lesson to reschedule it within the day,
  mark it missed, assign a substitute, or add a note. Click a grey hold block to change
  just that week.</p>
<?php endif; ?>

<?php LessonUIManager::renderModal(); ?>
<?php HoldBlockUIManager::renderModal(); ?>

<?php footer_html(); ?>
