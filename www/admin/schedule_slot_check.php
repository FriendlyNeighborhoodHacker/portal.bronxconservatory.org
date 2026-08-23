<?php
// GET JSON: is this weekly slot free for this teacher, for a lesson of this
// length? Backs the slot picker on the lead-conversion page.
//
// Advisory only — the authority is still ReservationManagement::createReservation,
// which re-checks inside the transaction that actually books the slot. What
// this buys is telling the admin before they convert a family, instead of
// after the parent and student records already exist.
//
// {ok:true, available:true, summary}       — free, with the label to display
// {ok:true, available:false, conflict}     — taken, with the reason
// {ok:false, error}                        — the request itself was wrong
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/reservation_picker_modal.php';
require_once __DIR__ . '/../lib/ScheduleConflicts.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');

$semesterId = (int)($_GET['semester_id'] ?? 0);
$locationId = (int)($_GET['location_id'] ?? 0);
$teacherUserId = (int)($_GET['teacher_user_id'] ?? 0);
$dayOfWeek = (int)($_GET['day_of_week'] ?? -1);
$startTime = trim((string)($_GET['start_time'] ?? ''));
$duration = (int)($_GET['duration_minutes'] ?? 0);

if ($dayOfWeek < 0 || $dayOfWeek > 6) {
    echo json_encode(['ok' => false, 'error' => 'Pick a day of the week.']);
    exit;
}
if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $startTime)) {
    echo json_encode(['ok' => false, 'error' => 'That start time does not look right.']);
    exit;
}
if ($duration <= 0 || $duration > 240) {
    echo json_encode(['ok' => false, 'error' => 'Lesson length looks wrong.']);
    exit;
}
if (!SemesterManagement::isTeacherAtLocation($semesterId, $locationId, $teacherUserId, $dayOfWeek)) {
    echo json_encode(['ok' => false, 'error' => 'That teacher is not assigned to that location on that day this semester.']);
    exit;
}

$conflict = ScheduleConflicts::weeklySlotConflict($semesterId, $teacherUserId, $dayOfWeek, $startTime, $duration);
if ($conflict !== null) {
    echo json_encode(['ok' => true, 'available' => false, 'conflict' => $conflict]);
    exit;
}

$column = reservation_pick_column(SemesterManagement::locationTeachers($semesterId), $locationId, $teacherUserId);
echo json_encode([
    'ok' => true,
    'available' => true,
    'summary' => $column ? reservation_pick_summary($column, $dayOfWeek, $startTime, $duration) : '',
]);
