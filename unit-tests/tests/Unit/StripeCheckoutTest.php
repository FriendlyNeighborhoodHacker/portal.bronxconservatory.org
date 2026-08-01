<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StripeCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        test_reset_all();
        if (!defined('STRIPE_SECRET_KEY')) {
            define('STRIPE_SECRET_KEY', 'sk_test_dummy');
        }
    }

    protected function tearDown(): void
    {
        StripeCheckout::setHttpTransportForTesting(null);
    }

    public function testWebhookSignatureVerification(): void
    {
        $secret = 'whsec_test';
        $payload = '{"id":"evt_1"}';
        $now = 1_900_000_000;
        $sig = hash_hmac('sha256', $now . '.' . $payload, $secret);

        $header = "t=$now,v1=$sig";
        $this->assertTrue(StripeCheckout::verifyWebhookSignature($payload, $header, $secret, 300, $now + 10));

        // Garbled signature, wrong secret, expired timestamp, malformed header.
        $this->assertFalse(StripeCheckout::verifyWebhookSignature($payload, "t=$now,v1=deadbeef", $secret, 300, $now));
        $this->assertFalse(StripeCheckout::verifyWebhookSignature($payload, $header, 'whsec_other', 300, $now));
        $this->assertFalse(StripeCheckout::verifyWebhookSignature($payload, $header, $secret, 300, $now + 301));
        $this->assertFalse(StripeCheckout::verifyWebhookSignature($payload, 'v1=' . $sig, $secret, 300, $now));
    }

    public function testCreateCheckoutSessionEncodesLineItemsAndMetadata(): void
    {
        $ctx = fx_admin_ctx();
        $childA = fx_student('Ann', 'Kid');
        $childB = fx_student('Ben', 'Kid');
        $parent = fx_parent_of($childA);

        $captured = null;
        StripeCheckout::setHttpTransportForTesting(function ($method, $url, $params) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'params' => $params];
            return [200, json_encode(['id' => 'cs_test_9', 'url' => 'https://checkout.stripe.com/pay/cs_test_9'])];
        });

        $session = StripeCheckout::createCheckoutSession(
            $ctx,
            [$childA => 10000, $childB => 2500, 999 => 0], // zero entries drop out
            $parent,
            7,
            'https://portal.example/success?session_id={CHECKOUT_SESSION_ID}',
            'https://portal.example/cancel'
        );

        $this->assertSame('cs_test_9', $session['id']);
        $this->assertSame('POST', $captured['method']);
        $this->assertStringEndsWith('/checkout/sessions', $captured['url']);
        $p = $captured['params'];
        $this->assertSame('payment', $p['mode']);
        $this->assertSame('10000', $p['line_items[0][price_data][unit_amount]']);
        $this->assertSame('2500', $p['line_items[1][price_data][unit_amount]']);
        $this->assertStringContainsString('Ann Kid', $p['line_items[0][price_data][product_data][name]']);
        $this->assertSame((string)$parent, $p['metadata[paid_by_user_id]']);
        $this->assertSame('7', $p['metadata[semester_id]']);
        $this->assertSame([(string)$childA => 10000, (string)$childB => 2500],
            json_decode($p['metadata[student_amounts]'], true));
        $this->assertArrayNotHasKey('line_items[2][quantity]', $p);
    }

    public function testCompletedSessionPostsOneCreditPerStudentIdempotently(): void
    {
        $childA = fx_student('Ann', 'Kid');
        $childB = fx_student('Ben', 'Kid');

        $session = [
            'id' => 'cs_live_1',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_9',
            'metadata' => [
                'semester_id' => '',
                'student_amounts' => json_encode([$childA => 10000, $childB => 2500]),
            ],
        ];

        $this->assertSame(2, StripeCheckout::handleCheckoutSessionCompleted($session));
        // Webhook retry / success-redirect race: nothing double-recorded.
        $this->assertSame(0, StripeCheckout::handleCheckoutSessionCompleted($session));

        $this->assertSame(-10000, Billing::balanceForStudentCents($childA));
        $this->assertSame(-2500, Billing::balanceForStudentCents($childB));

        // Unpaid sessions record nothing.
        $unpaid = $session;
        $unpaid['id'] = 'cs_live_2';
        $unpaid['payment_status'] = 'unpaid';
        $this->assertSame(0, StripeCheckout::handleCheckoutSessionCompleted($unpaid));
    }

    public function testApiErrorsSurfaceAsExceptions(): void
    {
        StripeCheckout::setHttpTransportForTesting(function () {
            return [402, json_encode(['error' => ['message' => 'Your card was declined.']])];
        });
        $this->expectExceptionMessage('Your card was declined.');
        StripeCheckout::retrieveCheckoutSession('cs_test_1');
    }
}
