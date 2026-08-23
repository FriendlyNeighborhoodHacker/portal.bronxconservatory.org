<?php
// POST: add or remove one of a teacher's (location, day) schedule assignments
// for a semester (the Edit Teacher page's "Schedule Days" card). The rules
// live in SemesterManagement::{add,remove}LocationTeacherDay, which throw
// sentences worth showing.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_admin();

$teacherId = (int)($_POST['teacher_user_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $teacherId <= 0) {
    header('Location: /admin/teachers.php');
    exit;
}
require_csrf();

$semesterId = (int)($_POST['semester_id'] ?? 0);
$action = $_POST['action'] ?? '';

try {
    $ctx = UserContext::getLoggedInUserContext();
    if ($action === 'add') {
        // The form's one select carries the (location, day) pair as "id:day".
        $parts = explode(':', (string)($_POST['location_day'] ?? ''));
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Pick a location and day.');
        }
        SemesterManagement::addLocationTeacherDay($ctx, $semesterId, (int)$parts[0], $teacherId, (int)$parts[1]);
        $_SESSION['people_flash'] = 'Assignment added.';
    } elseif ($action === 'remove') {
        SemesterManagement::removeLocationTeacherDay(
            $ctx, $semesterId,
            (int)($_POST['location_id'] ?? 0), $teacherId, (int)($_POST['day_of_week'] ?? -1)
        );
        $_SESSION['people_flash'] = 'Assignment removed.';
    } else {
        throw new InvalidArgumentException('Unknown action.');
    }
} catch (\Throwable $e) {
    $_SESSION['people_flash_error'] = $e->getMessage();
}
header('Location: /admin/teacher_edit.php?id=' . $teacherId);
exit;
