<?php
// POST JSON: apply the duration-change ledger entries and update the reservation.
//
// The admin has reviewed the calculated refund and new charge, possibly adjusted
// them, and confirmed the entries should be posted. This handler:
// 1. Posts the ledger entries (credit for refund, debit for new charge)
// 2. Updates the reservation's duration_minutes
// 3. Updates the reservation's lessons at the new duration
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
$newDurationMinutes = (int)($_POST['new_duration_minutes'] ?? 0);
// Amounts arrive as dollars (the review page's editable inputs).
$refundCents = Billing::parseAmountCents((string)($_POST['refund_amount'] ?? ''));
$newChargeCents = Billing::parseAmountCents((string)($_POST['new_charge_amount'] ?? ''));
$ctx = UserContext::getLoggedInUserContext();

try {
    if ($refundCents === null || $refundCents < 0 || $newChargeCents === null || $newChargeCents < 0) {
        throw new InvalidArgumentException('Refund and new charge must be non-negative dollar amounts.');
    }
    $reservation = ReservationManagement::findReservation($reservationId);
    if (!$reservation) {
        throw new InvalidArgumentException('That reservation is no longer here.');
    }
    if ($reservation['status'] !== 'confirmed') {
        throw new InvalidArgumentException('Only confirmed reservations can have duration-change accounting applied.');
    }

    $originalDuration = (int)$reservation['duration_minutes'];
    if ($newDurationMinutes === $originalDuration) {
        throw new InvalidArgumentException('Duration has not changed.');
    }

    // Update the duration first — it can be refused for a slot conflict, and
    // money must not have moved for a change that did not happen.
    ReservationManagement::updateReservation(
        $ctx,
        $reservationId,
        ['duration_minutes' => $newDurationMinutes]
    );

    Billing::postDurationChangeEntries(
        $ctx,
        (int)$reservation['student_user_id'],
        (int)$reservation['semester_id'],
        $refundCents,
        $newChargeCents,
        $originalDuration,
        $newDurationMinutes
    );

    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
