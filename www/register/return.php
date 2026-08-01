<?php
// Landing page after Stripe Checkout for a registration lead. On success,
// verify the session with Stripe and record the payment if the webhook
// hasn't already (both paths are idempotent). No login — the visitor is a
// prospective family.
require_once __DIR__ . '/register_ui.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();

$status = (string)($_GET['status'] ?? '');

if ($status === 'success') {
    $sessionId = (string)($_GET['session_id'] ?? '');
    try {
        $session = StripeCheckout::retrieveCheckoutSession($sessionId);
        if ((string)($session['payment_status'] ?? '') === 'paid') {
            StripeCheckout::handleCheckoutSessionCompleted($session);
        }
    } catch (\Throwable $e) {
        // The webhook will still record it; the done page shows live state.
    }
    header('Location: /register/done.php');
    exit;
}

header('Location: /register/done.php?pay=canceled');
exit;
