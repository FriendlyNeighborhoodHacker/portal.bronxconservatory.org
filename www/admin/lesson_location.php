<?php
// POST JSON: hold one lesson at a different location, or put it back at the
// reservation's usual one.
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

$raw = trim((string)($_POST['location_id'] ?? ''));
$locationId = $raw === '' ? null : (int)$raw;

try {
    LessonManagement::setLocationOverride(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['lesson_id'] ?? 0),
        $locationId
    );
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
