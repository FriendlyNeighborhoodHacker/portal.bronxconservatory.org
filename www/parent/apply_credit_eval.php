<?php
// POST: a parent puts their family credit toward one child's balance (the
// "Use your credit" card on Billing). The amount is decided here, never
// taken from the form: as much of the credit as that child owes.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/Billing.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /parent/billing.php');
    exit;
}
require_csrf();

$me = current_user();
$studentUserId = (int)($_POST['student_user_id'] ?? 0);

try {
    $applied = Billing::applyParentCreditByChoice(
        UserContext::getLoggedInUserContext(), (int)$me['id'], $studentUserId
    );
    $_SESSION['billing_flash'] = Billing::formatCents($applied) . ' of your credit was applied.';
} catch (\Throwable $e) {
    $_SESSION['billing_flash_error'] = $e->getMessage();
}
header('Location: /parent/billing.php');
exit;
