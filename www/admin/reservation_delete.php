<?php
// POST JSON: soft-delete a reservation (removes future lessons, keeps past).
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
    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    // Policy: deleting a confirmed reservation posts reversal credits, so it
    // must arrive with charges_acknowledged=1 (the JS shows the itemized
    // dialog first; this refusal is the backstop).
    $reservation = ReservationManagement::findReservation($reservationId);
    if ($reservation && (string)$reservation['status'] === 'confirmed'
        && (string)($_POST['charges_acknowledged'] ?? '') !== '1') {
        echo json_encode([
            'ok' => false,
            'needs_charge_confirmation' => true,
            'error' => 'Deleting a confirmed reservation reverses its charges — please review and confirm first.',
        ]);
        exit;
    }
    ReservationManagement::deleteReservation(
        UserContext::getLoggedInUserContext(),
        $reservationId
    );
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
