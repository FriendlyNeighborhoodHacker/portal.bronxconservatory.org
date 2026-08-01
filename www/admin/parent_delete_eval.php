<?php
// POST: soft-delete a parent.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/students.php');
    exit;
}
require_csrf();

try {
    UserManagement::deleteUser(UserContext::getLoggedInUserContext(), (int)($_POST['id'] ?? 0));
    $_SESSION['people_flash'] = 'Parent deleted.';
} catch (\Throwable $e) {
    $_SESSION['people_flash_error'] = $e->getMessage();
}
header('Location: /admin/students.php');
exit;
