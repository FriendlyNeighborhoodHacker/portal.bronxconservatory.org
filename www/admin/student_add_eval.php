<?php
// POST: create the student, then land on Edit Student. If the email already
// belongs to an account (even a soft-deleted one), that account is adopted:
// restored if needed, marked as a student, and the typed fields applied.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/students.php');
    exit;
}
require_csrf();

$ctx = UserContext::getLoggedInUserContext();

try {
    $person = UserManagement::adoptOrCreatePerson($ctx, [
        'first_name' => (string)($_POST['first_name'] ?? ''),
        'last_name' => (string)($_POST['last_name'] ?? ''),
        'email' => (string)($_POST['email'] ?? ''),
    ]);
    $userId = (int)$person['id'];
    StudentTeacherManagement::ensureStudentProfile($ctx, $userId, [
        'class_of' => (string)($_POST['class_of'] ?? ''),
    ]);
    // Merge instruments so adopting an existing student never drops any.
    InstrumentCatalog::addStudentInstruments($ctx, $userId, array_map('intval', (array)($_POST['instrument_ids'] ?? [])));

    $_SESSION['people_flash'] = $person['existed']
        ? 'That email already belonged to an account — it is now marked as a student and updated below.'
        : 'Student added.';
    header('Location: /admin/student_edit.php?id=' . $userId);
} catch (\Throwable $e) {
    $_SESSION['people_flash_error'] = $e->getMessage();
    $_SESSION['people_form_old'] = [
        'first_name' => (string)($_POST['first_name'] ?? ''),
        'last_name' => (string)($_POST['last_name'] ?? ''),
        'email' => (string)($_POST['email'] ?? ''),
        'class_of' => (string)($_POST['class_of'] ?? ''),
        'instrument_ids' => (array)($_POST['instrument_ids'] ?? []),
    ];
    header('Location: /admin/student_add.php');
}
exit;
