<?php
// POST JSON: hold one slot on the weekly calendar, with no standing weekly
// reservation behind it.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/HoldBlockManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
require_csrf();

$date = trim((string)($_POST['date'] ?? ''));
$startTime = trim((string)($_POST['start_time'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $startTime)) {
    echo json_encode(['ok' => false, 'error' => 'That is not a time we understand.']);
    exit;
}

try {
    $blockId = HoldBlockManagement::createAdHocHoldBlock(UserContext::getLoggedInUserContext(), [
        'semester_id' => (int)($_POST['semester_id'] ?? 0),
        'teacher_user_id' => (int)($_POST['teacher_user_id'] ?? 0),
        'location_id' => (int)($_POST['location_id'] ?? 0),
        'start_datetime' => $date . ' ' . $startTime,
        'duration_minutes' => (int)($_POST['duration_minutes'] ?? 30),
        'title' => (string)($_POST['title'] ?? ''),
    ]);
    echo json_encode(['ok' => true, 'hold_block_id' => $blockId]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
