<?php
// Admin Calendar — weekly view: the Semester Schedule's column structure,
// but with the real lessons of one week. Clicking a lesson opens the lesson
// modal (length / missed / substitute / location / cancel).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/schedule_grid.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/HoldBlockManagement.php';
require_once __DIR__ . '/../lib/ScheduleGridData.php';
require_once __DIR__ . '/../lib/LessonUIManager.php';
require_once __DIR__ . '/../lib/HoldBlockUIManager.php';
require_once __DIR__ . '/../lib/CalendarAddUIManager.php';
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
    $days = [SemesterManagement::DEFAULT_TEACHING_DAY => true];
}
$days = array_keys($days);
sort($days);

// Each day runs over its real opening hours — Saturday and Tuesday may keep
// entirely different ones.
$hours = SemesterManagement::dayHoursForSemester($semesterId);
$bounds = [];
foreach ($days as $day) {
    $bounds[$day] = isset($hours[$day])
        ? ScheduleGridData::slotBounds($hours[$day])
        : ScheduleGridData::DEFAULT_BOUNDS;
}
// Lessons and hold blocks share the grid: both are things occupying a
// teacher's column at a moment in the week.
//
// Both _teacher_user_id and _location_id are the EFFECTIVE values — where the
// lesson is actually happening and who is actually taking it. A week moved to
// another room belongs in that room's column, not the one its standing
// booking names.
$occupants = [];
foreach ($lessons as $lesson) {
    $lesson['kind'] = 'lesson';
    $lesson['_teacher_user_id'] = (int)$lesson['effective_teacher_user_id'];
    $lesson['_location_id'] = (int)$lesson['effective_location_id'];
    $occupants[] = $lesson;
}
foreach ($holdBlocks as $hold) {
    $hold['kind'] = 'hold';
    $hold['_teacher_user_id'] = (int)$hold['teacher_user_id'];
    $hold['_location_id'] = (int)$hold['location_id'];
    $occupants[] = $hold;
}

// Each key holds a LIST: double-booking is prevented, but if one ever slips
// through, the cell shows every commitment rather than hiding one.
$cellIndex = [];
$dayPairs = [];
foreach ($occupants as $occupant) {
    $ts = strtotime((string)$occupant['start_datetime']);
    $day = (int)date('w', $ts);
    $minutes = (int)date('G', $ts) * 60 + (int)date('i', $ts);
    $slot = intdiv($minutes, 30) * 30;
    $bounds[$day][0] = min($bounds[$day][0] ?? $slot, $slot);
    $bounds[$day][1] = max($bounds[$day][1] ?? $slot, $slot);
    $key = $occupant['_location_id'] . ':' . $occupant['_teacher_user_id'] . ':' . $day . ':' . $slot;
    $cellIndex[$key][] = $occupant;
    $dayPairs[$day][] = ['location_id' => $occupant['_location_id'], 'teacher_user_id' => $occupant['_teacher_user_id']];
}

// One band per day, each with the teachers assigned to THAT day, widened by
// the effective pair of anything actually happening on it — so a lesson
// someone is substituting, at whichever building it is being held in, shows
// up in their column there, including when neither of them has a column at
// that location this semester, which is exactly when it would otherwise
// vanish.
$bands = [];
foreach ($days as $day) {
    $bands[] = [
        'day' => $day,
        'label' => date('l, M j', strtotime('+' . $day . ' days', $weekStartTs)),
        'columns' => SemesterManagement::locationTeachersIncluding(
            SemesterManagement::locationTeachers($semesterId, $day),
            $dayPairs[$day] ?? []
        ),
        'bounds' => $bounds[$day],
    ];
}

$cellFn = function (array $column, array $row) use ($cellIndex, $weekStartTs) {
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
        // Empty cells carry the real date as well as the slot, because both
        // dragging and adding here act on one dated occurrence, not a weekly
        // pattern.
        $date = date('Y-m-d', strtotime('+' . (int)$row['day'] . ' days', $weekStartTs));
        $teacherLabel = ($column['teacher_preferred_name'] ?: $column['teacher_first_name'])
            . ' ' . $column['teacher_last_name'];
        return [
            'html' => '',
            'class' => '',
            'attrs' => [
                'data-slot-free' => '1',
                'data-slot-key' => $slotKey,
                'data-location-id' => $column['location_id'],
                'data-teacher-id' => $column['teacher_user_id'],
                'data-date' => $date,
                'data-time' => substr($row['time'], 0, 5),
                'data-context' => date('D M j', strtotime($date))
                    . ' · ' . date('g:i a', mktime(intdiv($row['minutes'], 60), $row['minutes'] % 60))
                    . ' · ' . $teacherLabel
                    . ' · ' . $column['location_name'],
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
            'data-student-id' => (int)$lesson['student_user_id'],
            'data-attended' => $attendedValue,
            'data-substitute-id' => (int)($lesson['substitute_teacher_user_id'] ?? 0) ?: '',
            'data-substitute-name' => $substituteName,
            'data-location-id' => (int)$lesson['effective_location_id'],
            'data-location-name' => (string)$lesson['location_name'],
            'data-duration' => $duration,
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
  <p class="small">Nothing scheduled this week yet.</p>
<?php endif; ?>

<?php /* The grid is drawn even on an empty week — that is precisely when you
         want to be able to click a slot and put something in it. */ ?>
  <?=schedule_day_grids_html($bands, $cellFn, 'week')?>
  <div class="grid-legend" style="margin-top:10px;">
    <span><span class="swatch" style="background:var(--res-paid-bg);"></span>Scheduled</span>
    <span><span class="swatch" style="background:var(--res-substitute-bg);"></span>Substitute teacher</span>
    <span><span class="swatch" style="background:#fff;"></span><em class="small">Missed</em></span>
    <span><span class="swatch" style="background:var(--res-hold-bg);"></span>Hold block</span>
  </div>
  <p class="small" style="margin-top:10px;">Click an empty cell to add a one-off lesson or hold
  this time. Click a lesson to mark it missed, assign a substitute, change its location, cancel
  it, or add a note. Click a grey hold block to change just that week. Press <strong>Edit</strong>
  to drag a lesson to another time — dropping it on another teacher makes them the substitute for
  that week.</p>

<?php LessonUIManager::renderModal($semesterId); ?>
<?php HoldBlockUIManager::renderModal(); ?>
<?php CalendarAddUIManager::renderModal($semesterId); ?>
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
