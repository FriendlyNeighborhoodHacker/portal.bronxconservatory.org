<?php
// POST JSON: set or clear a lesson's substitute teacher.
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

$raw = trim((string)($_POST['substitute_teacher_user_id'] ?? ''));
$substituteId = $raw === '' ? null : (int)$raw;

try {
    LessonManagement::setSubstituteTeacher(UserContext::getLoggedInUserContext(), (int)($_POST['lesson_id'] ?? 0), $substituteId);
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
