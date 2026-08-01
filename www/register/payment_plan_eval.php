<?php
// POST: step 4 — record the installment-plan choice.
require_once __DIR__ . '/register_ui.php';
Application::init();
registration_require_open();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /register/payment_plan.php');
    exit;
}
require_csrf();

$state = registration_state();
if (($_POST['action'] ?? '') === 'back') {
    header('Location: /register/policies.php');
    exit;
}

$choice = $_POST['installment'] ?? null;
if ($choice !== '0' && $choice !== '1') {
    registration_fail('Please choose whether you want the installment plan.', '/register/payment_plan.php');
}
$state['installment'] = (int)$choice;
registration_save(registration_mark_completed($state, 4));
header('Location: /register/review.php');
exit;
