<?php
// POST: return to checkout to pay for the browser session's lead (after
// "I'll pay later", a decline, or a canceled attempt). The checkout page
// owns the PaymentIntent, so this just sends them back there.
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

header('Location: /register/checkout.php');
exit;
