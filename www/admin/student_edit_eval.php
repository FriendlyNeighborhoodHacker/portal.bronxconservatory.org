<?php
// POST: save a student's basic information (including the demographic, which
// is one more field on the Edit Profile form). PRG to the student page.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_person_form.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/students.php');
    exit;
}
require_csrf();

$userId = (int)($_POST['id'] ?? 0);
$ctx = UserContext::getLoggedInUserContext();
try {
    UserManagement::updateProfile($ctx, $userId, person_basic_info_fields_from_post());
    if (array_key_exists('demographic', $_POST)) {
        $posted = (string)$_POST['demographic'];
        // Only write (and activity-log) an actual change.
        if ($posted !== (string)(StudentTeacherManagement::demographicForStudent($ctx, $userId) ?? '')) {
            StudentTeacherManagement::setStudentDemographic($ctx, $userId, $posted);
        }
    }
    $_SESSION['people_flash'] = 'Student saved.';
} catch (\Throwable $e) {
    $_SESSION['people_flash_error'] = $e->getMessage();
}
header('Location: /admin/student.php?id=' . $userId);
exit;
