<?php
// POST: save a location edit.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/locations.php');
    exit;
}
require_csrf();

$id = (int)($_POST['id'] ?? 0);
try {
    LocationManagement::update(
        UserContext::getLoggedInUserContext(),
        $id,
        (string)($_POST['name'] ?? ''),
        $_POST['address'] ?? null,
        !empty($_POST['is_active'])
    );
    $_SESSION['location_flash'] = 'Location saved.';
    header('Location: /admin/locations.php');
} catch (\Throwable $e) {
    $_SESSION['location_flash_error'] = $e->getMessage();
    header('Location: /admin/location_edit.php?id=' . $id);
}
exit;
