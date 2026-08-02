<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StripeCheckoutTest extends TestCase
{
    private int $semesterId;

    protected function setUp(): void
    {
        test_reset_all();
        if (!defined('STRIPE_SECRET_KEY')) {
            define('STRIPE_SECRET_KEY', 'sk_test_dummy');
        }
        $ctx = fx_admin_ctx();
        $this->semesterId = SemesterManagement::createSemester($ctx, 'fall', 2030, '2030-09-01', '2030-12-31', [
            'registration_fee' => '35.00',
            'lesson_fee_30_minutes' => '420.00',
            'lesson_fee_60_minutes' => '840.00',
            'guitar_ensemble_fee' => '270.00',
            'recital_fee' => '10.00',
            'installment_plan_fee' => '20.00',
            'lessons_per_semester' => 15,
        ]);
        Settings::set('registration_semester_id', (string)$this->semesterId);
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

    public function testFamilyPaymentIntentCarriesTheSplitAcrossChildren(): void
    {
        $childA = fx_student('Ann', 'Kid');
        $childB = fx_student('Ben', 'Kid');
        $parent = fx_parent_of($childA);

        $captured = null;
        StripeCheckout::setHttpTransportForTesting(function ($method, $url, $params) use (&$captured) {
            $captured = $params;
            return [200, json_encode(['id' => 'pi_family_1', 'client_secret' => 'pi_family_1_secret_abc'])];
        });

        $intent = StripeCheckout::createFamilyPaymentIntent(
            null,
            [$childA => 10000, $childB => 2500, 999 => 0], // zero entries drop out
            [$childA => 7, $childB => 0],                  // Ben's debt has no term
            $parent,
            'pat@example.org',
            'BCM tuition — Pat Parent'
        );

        $this->assertSame('pi_family_1', $intent['id']);
        $this->assertSame('pi_family_1_secret_abc', $intent['client_secret']);
        $this->assertSame('12500', $captured['amount']);
        $this->assertSame((string)$parent, $captured['metadata[paid_by_user_id]']);
        $this->assertSame([(string)$childA => 10000, (string)$childB => 2500],
            json_decode($captured['metadata[student_amounts]'], true));
        $this->assertSame([(string)$childA => 7, (string)$childB => null],
            json_decode($captured['metadata[student_semesters]'], true));
        $this->assertSame('pat@example.org', $captured['receipt_email']);
    }

    public function testSucceededFamilyIntentCreditsEachChildOnceForTheRightTerm(): void
    {
        $ctx = fx_admin_ctx();
        $childA = fx_student('Ann', 'Kid');
        $childB = fx_student('Ben', 'Kid');
        $semesterId = fx_semester($ctx, 'fall', 2030, '2030-09-01', '2030-12-20');

        $intent = [
            'id' => 'pi_family_2',
            'status' => 'succeeded',
            'amount_received' => 12500,
            'metadata' => [
                'paid_by_user_id' => '1',
                'student_amounts' => json_encode([$childA => 10000, $childB => 2500]),
                'student_semesters' => json_encode([$childA => $semesterId, $childB => null]),
            ],
        ];

        $this->assertSame(2, StripeCheckout::handlePaymentIntentSucceeded($intent));
        // Webhook retry racing the browser's return trip records nothing more.
        $this->assertSame(0, StripeCheckout::handlePaymentIntentSucceeded($intent));

        $this->assertSame(-10000, Billing::balanceForStudentCents($childA));
        $this->assertSame(-2500, Billing::balanceForStudentCents($childB));
        $this->assertSame(-10000, Billing::balanceForStudentSemesterCents($childA, $semesterId));

        // A different payment for the same family is a different intent, so it
        // records normally.
        $second = $intent;
        $second['id'] = 'pi_family_3';
        $this->assertSame(2, StripeCheckout::handlePaymentIntentSucceeded($second));
    }

    public function testApiErrorsSurfaceAsExceptions(): void
    {
        StripeCheckout::setHttpTransportForTesting(function () {
            return [402, json_encode(['error' => ['message' => 'Your card was declined.']])];
        });
        $this->expectExceptionMessage('Your card was declined.');
        StripeCheckout::retrieveCheckoutSession('cs_test_1');
    }

    public function testLeadPaymentIntentCarriesAmountAndLeadMetadata(): void
    {
        $captured = null;
        $capturedUrl = null;
        StripeCheckout::setHttpTransportForTesting(function ($method, $url, $params) use (&$captured, &$capturedUrl) {
            $captured = $params;
            $capturedUrl = $url;
            return [200, json_encode(['id' => 'pi_lead_1', 'client_secret' => 'pi_lead_1_secret_abc'])];
        });

        $intent = StripeCheckout::createLeadPaymentIntent(null, 42, 46500, 'rosa@example.org', 'BCM registration — Rosa Ramos');

        $this->assertStringEndsWith('/payment_intents', $capturedUrl);
        $this->assertSame('pi_lead_1', $intent['id']);
        $this->assertSame('pi_lead_1_secret_abc', $intent['client_secret']);
        $this->assertSame('46500', $captured['amount']);
        $this->assertSame('usd', $captured['currency']);
        $this->assertSame('42', $captured['metadata[lead_id]']);
        $this->assertSame('rosa@example.org', $captured['receipt_email']);
        $this->assertSame('true', $captured['automatic_payment_methods[enabled]']);
    }

    public function testLeadPaymentIntentRefusesAZeroAmount(): void
    {
        StripeCheckout::setHttpTransportForTesting(fn() => [200, '{}']);
        $this->expectException(InvalidArgumentException::class);
        StripeCheckout::createLeadPaymentIntent(null, 42, 0);
    }

    public function testSucceededPaymentIntentUpdatesTheLeadNotTheLedger(): void
    {
        $leadId = $this->makeLead();
        LeadManagement::attachPaymentIntent(null, $leadId, 'pi_lead_2');

        $intent = [
            'id' => 'pi_lead_2',
            'status' => 'succeeded',
            'amount_received' => 46500,
            'metadata' => ['lead_id' => (string)$leadId],
        ];

        $this->assertSame(1, StripeCheckout::handlePaymentIntentSucceeded($intent));
        // Webhook retry racing the browser's return trip records nothing more.
        $this->assertSame(0, StripeCheckout::handlePaymentIntentSucceeded($intent));

        $lead = LeadManagement::findLead($leadId);
        $this->assertSame(46500, (int)$lead['amount_paid_cents']);
        $this->assertSame('pi_lead_2', $lead['stripe_payment_intent_id']);
        $this->assertNotNull($lead['paid_at']);
        // The money stays on the lead — the ledger is untouched until convert.
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());
    }

    public function testUnsucceededOrUnknownPaymentIntentsRecordNothing(): void
    {
        $leadId = $this->makeLead();
        LeadManagement::attachPaymentIntent(null, $leadId, 'pi_lead_3');

        $base = ['id' => 'pi_lead_3', 'amount_received' => 46500, 'metadata' => ['lead_id' => (string)$leadId]];
        $this->assertSame(0, StripeCheckout::handlePaymentIntentSucceeded($base + ['status' => 'requires_payment_method']));
        $this->assertSame(0, StripeCheckout::handlePaymentIntentSucceeded($base + ['status' => 'processing']));
        // No lead in the metadata at all.
        $this->assertSame(0, StripeCheckout::handlePaymentIntentSucceeded([
            'id' => 'pi_lead_3', 'status' => 'succeeded', 'amount_received' => 46500, 'metadata' => [],
        ]));
        // An intent that was never attached to this lead cannot mark it paid.
        $this->assertSame(0, StripeCheckout::handlePaymentIntentSucceeded([
            'id' => 'pi_somebody_else', 'status' => 'succeeded', 'amount_received' => 46500,
            'metadata' => ['lead_id' => (string)$leadId],
        ]));

        $this->assertSame(0, (int)LeadManagement::findLead($leadId)['amount_paid_cents']);
    }

    /** A minimal registration lead to hang payments off. */
    private function makeLead(): int
    {
        return LeadManagement::createLead(null, $this->semesterId, [
            'first_name' => 'Rosa', 'last_name' => 'Ramos', 'email' => 'rosa@example.org',
            'phone' => '718-555-0110', 'address_street_1' => 'x', 'address_city' => 'Bronx',
            'address_state' => 'NY', 'address_zip' => '10454',
        ], [[
            'first_name' => 'Lucia', 'last_name' => 'Ramos', 'class_of' => 2031,
            'instrument' => 'Piano', 'lesson_length_minutes' => 30,
        ]], [], false);
    }

    public function testCompletedLeadSessionUpdatesTheLeadNotTheLedger(): void
    {
        $leadId = $this->makeLead();
        LeadManagement::attachCheckoutSession(null, $leadId, 'cs_lead_2');

        $session = [
            'id' => 'cs_lead_2',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_lead_2',
            'amount_total' => 46500,
            'metadata' => ['lead_id' => (string)$leadId],
        ];

        $this->assertSame(1, StripeCheckout::handleCheckoutSessionCompleted($session));
        // Race replay records nothing more.
        $this->assertSame(0, StripeCheckout::handleCheckoutSessionCompleted($session));

        $lead = LeadManagement::findLead($leadId);
        $this->assertSame(46500, (int)$lead['amount_paid_cents']);
        $this->assertSame('pi_lead_2', $lead['stripe_payment_intent_id']);
        // The money stays on the lead — the ledger is untouched until convert.
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());
    }
}
