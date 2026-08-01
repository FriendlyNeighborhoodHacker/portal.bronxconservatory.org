<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/Billing.php';
require_once __DIR__ . '/UserManagement.php';

/**
 * Stripe Checkout via the raw HTTPS API (no SDK): a parent's "Pay Now"
 * creates a Checkout Session with one line item per child; the webhook
 * (stripe_webhook.php) and the success-redirect fallback both record the
 * completed payment through Billing::recordStripePayment, whose unique key
 * makes the race harmless. Keys live in config.local.php
 * (STRIPE_SECRET_KEY / STRIPE_PUBLISHABLE_KEY / STRIPE_WEBHOOK_SECRET).
 */
class StripeCheckout {

    private const API_BASE = 'https://api.stripe.com/v1';

    /** @var ?callable (string $method, string $url, ?array $params) => [int $status, string $bodyJson] */
    private static $httpTransportForTesting = null;

    public static function setHttpTransportForTesting(?callable $transport): void {
        self::$httpTransportForTesting = $transport;
    }

    public static function isConfigured(): bool {
        if (self::$httpTransportForTesting !== null) {
            return true; // tests inject their own transport
        }
        return defined('STRIPE_SECRET_KEY') && STRIPE_SECRET_KEY !== '';
    }

    /**
     * Create a Checkout Session. $studentAmounts: [studentUserId => cents]
     * (only positive entries are charged). Returns ['id' => ..., 'url' => ...].
     */
    public static function createCheckoutSession(
        ?UserContext $ctx,
        array $studentAmounts,
        int $paidByUserId,
        ?int $semesterId,
        string $successUrl,
        string $cancelUrl
    ): array {
        self::assertConfigured();
        $studentAmounts = array_filter(array_map('intval', $studentAmounts), fn($cents) => $cents > 0);
        if (!$studentAmounts) {
            throw new InvalidArgumentException('Nothing to pay.');
        }

        $params = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata[paid_by_user_id]' => (string)$paidByUserId,
            'metadata[semester_id]' => $semesterId !== null ? (string)$semesterId : '',
            'metadata[student_amounts]' => json_encode($studentAmounts),
        ];
        $i = 0;
        foreach ($studentAmounts as $studentUserId => $cents) {
            $student = UserManagement::findById((int)$studentUserId);
            $label = $student
                ? 'BCM tuition — ' . trim($student['first_name'] . ' ' . $student['last_name'])
                : 'BCM tuition';
            $params["line_items[$i][price_data][currency]"] = 'usd';
            $params["line_items[$i][price_data][unit_amount]"] = (string)$cents;
            $params["line_items[$i][price_data][product_data][name]"] = $label;
            $params["line_items[$i][quantity]"] = '1';
            $i++;
        }

        $session = self::request('POST', self::API_BASE . '/checkout/sessions', $params);
        self::log($ctx, 'stripe.checkout_session_created', [
            'session_id' => $session['id'] ?? null,
            'paid_by_user_id' => $paidByUserId,
            'total_cents' => array_sum($studentAmounts),
        ]);
        return ['id' => (string)($session['id'] ?? ''), 'url' => (string)($session['url'] ?? '')];
    }

    public static function retrieveCheckoutSession(string $sessionId): array {
        self::assertConfigured();
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
            throw new InvalidArgumentException('Bad checkout session id.');
        }
        return self::request('GET', self::API_BASE . '/checkout/sessions/' . $sessionId, null);
    }

    /**
     * Verify a Stripe-Signature header against the raw payload:
     * "t=<ts>,v1=<hmac>,..." where hmac = HMAC-SHA256("$ts.$payload", secret).
     */
    public static function verifyWebhookSignature(string $payload, string $sigHeader, string $secret, int $toleranceSeconds = 300, ?int $now = null): bool {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $sigHeader) as $part) {
            $pieces = explode('=', trim($part), 2);
            if (count($pieces) !== 2) {
                continue;
            }
            if ($pieces[0] === 't') {
                $timestamp = (int)$pieces[1];
            } elseif ($pieces[0] === 'v1') {
                $signatures[] = $pieces[1];
            }
        }
        if ($timestamp === null || !$signatures) {
            return false;
        }
        if (abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Record a completed Checkout Session's payments in the ledger — one
     * credit per student from metadata[student_amounts]. Idempotent (unique
     * key per session + student). Returns how many credits were recorded.
     */
    public static function handleCheckoutSessionCompleted(array $session): int {
        if ((string)($session['payment_status'] ?? '') !== 'paid') {
            return 0;
        }
        $sessionId = (string)($session['id'] ?? '');
        $paymentIntentId = isset($session['payment_intent']) ? (string)$session['payment_intent'] : null;
        $metadata = (array)($session['metadata'] ?? []);
        $semesterId = (int)($metadata['semester_id'] ?? 0) ?: null;
        $studentAmounts = json_decode((string)($metadata['student_amounts'] ?? ''), true);
        if (!is_array($studentAmounts) || $sessionId === '') {
            return 0;
        }

        $recorded = 0;
        foreach ($studentAmounts as $studentUserId => $cents) {
            $studentUserId = (int)$studentUserId;
            $cents = (int)$cents;
            if ($studentUserId <= 0 || $cents <= 0) {
                continue;
            }
            if (Billing::recordStripePayment($studentUserId, $cents, $sessionId, $paymentIntentId, $semesterId)) {
                $recorded++;
            }
        }
        return $recorded;
    }

    // ── internals ─────────────────────────────────────────────────────────

    private static function request(string $method, string $url, ?array $params): array {
        if (self::$httpTransportForTesting !== null) {
            [$status, $body] = (self::$httpTransportForTesting)($method, $url, $params);
        } else {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET_KEY . ':');
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params ?? []));
            }
            $body = curl_exec($ch);
            if ($body === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException('Stripe request failed: ' . $error);
            }
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Stripe returned an unreadable response.');
        }
        if ($status >= 400) {
            $message = (string)($decoded['error']['message'] ?? ('Stripe error (HTTP ' . $status . ').'));
            throw new RuntimeException($message);
        }
        return $decoded;
    }

    private static function assertConfigured(): void {
        if (!self::isConfigured()) {
            throw new RuntimeException('Stripe is not configured (set STRIPE_SECRET_KEY in config.local.php).');
        }
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
