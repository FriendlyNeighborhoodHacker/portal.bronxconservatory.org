<?php
// POST JSON: change a reservation's status (schedule grid edit modal).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
Application::init();
require_admin();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
require_csrf();

try {
    ReservationManagement::setStatus(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['reservation_id'] ?? 0),
        (string)($_POST['status'] ?? '')
    );
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
