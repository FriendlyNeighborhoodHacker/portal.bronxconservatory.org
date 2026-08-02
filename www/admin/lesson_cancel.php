<?php
// POST JSON: cancel one lesson. It leaves the admin calendar and stops
// holding the slot; the family and the teacher still see it, marked cancelled.
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

try {
    LessonManagement::cancelLesson(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['lesson_id'] ?? 0)
    );
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
