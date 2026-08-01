<?php
// Admin router: with no semesters yet, walk the admin through first-time
// setup; otherwise land on the Semester Schedule.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_admin();

if (!SemesterManagement::hasAnySemester()) {
    header('Location: /admin/setup/index.php');
} else {
    header('Location: /admin/schedule.php');
}
exit;
