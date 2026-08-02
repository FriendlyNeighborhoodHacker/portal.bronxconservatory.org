<?php
// POST JSON: move one lesson to another moment and/or another teacher — the
// weekly calendar's drag-and-drop.
//
// Landing on a different teacher's column sets that teacher as the
// substitute for this week only; the standing reservation is never touched
// from here. LessonManagement::moveLesson refuses the move if the teacher who
// would end up with it is already booked.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
require_csrf();

$date = trim((string)($_POST['date'] ?? ''));
$startTime = trim((string)($_POST['start_time'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $startTime)) {
    echo json_encode(['ok' => false, 'error' => 'That is not a time we understand.']);
    exit;
}

try {
    $teacherUserId = (int)($_POST['teacher_user_id'] ?? 0);
    $locationId = (int)($_POST['location_id'] ?? 0);
    LessonManagement::moveLesson(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['lesson_id'] ?? 0),
        $date . ' ' . $startTime,
        $teacherUserId > 0 ? $teacherUserId : null,
        $locationId > 0 ? $locationId : null
    );
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
