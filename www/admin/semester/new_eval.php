<?php
// POST: create the semester, continue to step 2 (active locations).
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/SemesterManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/semester/new.php');
    exit;
}
require_csrf();

try {
    // Second installment due date defaults to the semester's midpoint so every
    // new semester has an explicit date (the admin can adjust it later).
    $secondDue = trim((string)($_POST['second_installment_due_date'] ?? ''));
    $startDate = (string)($_POST['start_date'] ?? '');
    $endDate = (string)($_POST['end_date'] ?? '');
    if ($secondDue === '' && $startDate !== '' && $endDate !== '') {
        $secondDue = SemesterManagement::midpointDate($startDate, $endDate);
    }
    $semesterId = SemesterManagement::createSemester(
        UserContext::getLoggedInUserContext(),
        (string)($_POST['season'] ?? ''),
        (int)($_POST['year'] ?? 0),
        $startDate,
        $endDate,
        (array)($_POST['pricing'] ?? []),
        $secondDue !== '' ? $secondDue : null
    );
    $_SESSION['admin_semester_id'] = $semesterId;
    header('Location: /admin/semester/locations.php?semester_id=' . $semesterId);
} catch (\Throwable $e) {
    $_SESSION['semester_flash_error'] = $e->getMessage();
    $_SESSION['semester_form_old'] = [
        'season' => (string)($_POST['season'] ?? ''),
        'year' => (string)($_POST['year'] ?? ''),
        'start_date' => (string)($_POST['start_date'] ?? ''),
        'end_date' => (string)($_POST['end_date'] ?? ''),
        'second_installment_due_date' => (string)($_POST['second_installment_due_date'] ?? ''),
        'pricing' => (array)($_POST['pricing'] ?? []),
    ];
    header('Location: /admin/semester/new.php');
}
exit;
