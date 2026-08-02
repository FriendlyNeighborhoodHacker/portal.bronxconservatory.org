<?php
// Evaluates information request page 2 and attaches the address to the
// uncompleted form (or, if page 3 has already run, to the lead itself).
require_once __DIR__ . '/inquiry_ui.php';
Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /inquiry/address.php');
    exit;
}
require_csrf();

$address = [
    'address_country' => trim((string)($_POST['address_country'] ?? InquiryManagement::DEFAULT_COUNTRY)),
    'address_street_1' => trim((string)($_POST['address_street_1'] ?? '')),
    'address_street_2' => trim((string)($_POST['address_street_2'] ?? '')),
    'address_city' => trim((string)($_POST['address_city'] ?? '')),
    'address_state' => trim((string)($_POST['address_state'] ?? '')),
    'address_province' => trim((string)($_POST['address_province'] ?? '')),
    'address_zip' => trim((string)($_POST['address_zip'] ?? '')),
];

// Saved before validating so the form repopulates when we send them back.
$state = inquiry_state();
$state['address'] = $address;
inquiry_save($state);

if ((string)($_POST['action'] ?? 'next') === 'back') {
    header('Location: /inquiry/contact.php');
    exit;
}

$error = InquiryManagement::validateAddress($address);
if ($error !== null) {
    inquiry_fail($error, '/inquiry/address.php');
}

try {
    $leadId = inquiry_lead_id();
    $draftId = inquiry_draft_id();
    if ($leadId !== null) {
        LeadManagement::updateInquiryAddress(null, $leadId, InquiryManagement::normalizeAddress($address));
    } elseif ($draftId !== null) {
        InquiryManagement::saveAddress(null, $draftId, $address);
    } else {
        // No record to attach to: the session was lost between pages. Send
        // them back to page 1 rather than dropping the address on the floor.
        inquiry_fail('Please start again — your session timed out.', '/inquiry/contact.php');
    }
} catch (\Throwable $e) {
    inquiry_fail(
        $e->getMessage() . ' Please try again, or call us at ' . Settings::contactPhone() . '.',
        '/inquiry/address.php'
    );
}

inquiry_save(inquiry_mark_completed($state, 2));
header('Location: /inquiry/student.php');
exit;
