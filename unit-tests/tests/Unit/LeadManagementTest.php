<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LeadManagementTest extends TestCase
{
    private int $semesterId;

    protected function setUp(): void
    {
        test_reset_all();
        // Create a semester with pinned pricing for all tests.
        $ctx = fx_admin_ctx();
        $this->semesterId = SemesterManagement::createSemester($ctx, 'fall', 2025, '2025-09-01', '2025-12-31', [
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
            'first_name' => 'Lucia', 'last_name' => 'Ramos', 'class_of' => 2031,
            'instrument' => 'Piano', 'lesson_length_minutes' => 30, 'guitar_ensemble' => 0,
            'shirt_size' => 'Youth M',
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
            null, $this->semesterId, $this->parent(),
            $students ?? [$this->student()],
            $this->scheduling(), $installment
        );
    }

    // ===== Residency and the non-resident / online rate =====

    public function testBronxResidentByCityOrZip(): void
    {
        $this->assertTrue(LeadManagement::isBronxResident('Bronx', '99999'));
        $this->assertTrue(LeadManagement::isBronxResident('  bRoNx ', ''));
        $this->assertTrue(LeadManagement::isBronxResident('The Bronx', ''));
        $this->assertTrue(LeadManagement::isBronxResident('New York', '10451'));
        $this->assertTrue(LeadManagement::isBronxResident('New York', '10475-1234'));
        $this->assertTrue(LeadManagement::isBronxResident('', '10463'));

        $this->assertFalse(LeadManagement::isBronxResident('New York', '10450'));
        $this->assertFalse(LeadManagement::isBronxResident('Yonkers', '10701'));
        $this->assertFalse(LeadManagement::isBronxResident('Mount Vernon', '10476'));
        $this->assertFalse(LeadManagement::isBronxResident('Bronxville', '10708'));
        $this->assertFalse(LeadManagement::isBronxResident('', ''));
    }

    public function testNonresidentPricingAppliesOutsideTheBronxAndOnline(): void
    {
        $bronx = $this->parent();
        $yonkers = $this->parent(['address_city' => 'Yonkers', 'address_zip' => '10701']);
        $online = ['location_preference' => LeadManagement::ONLINE_LOCATION_PREFERENCE];

        $this->assertFalse(LeadManagement::usesNonresidentPricing($bronx, $this->scheduling()));
        $this->assertTrue(LeadManagement::usesNonresidentPricing($yonkers, $this->scheduling()));
        $this->assertTrue(LeadManagement::usesNonresidentPricing($bronx, $online), 'online is the non-resident rate');
    }

    private function twoRateSemester(): int
    {
        return SemesterManagement::createSemester(fx_admin_ctx(), 'spring', 2026, '2026-02-01', '2026-06-30', [
            'registration_fee' => '35.00',
            'lesson_fee_30_minutes' => '420.00', 'lesson_fee_60_minutes' => '840.00', 'guitar_ensemble_fee' => '270.00',
            'lesson_fee_30_minutes_nonresident' => '450.00', 'lesson_fee_60_minutes_nonresident' => '900.00',
            'guitar_ensemble_fee_nonresident' => '300.00',
            'recital_fee' => '10.00', 'installment_plan_fee' => '20.00', 'lessons_per_semester' => 15,
        ]);
    }

    public function testQuoteAtTheNonresidentRate(): void
    {
        $semesterId = $this->twoRateSemester();
        $students = [$this->student(['guitar_ensemble' => 1])];

        $resident = LeadManagement::priceQuote($semesterId, $students, false);
        $this->assertSame(74500, $resident['total_cents']); // 35 + 420 + 10 + 270 + 10

        $other = LeadManagement::priceQuote($semesterId, $students, false, true);
        $this->assertSame(80500, $other['total_cents']); // 35 + 450 + 10 + 300 + 10
        $this->assertStringContainsString('non-resident / online rate', $other['lines'][1]['label']);

        $sixty = LeadManagement::priceQuote($semesterId, [$this->student(['lesson_length_minutes' => 60])], false, true);
        $this->assertSame(94500, $sixty['total_cents']); // 35 + 900 + 10
    }

    public function testNonresidentQuoteFallsBackWhenTheSemesterHasOneRate(): void
    {
        // setUp's semester has no non-resident fees: nobody is quoted $0.
        $quote = LeadManagement::priceQuote($this->semesterId, [$this->student()], false, true);
        $this->assertSame(46500, $quote['total_cents']);
        $this->assertStringNotContainsString('non-resident', $quote['lines'][1]['label']);
    }

    public function testCreateLeadQuotesByAddressAndOnlineChoice(): void
    {
        $semesterId = $this->twoRateSemester();
        $make = fn(array $parent, array $scheduling) => LeadManagement::findLead(
            LeadManagement::createLead(null, $semesterId, $parent, [$this->student()], $scheduling, false)
        );

        $bronx = $make($this->parent(), $this->scheduling());
        $this->assertSame(0, (int)$bronx['nonresident_pricing']);
        $this->assertSame(46500, (int)$bronx['amount_quoted_cents']);

        $yonkers = $make(
            $this->parent(['email' => 'y@example.org', 'address_city' => 'Yonkers', 'address_zip' => '10701']),
            $this->scheduling()
        );
        $this->assertSame(1, (int)$yonkers['nonresident_pricing']);
        $this->assertSame(49500, (int)$yonkers['amount_quoted_cents']); // 35 + 450 + 10

        // A Bronx family studying online: the online rate, and no day or time kept.
        $online = $make(
            $this->parent(['email' => 'o@example.org']),
            ['location_preference' => LeadManagement::ONLINE_LOCATION_PREFERENCE]
                + $this->scheduling()
        );
        $this->assertSame(1, (int)$online['nonresident_pricing']);
        $this->assertSame(49500, (int)$online['amount_quoted_cents']);
        $this->assertSame('Online Lessons', $online['location_preference']);
        $this->assertSame('[]', $online['preferred_days']);
        $this->assertSame('[]', $online['availability_blocks']);
    }

    // ===== Registration fee: one per family per semester =====

    public function testSecondRegistrationInTheSameSemesterWaivesTheRegistrationFee(): void
    {
        // First child: registered and paid, not yet converted by staff.
        $first = $this->makeLead();
        $this->assertSame(46500, (int)LeadManagement::findLead($first)['amount_quoted_cents']); // 35 + 420 + 10
        $this->assertFalse(LeadManagement::registrationFeeAlreadyCovered('rosa@example.org', $this->semesterId));
        LeadManagement::attachCheckoutSession(null, $first, 'cs_fee_1');
        LeadManagement::recordLeadPayment($first, 46500, 'cs_fee_1', 'pi_fee_1');

        $this->assertTrue(LeadManagement::registrationFeeAlreadyCovered(' Rosa@Example.org ', $this->semesterId));
        $this->assertFalse(LeadManagement::registrationFeeAlreadyCovered('rosa@example.org', $this->semesterId, $first), 'a lead never waives itself');
        $this->assertFalse(LeadManagement::registrationFeeAlreadyCovered('someone.else@example.org', $this->semesterId));

        // Second child, same email, same semester: no registration fee.
        $second = LeadManagement::createLead(
            null, $this->semesterId, $this->parent(), [$this->student(['first_name' => 'Mateo'])], $this->scheduling(), false
        );
        $lead = LeadManagement::findLead($second);
        $this->assertSame(43000, (int)$lead['amount_quoted_cents']); // 420 + 10
        $this->assertStringContainsString('waived', json_decode($lead['quote_json'], true)[0]['label']);

        // A different semester is a new fee.
        $other = SemesterManagement::createSemester(fx_admin_ctx(), 'spring', 2026, '2026-02-01', '2026-06-30', [
            'registration_fee' => '35.00', 'lesson_fee_30_minutes' => '420.00', 'lesson_fee_60_minutes' => '840.00',
            'guitar_ensemble_fee' => '270.00', 'recital_fee' => '10.00', 'installment_plan_fee' => '20.00',
            'lessons_per_semester' => 15,
        ]);
        $this->assertFalse(LeadManagement::registrationFeeAlreadyCovered('rosa@example.org', $other));
    }

    public function testRegistrationFeeIsWaivedOnceTheFamilyAccountHasBeenCharged(): void
    {
        [$ctx, $teacher, $semesterId, $locationId, $dayOfWeek] = $this->convertSetup();
        $leadId = LeadManagement::createLead(null, $semesterId, $this->parent(), [$this->student()], $this->scheduling(), false);
        $quoted = (int)LeadManagement::findLead($leadId)['amount_quoted_cents'];
        LeadManagement::attachCheckoutSession(null, $leadId, 'cs_fee_2');
        LeadManagement::recordLeadPayment($leadId, $quoted, 'cs_fee_2', 'pi_fee_2');
        $this->convertPlacingAll($ctx, $leadId, $teacher, $locationId, $dayOfWeek);

        // The parent now has an account with a charged child: the next
        // registration from that email is quoted without the fee…
        $this->assertTrue(LeadManagement::registrationFeeAlreadyCovered('rosa@example.org', $semesterId, $leadId));
        $second = LeadManagement::createLead(
            null, $semesterId, $this->parent(), [$this->student(['first_name' => 'Mateo'])], $this->scheduling(), false
        );
        $secondQuoted = (int)LeadManagement::findLead($second)['amount_quoted_cents'];
        $this->assertSame($quoted - SemesterManagement::registrationFeeCents(SemesterManagement::find($semesterId)), $secondQuoted);

        // …and paying that quote covers the second child exactly at conversion.
        LeadManagement::attachCheckoutSession(null, $second, 'cs_fee_3');
        LeadManagement::recordLeadPayment($second, $secondQuoted, 'cs_fee_3', 'pi_fee_3');
        $options = ['students' => []];
        foreach (LeadManagement::studentsForLead($second) as $leadStudent) {
            $options['students'][(int)$leadStudent['id']] = ['reservation' => [
                'teacher_user_id' => $teacher, 'location_id' => $locationId, 'day_of_week' => $dayOfWeek,
                'start_time' => '12:00', 'duration_minutes' => 30,
            ]];
            $options['payment_target_lead_student_id'] = (int)$leadStudent['id'];
        }
        $result = LeadManagement::convertLead($ctx, $second, $options);
        $this->assertSame(0, Billing::balanceForStudentCents(array_values($result['student_user_ids'])[0]));
    }

    // ===== Back from checkout: the same lead is rewritten =====

    public function testResubmittingRewritesTheSameLead(): void
    {
        $leadId = $this->makeLead();
        $this->assertSame(46500, (int)LeadManagement::findLead($leadId)['amount_quoted_cents']);

        $again = LeadManagement::createLead(
            null, $this->semesterId, $this->parent(['phone' => '718-555-0199']),
            [$this->student(['lesson_length_minutes' => 60]), $this->student(['first_name' => 'Mateo'])],
            $this->scheduling(), false, $leadId
        );
        $this->assertSame($leadId, $again);

        $lead = LeadManagement::findLead($leadId);
        $this->assertSame('718-555-0199', $lead['phone']);
        $this->assertSame(131500, (int)$lead['amount_quoted_cents']); // 35 + 840 + 10 + 420 + 10
        $this->assertSame('new', $lead['status']);
        $names = array_column(LeadManagement::studentsForLead($leadId), 'first_name');
        sort($names);
        $this->assertSame(['Lucia', 'Mateo'], $names);
        $this->assertSame(1, (int)pdo()->query('SELECT COUNT(*) FROM leads')->fetchColumn());
    }

    public function testAPaidLeadCannotBeRewritten(): void
    {
        $leadId = $this->makeLead();
        pdo()->prepare('UPDATE leads SET amount_paid_cents = 46500 WHERE id = ?')->execute([$leadId]);
        $this->assertFalse(LeadManagement::canFamilyStillEdit(LeadManagement::findLead($leadId)));

        $this->expectException(InvalidArgumentException::class);
        LeadManagement::createLead(
            null, $this->semesterId, $this->parent(), [$this->student()], $this->scheduling(), false, $leadId
        );
    }

    // ===== Pricing =====
    // Seeds: registration 35.00, recital 10.00, tuition_30 420.00,
    // tuition_60 840.00, ensemble 270.00, installment fee 20.00.

    public function testQuoteSingle30MinuteStudentFullPay(): void
    {
        $quote = LeadManagement::priceQuote($this->semesterId, [$this->student()], false);
        $this->assertSame(46500, $quote['total_cents']); // 35 + 420 + 10
        $this->assertSame(46500, $quote['due_now_cents']);
    }

    public function testQuoteSingle60MinuteStudent(): void
    {
        $quote = LeadManagement::priceQuote($this->semesterId, [$this->student(['lesson_length_minutes' => 60])], false);
        $this->assertSame(88500, $quote['total_cents']); // 35 + 840 + 10
    }

    public function testQuoteEnsembleAddsTuitionAndSecondRecitalBlock(): void
    {
        $quote = LeadManagement::priceQuote(
            $this->semesterId,
            [$this->student(['instrument' => 'Guitar', 'guitar_ensemble' => 1])], false
        );
        // 35 + 420 + 10 + 270 + 10 (second lesson block)
        $this->assertSame(74500, $quote['total_cents']);
    }

    public function testQuoteChargesRegistrationOncePerFamily(): void
    {
        $quote = LeadManagement::priceQuote(
            $this->semesterId,
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
        $quote = LeadManagement::priceQuote($this->semesterId, [$this->student()], true);
        // total = 35 + 420 + 10 + 20 = 485; due now = fees (35+10+20) + 420/2
        $this->assertSame(48500, $quote['total_cents']);
        $this->assertSame(6500 + 21000, $quote['due_now_cents']);
    }

    public function testQuoteInstallmentRoundsOddCentUp(): void
    {
        $ctx = fx_admin_ctx();
        $altSemesterId = SemesterManagement::createSemester($ctx, 'spring', 2026, '2026-01-01', '2026-05-31', [
            'registration_fee' => '35.00',
            'lesson_fee_30_minutes' => '420.01',
            'lesson_fee_60_minutes' => '840.02',
            'guitar_ensemble_fee' => '270.00',
            'recital_fee' => '10.00',
            'installment_plan_fee' => '20.00',
            'lessons_per_semester' => 15,
        ]);
        $quote = LeadManagement::priceQuote($altSemesterId, [$this->student()], true);
        // half of 42001 rounds up to 21001
        $this->assertSame(6500 + 21001, $quote['due_now_cents']);
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
        $this->assertSame(2031, (int)$students[0]['class_of']);

        // Deliberately NOT live data: no users, profiles, or reservations beyond the admins in setUp.
        $this->assertLessThanOrEqual(2, (int)pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn()); // just admin(s)
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
            'class of is an age' => [[$this->parent(), [$this->student(['class_of' => 9])]]],
            'shirt size off the list' => [[$this->parent(), [$this->student(['shirt_size' => 'Huge'])]]],
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

        // "Class of" carries through to the real student profile.
        $st = pdo()->prepare('SELECT class_of FROM student_profiles WHERE user_id = ?');
        $st->execute([$studentId]);
        $this->assertSame(2031, (int)$st->fetchColumn());
        // …and the shirt size onto the user record.
        $this->assertSame('Youth M', UserManagement::findById($studentId)['shirt_size']);
        $parentRow = UserManagement::findById($parentId);
        $this->assertSame('rosa@example.org', $parentRow['email']);
        $this->assertSame('100 Willis Ave', $parentRow['address_street_1']);

        // The family paid in full, so the slot is CONFIRMED, the semester's
        // charges are posted, and the payment is set against them.
        $this->assertCount(1, $result['reservation_ids']);
        $this->assertTrue($result['reservations_confirmed']);
        $reservation = ReservationManagement::findReservation($result['reservation_ids'][0]);
        $this->assertSame('confirmed', $reservation['status']);
        $debits = (int)pdo()->query("SELECT COALESCE(SUM(amount_cents),0) FROM ledger_entries WHERE accounting_type='debit'")->fetchColumn();
        $this->assertSame((int)LeadManagement::findLead($leadId)['amount_quoted_cents'], $debits, 'charges match the quote');

        $this->assertTrue($result['payment_recorded']);
        $this->assertSame($debits - 46500, Billing::balanceForStudentCents($studentId));

        $lead = LeadManagement::findLead($leadId);
        $this->assertSame('converted', $lead['status']);
        $this->assertSame($parentId, (int)$lead['converted_parent_user_id']);
    }

    /** Convert a lead placing every student in consecutive half-hour slots; returns the result. */
    private function convertPlacingAll(UserContext $ctx, int $leadId, int $teacher, int $locationId, int $dayOfWeek): array
    {
        $options = ['students' => []];
        foreach (LeadManagement::studentsForLead($leadId) as $i => $leadStudent) {
            $options['students'][(int)$leadStudent['id']] = ['reservation' => [
                'teacher_user_id' => $teacher, 'location_id' => $locationId, 'day_of_week' => $dayOfWeek,
                'start_time' => sprintf('%02d:00', 10 + $i), 'duration_minutes' => 30,
            ]];
            $options['payment_target_lead_student_id'] ??= (int)$leadStudent['id'];
        }
        return LeadManagement::convertLead($ctx, $leadId, $options);
    }

    public function testConvertingAnUnpaidLeadLeavesTheSlotPendingWithNoCharges(): void
    {
        [$ctx, $teacher, $semesterId, $locationId, $dayOfWeek] = $this->convertSetup();
        $leadId = LeadManagement::createLead(null, $semesterId, $this->parent(), [$this->student()], $this->scheduling(), false);

        $result = $this->convertPlacingAll($ctx, $leadId, $teacher, $locationId, $dayOfWeek);

        $this->assertFalse($result['reservations_confirmed']);
        $this->assertSame('pending_confirmation', ReservationManagement::findReservation($result['reservation_ids'][0])['status']);
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());
    }

    public function testConvertingAnInstallmentLeadConfirmsAndLeavesTheRestOwing(): void
    {
        [$ctx, $teacher, $semesterId, $locationId, $dayOfWeek] = $this->convertSetup();
        $leadId = LeadManagement::createLead(null, $semesterId, $this->parent(), [$this->student()], $this->scheduling(), true);
        $lead = LeadManagement::findLead($leadId);
        $quoted = (int)$lead['amount_quoted_cents'];
        $dueNow = (int)$lead['amount_due_now_cents'];
        $this->assertLessThan($quoted, $dueNow);
        LeadManagement::attachCheckoutSession(null, $leadId, 'cs_inst_1');
        LeadManagement::recordLeadPayment($leadId, $dueNow, 'cs_inst_1', 'pi_inst_1');

        $result = $this->convertPlacingAll($ctx, $leadId, $teacher, $locationId, $dayOfWeek);
        $studentId = array_values($result['student_user_ids'])[0];

        $this->assertSame('confirmed', ReservationManagement::findReservation($result['reservation_ids'][0])['status']);
        // Charged what was quoted (installment fee included), credited what was paid.
        $this->assertSame($quoted - $dueNow, Billing::balanceForStudentCents($studentId));
        $summary = Billing::balanceSummaryForStudent($studentId);
        $this->assertSame($quoted, $summary['semesters'][0]['charged_cents']);
        $this->assertSame($dueNow, $summary['semesters'][0]['paid_cents']);
    }

    public function testConvertingAPaidLeadSplitsThePaymentAcrossSiblings(): void
    {
        [$ctx, $teacher, $semesterId, $locationId, $dayOfWeek] = $this->convertSetup();
        $leadId = LeadManagement::createLead(
            null, $semesterId, $this->parent(),
            [$this->student(), $this->student(['first_name' => 'Mateo'])], $this->scheduling(), false
        );
        $quoted = (int)LeadManagement::findLead($leadId)['amount_quoted_cents'];
        LeadManagement::attachCheckoutSession(null, $leadId, 'cs_sib_1');
        LeadManagement::recordLeadPayment($leadId, $quoted, 'cs_sib_1', 'pi_sib_1');

        $result = $this->convertPlacingAll($ctx, $leadId, $teacher, $locationId, $dayOfWeek);
        [$first, $second] = array_values($result['student_user_ids']);

        // Both confirmed, and every cent of the payment is on a ledger once.
        foreach ($result['reservation_ids'] as $reservationId) {
            $this->assertSame('confirmed', ReservationManagement::findReservation($reservationId)['status']);
        }
        $credits = (int)pdo()->query("SELECT SUM(amount_cents) FROM ledger_entries WHERE accounting_type='credit'")->fetchColumn();
        $this->assertSame($quoted, $credits);
        // One registration fee for the family, as quoted — so the payment
        // covers both children exactly.
        $this->assertSame(0, Billing::balanceForStudentCents($first));
        $this->assertSame(0, Billing::balanceForStudentCents($second));
        $fees = (int)pdo()->query("SELECT COUNT(*) FROM ledger_entries WHERE entry_type='registration' AND accounting_type='debit'")->fetchColumn();
        $this->assertSame(1, $fees);
    }

    public function testPaidSiblingsConvertedWithoutTimesHoldTheMoneyAsParentCredit(): void
    {
        [$ctx, $teacher, $semesterId, $locationId, $dayOfWeek] = $this->convertSetup();
        $leadId = LeadManagement::createLead(
            null, $semesterId, $this->parent(),
            [$this->student(), $this->student(['first_name' => 'Mateo'])], $this->scheduling(), false
        );
        $quoted = (int)LeadManagement::findLead($leadId)['amount_quoted_cents'];
        LeadManagement::attachCheckoutSession(null, $leadId, 'cs_pc_1');
        LeadManagement::recordLeadPayment($leadId, $quoted, 'cs_pc_1', 'pi_pc_1');

        // No times chosen: nobody is charged, so nothing is used yet.
        $result = LeadManagement::convertLead($ctx, $leadId, []);
        [$first, $second] = array_values($result['student_user_ids']);
        $parent = $result['parent_user_id'];

        $this->assertSame($quoted, $result['parent_credit_cents']);
        $this->assertSame($quoted, Billing::parentCreditBalanceCents($parent));
        $this->assertSame(0, Billing::balanceForStudentCents($first));
        $this->assertSame(0, Billing::balanceForStudentCents($second));
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());

        // Converting again never records the payment a second time.
        $again = LeadManagement::convertLead($ctx, $leadId, []);
        $this->assertFalse($again['payment_recorded']);
        $this->assertSame($quoted, Billing::parentCreditBalanceCents($parent));

        // Confirming each child later draws on the parent credit, leaving
        // both at zero and the credit used up (one registration fee between them).
        foreach ([[$first, '10:00'], [$second, '11:00']] as [$studentId, $time]) {
            ReservationManagement::createReservation($ctx, [
                'semester_id' => $semesterId, 'teacher_user_id' => $teacher, 'location_id' => $locationId,
                'student_user_id' => $studentId, 'day_of_week' => $dayOfWeek, 'start_time' => $time,
                'status' => 'confirmed',
            ]);
            $this->assertSame(0, Billing::balanceForStudentCents($studentId));
        }
        $this->assertSame(0, Billing::parentCreditBalanceCents($parent));
    }

    public function testAPaidOnlyChildConvertedWithoutATimeKeepsTheCreditThemself(): void
    {
        [$ctx, , $semesterId] = $this->convertSetup();
        $leadId = LeadManagement::createLead(null, $semesterId, $this->parent(), [$this->student()], $this->scheduling(), false);
        $quoted = (int)LeadManagement::findLead($leadId)['amount_quoted_cents'];
        LeadManagement::attachCheckoutSession(null, $leadId, 'cs_pc_2');
        LeadManagement::recordLeadPayment($leadId, $quoted, 'cs_pc_2', 'pi_pc_2');

        $result = LeadManagement::convertLead($ctx, $leadId, []);

        $this->assertSame(0, $result['parent_credit_cents']);
        $this->assertSame(0, Billing::parentCreditBalanceCents($result['parent_user_id']));
        $this->assertSame(-$quoted, Billing::balanceForStudentCents(array_values($result['student_user_ids'])[0]));
    }

    public function testConvertStoresAnApproximateBirthdateFromClassOfWhenNoneIsGiven(): void
    {
        [$ctx, , $semesterId] = $this->convertSetup();
        $leadId = LeadManagement::createLead(
            null, $semesterId, $this->parent(),
            [
                $this->student(['first_name' => 'Blank', 'class_of' => '2032']),
                $this->student(['first_name' => 'Known', 'class_of' => '2032']),
            ],
            $this->scheduling(), false
        );
        $leadStudents = LeadManagement::studentsForLead($leadId);
        $blank = (int)$leadStudents[0]['id'];
        $known = (int)$leadStudents[1]['id'];

        $result = LeadManagement::convertLead($ctx, $leadId, [
            'students' => [$known => ['date_of_birth' => '2014-10-09']],
        ]);

        $profile = function (int $userId): array {
            return pdo()->query('SELECT date_of_birth, date_of_birth_is_approximate, class_of FROM student_profiles WHERE user_id = ' . $userId)->fetch();
        };
        // Left blank: March 1 of the year they turn 18 (the class year), flagged.
        $this->assertSame('2014-03-01', StudentTeacherManagement::approximateBirthdateForClassOf(2032));
        $row = $profile((int)$result['student_user_ids'][$blank]);
        $this->assertSame(['2014-03-01', 1, 2032], [$row['date_of_birth'], (int)$row['date_of_birth_is_approximate'], (int)$row['class_of']]);
        // Entered: stored as given, not flagged.
        $row = $profile((int)$result['student_user_ids'][$known]);
        $this->assertSame(['2014-10-09', 0], [$row['date_of_birth'], (int)$row['date_of_birth_is_approximate']]);

        // Saving the real birthday later replaces the stand-in and clears the flag;
        // a save that carries no date leaves both alone.
        $userId = (int)$result['student_user_ids'][$blank];
        StudentTeacherManagement::ensureStudentProfile($ctx, $userId, ['grade' => '7']);
        $this->assertSame(1, (int)$profile($userId)['date_of_birth_is_approximate']);
        StudentTeacherManagement::ensureStudentProfile($ctx, $userId, ['date_of_birth' => '2014-06-20']);
        $row = $profile($userId);
        $this->assertSame(['2014-06-20', 0], [$row['date_of_birth'], (int)$row['date_of_birth_is_approximate']]);
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

    // ===== Queue: filters and paging =====

    public function testListLeadsFiltersByStatusesAndPages(): void
    {
        $ctx = fx_admin_ctx();
        $ids = [];
        foreach (LeadManagement::STATUSES as $status) {
            $id = $this->makeLead();
            LeadManagement::updateStatus($ctx, $id, $status);
            $ids[$status] = $id;
        }

        $active = LeadManagement::listLeads(['statuses' => LeadManagement::ACTIVE_STATUSES], 25, 0);
        $this->assertSame(3, LeadManagement::countLeads(['statuses' => LeadManagement::ACTIVE_STATUSES]));
        $activeIds = array_map('intval', array_column($active, 'id'));
        sort($activeIds);
        $expected = [$ids['new'], $ids['contacted'], $ids['scheduled']];
        sort($expected);
        $this->assertSame($expected, $activeIds);
        $this->assertNotContains($ids['converted'], $activeIds);
        $this->assertNotContains($ids['declined'], $activeIds);

        // Newest first, and the window actually moves.
        $this->assertSame(5, LeadManagement::countLeads());
        $firstPage = LeadManagement::listLeads([], 2, 0);
        $secondPage = LeadManagement::listLeads([], 2, 2);
        $this->assertCount(2, $firstPage);
        $this->assertCount(2, $secondPage);
        $this->assertSame((int)$ids['declined'], (int)$firstPage[0]['id']); // created last
        $this->assertNotSame((int)$firstPage[0]['id'], (int)$secondPage[0]['id']);
    }

    public function testListLeadsRejectsUnknownFilterValues(): void
    {
        $this->makeLead();
        // A hand-edited query string can only widen the view, never error.
        $this->assertSame(1, LeadManagement::countLeads(['statuses' => ['nonsense'], 'source' => 'nope']));
    }

    public function testListLeadsAttachesStudentsForEveryRow(): void
    {
        $twoStudents = $this->makeLead([
            $this->student(['first_name' => 'Lucia']),
            $this->student(['first_name' => 'Marco', 'instrument' => 'Violin']),
        ]);
        $oneStudent = $this->makeLead([$this->student(['first_name' => 'Sol'])]);

        $byId = [];
        foreach (LeadManagement::listLeads() as $lead) {
            $byId[(int)$lead['id']] = $lead['students'];
        }
        $this->assertCount(2, $byId[$twoStudents]);
        $this->assertCount(1, $byId[$oneStudent]);
        $this->assertSame('Sol', $byId[$oneStudent][0]['first_name']);
        $this->assertSame(['Lucia', 'Marco'], array_column($byId[$twoStudents], 'first_name'));
    }

    public function testStatusCountsCoversEveryStatusAndNarrowsBySource(): void
    {
        $ctx = fx_admin_ctx();
        $registration = $this->makeLead();
        LeadManagement::updateStatus($ctx, $registration, 'contacted');
        $this->makeInquiryLead();

        $all = LeadManagement::statusCounts();
        $this->assertSame(array_keys(array_fill_keys(LeadManagement::STATUSES, 0)), array_keys($all));
        $this->assertSame(1, $all['new']);
        $this->assertSame(1, $all['contacted']);
        $this->assertSame(0, $all['declined']);

        $this->assertSame(0, LeadManagement::statusCounts(['source' => 'inquiry'])['contacted']);
        $this->assertSame(1, LeadManagement::statusCounts(['source' => 'inquiry'])['new']);
        $this->assertSame(1, LeadManagement::statusCounts(['source' => 'registration'])['contacted']);
    }

    // ===== Notes =====

    public function testAddLeadNoteRecordsAuthorAndAppends(): void
    {
        $ctx = fx_admin_ctx();
        $leadId = $this->makeLead();

        LeadManagement::addLeadNote($ctx, $leadId, 'Called, left a voicemail.');
        LeadManagement::addLeadNote($ctx, $leadId, 'Called back — wants Saturdays.');

        $notes = LeadManagement::notesForLead($leadId);
        $this->assertCount(2, $notes, 'notes append, they never replace each other');
        $this->assertSame('Called, left a voicemail.', $notes[0]['body']);
        $this->assertSame('Called back — wants Saturdays.', $notes[1]['body']);
        $this->assertSame($ctx->id, (int)$notes[0]['created_by_user_id']);
        $this->assertNotSame('', trim((string)$notes[0]['author_first_name']));
    }

    public function testAddLeadNoteChangesStatusInTheSameSave(): void
    {
        $ctx = fx_admin_ctx();
        $leadId = $this->makeLead();

        LeadManagement::addLeadNote($ctx, $leadId, 'Booked them in.', 'scheduled');

        $this->assertSame('scheduled', LeadManagement::findLead($leadId)['status']);
        $notes = LeadManagement::notesForLead($leadId);
        $this->assertSame('scheduled', $notes[0]['status_after']);
    }

    public function testAddLeadNoteLeavesStatusAfterNullWhenNothingChanged(): void
    {
        $ctx = fx_admin_ctx();
        $leadId = $this->makeLead();

        // Resubmitting the current status is not a change worth recording.
        LeadManagement::addLeadNote($ctx, $leadId, 'Still thinking about it.', 'new');

        $this->assertNull(LeadManagement::notesForLead($leadId)[0]['status_after']);
    }

    public function testAddLeadNoteAllowsStatusOnlyEntry(): void
    {
        $ctx = fx_admin_ctx();
        $leadId = $this->makeLead();

        LeadManagement::addLeadNote($ctx, $leadId, '   ', 'declined');

        $notes = LeadManagement::notesForLead($leadId);
        $this->assertCount(1, $notes);
        $this->assertSame('', $notes[0]['body']);
        $this->assertSame('declined', $notes[0]['status_after']);
    }

    public function testAddLeadNoteRejectsEmptyNoteWithNoStatusChange(): void
    {
        $ctx = fx_admin_ctx();
        $leadId = $this->makeLead();

        $this->expectException(InvalidArgumentException::class);
        LeadManagement::addLeadNote($ctx, $leadId, '  ', 'new');
    }

    public function testAddLeadNoteRequiresAdmin(): void
    {
        $leadId = $this->makeLead();
        $this->expectException(RuntimeException::class);
        LeadManagement::addLeadNote(new UserContext(fx_user('Nel', 'Nobody'), false), $leadId, 'sneaky');
    }

    public function testNotesForLeadKeepsAuthorlessMigratedNotes(): void
    {
        $leadId = $this->makeLead();
        // What the migration writes for an old leads.admin_notes blob.
        pdo()->prepare('INSERT INTO lead_notes (lead_id, created_by_user_id, body) VALUES (?, NULL, ?)')
            ->execute([$leadId, 'Imported from the old notes field.']);

        $notes = LeadManagement::notesForLead($leadId);
        $this->assertCount(1, $notes);
        $this->assertNull($notes[0]['created_by_user_id']);
        $this->assertNull($notes[0]['author_first_name']);
    }

    // ===== Information-request leads =====

    private function inquiryDraft(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Maria', 'last_name' => 'Delgado',
            'email' => 'Maria.Delgado@Example.com', 'phone' => '718-555-0142',
            'newsletter_opt_in' => 1, 'sms_consent' => 0,
            'address_country' => 'United States', 'address_street_1' => '1234 Grand Concourse',
            'address_city' => 'Bronx', 'address_state' => 'NY', 'address_zip' => '10456',
        ];
    }

    private function inquiryStudent(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Luis', 'last_name' => 'Delgado', 'age' => 9,
            'enrollment_status' => 'new',
            'instruments_of_interest' => ['Piano', 'Violin'],
            'instruments_other' => '',
        ];
    }

    private function makeInquiryLead(): int
    {
        return LeadManagement::createInquiryLead(null, $this->inquiryDraft(), $this->inquiryStudent());
    }

    public function testCreateInquiryLeadStoresWhatTheFormAsked(): void
    {
        $leadId = $this->makeInquiryLead();
        $lead = LeadManagement::findLead($leadId);

        $this->assertSame('inquiry', $lead['source']);
        $this->assertNull($lead['semester_id']);
        $this->assertSame(0, (int)$lead['amount_quoted_cents']);
        $this->assertSame(0, (int)$lead['amount_due_now_cents']);
        $this->assertSame('maria.delgado@example.com', $lead['email'], 'email is normalised');
        $this->assertSame(1, (int)$lead['newsletter_opt_in']);
        $this->assertSame('United States', $lead['address_country']);

        $student = LeadManagement::studentsForLead($leadId)[0];
        $this->assertSame(9, (int)$student['age']);
        $this->assertSame('new', $student['enrollment_status']);
        $this->assertNull($student['instrument'], 'an inquiry has decided no instrument yet');
        $this->assertNull($student['lesson_length_minutes']);
        $this->assertSame(['Piano', 'Violin'], json_decode($student['instruments_of_interest'], true));
    }

    public function testCreateInquiryLeadDropsInstrumentsOutsideTheVocabulary(): void
    {
        $leadId = LeadManagement::createInquiryLead(
            null,
            $this->inquiryDraft(),
            $this->inquiryStudent(['instruments_of_interest' => ['Piano', 'Kazoo']])
        );
        $student = LeadManagement::studentsForLead($leadId)[0];
        $this->assertSame(['Piano'], json_decode($student['instruments_of_interest'], true));
    }

    public function testReplaceInquiryStudentEditsRatherThanForks(): void
    {
        $leadId = $this->makeInquiryLead();
        LeadManagement::replaceInquiryStudent(null, $leadId, $this->inquiryStudent([
            'first_name' => 'Luisa', 'age' => 11, 'instruments_of_interest' => ['Cello'],
        ]));

        $students = LeadManagement::studentsForLead($leadId);
        $this->assertCount(1, $students, 'going back and continuing must not create a second student');
        $this->assertSame('Luisa', $students[0]['first_name']);
        $this->assertSame(11, (int)$students[0]['age']);
    }

    public function testUpdateInquiryDetailsRoundTripsEveryAnswer(): void
    {
        $leadId = $this->makeInquiryLead();
        LeadManagement::updateInquiryDetails(null, $leadId, [
            'semester_label' => 'Fall 2026',
            'owned_instruments' => ['Piano', 'Percussion', 'Trombone'], // last one is not offered
            'owned_instruments_other' => 'A very old ukulele',
            'music_background' => 'Two years of recorder.',
            'theory_program_interest' => 'need_info',
            'theory_knowledge' => 'beginner',
            'referral_source' => 'Word of Mouth',
            'comments' => 'Saturday mornings?',
        ]);

        $lead = LeadManagement::findLead($leadId);
        $this->assertSame('Fall 2026', $lead['semester_label']);
        $this->assertSame(['Piano', 'Percussion'], json_decode($lead['owned_instruments'], true));
        $this->assertSame('A very old ukulele', $lead['owned_instruments_other']);
        $this->assertSame('need_info', $lead['theory_program_interest']);
        $this->assertSame('beginner', $lead['theory_knowledge']);
        $this->assertSame('Word of Mouth', $lead['referral_source']);
        $this->assertSame('Saturday mornings?', $lead['inquiry_comments']);
    }

    public function testUpdateInquiryDetailsIgnoresUnknownVocabularyValues(): void
    {
        $leadId = $this->makeInquiryLead();
        LeadManagement::updateInquiryDetails(null, $leadId, [
            'theory_program_interest' => 'maybe',
            'theory_knowledge' => 'expert',
        ]);
        $lead = LeadManagement::findLead($leadId);
        $this->assertNull($lead['theory_program_interest']);
        $this->assertNull($lead['theory_knowledge']);
    }

    public function testInstrumentResolutionForInquiryVocabulary(): void
    {
        $this->assertSame(
            (int)InstrumentCatalog::findByName('Double Bass')['id'],
            LeadManagement::instrumentIdForInterest('Bass')
        );
        $this->assertSame(
            (int)InstrumentCatalog::findByName('Guitar')['id'],
            LeadManagement::instrumentIdForInterest('Guitar Ensemble')
        );
        $this->assertNull(LeadManagement::instrumentIdForInterest('Other'));
        $this->assertNull(LeadManagement::instrumentIdForChoice(''), 'an empty choice never hits the catalog');
    }

    public function testDefaultInstrumentPrefersTheChosenOneThenTheFirstInterest(): void
    {
        $pianoId = (int)InstrumentCatalog::findByName('Piano')['id'];
        $celloId = (int)InstrumentCatalog::findByName('Cello')['id'];

        $this->assertSame($pianoId, LeadManagement::defaultInstrumentIdForLeadStudent(['instrument' => 'Piano']));
        $this->assertSame($celloId, LeadManagement::defaultInstrumentIdForLeadStudent([
            'instrument' => null,
            'instruments_of_interest' => json_encode(['Other', 'Cello', 'Piano']),
        ]));
        $this->assertNull(LeadManagement::defaultInstrumentIdForLeadStudent([
            'instrument' => null, 'instruments_of_interest' => json_encode(['Other']),
        ]));
    }

    public function testConvertInquiryLeadWithNoInstrumentOrLessonLength(): void
    {
        [$ctx] = $this->convertSetup();
        $leadId = $this->makeInquiryLead();
        $lsId = (int)LeadManagement::studentsForLead($leadId)[0]['id'];

        $result = LeadManagement::convertLead($ctx, $leadId, []);

        $this->assertGreaterThan(0, $result['parent_user_id']);
        $studentId = $result['student_user_ids'][$lsId];
        $this->assertTrue(StudentTeacherManagement::isParentOf($result['parent_user_id'], $studentId));
        // The first interest that maps to a real instrument comes across.
        $this->assertSame(['Piano'], InstrumentCatalog::namesForStudent($studentId));
        $this->assertSame([], $result['reservation_ids']);
        $this->assertFalse($result['payment_recorded']);
        $this->assertSame('converted', LeadManagement::findLead($leadId)['status']);
    }

    public function testConvertUsesTheStudentsLessonLengthWhenNoDurationGiven(): void
    {
        [$ctx, $teacher, $semesterId, $locationId, $dayOfWeek] = $this->convertSetup();
        $leadId = LeadManagement::createLead(
            null, $semesterId, $this->parent(),
            [$this->student(['lesson_length_minutes' => 60])],
            [], false
        );
        $lsId = (int)LeadManagement::studentsForLead($leadId)[0]['id'];

        $result = LeadManagement::convertLead($ctx, $leadId, [
            'students' => [$lsId => [
                'reservation' => [
                    'teacher_user_id' => $teacher, 'location_id' => $locationId,
                    'day_of_week' => $dayOfWeek, 'start_time' => '10:00',
                    'duration_minutes' => 0, // an empty duration field posts as "0"
                ],
            ]],
        ]);

        $reservation = ReservationManagement::findReservation($result['reservation_ids'][0]);
        $this->assertSame(60, (int)$reservation['duration_minutes'], 'never silently books 30 minutes');
    }

    public function testConvertInquiryLeadFallsBackToThirtyMinutes(): void
    {
        [$ctx, $teacher, $semesterId, $locationId, $dayOfWeek] = $this->convertSetup();
        $leadId = $this->makeInquiryLead();
        $lsId = (int)LeadManagement::studentsForLead($leadId)[0]['id'];

        $result = LeadManagement::convertLead($ctx, $leadId, [
            'students' => [$lsId => [
                'reservation' => [
                    'teacher_user_id' => $teacher, 'location_id' => $locationId,
                    'day_of_week' => $dayOfWeek, 'start_time' => '10:00',
                    'semester_id' => $semesterId,
                ],
            ]],
        ]);

        $reservation = ReservationManagement::findReservation($result['reservation_ids'][0]);
        $this->assertSame(30, (int)$reservation['duration_minutes']);
    }
}
