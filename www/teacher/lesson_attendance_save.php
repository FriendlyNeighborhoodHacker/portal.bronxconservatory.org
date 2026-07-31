<?php
// Ajax POST: mark attendance. Returns the attendance-controls HTML fragment
// (same function the dashboard renders with).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/fragments.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}
require_csrf();

$ctx = UserContext::getLoggedInUserContext();
$lessonId = (int)($_POST['lesson_id'] ?? 0);
$studentUserId = (int)($_POST['student_user_id'] ?? 0) ?: null;
$attended = !empty($_POST['attended']);

try {
    LessonManagement::markAttendance($ctx, $lessonId, $studentUserId, $attended);
    echo teacher_attendance_html($lessonId, $studentUserId, $attended ? 1 : 0);
} catch (\Throwable $e) {
    http_response_code(400);
    echo h($e->getMessage());
}
