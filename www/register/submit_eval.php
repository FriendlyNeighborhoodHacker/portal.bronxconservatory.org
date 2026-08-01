<?php
// POST: final submit. Verifies reCAPTCHA, creates the lead (once — a
// double-submit reuses the session's lead), emails the confirmation, then
// hands off to Stripe Checkout when configured.
require_once __DIR__ . '/register_ui.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
require_once __DIR__ . '/../mailer.php';
Application::init();
$semester = registration_require_open();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /register/review.php');
    exit;
}
require_csrf();

$state = registration_require_step(5);

$recaptchaError = recaptcha_verify_or_null();
if ($recaptchaError !== null) {
    registration_fail($recaptchaError, '/register/review.php');
}

$installment = (int)$state['installment'] === 1;

// Create the lead exactly once per browser session; a re-submit (back
// button, double click) reuses it.
$leadId = (int)($_SESSION['registration_lead_id'] ?? 0);
if ($leadId === 0 || !LeadManagement::findLead($leadId)) {
    try {
        $leadId = LeadManagement::createLead(
            null,
            (int)$semester['id'],
            (array)$state['family'],
            registration_clean_students($state),
            (array)$state['scheduling'],
            $installment
        );
    } catch (InvalidArgumentException $e) {
        registration_fail($e->getMessage(), '/register/review.php');
    } catch (\Throwable $e) {
        registration_fail('Something went wrong saving your registration: ' . $e->getMessage()
            . ' — please try again or call us at ' . Settings::contactPhone() . '.', '/register/review.php');
    }
    $_SESSION['registration_lead_id'] = $leadId;

    // Confirmation email is best-effort — logged either way, never blocking.
    try {
        $lead = LeadManagement::findLead($leadId);
        send_lead_registration_email(
            $lead,
            LeadManagement::studentsForLead($leadId),
            json_decode((string)$lead['quote_json'], true) ?: []
        );
    } catch (\Throwable $e) {
        // The email log has the failure; the family still sees the done page.
    }

    // The wizard state has served its purpose.
    registration_reset();
}

// On to checkout, where the card fields live. When there is nothing to
// collect — Stripe unconfigured, or a zero quote — the registration simply
// finishes and BCM follows up about payment.
$lead = LeadManagement::findLead($leadId);
if (StripeCheckout::isConfigured() && (int)$lead['amount_paid_cents'] === 0 && (int)$lead['amount_due_now_cents'] > 0) {
    header('Location: /register/checkout.php');
    exit;
}

header('Location: /register/done.php');
exit;
