<?php
// Admin Calendar — weekly view: the Semester Schedule's column structure,
// but with the real lessons of one week. Clicking a lesson opens the lesson
// modal (reschedule / missed / substitute / note).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/schedule_grid.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
require_once __DIR__ . '/../lib/LessonUIManager.php';
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

// Notes per lesson (for prefilling the modal's note box with MY note).
$myUserId = (int)current_user()['id'];
$notesByLesson = [];
foreach ($lessons as $lesson) {
    $note = NotesManagement::lessonNoteFor((int)$lesson['id'], $myUserId);
    if ($note) {
        $notesByLesson[(int)$lesson['id']] = (string)$note['body'];
    }
}

// Days shown: any weekday this week having lessons or scheduled class dates.
$days = [];
foreach ($lessons as $lesson) {
    $days[(int)date('w', strtotime((string)$lesson['start_datetime']))] = true;
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
$cellIndex = [];
foreach ($lessons as $lesson) {
    $ts = strtotime((string)$lesson['start_datetime']);
    $day = (int)date('w', $ts);
    $minutes = (int)date('G', $ts) * 60 + (int)date('i', $ts);
    $slot = intdiv($minutes, 30) * 30;
    $bounds[$day][0] = min($bounds[$day][0] ?? $slot, $slot);
    $bounds[$day][1] = max($bounds[$day][1] ?? $slot, $slot);
    $key = $lesson['location_id'] . ':' . $lesson['effective_teacher_user_id'] . ':' . $day . ':' . $slot;
    $cellIndex[$key] = $lesson;
}
$rows = schedule_row_slots($days, $bounds);

$cellFn = function (array $column, array $row) use ($cellIndex, $notesByLesson) {
    $columnKey = $column['location_id'] . ':' . $column['teacher_user_id'];
    $lesson = $cellIndex[$columnKey . ':' . $row['day'] . ':' . $row['minutes']] ?? null;
    if ($lesson === null) {
        for ($back = 30; $back <= 210; $back += 30) {
            $prior = $cellIndex[$columnKey . ':' . $row['day'] . ':' . ($row['minutes'] - $back)] ?? null;
            if ($prior && (int)ceil((int)$prior['duration_minutes'] / 30) * 30 > $back) {
                return ['skip' => true];
            }
        }
        return ['html' => '', 'class' => '', 'attrs' => []];
    }

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

    return [
        'html' => '<span class="cell-student' . ($missed ? ' lesson-cancelled' : '') . '">' . h($studentName) . '</span>'
                . ($statusBits ? '<span class="cell-status">' . h(implode(' · ', $statusBits)) . '</span>' : ''),
        'class' => $missed ? 'res-reach-out' : 'res-paid',
        'rowspan' => max(1, (int)ceil((int)$lesson['duration_minutes'] / 30)),
        'attrs' => [
            'data-lesson-id' => $lesson['id'],
            'data-student-name' => $studentName,
            'data-attended' => $attendedValue,
            'data-substitute-name' => $substituteName,
            'data-note' => $notesByLesson[(int)$lesson['id']] ?? '',
            'data-context' => $context,
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

<?php if (!$lessons): ?>
  <div class="card"><p>No lessons this week.</p></div>
<?php else: ?>
  <?=schedule_grid_html($columns, $rows, $cellFn)?>
  <p class="small" style="margin-top:10px;">Click a lesson to reschedule it within the day,
  mark it missed, assign a substitute, or add a note.</p>
<?php endif; ?>

<?php LessonUIManager::renderModal(); ?>

<?php footer_html(); ?>
