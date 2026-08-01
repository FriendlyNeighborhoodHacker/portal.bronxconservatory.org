<?php
// POST: carry a previous semester's schedule into this one as
// pending_reach_out reservations (the call list to work through).
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/SemesterManagement.php';
require_once __DIR__ . '/../../lib/ReservationManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/semesters.php');
    exit;
}
require_csrf();

$semesterId = (int)($_POST['semester_id'] ?? 0);
$sourceSemesterId = (int)($_POST['source_semester_id'] ?? 0);

try {
    $summary = ReservationManagement::carryForwardFromSemester(
        UserContext::getLoggedInUserContext(), $semesterId, $sourceSemesterId
    );
    $source = SemesterManagement::find($sourceSemesterId);
    $parts = [];
    if ($summary['created']) $parts[] = $summary['created'] . ' carried over as pending reach out';
    if ($summary['skipped']) $parts[] = $summary['skipped'] . ' skipped';
    $_SESSION['import_flash'] = 'Pre-populated from ' . ($source ? SemesterManagement::label($source) : 'the previous semester')
        . ': ' . ($parts ? implode(', ', $parts) : 'nothing to do') . '.';
    header('Location: /admin/semesters.php');
} catch (\Throwable $e) {
    $_SESSION['import_flash_error'] = 'Pre-populate failed: ' . $e->getMessage();
    header('Location: /admin/semester/prepopulate.php?' . http_build_query([
        'semester_id' => $semesterId,
        'source_semester_id' => $sourceSemesterId,
    ]));
}
exit;
