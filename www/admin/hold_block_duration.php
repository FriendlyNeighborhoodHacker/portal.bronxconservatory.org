<?php
// POST JSON: change the length of ONE week of a hold block (the standing
// reservation and its other weeks are untouched).
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
    $minutes = trim((string)($_POST['duration_minutes'] ?? ''));
    if (!ctype_digit($minutes)) {
        throw new InvalidArgumentException('Please enter the length in minutes.');
    }
    HoldBlockManagement::setBlockDuration(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['hold_block_id'] ?? 0),
        (int)$minutes
    );
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
