<?php
// Ajax POST: capture a drop-off draft the moment the family types a
// plausible email OR phone number on the registration form — before they
// ever press Continue. A family who types either and walks away is already
// worth a phone call, so this writes the same incomplete_inquiries draft
// the family step writes, at stage 1 ("contact only"), with whatever else
// is on the form so far. The session keeps the draft id, so repeats update
// one row; family_eval.php later completes the same draft.
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
$phone = trim((string)($_POST['phone'] ?? ''));
$emailOk = (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
$phoneOk = strlen(preg_replace('/\D/', '', $phone)) >= 10; // the family-step rule
if ((!$emailOk && !$phoneOk) || !empty($_SESSION['registration_lead_id'])) {
    echo json_encode(['ok' => false]);
    exit;
}
// Whatever else is on the form comes along; the stored family state fills
// gaps if the visitor did this step before (Back button), and a value the
// draft already holds is never overwritten by a worse one — a half-typed
// email must not erase the good email captured a moment ago.
$draft = registration_draft_id() !== null ? InquiryManagement::find(registration_draft_id()) : null;
$state = registration_state();
$posted = fn(string $key) => trim((string)($_POST[$key] ?? ''));
$family = array_merge((array)$state['family'], [
    'first_name' => $posted('first_name') !== '' ? $posted('first_name') : (string)($draft['first_name'] ?? ''),
    'last_name' => $posted('last_name') !== '' ? $posted('last_name') : (string)($draft['last_name'] ?? ''),
    'phone' => $phoneOk ? $phone : (((string)($draft['phone'] ?? '')) ?: $phone),
    'email' => $emailOk ? $email : (string)($draft['email'] ?? ''),
]);

try {
    $_SESSION['registration_draft_id'] =
        InquiryManagement::recordRegistrationDraft(null, registration_draft_id(), $family, 1);
    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false]);
}
