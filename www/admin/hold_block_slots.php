<?php
// GET JSON: the times one hold block could move to on its own day — every
// 30-minute slot in the day's class window not occupied by another lesson or
// hold block of the same teacher. {ok, items:[{value:"HH:MM", label, current}]}
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/HoldBlockManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');
$block = HoldBlockManagement::getBlock((int)($_GET['hold_block_id'] ?? 0));
if (!$block) {
    echo json_encode(['ok' => false, 'error' => 'Hold block not found.']);
    exit;
}

$date = date('Y-m-d', strtotime((string)$block['start_datetime']));
$currentTime = date('H:i', strtotime((string)$block['start_datetime']));
$duration = (int)$block['duration_minutes'];
$teacherUserId = (int)$block['teacher_user_id'];

// The day's window comes from the location's class date; default 9:00–5:00.
$windowStart = 9 * 60;
$windowEnd = 17 * 60;
foreach (SemesterManagement::locationDates((int)$block['semester_id'], (int)$block['location_id']) as $dateRow) {
    if ($dateRow['date'] === $date) {
        $windowStart = (int)substr($dateRow['start_time'], 0, 2) * 60 + (int)substr($dateRow['start_time'], 3, 2);
        $windowEnd = (int)substr($dateRow['end_time'], 0, 2) * 60 + (int)substr($dateRow['end_time'], 3, 2);
        break;
    }
}

// Busy intervals for the same teacher that day: their lessons, plus their
// other hold blocks.
$busy = [];
foreach (LessonManagement::lessonsForTeacherOnDate($teacherUserId, $date) as $lesson) {
    $ts = strtotime((string)$lesson['start_datetime']);
    $start = (int)date('G', $ts) * 60 + (int)date('i', $ts);
    $busy[] = [$start, $start + (int)$lesson['duration_minutes']];
}
foreach (HoldBlockManagement::holdBlocksForTeacherOnDate($teacherUserId, $date) as $other) {
    if ((int)$other['id'] === (int)$block['id']) {
        continue;
    }
    $ts = strtotime((string)$other['start_datetime']);
    $start = (int)date('G', $ts) * 60 + (int)date('i', $ts);
    $busy[] = [$start, $start + (int)$other['duration_minutes']];
}

$items = [];
for ($m = $windowStart; $m + $duration <= $windowEnd; $m += 30) {
    $conflicts = false;
    foreach ($busy as [$busyStart, $busyEnd]) {
        if ($m < $busyEnd && $busyStart < $m + $duration) {
            $conflicts = true;
            break;
        }
    }
    $value = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    if ($conflicts && $value !== $currentTime) {
        continue;
    }
    $items[] = [
        'value' => $value,
        'label' => date('g:i a', mktime(intdiv($m, 60), $m % 60)),
        'current' => $value === $currentTime,
    ];
}
// The current time always appears even if it sits outside the window.
if (!array_filter($items, fn($it) => $it['current'])) {
    $items[] = ['value' => $currentTime, 'label' => date('g:i a', strtotime($currentTime)), 'current' => true];
}

echo json_encode(['ok' => true, 'items' => $items]);
