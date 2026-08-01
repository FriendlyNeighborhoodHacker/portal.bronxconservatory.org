<?php
// POST: retry payment for the browser session's lead (after a canceled or
// failed checkout). Creates a fresh Checkout Session and redirects to it.
require_once __DIR__ . '/register_ui.php';
require_once __DIR__ . '/../lib/StripeCheckout.php';
Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /register/done.php');
    exit;
}
require_csrf();

$leadId = (int)($_SESSION['registration_lead_id'] ?? 0);
$lead = $leadId ? LeadManagement::findLead($leadId) : null;
if (!$lead || (int)$lead['amount_paid_cents'] > 0 || !StripeCheckout::isConfigured()) {
    header('Location: /register/done.php');
    exit;
}

try {
    $base = rtrim(Settings::get('site_base_url', ''), '/');
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $quoteLines = json_decode((string)$lead['quote_json'], true) ?: [];
    $dueNow = (int)$lead['amount_due_now_cents'];
    $lines = $dueNow === (int)$lead['amount_quoted_cents']
        ? $quoteLines
        : [['label' => 'BCM registration — due today (fees + 50% tuition deposit)', 'amount_cents' => $dueNow]];
    $session = StripeCheckout::createLeadCheckoutSession(
        null,
        $leadId,
        $lines,
        $base . '/register/return.php?status=success&session_id={CHECKOUT_SESSION_ID}',
        $base . '/register/return.php?status=cancel'
    );
    LeadManagement::attachCheckoutSession(null, $leadId, $session['id']);
    header('Location: ' . $session['url']);
} catch (\Throwable $e) {
    header('Location: /register/done.php?pay=error');
}
exit;
