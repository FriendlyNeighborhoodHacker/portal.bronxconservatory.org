<?php
// POST JSON: move a standing weekly reservation to another (teacher, day,
// time) — the Semester Schedule's drag-and-drop.
//
// The whole rule lives in ReservationManagement::updateReservation: the slot
// must be free of other reservations and hold blocks for that teacher, and no
// lesson the move would generate may collide with one already on the calendar.
// It throws with a sentence worth showing, so this file just relays it.
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
    ReservationManagement::updateReservation(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['reservation_id'] ?? 0),
        [
            'teacher_user_id' => (int)($_POST['teacher_user_id'] ?? 0),
            'location_id' => (int)($_POST['location_id'] ?? 0),
            'day_of_week' => (int)($_POST['day_of_week'] ?? -1),
            'start_time' => (string)($_POST['start_time'] ?? ''),
        ]
    );
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
