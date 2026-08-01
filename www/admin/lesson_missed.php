<?php
// POST JSON: set a lesson's attendance ('' = unmarked, 1 = attended,
// 0 = missed).
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

$raw = (string)($_POST['attended'] ?? '');
$attended = $raw === '' ? null : ((int)$raw === 1);

try {
    LessonManagement::markAttendance(UserContext::getLoggedInUserContext(), (int)($_POST['lesson_id'] ?? 0), $attended);
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
