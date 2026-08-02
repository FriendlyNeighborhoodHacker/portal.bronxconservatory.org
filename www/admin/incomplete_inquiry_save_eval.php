<?php
// POST: save an admin's edits to an uncompleted form without finishing it.
//
// The student and page-4 answers are posted too (one form, two buttons), but
// the uncompleted-form record has nowhere to keep them — they only mean
// something once this becomes a lead. They are handed straight back to the
// page so an admin who saves mid-call does not lose what they had typed.
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
$form = incomplete_inquiry_posted_form();

try {
    InquiryManagement::saveContactAndAddress($ctx, $id, $form['contact'], $form['address']);
    $_SESSION['inquiry_flash'] = 'Saved.';
} catch (\Throwable $e) {
    $_SESSION['inquiry_flash_error'] = $e->getMessage();
}
$_SESSION['incomplete_inquiry_old'] = incomplete_inquiry_old_input($form);

header('Location: /admin/incomplete_inquiry.php?id=' . $id);
exit;
