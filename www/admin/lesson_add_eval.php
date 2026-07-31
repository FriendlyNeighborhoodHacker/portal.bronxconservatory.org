<?php
// POST: create a one-off lesson.
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

$fields = lesson_data_from_post($_POST);
try {
    if (!$fields['teacher_user_id']) {
        throw new InvalidArgumentException('A teacher is required.');
    }
    if (trim($fields['start_datetime']) === '') {
        throw new InvalidArgumentException('Date and time are required.');
    }
    if ($fields['lesson_type'] === 'individual' && empty($fields['student_user_id'])) {
        throw new InvalidArgumentException('Pick a student for an individual lesson.');
    }
    $lessonId = LessonManagement::createLesson(UserContext::getLoggedInUserContext(), $fields);
    $_SESSION['lesson_flash'] = 'Lesson created.';
    header('Location: /admin/lesson.php?id=' . $lessonId);
} catch (\Throwable $e) {
    $_SESSION['lesson_flash_error'] = $e->getMessage();
    $_SESSION['lesson_old'] = $_POST;
    header('Location: /admin/lesson_add.php');
}
exit;
