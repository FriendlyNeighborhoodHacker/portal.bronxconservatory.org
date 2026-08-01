<?php
// POST: save the semester's active locations, continue to the class-dates
// CSV import (step 3), which chains through teachers-per-location (4), hold
// blocks (5) and the schedule itself (6) before landing on the Semester
// Schedule. Each step's Cancel button goes to the NEXT step, so an admin who
// has nothing to upload yet can walk straight through.
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/SemesterManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/semesters.php');
    exit;
}
require_csrf();

$semesterId = (int)($_POST['semester_id'] ?? 0);
$locationIds = array_map('intval', (array)($_POST['location_ids'] ?? []));

try {
    if (!$locationIds) {
        throw new InvalidArgumentException('Pick at least one location.');
    }
    SemesterManagement::setActiveLocations(UserContext::getLoggedInUserContext(), $semesterId, $locationIds);

    $step6 = '/admin/import/upload.php?' . http_build_query([
        'flow' => 'reservations',
        'semester_id' => $semesterId,
        'next' => '/admin/schedule.php',
    ]);
    $step5 = '/admin/import/upload.php?' . http_build_query([
        'flow' => 'hold_blocks',
        'semester_id' => $semesterId,
        'next' => $step6,
    ]);
    $step4 = '/admin/import/upload.php?' . http_build_query([
        'flow' => 'location_teachers',
        'semester_id' => $semesterId,
        'next' => $step5,
    ]);
    $step3 = '/admin/import/upload.php?' . http_build_query([
        'flow' => 'location_dates',
        'semester_id' => $semesterId,
        'next' => $step4,
    ]);
    header('Location: ' . $step3);
} catch (\Throwable $e) {
    $_SESSION['semester_flash_error'] = $e->getMessage();
    header('Location: /admin/semester/locations.php?semester_id=' . $semesterId);
}
exit;
