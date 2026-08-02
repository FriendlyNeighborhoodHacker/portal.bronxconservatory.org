<?php
// Ajax POST: mark attendance (1 = attended, 0 = missed, empty = unmark).
// Returns the attendance-controls HTML fragment (same function the dashboard
// renders with).
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
// An absent field is not the same as "0": it is the teacher taking the mark
// back off, which markAttendance stores as NULL.
$raw = trim((string)($_POST['attended'] ?? ''));
$attended = $raw === '' ? null : ((int)$raw === 1);

try {
    LessonManagement::markAttendance($ctx, $lessonId, $attended);
    echo teacher_attendance_html($lessonId, null, $attended === null ? null : ($attended ? 1 : 0));
} catch (\Throwable $e) {
    http_response_code(400);
    echo h($e->getMessage());
}
