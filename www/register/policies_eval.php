<?php
// POST: step 3 — record the policies agreement.
require_once __DIR__ . '/register_ui.php';
Application::init();
registration_require_open();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /register/policies.php');
    exit;
}
require_csrf();

$state = registration_state();
$state['policies_agreed'] = !empty($_POST['agree']);
registration_save($state);

if (($_POST['action'] ?? '') === 'back') {
    header('Location: /register/students.php');
    exit;
}
if (!$state['policies_agreed']) {
    registration_fail('Please read and agree to the policies to continue.', '/register/policies.php');
}

registration_save(registration_mark_completed($state, 3));
header('Location: /register/payment_plan.php');
exit;
