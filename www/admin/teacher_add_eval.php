<?php
// POST: create the teacher, then land on Edit Teacher. If the email already
// belongs to an account (even a soft-deleted one), that account is adopted:
// restored if needed, marked as a teacher, and the typed fields applied.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/teachers.php');
    exit;
}
require_csrf();

$ctx = UserContext::getLoggedInUserContext();

try {
    $person = UserManagement::adoptOrCreatePerson($ctx, [
        'first_name' => (string)($_POST['first_name'] ?? ''),
        'last_name' => (string)($_POST['last_name'] ?? ''),
        'email' => (string)($_POST['email'] ?? ''),
        'cell_phone' => (string)($_POST['cell_phone'] ?? ''),
    ]);
    $userId = (int)$person['id'];
    StudentTeacherManagement::ensureTeacherProfile($ctx, $userId);
    // Merge instruments so adopting an existing teacher never drops any.
    InstrumentCatalog::addTeacherInstruments($ctx, $userId, array_map('intval', (array)($_POST['instrument_ids'] ?? [])));

    $_SESSION['people_flash'] = $person['existed']
        ? 'That email already belonged to an account — it is now marked as a teacher and updated below.'
        : 'Teacher added.';
    header('Location: /admin/teacher_edit.php?id=' . $userId);
} catch (\Throwable $e) {
    $_SESSION['people_flash_error'] = $e->getMessage();
    $_SESSION['people_form_old'] = [
        'first_name' => (string)($_POST['first_name'] ?? ''),
        'last_name' => (string)($_POST['last_name'] ?? ''),
        'email' => (string)($_POST['email'] ?? ''),
        'cell_phone' => (string)($_POST['cell_phone'] ?? ''),
        'instrument_ids' => (array)($_POST['instrument_ids'] ?? []),
    ];
    header('Location: /admin/teacher_add.php');
}
exit;
