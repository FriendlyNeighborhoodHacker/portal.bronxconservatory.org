<?php
// POST: finish an uncompleted form on the family's behalf and turn it into a
// lead. On success the form is gone and the admin lands on the new lead; on
// failure nothing is written and the page comes back with everything they
// typed still in it.
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
    $leadId = InquiryManagement::completeAsLead(
        $ctx, $id,
        $form['contact'], $form['address'], $form['student'], $form['details'],
        Settings::inquirySemesterOptions()
    );
    $_SESSION['lead_flash'] = 'Form completed — this family is a lead now.';
    header('Location: /admin/lead.php?id=' . $leadId);
} catch (\Throwable $e) {
    $_SESSION['inquiry_flash_error'] = $e->getMessage();
    $_SESSION['incomplete_inquiry_old'] = incomplete_inquiry_old_input($form);
    header('Location: /admin/incomplete_inquiry.php?id=' . $id);
}
exit;
