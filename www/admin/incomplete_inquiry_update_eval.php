<?php
// POST: save the internal notes on an uncompleted information-request form.
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
    InquiryManagement::saveAdminNotes($ctx, $id, (string)($_POST['admin_notes'] ?? ''));
    $_SESSION['inquiry_flash'] = 'Saved.';
} catch (\Throwable $e) {
    $_SESSION['inquiry_flash_error'] = $e->getMessage();
}
header('Location: /admin/incomplete_inquiry.php?id=' . $id);
exit;
