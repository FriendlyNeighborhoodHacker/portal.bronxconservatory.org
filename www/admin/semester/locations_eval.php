<?php
// POST: save the semester's active locations, continue to the class-dates
// CSV import (step 3), which chains through teachers-per-location (4), hold
// blocks (5), the schedule itself (6) and the opening charges and payments
// (7) before landing on the Semester Schedule. Each step's Cancel button goes
// to the NEXT step, so an admin who has nothing to upload yet can walk
// straight through.
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/SemesterManagement.php';
require_once __DIR__ . '/../../lib/LocationManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/semesters.php');
    exit;
}
require_csrf();

$semesterId = (int)($_POST['semester_id'] ?? 0);
$locationIds = array_map('intval', (array)($_POST['location_ids'] ?? []));
$dayInput = (array)($_POST['location_days'] ?? []);

try {
    if (!$locationIds) {
        throw new InvalidArgumentException('Pick at least one location.');
    }
    // Each selected location needs at least one class day, with hours.
    $locationNames = [];
    foreach (LocationManagement::all() as $location) {
        $locationNames[(int)$location['id']] = (string)$location['name'];
    }
    $weekdaysByLocation = [];
    foreach ($locationIds as $locationId) {
        $days = [];
        foreach ((array)($dayInput[$locationId] ?? []) as $dow => $dayRow) {
            if (empty($dayRow['on'])) {
                continue;
            }
            $days[] = [(int)$dow, (string)($dayRow['start'] ?? ''), (string)($dayRow['end'] ?? '')];
        }
        if (!$days) {
            throw new InvalidArgumentException(
                'Pick at least one class day for ' . ($locationNames[$locationId] ?? 'each location') . '.'
            );
        }
        $weekdaysByLocation[$locationId] = $days;
    }
    SemesterManagement::setActiveLocations(
        UserContext::getLoggedInUserContext(), $semesterId, $locationIds, $weekdaysByLocation
    );

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
