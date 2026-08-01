<?php
// Landing page after Stripe Checkout. On success, verify the session with
// Stripe and record the payment if the webhook hasn't already (both paths
// are idempotent), then bounce back to Billing with a flash.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();
require_login();

$status = (string)($_GET['status'] ?? '');

if ($status === 'success') {
    $sessionId = (string)($_GET['session_id'] ?? '');
    try {
        $session = StripeCheckout::retrieveCheckoutSession($sessionId);
        if ((string)($session['payment_status'] ?? '') === 'paid') {
            StripeCheckout::handleCheckoutSessionCompleted($session);
            $_SESSION['billing_flash'] = 'Thank you! Your payment was received.';
        } else {
            $_SESSION['billing_flash_error'] = 'Your payment has not completed yet. If you finished checkout, it will appear shortly.';
        }
    } catch (\Throwable $e) {
        $_SESSION['billing_flash_error'] = 'We could not confirm the payment yet: ' . $e->getMessage();
    }
} elseif ($status === 'cancel') {
    $_SESSION['billing_flash_error'] = 'Checkout was canceled — nothing was charged.';
}

header('Location: /parent/billing.php');
exit;
