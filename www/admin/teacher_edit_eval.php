<?php
// POST: save a teacher's basic information and instruments.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/person_form.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/teachers.php');
    exit;
}
require_csrf();

$userId = (int)($_POST['id'] ?? 0);
$ctx = UserContext::getLoggedInUserContext();
try {
    UserManagement::updateProfile($ctx, $userId, person_basic_info_fields_from_post());
    InstrumentCatalog::setTeacherInstruments($ctx, $userId, array_map('intval', (array)($_POST['instrument_ids'] ?? [])));
    $_SESSION['people_flash'] = 'Teacher saved.';
} catch (\Throwable $e) {
    $_SESSION['people_flash_error'] = $e->getMessage();
}
header('Location: /admin/teacher_edit.php?id=' . $userId);
exit;
