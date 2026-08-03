<?php
// POST JSON: change a weekly reservation's length and/or status (schedule
// grid edit modal).
//
// Length goes first: it is the one that can be refused for a clash, and
// confirming afterwards generates the semester's lessons at the new length
// rather than the old one. If the duration changes on a confirmed reservation,
// we return an accounting payload instead of applying directly — the admin
// must review and approve the refund/charge ledger entries.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
require_once __DIR__ . '/../lib/Billing.php';
Application::init();
require_admin();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}
require_csrf();

$reservationId = (int)($_POST['reservation_id'] ?? 0);
$duration = (int)($_POST['duration_minutes'] ?? 0);
$ctx = UserContext::getLoggedInUserContext();

try {
    $reservation = ReservationManagement::findReservation($reservationId);
    if (!$reservation) {
        throw new InvalidArgumentException('That reservation is no longer here.');
    }
    if ($duration > 0 && $duration !== (int)$reservation['duration_minutes']) {
        // Duration is changing. If confirmed, we need to handle accounting.
        if ($reservation['status'] === 'confirmed') {
            $calc = Billing::durationChangeLedgerCalculation(
                $reservationId,
                (int)$reservation['semester_id'],
                (int)$reservation['duration_minutes'],
                $duration
            );
            echo json_encode([
                'ok' => false,
                'needs_accounting' => true,
                'reservation_id' => $reservationId,
                'calculation' => $calc,
            ]);
            exit;
        }
        // Pending reservation: just update, no charges yet.
        ReservationManagement::updateReservation($ctx, $reservationId, ['duration_minutes' => $duration]);
    }
    ReservationManagement::setStatus($ctx, $reservationId, (string)($_POST['status'] ?? ''));
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
