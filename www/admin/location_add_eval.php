<?php
// POST: create a location.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/locations.php');
    exit;
}
require_csrf();

try {
    LocationManagement::create(UserContext::getLoggedInUserContext(), (string)($_POST['name'] ?? ''), $_POST['address'] ?? null);
    $_SESSION['location_flash'] = 'Location added.';
} catch (\Throwable $e) {
    $_SESSION['location_flash_error'] = $e->getMessage();
}
header('Location: /admin/locations.php');
exit;
