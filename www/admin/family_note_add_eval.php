<?php
// POST: add an internal note to a family record.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}
require_csrf();

$familyId = (int)($_POST['family_id'] ?? 0);
$body = (string)($_POST['body'] ?? '');
$ctx = UserContext::getLoggedInUserContext();

try {
    NotesManagement::addNote($ctx, 'family', $familyId, null, $body);
    $_SESSION['family_flash'] = 'Note added.';
} catch (\Throwable $e) {
    $_SESSION['family_flash_error'] = $e->getMessage();
}

header('Location: /admin/family.php?id=' . $familyId);
exit;
