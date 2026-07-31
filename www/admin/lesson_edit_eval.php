<?php
// POST: save a lesson edit.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/lesson_form_fields.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/lessons.php');
    exit;
}
require_csrf();

$lessonId = (int)($_POST['id'] ?? 0);
$fields = lesson_data_from_post($_POST);
try {
    if (!$fields['teacher_user_id']) {
        throw new InvalidArgumentException('A teacher is required.');
    }
    LessonManagement::updateLesson(UserContext::getLoggedInUserContext(), $lessonId, $fields);
    $_SESSION['lesson_flash'] = 'Lesson saved.';
    header('Location: /admin/lesson.php?id=' . $lessonId);
} catch (\Throwable $e) {
    $_SESSION['lesson_flash_error'] = $e->getMessage();
    $_SESSION['lesson_old'] = $_POST;
    header('Location: /admin/lesson_edit.php?id=' . $lessonId);
}
exit;
