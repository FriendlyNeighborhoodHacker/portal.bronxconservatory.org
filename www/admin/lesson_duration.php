<?php
// POST JSON: change how long one lesson runs. Only that occurrence; the
// standing weekly booking keeps its own length.
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
    LessonManagement::setLessonDuration(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['lesson_id'] ?? 0),
        (int)($_POST['duration_minutes'] ?? 0)
    );
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
