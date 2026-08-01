<?php
// POST: create the teacher, then land on Edit Teacher.
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
    $userId = UserManagement::createUser($ctx, [
        'first_name' => (string)($_POST['first_name'] ?? ''),
        'last_name' => (string)($_POST['last_name'] ?? ''),
        'email' => (string)($_POST['email'] ?? ''),
        'no_login' => true,
    ]);
    $cell = trim((string)($_POST['cell_phone'] ?? ''));
    if ($cell !== '') {
        UserManagement::updateProfile($ctx, $userId, ['cell_phone' => $cell]);
    }
    StudentTeacherManagement::ensureTeacherProfile($ctx, $userId);
    InstrumentCatalog::setTeacherInstruments($ctx, $userId, array_map('intval', (array)($_POST['instrument_ids'] ?? [])));

    $_SESSION['people_flash'] = 'Teacher added.';
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
