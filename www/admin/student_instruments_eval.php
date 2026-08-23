<?php
// POST: save a student's instrument set.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/students.php');
    exit;
}
require_csrf();

$userId = (int)($_POST['id'] ?? 0);
$returnTo = validate_relative_next_path($_POST['return_to'] ?? '');
if ($returnTo === '') {
    $returnTo = '/admin/student.php?id=' . $userId;
}
try {
    InstrumentCatalog::setStudentInstruments(
        UserContext::getLoggedInUserContext(),
        $userId,
        array_map('intval', (array)($_POST['instrument_ids'] ?? []))
    );
    $_SESSION['people_flash'] = 'Instruments saved.';
} catch (\Throwable $e) {
    $_SESSION['people_flash_error'] = $e->getMessage();
}
header('Location: ' . $returnTo);
exit;
