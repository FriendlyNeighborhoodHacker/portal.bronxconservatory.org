<?php
// POST: delete an uncompleted information-request form.
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
    InquiryManagement::delete($ctx, $id);
    $_SESSION['inquiry_flash'] = 'Uncompleted form deleted.';
    header('Location: /admin/incomplete_inquiries.php');
} catch (\Throwable $e) {
    $_SESSION['inquiry_flash_error'] = $e->getMessage();
    header('Location: /admin/incomplete_inquiry.php?id=' . $id);
}
exit;
