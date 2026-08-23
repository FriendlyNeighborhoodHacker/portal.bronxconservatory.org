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
    // Policy: money never moves without an explicit line-item confirmation.
    // A status change to or from confirmed posts charges or reversal credits,
    // so it must arrive with charges_acknowledged=1 (the JS shows the
    // itemized dialog first; this refusal is the backstop).
    $requestedStatus = (string)($_POST['status'] ?? '');
    $currentStatus = (string)$reservation['status'];
    $statusAffectsCharges = $requestedStatus !== '' && $requestedStatus !== $currentStatus
        && ($requestedStatus === 'confirmed' || $currentStatus === 'confirmed');
    if ($statusAffectsCharges && (string)($_POST['charges_acknowledged'] ?? '') !== '1') {
        echo json_encode([
            'ok' => false,
            'needs_charge_confirmation' => true,
            'error' => 'This change affects charges — please review and confirm them first.',
        ]);
        exit;
    }
    if ($duration > 0 && $duration !== (int)$reservation['duration_minutes']) {
        // Duration is changing. If confirmed, the review page recomputes and
        // shows the refund/charge entries before anything posts.
        if ($reservation['status'] === 'confirmed') {
            echo json_encode([
                'ok' => false,
                'needs_accounting' => true,
                'reservation_id' => $reservationId,
            ]);
            exit;
        }
        // Pending reservation: just update, no charges yet.
        ReservationManagement::updateReservation($ctx, $reservationId, ['duration_minutes' => $duration]);
    }
    ReservationManagement::setStatus($ctx, $reservationId, $requestedStatus, [
        'include_installment_fee' => (string)($_POST['include_installment_fee'] ?? '') === '1',
    ]);
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
