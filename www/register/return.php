<?php
// Where Stripe sends the family after they submit the embedded card form.
// The webhook is the authoritative record; this is the fast path so the
// thank-you page can show the payment immediately. Both are idempotent.
// No login — the visitor is a prospective family.
require_once __DIR__ . '/register_ui.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();

$intentId = (string)($_GET['payment_intent'] ?? '');

if ($intentId !== '') {
    try {
        $intent = StripeCheckout::retrievePaymentIntent($intentId);
        $status = (string)($intent['status'] ?? '');
        if ($status === 'succeeded') {
            StripeCheckout::handlePaymentIntentSucceeded($intent);
            // The intent is spent; a retry would need a fresh one.
            unset($_SESSION['registration_payment_client_secret'], $_SESSION['registration_payment_lead_id']);
            header('Location: /register/done.php');
            exit;
        }
        if ($status === 'processing') {
            header('Location: /register/done.php?pay=processing');
            exit;
        }
        // requires_payment_method — the card was declined or abandoned.
        $_SESSION['registration_error'] = 'That payment did not go through. Please try another card.';
        header('Location: /register/checkout.php');
        exit;
    } catch (\Throwable $e) {
        // The webhook will still record it if the charge actually succeeded.
        header('Location: /register/done.php?pay=unconfirmed');
        exit;
    }
}

// A Checkout Session returning from the older hosted flow.
if ((string)($_GET['status'] ?? '') === 'success') {
    try {
        $session = StripeCheckout::retrieveCheckoutSession((string)($_GET['session_id'] ?? ''));
        if ((string)($session['payment_status'] ?? '') === 'paid') {
            StripeCheckout::handleCheckoutSessionCompleted($session);
        }
    } catch (\Throwable $e) {
        // Fall through to the thank-you page; the webhook is authoritative.
    }
    header('Location: /register/done.php');
    exit;
}

header('Location: /register/done.php?pay=canceled');
exit;
