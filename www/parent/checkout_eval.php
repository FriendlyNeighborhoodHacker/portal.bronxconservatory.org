<?php
// POST: start a Stripe Checkout for the family's outstanding balance —
// one line item per child who owes — then redirect to Stripe.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/Billing.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /parent/billing.php');
    exit;
}
require_csrf();

$me = current_user();

try {
    $studentAmounts = array_filter(
        Billing::balancesForParentChildren((int)$me['id']),
        fn($cents) => $cents > 0
    );
    if (!$studentAmounts) {
        throw new InvalidArgumentException('You are paid in full — nothing to pay.');
    }
    $baseUrl = rtrim(Settings::get('site_base_url', ''), '/');
    if ($baseUrl === '') {
        // Local development fallback: derive from the request.
        $baseUrl = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $default = SemesterManagement::resolveDefaultSemester();

    $session = StripeCheckout::createCheckoutSession(
        UserContext::getLoggedInUserContext(),
        $studentAmounts,
        (int)$me['id'],
        $default ? (int)$default['id'] : null,
        $baseUrl . '/parent/billing_return.php?status=success&session_id={CHECKOUT_SESSION_ID}',
        $baseUrl . '/parent/billing_return.php?status=cancel'
    );
    if ($session['url'] === '') {
        throw new RuntimeException('Stripe did not return a checkout link.');
    }
    header('Location: ' . $session['url']);
} catch (\Throwable $e) {
    $_SESSION['billing_flash_error'] = $e->getMessage();
    header('Location: /parent/billing.php');
}
exit;
