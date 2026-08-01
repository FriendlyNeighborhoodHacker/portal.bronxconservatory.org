<?php
// POST: unlink a parent from a child.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/students.php');
    exit;
}
require_csrf();

$returnTo = validate_relative_next_path($_POST['return_to'] ?? '');
if ($returnTo === '') {
    $returnTo = '/admin/students.php';
}

try {
    StudentTeacherManagement::unlinkParentChild(
        UserContext::getLoggedInUserContext(),
        (int)($_POST['parent_user_id'] ?? 0),
        (int)($_POST['child_user_id'] ?? 0)
    );
    $_SESSION['people_flash'] = 'Unlinked.';
} catch (\Throwable $e) {
    $_SESSION['people_flash_error'] = $e->getMessage();
}
header('Location: ' . $returnTo);
exit;
