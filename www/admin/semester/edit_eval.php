<?php
// POST: save an edited semester, back to the semesters list.
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

try {
    SemesterManagement::updateSemester(
        UserContext::getLoggedInUserContext(),
        $semesterId,
        (string)($_POST['season'] ?? ''),
        (int)($_POST['year'] ?? 0),
        (string)($_POST['start_date'] ?? ''),
        (string)($_POST['end_date'] ?? ''),
        (array)($_POST['pricing'] ?? []),
        (string)($_POST['second_installment_due_date'] ?? '') !== ''
            ? (string)$_POST['second_installment_due_date'] : null
    );
    $_SESSION['import_flash'] = 'Semester updated.';
    header('Location: /admin/semesters.php');
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
    header('Location: /admin/semester/edit.php?semester_id=' . $semesterId);
}
exit;
