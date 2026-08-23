<?php
// POST JSON: create a hold block reservation (schedule grid add modal).
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

try {
    $id = HoldBlockManagement::createHoldBlockReservation(UserContext::getLoggedInUserContext(), [
        'semester_id' => (int)($_POST['semester_id'] ?? 0),
        'teacher_user_id' => (int)($_POST['teacher_user_id'] ?? 0),
        'location_id' => (int)($_POST['location_id'] ?? 0),
        'day_of_week' => (int)($_POST['day_of_week'] ?? -1),
        'start_time' => (string)($_POST['start_time'] ?? ''),
        'duration_minutes' => (int)($_POST['duration_minutes'] ?? 30),
        'title' => (string)($_POST['title'] ?? ''),
        'block_type' => (string)($_POST['block_type'] ?? 'hold'),
    ]);
    echo json_encode(['ok' => true, 'hold_block_reservation_id' => $id]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
