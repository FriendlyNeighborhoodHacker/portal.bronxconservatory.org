<?php
// Evaluates information request page 4: fills in the rest of the lead, then
// sends the confirmation and the staff notification.
//
// The emails come last and each is wrapped on its own, because the lead is
// already saved by this point — a dead SMTP host must never cost us a family.
// Both attempts are recorded in the email log either way.
require_once __DIR__ . '/inquiry_ui.php';
require_once __DIR__ . '/../mailer.php';
Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /inquiry/details.php');
    exit;
}
require_csrf();

$details = [
    'semester_label' => (string)($_POST['semester_label'] ?? ''),
    'owned_instruments' => array_values(array_intersect(
        array_map('strval', (array)($_POST['owned_instruments'] ?? [])),
        LeadManagement::OWNED_INSTRUMENT_CHOICES
    )),
    'owned_instruments_other' => trim((string)($_POST['owned_instruments_other'] ?? '')),
    'music_background' => trim((string)($_POST['music_background'] ?? '')),
    'theory_program_interest' => (string)($_POST['theory_program_interest'] ?? ''),
    'theory_knowledge' => (string)($_POST['theory_knowledge'] ?? ''),
    'comments' => trim((string)($_POST['comments'] ?? '')),
    'referral_source' => (string)($_POST['referral_source'] ?? ''),
];

$state = inquiry_state();
$state['details'] = $details;
inquiry_save($state);

if ((string)($_POST['action'] ?? 'next') === 'back') {
    header('Location: /inquiry/student.php');
    exit;
}

$error = InquiryManagement::validateDetails($details, Settings::inquirySemesterOptions());
if ($error !== null) {
    inquiry_fail($error, '/inquiry/details.php');
}

$leadId = inquiry_lead_id();
if ($leadId === null) {
    inquiry_fail('Please start again — your session timed out.', '/inquiry/contact.php');
}

try {
    LeadManagement::updateInquiryDetails(null, $leadId, $details);
} catch (\Throwable $e) {
    inquiry_fail(
        $e->getMessage() . ' Please try again, or call us at ' . Settings::contactPhone() . '.',
        '/inquiry/details.php'
    );
}

$lead = LeadManagement::findLead($leadId);
$students = LeadManagement::studentsForLead($leadId);
$leadStudent = $students[0] ?? [];
if ($lead) {
    try {
        send_inquiry_confirmation_email($lead, $leadStudent);
    } catch (\Throwable $e) {
        // Recorded in the email log; the family is already in the queue.
    }
    try {
        send_inquiry_staff_notification_email($lead, $leadStudent);
    } catch (\Throwable $e) {
        // Same: staff can still find this lead on the Leads page.
    }
}

inquiry_reset();
header('Location: /inquiry/done.php');
exit;
