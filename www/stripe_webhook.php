<?php
// Stripe webhook receiver. POST only; the Stripe-Signature header is the
// authorization (no CSRF by design). Events are deduplicated in
// stripe_webhook_events, so retries and the success-redirect fallback are
// harmless. Handles checkout.session.completed -> ledger credits, and
// payment_intent.succeeded -> registration lead payments.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/StripeCheckout.php';
Application::init();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST required');
}
if (!defined('STRIPE_WEBHOOK_SECRET') || STRIPE_WEBHOOK_SECRET === '') {
    http_response_code(500);
    exit('Webhook secret not configured');
}

$payload = (string)file_get_contents('php://input');
$sigHeader = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

if (!StripeCheckout::verifyWebhookSignature($payload, $sigHeader, STRIPE_WEBHOOK_SECRET)) {
    http_response_code(400);
    exit('Bad signature');
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['id'])) {
    http_response_code(400);
    exit('Bad payload');
}

// Dedup: each event id is processed at most once.
$st = pdo()->prepare('INSERT IGNORE INTO stripe_webhook_events (stripe_event_id, event_type, payload_json) VALUES (?,?,?)');
$st->execute([(string)$event['id'], (string)($event['type'] ?? ''), $payload]);
if ($st->rowCount() === 0) {
    http_response_code(200);
    exit('Already processed');
}

switch ((string)($event['type'] ?? '')) {
    case 'checkout.session.completed':
        // Hosted Checkout: parent billing, and any registration session
        // still in flight from before the embedded card form.
        StripeCheckout::handleCheckoutSessionCompleted((array)($event['data']['object'] ?? []));
        break;
    case 'payment_intent.succeeded':
        // The registration form's embedded card fields.
        StripeCheckout::handlePaymentIntentSucceeded((array)($event['data']['object'] ?? []));
        break;
}

http_response_code(200);
echo 'OK';
