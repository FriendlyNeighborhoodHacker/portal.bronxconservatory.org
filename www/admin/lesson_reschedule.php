<?php
// POST JSON: move a lesson to another time on the same day.
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
    LessonManagement::rescheduleWithinDay(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['lesson_id'] ?? 0),
        (string)($_POST['start_time'] ?? '')
    );
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
