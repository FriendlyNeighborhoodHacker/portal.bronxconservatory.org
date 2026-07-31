<?php
// POST: set/clear a lesson's substitute teacher (the teacher_override).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/lessons.php');
    exit;
}
require_csrf();

$lessonId = (int)($_POST['lesson_id'] ?? 0);
$subId = (int)($_POST['substitute_teacher_user_id'] ?? 0) ?: null;

try {
    LessonManagement::setSubstituteTeacher(UserContext::getLoggedInUserContext(), $lessonId, $subId);
    $_SESSION['lesson_flash'] = $subId ? 'Substitute saved.' : 'Substitute cleared.';
} catch (\Throwable $e) {
    $_SESSION['lesson_flash_error'] = $e->getMessage();
}
header('Location: /admin/lesson.php?id=' . $lessonId);
exit;
