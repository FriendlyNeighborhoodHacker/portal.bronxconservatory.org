<?php
// POST: soft-delete a teacher.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/teachers.php');
    exit;
}
require_csrf();

try {
    UserManagement::deleteUser(UserContext::getLoggedInUserContext(), (int)($_POST['id'] ?? 0));
    $_SESSION['people_flash'] = 'Teacher deleted.';
} catch (\Throwable $e) {
    $_SESSION['people_flash_error'] = $e->getMessage();
}
header('Location: /admin/teachers.php');
exit;
