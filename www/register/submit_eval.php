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

$recaptchaError = recaptcha_verify_or_null('register_submit');
if ($recaptchaError !== null) {
    registration_fail($recaptchaError, '/register/review.php');
}

$installment = (int)$state['installment'] === 1;

// One lead per registration. A re-submit — a double click, or Back from
// checkout to change an answer — rewrites that same lead from the answers as
// they stand now, so the quote and the amount to pay always match the form.
// (The wizard state names its own lead, so a NEW registration started in the
// same browser never touches the previous family's.)
$leadId = (int)($state['lead_id'] ?? 0);
$existing = $leadId ? LeadManagement::findLead($leadId) : null;
if ($existing && !LeadManagement::canFamilyStillEdit($existing)) {
    $_SESSION['registration_lead_id'] = $leadId;
    // Paid (or already taken up by staff): nothing more to submit.
    header('Location: /register/done.php');
    exit;
}
$isResubmit = (bool)$existing;
try {
    $leadId = LeadManagement::createLead(
        null,
        (int)$semester['id'],
        (array)$state['family'],
        registration_clean_students($state),
        (array)$state['scheduling'],
        $installment,
        $existing ? $leadId : null
    );
} catch (InvalidArgumentException $e) {
    registration_fail($e->getMessage(), '/register/review.php');
} catch (\Throwable $e) {
    registration_fail('Something went wrong saving your registration: ' . $e->getMessage()
        . ' — please try again or call us at ' . Settings::contactPhone() . '.', '/register/review.php');
}
$lead = LeadManagement::findLead($leadId);
$changed = $isResubmit && (
    (string)$lead['quote_json'] !== (string)$existing['quote_json']
    || (int)$lead['amount_due_now_cents'] !== (int)$existing['amount_due_now_cents']
    || (string)$lead['email'] !== (string)$existing['email']
);
if ($changed) {
    // The payment that was being set up is for the old amount; checkout
    // starts a fresh one.
    unset($_SESSION['registration_payment_client_secret'], $_SESSION['registration_payment_lead_id']);
}

if (!$isResubmit) {
    $_SESSION['registration_lead_id'] = $leadId;
    $state['lead_id'] = $leadId;
    registration_save($state);

    // The lead is the record now — retire the drop-off draft, carrying any
    // notes staff wrote while the form sat unfinished onto the lead. Best
    // effort: the registration must finish even if this hiccups.
    $draftId = registration_draft_id();
    if ($draftId !== null) {
        try {
            InquiryManagement::finishRegistrationDraft(null, $draftId, $leadId);
        } catch (\Throwable $e) {
            // The draft stays in the queue; staff will see the lead exists.
        }
        unset($_SESSION['registration_draft_id']);
    }
}

// Confirmation email on the first submit, and again when a resubmit changed
// what it says. Best-effort — logged either way, never blocking.
if (!$isResubmit || $changed) {
    try {
        send_lead_registration_email(
            $lead,
            LeadManagement::studentsForLead($leadId),
            json_decode((string)$lead['quote_json'], true) ?: []
        );
    } catch (\Throwable $e) {
        // The email log has the failure; the family still sees the done page.
    }
}

// The wizard's answers are kept (not reset) so Back from checkout shows them;
// done.php clears them.

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
