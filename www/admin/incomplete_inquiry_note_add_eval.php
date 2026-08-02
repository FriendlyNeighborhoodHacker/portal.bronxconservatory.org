<?php
// POST: append an internal note to an uncompleted form. Notes are history, so
// this only ever adds — and they follow the family onto the lead if the form
// is later completed.
require_once __DIR__ . '/incomplete_inquiry_ui.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/incomplete_inquiries.php');
    exit;
}
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$ctx = UserContext::getLoggedInUserContext();

try {
    InquiryManagement::addNote($ctx, $id, (string)($_POST['note_body'] ?? ''));
    $_SESSION['inquiry_flash'] = 'Note added.';
} catch (\Throwable $e) {
    $_SESSION['inquiry_flash_error'] = $e->getMessage();
    // Hand back what they typed rather than making them write it again.
    $_SESSION['incomplete_inquiry_note_old'] = (string)($_POST['note_body'] ?? '');
}
header('Location: /admin/incomplete_inquiry.php?id=' . $id);
exit;
