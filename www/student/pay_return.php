<?php
// Where Stripe sends the student after they submit the card form. The webhook
// is the authoritative record; this is the fast path so the balance is right
// by the time they are looking at it again. Both are idempotent.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('student', $roles, true) && empty($me['is_admin'])) {
    http_response_code(403);
    die('Students only');
}

$intentId = (string)($_GET['payment_intent'] ?? '');

if ($intentId !== '') {
    try {
        $intent = StripeCheckout::retrievePaymentIntent($intentId);
        $status = (string)($intent['status'] ?? '');
        if ($status === 'succeeded') {
            StripeCheckout::handlePaymentIntentSucceeded($intent);
            // The intent is spent; another payment needs a fresh one.
            unset($_SESSION['student_payment_client_secret'], $_SESSION['student_payment_key']);
            $_SESSION['billing_flash'] = 'Thank you! Your payment was received.';
        } elseif ($status === 'processing') {
            $_SESSION['billing_flash'] = 'Thank you! Your payment is being processed and will appear here shortly.';
        } else {
            $_SESSION['billing_flash_error'] = 'That payment did not go through — nothing was charged. Please try another card, or call us.';
        }
    } catch (\Throwable $e) {
        // If the charge did succeed, the webhook still records it.
        $_SESSION['billing_flash_error'] = 'We could not confirm the payment yet: ' . $e->getMessage();
    }
} else {
    $_SESSION['billing_flash_error'] = 'Payment was canceled — nothing was charged.';
}

header('Location: /student/billing.php');
exit;
