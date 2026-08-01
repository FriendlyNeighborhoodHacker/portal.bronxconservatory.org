<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LeadManagementTest extends TestCase
{
    protected function setUp(): void
    {
        test_reset_all();
        // Pin the public-form pricing (other test classes pin their own).
        Settings::set('registration_cost', '35.00');
        Settings::set('recital_fee', '10.00');
        Settings::set('tuition_30', '420.00');
        Settings::set('tuition_60', '840.00');
        Settings::set('tuition_ensemble', '270.00');
        Settings::set('installment_fee', '20.00');
    }

    // ===== Fixtures =====

    private function parent(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Rosa', 'last_name' => 'Ramos',
            'email' => 'rosa@example.org', 'phone' => '718-555-0110',
            'sms_consent' => 1,
            'address_street_1' => '100 Willis Ave', 'address_city' => 'Bronx',
            'address_state' => 'NY', 'address_zip' => '10454',
        ];
    }

    private function student(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Lucia', 'last_name' => 'Ramos', 'age' => 9,
            'instrument' => 'Piano', 'lesson_length_minutes' => 30, 'guitar_ensemble' => 0,
        ];
    }

    private function scheduling(): array
    {
        return [
            'location_preference' => 'Bronx Community College',
            'preferred_days' => ['Saturday'],
            'availability_blocks' => ['9-11', '11-1'],
            'notes' => 'siblings need back-to-back',
        ];
    }

    private function makeLead(?array $students = null, bool $installment = false): int
    {
        return LeadManagement::createLead(
            null, null, $this->parent(),
            $students ?? [$this->student()],
            $this->scheduling(), $installment
        );
    }

    // ===== Pricing =====
    // Seeds: registration 35.00, recital 10.00, tuition_30 420.00,
    // tuition_60 840.00, ensemble 270.00, installment fee 20.00.

    public function testQuoteSingle30MinuteStudentFullPay(): void
    {
        $quote = LeadManagement::priceQuote([$this->student()], false);
        $this->assertSame(46500, $quote['total_cents']); // 35 + 420 + 10
        $this->assertSame(46500, $quote['due_now_cents']);
    }

    public function testQuoteSingle60MinuteStudent(): void
    {
        $quote = LeadManagement::priceQuote([$this->student(['lesson_length_minutes' => 60])], false);
        $this->assertSame(88500, $quote['total_cents']); // 35 + 840 + 10
    }

    public function testQuoteEnsembleAddsTuitionAndSecondRecitalBlock(): void
    {
        $quote = LeadManagement::priceQuote(
            [$this->student(['instrument' => 'Guitar', 'guitar_ensemble' => 1])], false
        );
        // 35 + 420 + 10 + 270 + 10 (second lesson block)
        $this->assertSame(74500, $quote['total_cents']);
    }

    public function testQuoteChargesRegistrationOncePerFamily(): void
    {
        $quote = LeadManagement::priceQuote(
            [$this->student(), $this->student(['first_name' => 'Marco', 'instrument' => 'Violin'])],
            false
        );
        // 35 + 2 × (420 + 10)
        $this->assertSame(89500, $quote['total_cents']);
        $registrationLines = array_filter(
            $quote['lines'],
            fn(array $line) => str_contains($line['label'], 'Registration fee')
        );
        $this->assertCount(1, $registrationLines);
    }

    public function testQuoteInstallmentDueNowIsFeesPlusHalfTuition(): void
    {
        $quote = LeadManagement::priceQuote([$this->student()], true);
        // total = 35 + 420 + 10 + 20 = 485; due now = fees (35+10+20) + 420/2
        $this->assertSame(48500, $quote['total_cents']);
        $this->assertSame(6500 + 21000, $quote['due_now_cents']);
    }

    public function testQuoteInstallmentRoundsOddCentUp(): void
    {
        $original = Settings::get('tuition_30');
        try {
            Settings::set('tuition_30', '420.01');
            $quote = LeadManagement::priceQuote([$this->student()], true);
            // half of 42001 rounds up to 21001
            $this->assertSame(6500 + 21001, $quote['due_now_cents']);
        } finally {
            Settings::set('tuition_30', $original);
        }
    }

    // ===== createLead =====

    public function testCreateLeadPersistsEverythingAndFreezesQuote(): void
    {
        $semesterCtx = fx_admin_ctx();
        UserContext::set($semesterCtx);
        $semesterId = fx_semester($semesterCtx);

        $leadId = LeadManagement::createLead(
            null, $semesterId, $this->parent(),
            [$this->student(), $this->student(['first_name' => 'Marco', 'instrument' => 'Cello/Bass', 'lesson_length_minutes' => 60])],
            $this->scheduling(), true
        );

        $lead = LeadManagement::findLead($leadId);
        $this->assertSame('new', $lead['status']);
        $this->assertSame($semesterId, (int)$lead['semester_id']);
        $this->assertSame('rosa@example.org', $lead['email']);
        $this->assertSame(1, (int)$lead['sms_consent']);
        $this->assertSame(1, (int)$lead['installment_plan']);
        $this->assertNotNull($lead['policies_agreed_at']);
        $this->assertSame(['Saturday'], json_decode($lead['preferred_days'], true));
        $this->assertSame(['9-11', '11-1'], json_decode($lead['availability_blocks'], true));
        // 35 + (420+10) + (840+10) + 20 installment
        $this->assertSame(133500, (int)$lead['amount_quoted_cents']);
        $this->assertSame(0, (int)$lead['amount_paid_cents']);
        $this->assertNotEmpty(json_decode($lead['quote_json'], true));

        $students = LeadManagement::studentsForLead($leadId);
        $this->assertCount(2, $students);
        $this->assertSame('Cello/Bass', $students[1]['instrument']);
        $this->assertSame(60, (int)$students[1]['lesson_length_minutes']);

        // Deliberately NOT live data: no users, profiles, or reservations.
        $this->assertSame(1, (int)pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn()); // just the fx admin
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM student_profiles')->fetchColumn());
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM semester_lesson_reservations')->fetchColumn());
    }

    public function testCreateLeadRejectsBadInput(): void
    {
        $cases = [
            'no students' => [[$this->parent(), []]],
            'bad email' => [[$this->parent(['email' => 'nope']), [$this->student()]]],
            'bad instrument' => [[$this->parent(), [$this->student(['instrument' => 'Theremin'])]]],
            'bad length' => [[$this->parent(), [$this->student(['lesson_length_minutes' => 45])]]],
            'short phone' => [[$this->parent(['phone' => '123']), [$this->student()]]],
        ];
        foreach ($cases as $label => [[$parent, $students]]) {
            try {
                LeadManagement::createLead(null, null, $parent, $students, [], false);
                $this->fail("Expected InvalidArgumentException for: $label");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage(), $label);
            }
        }
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM leads')->fetchColumn());
    }

    // ===== Status + payment =====

    public function testUpdateStatusRequiresAdminAndValidStatus(): void
    {
        $leadId = $this->makeLead();
        $ctx = fx_admin_ctx();

        LeadManagement::updateStatus($ctx, $leadId, 'contacted');
        $this->assertSame('contacted', LeadManagement::findLead($leadId)['status']);

        try {
            LeadManagement::updateStatus($ctx, $leadId, 'bogus');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
        }
        $this->expectException(RuntimeException::class);
        LeadManagement::updateStatus(new UserContext(999, false), $leadId, 'contacted');
    }

    public function testRecordLeadPaymentIsIdempotent(): void
    {
        $leadId = $this->makeLead();
        LeadManagement::attachCheckoutSession(null, $leadId, 'cs_test_123');

        $this->assertTrue(LeadManagement::recordLeadPayment($leadId, 46500, 'cs_test_123', 'pi_1'));
        $lead = LeadManagement::findLead($leadId);
        $this->assertSame(46500, (int)$lead['amount_paid_cents']);
        $this->assertNotNull($lead['paid_at']);

        // Replays (webhook + return-page race) change nothing.
        $this->assertFalse(LeadManagement::recordLeadPayment($leadId, 46500, 'cs_test_123', 'pi_1'));
        $this->assertSame(46500, (int)LeadManagement::findLead($leadId)['amount_paid_cents']);

        // A session id that doesn't match the lead is rejected.
        $this->assertFalse(LeadManagement::recordLeadPayment($leadId, 100, 'cs_other', 'pi_2'));

        $this->assertSame($leadId, (int)LeadManagement::findLeadByCheckoutSession('cs_test_123')['id']);
    }

    // ===== Convert =====

    private function convertSetup(): array
    {
        $ctx = fx_admin_ctx();
        UserContext::set($ctx);
        $teacher = fx_teacher('Marisol', 'Vega');
        [$semesterId, $locationId, , $dayOfWeek] = fx_semester_with_dates($ctx, $teacher, '2030-09-07', 4);
        return [$ctx, $teacher, $semesterId, $locationId, $dayOfWeek];
    }

    public function testConvertCreatesFamilyAndOptionalReservation(): void
    {
        [$ctx, $teacher, $semesterId, $locationId, $dayOfWeek] = $this->convertSetup();

        $leadId = LeadManagement::createLead(
            null, $semesterId, $this->parent(),
            [$this->student(['instrument' => 'Cello/Bass'])],
            $this->scheduling(), false
        );
        LeadManagement::attachCheckoutSession(null, $leadId, 'cs_conv_1');
        LeadManagement::recordLeadPayment($leadId, 46500, 'cs_conv_1', 'pi_9');

        $leadStudents = LeadManagement::studentsForLead($leadId);
        $lsId = (int)$leadStudents[0]['id'];

        $result = LeadManagement::convertLead($ctx, $leadId, [
            'students' => [$lsId => [
                'reservation' => [
                    'teacher_user_id' => $teacher,
                    'location_id' => $locationId,
                    'day_of_week' => $dayOfWeek,
                    'start_time' => '10:00',
                    'duration_minutes' => 30,
                ],
            ]],
            'payment_target_lead_student_id' => $lsId,
        ]);

        $this->assertFalse($result['parent_existed']);
        $parentId = $result['parent_user_id'];
        $studentId = $result['student_user_ids'][$lsId];

        // Family structure exists.
        $this->assertTrue(StudentTeacherManagement::isParentOf($parentId, $studentId));
        $this->assertSame(['Cello'], InstrumentCatalog::namesForStudent($studentId)); // Cello/Bass default
        $parentRow = UserManagement::findById($parentId);
        $this->assertSame('rosa@example.org', $parentRow['email']);
        $this->assertSame('100 Willis Ave', $parentRow['address_street_1']);

        // Reservation is pending_reach_out with ZERO debits.
        $this->assertCount(1, $result['reservation_ids']);
        $reservation = ReservationManagement::findReservation($result['reservation_ids'][0]);
        $this->assertSame('pending_reach_out', $reservation['status']);
        $debits = (int)pdo()->query("SELECT COUNT(*) FROM ledger_entries WHERE accounting_type='debit'")->fetchColumn();
        $this->assertSame(0, $debits);

        // The Stripe payment moved onto the student's ledger.
        $this->assertTrue($result['payment_recorded']);
        $this->assertSame(-46500, Billing::balanceForStudentCents($studentId)); // credit only

        $lead = LeadManagement::findLead($leadId);
        $this->assertSame('converted', $lead['status']);
        $this->assertSame($parentId, (int)$lead['converted_parent_user_id']);
    }

    public function testConvertAdoptsExistingParentEmailAndInstrumentOverride(): void
    {
        [$ctx] = $this->convertSetup();
        $existingId = fx_user('Rosa', 'Ramos', ['email' => 'rosa@example.org']);

        $leadId = $this->makeLead([$this->student(['instrument' => 'Cello/Bass'])]);
        $lsId = (int)LeadManagement::studentsForLead($leadId)[0]['id'];
        $doubleBassId = (int)InstrumentCatalog::findByName('Double Bass')['id'];

        $result = LeadManagement::convertLead($ctx, $leadId, [
            'students' => [$lsId => ['instrument_id' => $doubleBassId]],
        ]);

        $this->assertTrue($result['parent_existed']);
        $this->assertSame($existingId, $result['parent_user_id']);
        $this->assertSame(['Double Bass'], InstrumentCatalog::namesForStudent($result['student_user_ids'][$lsId]));
    }

    public function testConvertIsIdempotentOnReentry(): void
    {
        [$ctx] = $this->convertSetup();
        $leadId = $this->makeLead();
        LeadManagement::attachCheckoutSession(null, $leadId, 'cs_conv_2');
        LeadManagement::recordLeadPayment($leadId, 46500, 'cs_conv_2', null);
        $lsId = (int)LeadManagement::studentsForLead($leadId)[0]['id'];

        $first = LeadManagement::convertLead($ctx, $leadId, ['payment_target_lead_student_id' => $lsId]);
        $usersAfterFirst = (int)pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $ledgerAfterFirst = (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn();

        $second = LeadManagement::convertLead($ctx, $leadId, ['payment_target_lead_student_id' => $lsId]);

        $this->assertSame($usersAfterFirst, (int)pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame($ledgerAfterFirst, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());
        $this->assertSame($first['student_user_ids'], $second['student_user_ids']);
        $this->assertFalse($second['payment_recorded']);
        $this->assertNotNull($second['payment_notice']);
        // Payment never doubled.
        $studentId = $first['student_user_ids'][$lsId];
        $this->assertSame(-46500, Billing::balanceForStudentCents($studentId));
    }

    public function testConvertConflictingSlotThrowsAndLeadStaysUnconverted(): void
    {
        [$ctx, $teacher, $semesterId, $locationId, $dayOfWeek] = $this->convertSetup();

        // Occupy the slot first.
        $blocker = fx_student('Blocka', 'Bost');
        ReservationManagement::createReservation($ctx, [
            'semester_id' => $semesterId, 'teacher_user_id' => $teacher, 'location_id' => $locationId,
            'student_user_id' => $blocker, 'day_of_week' => $dayOfWeek, 'start_time' => '10:00',
            'duration_minutes' => 30, 'status' => 'confirmed',
        ], ['post_charges' => false]);

        $leadId = LeadManagement::createLead(null, $semesterId, $this->parent(), [$this->student()], [], false);
        $lsId = (int)LeadManagement::studentsForLead($leadId)[0]['id'];

        try {
            LeadManagement::convertLead($ctx, $leadId, [
                'students' => [$lsId => [
                    'reservation' => [
                        'teacher_user_id' => $teacher, 'location_id' => $locationId,
                        'day_of_week' => $dayOfWeek, 'start_time' => '10:00', 'duration_minutes' => 30,
                    ],
                ]],
            ]);
            $this->fail('Expected a conflict exception');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertNotSame('converted', LeadManagement::findLead($leadId)['status']);
    }
}
