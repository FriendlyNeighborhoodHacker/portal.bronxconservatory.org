<?php
// POST JSON: retitle or resize a hold block reservation (schedule grid edit modal).
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
    HoldBlockManagement::updateHoldBlockReservation(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['hold_block_reservation_id'] ?? 0),
        [
            'duration_minutes' => (int)($_POST['duration_minutes'] ?? 30),
            'title' => (string)($_POST['title'] ?? ''),
        ]
    );
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
