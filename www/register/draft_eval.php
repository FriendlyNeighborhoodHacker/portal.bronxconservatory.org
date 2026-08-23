<?php
// Ajax POST: capture a drop-off draft the moment the family types a
// plausible email on the registration form — before they ever press
// Continue. A family who types their email and walks away is already worth
// a phone call, so this writes the same incomplete_inquiries draft the
// family step writes, at stage 1 ("email only"), with whatever name and
// phone are on the form so far. The session keeps the draft id, so repeats
// update one row; family_eval.php later completes the same draft.
//
// Silent by design: this must never interrupt someone who is still typing,
// so every outcome — including an email that does not validate — is a quiet
// JSON answer the page ignores.
require_once __DIR__ . '/register_ui.php';
Application::init();
registration_require_open();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
require_csrf();

$email = trim((string)($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !empty($_SESSION['registration_lead_id'])) {
    echo json_encode(['ok' => false]);
    exit;
}

// Whatever else is on the form comes along; the stored family state fills
// gaps if the visitor did this step before (Back button).
$state = registration_state();
$family = array_merge((array)$state['family'], [
    'first_name' => trim((string)($_POST['first_name'] ?? '')),
    'last_name' => trim((string)($_POST['last_name'] ?? '')),
    'phone' => trim((string)($_POST['phone'] ?? '')),
    'email' => $email,
]);

try {
    $_SESSION['registration_draft_id'] =
        InquiryManagement::recordRegistrationDraft(null, registration_draft_id(), $family, 1);
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false]);
}
