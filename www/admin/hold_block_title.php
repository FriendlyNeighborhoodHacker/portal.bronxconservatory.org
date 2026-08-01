<?php
// POST JSON: give one week of a hold block its own title (blank clears the
// override so it follows the standing title again).
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
    HoldBlockManagement::setBlockTitleOverride(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['hold_block_id'] ?? 0),
        (string)($_POST['title'] ?? '')
    );
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
