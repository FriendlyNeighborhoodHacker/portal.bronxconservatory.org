<?php
// POST: record or clear a student's demographic code (admin only).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/students.php');
    exit;
}
require_csrf();

$userId = (int)($_POST['id'] ?? 0);
try {
    StudentTeacherManagement::setStudentDemographic(
        UserContext::getLoggedInUserContext(),
        $userId,
        (string)($_POST['demographic'] ?? '')
    );
    $_SESSION['people_flash'] = 'Demographics saved.';
} catch (\Throwable $e) {
    $_SESSION['people_flash_error'] = $e->getMessage();
}
header('Location: /admin/student_edit.php?id=' . $userId);
exit;
