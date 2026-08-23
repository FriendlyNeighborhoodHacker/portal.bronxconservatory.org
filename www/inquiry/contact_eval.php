<?php
// Evaluates information request page 1 and saves the uncompleted form.
//
// The draft is written only after validation passes: a misspelled email is not
// worth a callback, and it would poison the confirmation email later. The
// session state is saved first regardless, so a visitor sent back by an error
// finds everything they typed still in the form.
require_once __DIR__ . '/inquiry_ui.php';
Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /inquiry/contact.php');
    exit;
}
require_csrf();

$contact = [
    'first_name' => trim((string)($_POST['first_name'] ?? '')),
    'last_name' => trim((string)($_POST['last_name'] ?? '')),
    'email' => trim((string)($_POST['email'] ?? '')),
    'confirm_email' => trim((string)($_POST['confirm_email'] ?? '')),
    'phone' => trim((string)($_POST['phone'] ?? '')),
    'newsletter_opt_in' => !empty($_POST['newsletter_opt_in']),
    'sms_consent' => !empty($_POST['sms_consent']),
];

$state = inquiry_state();
$state['contact'] = $contact;
inquiry_save($state);

$recaptchaError = recaptcha_verify_or_null('inquiry_contact');
if ($recaptchaError !== null) {
    inquiry_fail($recaptchaError, '/inquiry/contact.php');
}

$error = InquiryManagement::validateContact($contact);
if ($error !== null) {
    inquiry_fail($error, '/inquiry/contact.php');
}

try {
    $leadId = inquiry_lead_id();
    $draftId = inquiry_draft_id();
    if ($leadId !== null) {
        // Page 3 already promoted this visitor; the lead is the record now.
        LeadManagement::updateInquiryContact(null, $leadId, $contact);
    } elseif ($draftId === null) {
        $_SESSION['inquiry_draft_id'] = InquiryManagement::startInquiry(null, $contact);
    } else {
        InquiryManagement::updateContact(null, $draftId, $contact);
    }
} catch (\Throwable $e) {
    inquiry_fail(
        $e->getMessage() . ' Please try again, or call us at ' . Settings::contactPhone() . '.',
        '/inquiry/contact.php'
    );
}

inquiry_save(inquiry_mark_completed($state, 1));
header('Location: /inquiry/address.php');
exit;
