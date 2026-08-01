<?php
// POST: save a student's basic information.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/person_form.php';
require_once __DIR__ . '/../lib/UserManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/students.php');
    exit;
}
require_csrf();

$userId = (int)($_POST['id'] ?? 0);
try {
    UserManagement::updateProfile(UserContext::getLoggedInUserContext(), $userId, person_basic_info_fields_from_post());
    $_SESSION['people_flash'] = 'Student saved.';
} catch (\Throwable $e) {
    $_SESSION['people_flash_error'] = $e->getMessage();
}
header('Location: /admin/student_edit.php?id=' . $userId);
exit;
