<?php
// GET: remember which semester the admin pages operate on (session only —
// no persistent data changes) and bounce back to the page they were on.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_admin();

$semesterId = (int)($_GET['semester_id'] ?? 0);
if ($semesterId > 0 && SemesterManagement::find($semesterId)) {
    $_SESSION['admin_semester_id'] = $semesterId;
}

$return = validate_relative_next_path($_GET['return'] ?? '');
if ($return === '') {
    $return = '/admin/schedule.php';
}
header('Location: ' . $return);
exit;
