<?php
// Home: routes each user to the dashboard for their primary role.
// Roles derive from profile rows (Application::rolesForUser); a user holding
// several roles lands on the highest-priority one and reaches the others
// through the sidebar. Logged-out visitors get the public landing page
// (Register / Login) instead of being bounced straight to login.
require_once __DIR__ . '/partials.php';
Application::init();

if (!current_user()) {
    header('Location: /welcome.php');
    exit;
}

$user = current_user();
$roles = Application::rolesForUser((int)$user['id']);

if (in_array('admin', $roles, true)) {
    header('Location: /admin/index.php');
} elseif (in_array('teacher', $roles, true)) {
    header('Location: /teacher/index.php');
} elseif (in_array('parent', $roles, true)) {
    header('Location: /parent/index.php');
} elseif (in_array('student', $roles, true)) {
    header('Location: /student/index.php');
} else {
    // No role yet (e.g. a freshly invited account an admin hasn't linked):
    // the profile page always works.
    header('Location: /profile/');
}
exit;
