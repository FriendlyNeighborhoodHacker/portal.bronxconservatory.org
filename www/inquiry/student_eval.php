<?php
// Evaluates information request page 3 and promotes the uncompleted form into
// a real lead — from here on the family is in the Leads queue, whether or not
// they finish page 4.
//
// Re-entrant: a visitor who goes Back and returns edits the lead they already
// created rather than forking a second one.
require_once __DIR__ . '/inquiry_ui.php';
Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /inquiry/student.php');
    exit;
}
require_csrf();

$student = [
    'first_name' => trim((string)($_POST['first_name'] ?? '')),
    'last_name' => trim((string)($_POST['last_name'] ?? '')),
    'age' => trim((string)($_POST['age'] ?? '')),
    'enrollment_status' => (string)($_POST['enrollment_status'] ?? ''),
    'instruments_of_interest' => array_values(array_intersect(
        array_map('strval', (array)($_POST['instruments_of_interest'] ?? [])),
        LeadManagement::INQUIRY_INSTRUMENT_INTERESTS
    )),
    'instruments_other' => trim((string)($_POST['instruments_other'] ?? '')),
];

$state = inquiry_state();
$state['student'] = $student;
inquiry_save($state);

if ((string)($_POST['action'] ?? 'next') === 'back') {
    header('Location: /inquiry/address.php');
    exit;
}

$error = InquiryManagement::validateStudent($student);
if ($error !== null) {
    inquiry_fail($error, '/inquiry/student.php');
}

try {
    $leadId = inquiry_lead_id();
    if ($leadId !== null && LeadManagement::findLead($leadId)) {
        LeadManagement::replaceInquiryStudent(null, $leadId, $student);
    } else {
        $draftId = inquiry_draft_id();
        if ($draftId === null) {
            inquiry_fail('Please start again — your session timed out.', '/inquiry/contact.php');
        }
        $_SESSION['inquiry_lead_id'] = InquiryManagement::promoteToLead(null, $draftId, $student);
        unset($_SESSION['inquiry_draft_id']);
    }
} catch (\Throwable $e) {
    inquiry_fail(
        $e->getMessage() . ' Please try again, or call us at ' . Settings::contactPhone() . '.',
        '/inquiry/student.php'
    );
}

inquiry_save(inquiry_mark_completed($state, 3));
header('Location: /inquiry/details.php');
exit;
