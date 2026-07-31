<?php
// Handles the registration form POST (both submit buttons). On success:
// creates the family, emails the parent an account invite, and redirects to
// the confirmation page. On failure: flashes the error + submitted values
// back to register.php so nothing has to be retyped.
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/FamilyManagement.php';
require_once __DIR__ . '/lib/UserManagement.php';
require_once __DIR__ . '/lib/EmailTemplates.php';

Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /register.php');
    exit;
}
require_csrf();

$parent = (array)($_POST['parent'] ?? []);
$students = array_values((array)($_POST['students'] ?? []));
$prefs = (array)($_POST['prefs'] ?? []);
$path = ($_POST['submit_action'] ?? '') === 'complete_enrollment' ? 'complete_enrollment' : 'talk_first';

$flashBack = function (string $message) use ($parent, $students, $prefs): void {
    $_SESSION['register_error'] = $message;
    $_SESSION['register_old'] = ['parent' => $parent, 'students' => $students, 'prefs' => $prefs];
    header('Location: /register.php');
    exit;
};

// Validation (server-side; the form's `required` attributes are a courtesy).
if (trim((string)($parent['first_name'] ?? '')) === '' || trim((string)($parent['last_name'] ?? '')) === '') {
    $flashBack('Please enter the parent or guardian\'s name.');
}
if (!filter_var((string)($parent['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
    $flashBack('Please enter a valid email address.');
}
if (trim((string)($parent['cell_phone'] ?? '')) === '') {
    $flashBack('Please enter a cell phone number so we can reach you.');
}
if (trim((string)($parent['emergency_contact_name'] ?? '')) === '' || trim((string)($parent['emergency_contact_phone'] ?? '')) === '') {
    $flashBack('Please provide an emergency contact.');
}
if (empty($prefs['consent_terms']) || empty($prefs['consent_liability'])) {
    $flashBack('Please agree to the terms and conditions and the liability waiver.');
}

$hasStudent = !empty($parent['parent_is_student']);
foreach ($students as $s) {
    if (trim((string)($s['first_name'] ?? '')) !== '' && trim((string)($s['last_name'] ?? '')) !== '') {
        $hasStudent = true;
    }
}
if (!$hasStudent) {
    $flashBack('Please add at least one student (or check "I\'m registering for lessons for myself").');
}

try {
    $result = FamilyManagement::createFamilyFromRegistration(null, $parent, $students, $prefs, $path);
} catch (DuplicateAccountException $e) {
    $flashBack('It looks like you already have a BCM account. Please log in to re-enroll, or call us at '
        . Settings::contactPhone() . ' and we\'ll help.');
} catch (InvalidArgumentException $e) {
    $flashBack($e->getMessage());
} catch (\Throwable $e) {
    $flashBack('Something went wrong saving your registration: ' . $e->getMessage()
        . ' — please try again or call us at ' . Settings::contactPhone() . '.');
}

// Emails are best-effort: the registration is already saved, and every send
// is recorded in the email log either way.
$parentUserId = (int)$result['parent_user_id'];
try {
    UserManagement::sendAccountInvite(null, $parentUserId);
} catch (\Throwable $e) {
    // e.g. the parent already had a passwordless account invite pending.
}
try {
    $studentFirstNames = array_values(array_filter(array_map(
        fn($s) => trim((string)($s['first_name'] ?? '')),
        $students
    )));
    $welcome = EmailTemplates::registrationReceived((string)$parent['first_name'], $path, $studentFirstNames);
    send_email((string)$parent['email'], $welcome['subject'], $welcome['html'],
        trim((string)$parent['first_name'] . ' ' . (string)$parent['last_name']));
} catch (\Throwable $e) {
    // Logged by the mailer; never blocks the confirmation page.
}

header('Location: /register_confirm.php?path=' . urlencode($path));
exit;
