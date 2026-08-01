<?php
// POST: validate + save step 1 (family info) into the wizard state.
require_once __DIR__ . '/register_ui.php';
Application::init();
registration_require_open();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /register/family.php');
    exit;
}
require_csrf();

$family = [
    'first_name' => trim((string)($_POST['first_name'] ?? '')),
    'last_name' => trim((string)($_POST['last_name'] ?? '')),
    'phone' => trim((string)($_POST['phone'] ?? '')),
    'email' => trim((string)($_POST['email'] ?? '')),
    'sms_consent' => !empty($_POST['sms_consent']) ? 1 : 0,
    'address_street_1' => trim((string)($_POST['address_street_1'] ?? '')),
    'address_street_2' => trim((string)($_POST['address_street_2'] ?? '')),
    'address_city' => trim((string)($_POST['address_city'] ?? '')),
    'address_state' => trim((string)($_POST['address_state'] ?? '')),
    'address_zip' => trim((string)($_POST['address_zip'] ?? '')),
];

// Save first so the form repopulates even when validation fails.
$state = registration_state();
$state['family'] = $family;
registration_save($state);

if ($family['first_name'] === '' || $family['last_name'] === '') {
    registration_fail('Please enter the parent or guardian\'s name.', '/register/family.php');
}
if (!filter_var($family['email'], FILTER_VALIDATE_EMAIL)) {
    registration_fail('Please enter a valid email address.', '/register/family.php');
}
if (strlen(preg_replace('/\D/', '', $family['phone'])) < 10) {
    registration_fail('Please enter a phone number with area code so we can reach you.', '/register/family.php');
}
if ($family['address_street_1'] === '' || $family['address_city'] === ''
    || $family['address_state'] === '' || $family['address_zip'] === '') {
    registration_fail('Please fill in your address.', '/register/family.php');
}

registration_save(registration_mark_completed($state, 1));
header('Location: /register/students.php');
exit;
