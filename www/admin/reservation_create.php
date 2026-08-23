<?php
// POST JSON: create a semester lesson reservation (schedule grid add modal).
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
    // Policy: creating a reservation directly as confirmed posts charges, so
    // it must arrive with charges_acknowledged=1 (the JS shows the itemized
    // dialog first; this refusal is the backstop).
    $status = (string)($_POST['status'] ?? 'pending_reach_out');
    if ($status === 'confirmed' && (string)($_POST['charges_acknowledged'] ?? '') !== '1') {
        echo json_encode([
            'ok' => false,
            'needs_charge_confirmation' => true,
            'error' => 'Confirming posts charges — please review and confirm them first.',
        ]);
        exit;
    }
    $id = ReservationManagement::createReservation(UserContext::getLoggedInUserContext(), [
        'semester_id' => (int)($_POST['semester_id'] ?? 0),
        'teacher_user_id' => (int)($_POST['teacher_user_id'] ?? 0),
        'location_id' => (int)($_POST['location_id'] ?? 0),
        'student_user_id' => (int)($_POST['student_user_id'] ?? 0),
        'day_of_week' => (int)($_POST['day_of_week'] ?? -1),
        'start_time' => (string)($_POST['start_time'] ?? ''),
        'duration_minutes' => (int)($_POST['duration_minutes'] ?? 30),
        'status' => $status,
    ], [
        'include_installment_fee' => (string)($_POST['include_installment_fee'] ?? '') === '1',
    ]);
    echo json_encode(['ok' => true, 'reservation_id' => $id]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
