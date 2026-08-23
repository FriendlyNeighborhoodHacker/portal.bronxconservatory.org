<?php
// POST: save the semester's active locations, continue to the class-days
// CSV import (step 3), which chains through class dates (4),
// teachers-per-location (5), hold blocks (6), the schedule itself (7) and
// the opening charges and payments (8) before landing on the Semester
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

    $step7 = '/admin/import/upload.php?' . http_build_query([
        'flow' => 'ledger_entries',
        'semester_id' => $semesterId,
        'next' => '/admin/schedule.php',
    ]);
    $step6 = '/admin/import/upload.php?' . http_build_query([
        'flow' => 'reservations',
        'semester_id' => $semesterId,
        'next' => $step7,
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
    $step3b = '/admin/import/upload.php?' . http_build_query([
        'flow' => 'location_dates',
        'semester_id' => $semesterId,
        'next' => $step4,
    ]);
    $step3 = '/admin/import/upload.php?' . http_build_query([
        'flow' => 'location_weekdays',
        'semester_id' => $semesterId,
        'next' => $step3b,
    ]);
    header('Location: ' . $step3);
} catch (\Throwable $e) {
    $_SESSION['semester_flash_error'] = $e->getMessage();
    header('Location: /admin/semester/locations.php?semester_id=' . $semesterId);
}
exit;
