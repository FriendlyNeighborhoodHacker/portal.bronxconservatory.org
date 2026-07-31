<?php
// POST: create a recurring lesson template.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/lesson_form_fields.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/recurring_lessons.php');
    exit;
}
require_csrf();

$fields = lesson_data_from_post($_POST);
$startDate = trim((string)($_POST['recurring_start_date'] ?? ''));
try {
    if (!$fields['teacher_user_id']) {
        throw new InvalidArgumentException('A teacher is required.');
    }
    if ($startDate === '' || trim((string)($_POST['start_time'] ?? '')) === '') {
        throw new InvalidArgumentException('A first date and time are required.');
    }
    if ($fields['lesson_type'] === 'individual' && empty($fields['student_user_id'])) {
        throw new InvalidArgumentException('Pick a student for an individual lesson.');
    }
    LessonManagement::createRecurring(UserContext::getLoggedInUserContext(), [
        'lesson_type' => $fields['lesson_type'],
        'name' => $fields['name'],
        'instrument_id' => $fields['instrument_id'],
        'teacher_user_id' => $fields['teacher_user_id'],
        'student_user_id' => $fields['student_user_id'],
        'location_id' => $fields['location_id'],
        'room' => $fields['room'],
        'is_online' => $fields['is_online'],
        'day_of_week' => (int)date('w', strtotime($startDate)),
        'start_time' => (string)$_POST['start_time'],
        'duration_minutes' => $fields['duration_minutes'],
        'start_date' => $startDate,
        'end_date' => $_POST['recurring_end_date'] ?? null,
    ], $fields['student_user_ids'], [$fields['teacher_user_id']]);
    $_SESSION['recurring_flash'] = 'Recurring lesson created — now generate its occurrences.';
    header('Location: /admin/recurring_lessons.php');
} catch (\Throwable $e) {
    $_SESSION['recurring_flash_error'] = $e->getMessage();
    $_SESSION['recurring_old'] = $_POST;
    header('Location: /admin/recurring_add.php');
}
exit;
