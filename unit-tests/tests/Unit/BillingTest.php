<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BillingTest extends TestCase
{
    private UserContext $ctx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
        // Pricing is now configured per-semester via fx_semester_with_dates fixture.
    }

    private function confirmReservationFor(int $studentId, string $firstDate = '2030-09-07', int $weeks = 4, bool $includeInstallmentFee = false): array
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), $firstDate, $weeks);
        [$semesterId, $locationId, $teacherId, $dayOfWeek] = $setup;
        $reservationId = ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId,
            'teacher_user_id' => $teacherId,
            'location_id' => $locationId,
            'student_user_id' => $studentId,
            'day_of_week' => $dayOfWeek,
            'start_time' => '10:00',
            'status' => 'confirmed',
        ], ['include_installment_fee' => $includeInstallmentFee]);
        return [$semesterId, $reservationId, $setup];
    }

    public function testSemesterCostHelpersParsePricing(): void
    {
        $student = fx_student();
        [$semesterId] = $this->confirmReservationFor($student);
        $semester = SemesterManagement::find($semesterId);

        $this->assertSame(5000, SemesterManagement::registrationFeeCents($semester));
        $this->assertSame(30000, SemesterManagement::lessonFeeCents($semester, 30));
        $this->assertSame(2500, SemesterManagement::recitalFeeCents($semester));
    }

    public function testConfirmationPostsBaseChargesOnce(): void
    {
        $student = fx_student();
        [$semesterId] = $this->confirmReservationFor($student);

        // Registration 5000 + lessons 30000 + recital 2500. The installment
        // plan fee is NOT posted unless the admin opts the family in.
        $this->assertSame(37500, Billing::balanceForStudentCents($student));
        $this->assertSame(37500, Billing::balanceForStudentSemesterCents($student, $semesterId));

        // Idempotent — reposting (e.g. a second instrument confirmed) adds nothing.
        Billing::postSemesterConfirmationCharges($this->ctx, $student, $semesterId, 30);
        $this->assertSame(37500, Billing::balanceForStudentCents($student));
        $this->assertCount(3, Billing::ledgerForStudent($student, $semesterId));
    }

    // ===== Resident, non-resident and online rates =====
    // Fixture semester: 30-minute lessons 300.00; the tests below add a
    // non-resident / online fee of 345.00.

    private function addNonresidentFees(int $semesterId): void
    {
        pdo()->prepare(
            'UPDATE semesters SET lesson_fee_30_minutes_nonresident = 345, lesson_fee_60_minutes_nonresident = 690 WHERE id = ?'
        )->execute([$semesterId]);
    }

    private function setAddress(int $userId, string $city, string $zip): void
    {
        pdo()->prepare('UPDATE users SET address_city = ?, address_zip = ? WHERE id = ?')->execute([$city, $zip, $userId]);
    }

    public function testLessonRateFollowsTheFamilyAddress(): void
    {
        $student = fx_student();
        [$semesterId, , $setup] = $this->confirmReservationFor($student);
        $semester = SemesterManagement::find($semesterId);
        $this->assertSame('resident', Billing::lessonRateFor($semester, $student), 'one-rate semester');

        $this->addNonresidentFees($semesterId);
        $semester = SemesterManagement::find($semesterId);
        $this->assertSame('resident', Billing::lessonRateFor($semester, $student), 'no address on file');

        $parent = fx_parent_of($student);
        $this->setAddress($parent, 'Yonkers', '10701');
        $this->assertSame('nonresident', Billing::lessonRateFor($semester, $student));

        // City or ZIP: either one makes the family Bronx residents.
        $this->setAddress($parent, 'New York', '10463');
        $this->assertSame('resident', Billing::lessonRateFor($semester, $student));
        $this->setAddress($parent, 'bronx', '');
        $this->assertSame('resident', Billing::lessonRateFor($semester, $student));

        // One Bronx address in the family is enough.
        $this->setAddress($parent, 'Yonkers', '10701');
        $this->setAddress($student, 'Bronx', '10454');
        $this->assertSame('resident', Billing::lessonRateFor($semester, $student));

        // An online location is the online rate whatever the address.
        // (Locations outlive a test reset, so the flag is put back.)
        pdo()->prepare('UPDATE locations SET is_online = 1 WHERE id = ?')->execute([$setup[1]]);
        try {
            $this->assertSame('online', Billing::lessonRateFor($semester, $student, $setup[1]));
        } finally {
            pdo()->prepare('UPDATE locations SET is_online = 0 WHERE id = ?')->execute([$setup[1]]);
        }
    }

    public function testConfirmationChargesANonresidentTheNonresidentRate(): void
    {
        $student = fx_student();
        $this->setAddress(fx_parent_of($student), 'Yonkers', '10701');
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 4);
        $this->addNonresidentFees($setup[0]);

        $preview = Billing::confirmationChargesPreview($student, $setup[0], 30, null, $setup[1]);
        $this->assertSame(42000, $preview['total_cents']); // 50 + 345 + 25
        $this->assertSame('Semester lessons, Non-resident, 30-minutes', $preview['lines'][1]['description']);

        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $setup[0], 'teacher_user_id' => $setup[2], 'location_id' => $setup[1],
            'student_user_id' => $student, 'day_of_week' => $setup[3], 'start_time' => '10:00',
            'status' => 'confirmed',
        ]);
        $this->assertSame(42000, Billing::balanceForStudentCents($student));
    }

    public function testConfirmationWithInstallmentOptInAddsTheFee(): void
    {
        $student = fx_student();
        [$semesterId] = $this->confirmReservationFor($student, '2030-09-07', 4, true);

        // Registration 5000 + lessons 30000 + recital 2500 + installment 2500.
        $this->assertSame(40000, Billing::balanceForStudentCents($student));
        $this->assertCount(4, Billing::ledgerForStudent($student, $semesterId));

        // The fee is idempotent too — opting in again posts nothing new.
        Billing::postSemesterConfirmationCharges($this->ctx, $student, $semesterId, 30, true);
        $this->assertSame(40000, Billing::balanceForStudentCents($student));
        $this->assertCount(4, Billing::ledgerForStudent($student, $semesterId));
    }

    public function testUnconfirmBeforeAnyLessonReversesViaOtherCredits(): void
    {
        $student = fx_student();
        [$semesterId, $reservationId] = $this->confirmReservationFor($student); // all dates in 2030

        ReservationManagement::setStatus($this->ctx, $reservationId, 'pending_confirmation');

        $this->assertSame(0, Billing::balanceForStudentCents($student));
        $ledger = Billing::ledgerForStudent($student, $semesterId);
        $this->assertCount(6, $ledger); // 3 debits + 3 offsetting credits
        $credits = array_filter($ledger, fn($e) => $e['accounting_type'] === 'credit');
        foreach ($credits as $credit) {
            $this->assertSame('other', $credit['entry_type']);
            $this->assertStringStartsWith('Reversal:', (string)$credit['description']);
        }

        // Confirming again re-posts the charges (the earlier debits were
        // fully reversed, so hasDebit still short-circuits... it must NOT:
        // the balance must come back).
        ReservationManagement::setStatus($this->ctx, $reservationId, 'confirmed');
        $this->assertSame(37500, Billing::balanceForStudentCents($student));
    }

    public function testUnconfirmAlsoReversesAnOptedInInstallmentFee(): void
    {
        $student = fx_student();
        [$semesterId, $reservationId] = $this->confirmReservationFor($student, '2030-09-07', 4, true);
        $this->assertSame(40000, Billing::balanceForStudentCents($student));

        ReservationManagement::setStatus($this->ctx, $reservationId, 'pending_confirmation');
        $this->assertSame(0, Billing::balanceForStudentCents($student));
        $this->assertCount(8, Billing::ledgerForStudent($student, $semesterId)); // 4 debits + 4 credits
    }

    public function testUnconfirmAfterALessonOccurredPostsNothing(): void
    {
        $student = fx_student();
        $pastFirst = date('Y-m-d', strtotime('-2 weeks', strtotime('last saturday')));
        [$semesterId, $reservationId] = $this->confirmReservationFor($student, $pastFirst, 5);

        ReservationManagement::setStatus($this->ctx, $reservationId, 'pending_confirmation');

        // No reversal: the student already had lessons this semester.
        $this->assertSame(37500, Billing::balanceForStudentCents($student));
        $this->assertCount(3, Billing::ledgerForStudent($student, $semesterId));
    }

    public function testAnotherConfirmedReservationBlocksReversal(): void
    {
        $student = fx_student();
        [$semesterId, $reservationId, $setup] = $this->confirmReservationFor($student);
        [, $locationId, $teacherId, $dayOfWeek] = $setup;

        // A second confirmed reservation (different time) for the same student.
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId,
            'teacher_user_id' => $teacherId,
            'location_id' => $locationId,
            'student_user_id' => $student,
            'day_of_week' => $dayOfWeek,
            'start_time' => '11:00',
            'status' => 'confirmed',
        ]);
        $this->assertSame(37500, Billing::balanceForStudentCents($student)); // charged once

        ReservationManagement::setStatus($this->ctx, $reservationId, 'pending_confirmation');
        $this->assertSame(37500, Billing::balanceForStudentCents($student)); // still owed
    }

    public function testManualPaymentScholarshipAndCustomEntry(): void
    {
        $student = fx_student();
        [$semesterId] = $this->confirmReservationFor($student);

        Billing::recordManualPayment($this->ctx, $student, 20000, '2030-09-10', $semesterId, 'Check #123');
        $this->assertSame(17500, Billing::balanceForStudentCents($student));

        Billing::applyScholarship($this->ctx, $student, $semesterId, 10000, 'Sliding scale');
        $this->assertSame(7500, Billing::balanceForStudentCents($student));

        Billing::addCustomEntry($this->ctx, $student, 'credit', 7500, $semesterId, 'Recital opt-out adjustment');
        $this->assertSame(0, Billing::balanceForStudentCents($student));

        $this->expectException(InvalidArgumentException::class);
        Billing::addCustomEntry($this->ctx, $student, 'credit', 100, $semesterId, '');
    }

    public function testStripePaymentIsIdempotentPerSessionAndStudent(): void
    {
        $student = fx_student();
        [$semesterId] = $this->confirmReservationFor($student);

        $this->assertTrue(Billing::recordStripePayment($student, 37500, 'cs_test_1', 'pi_1', $semesterId));
        $this->assertFalse(Billing::recordStripePayment($student, 37500, 'cs_test_1', 'pi_1', $semesterId));
        $this->assertSame(0, Billing::balanceForStudentCents($student));
    }

    // ── Balances by semester, and being behind ────────────────────────────

    public function testIsSemesterPaymentBehindFollowsTheTwoDeadlines(): void
    {
        $start = '2030-09-07';
        // More than two weeks out: nothing is late, however little is paid.
        $this->assertFalse(Billing::isSemesterPaymentBehind(37500, 37500, 0, $start, 0, 14, null, '2030-08-01'));
        // Inside two weeks with nothing paid: behind.
        $this->assertTrue(Billing::isSemesterPaymentBehind(37500, 37500, 0, $start, 0, 14, null, '2030-08-30'));
        // Half paid by then: on schedule.
        $this->assertFalse(Billing::isSemesterPaymentBehind(18750, 37500, 18750, $start, 0, 14, null, '2030-08-30'));
        // Half paid, but the 6th of 14 lessons has come: the rest is due.
        $this->assertTrue(Billing::isSemesterPaymentBehind(18750, 37500, 18750, $start, 6, 14, null, '2030-10-20'));
        $this->assertFalse(Billing::isSemesterPaymentBehind(18750, 37500, 18750, $start, 5, 14, null, '2030-10-13'));
        // Paid in full is never behind, whatever the date.
        $this->assertFalse(Billing::isSemesterPaymentBehind(0, 37500, 37500, $start, 14, 14, null, '2031-01-01'));
        // With an explicit second-installment date, the date decides — not the
        // lesson count.
        $this->assertFalse(Billing::isSemesterPaymentBehind(18750, 37500, 18750, $start, 6, 14, '2030-10-25', '2030-10-20'));
        $this->assertTrue(Billing::isSemesterPaymentBehind(18750, 37500, 18750, $start, 3, 14, '2030-10-01', '2030-10-02'));
    }

    public function testSemesterPaymentBehindReasonNamesTheMissedDeadline(): void
    {
        $start = '2030-09-07';
        // The deposit deadline names its date, two weeks before the start.
        $reason = Billing::semesterPaymentBehindReason(37500, 37500, 0, $start, 0, 14, true, null, '2030-08-30');
        $this->assertStringContainsString('half of the semester balance', $reason);
        $this->assertStringContainsString('Aug 24, 2030', $reason);
        // Without the installment plan, the rest was due before the first lesson…
        $reason = Billing::semesterPaymentBehindReason(18750, 37500, 18750, $start, 1, 14, false, null, '2030-09-10');
        $this->assertStringContainsString('before the first lesson', $reason);
        // …but until that lesson, half paid is on schedule.
        $this->assertNull(Billing::semesterPaymentBehindReason(18750, 37500, 18750, $start, 0, 14, false, null, '2030-09-05'));
        // The installment plan with no explicit date falls back to the
        // half-way lesson rule (legacy semesters).
        $this->assertNull(Billing::semesterPaymentBehindReason(18750, 37500, 18750, $start, 5, 14, true, null, '2030-10-13'));
        $reason = Billing::semesterPaymentBehindReason(18750, 37500, 18750, $start, 6, 14, true, null, '2030-10-20');
        $this->assertStringContainsString('installment plan', $reason);
        $this->assertStringContainsString('6th lesson', $reason);
    }

    public function testSemesterPaymentBehindReasonUsesTheExplicitSecondInstallmentDate(): void
    {
        $start = '2030-09-07';
        $due = '2030-10-15';
        // On or before the due date: on schedule, whatever the lesson count says.
        $this->assertNull(Billing::semesterPaymentBehindReason(18750, 37500, 18750, $start, 10, 14, true, $due, '2030-10-15'));
        // The day after: behind, and the reason names the date.
        $reason = Billing::semesterPaymentBehindReason(18750, 37500, 18750, $start, 10, 14, true, $due, '2030-10-16');
        $this->assertStringContainsString('installment plan', $reason);
        $this->assertStringContainsString('Oct 15, 2030', $reason);
    }

    public function testABehindTermCarriesTheReasonForItsMissedDeadline(): void
    {
        $student = fx_student();
        // 14 weekly lessons; ten weeks in, well past the half-way lesson.
        $firstDate = date('Y-m-d', strtotime('-10 weeks'));
        [$semesterId] = $this->confirmReservationFor($student, $firstDate, 14, true);

        // The admin opted this family into the installment plan at
        // confirmation, so they follow the installment schedule; with less
        // than half paid, the deposit deadline is the one named.
        $term = Billing::balanceSummaryForStudent($student)['semesters'][0];
        $this->assertTrue($term['behind']);
        $this->assertStringContainsString('half of the semester balance', $term['behind_reason']);

        // Half paid: the missed deadline is now the installment plan's
        // half-way lesson.
        Billing::recordManualPayment($this->ctx, $student, 22000, date('Y-m-d'), $semesterId, 'Check #1');
        $term = Billing::balanceSummaryForStudent($student)['semesters'][0];
        $this->assertTrue($term['behind']);
        $this->assertStringContainsString('installment plan', $term['behind_reason']);
        $this->assertStringContainsString('6th lesson', $term['behind_reason']);

        // The summary hands the same reasons up, labeled by term.
        $summary = Billing::balanceSummaryForStudent($student);
        $this->assertSame($term['label'], $summary['behind_reasons'][0]['label']);
        $this->assertSame($term['behind_reason'], $summary['behind_reasons'][0]['reason']);

        // Paying it off clears the reason with the flag.
        Billing::recordManualPayment($this->ctx, $student, 20500, date('Y-m-d'), $semesterId, 'Check #2');
        $term = Billing::balanceSummaryForStudent($student)['semesters'][0];
        $this->assertFalse($term['behind']);
        $this->assertNull($term['behind_reason']);
    }

    public function testReversedChargesAreNotShownAsPayments(): void
    {
        $student = fx_student();
        $fall = fx_semester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');

        Billing::addCustomEntry($this->ctx, $student, 'debit', 76000, $fall, 'Lessons, charged twice by mistake');
        Billing::addCustomEntry($this->ctx, $student, 'credit', 38000, $fall, 'Reversal of the duplicate');
        Billing::applyScholarship($this->ctx, $student, $fall, 5000, 'Scholarship');
        Billing::recordManualPayment($this->ctx, $student, 10000, '2030-06-01', $fall, 'Check #7');

        $term = Billing::semesterBalancesForStudent($student, '2030-06-02')[0];
        $this->assertSame(33000, $term['charged_cents'], 'charges net of the reversal and the scholarship');
        $this->assertSame(10000, $term['paid_cents'], 'only what the family paid');
        $this->assertSame(23000, $term['balance_cents']);
    }

    // ===== Parent credit =====

    public function testAChargeDrawsOnTheStudentsCreditThenTheParentCredit(): void
    {
        $student = fx_student();
        $parent = fx_parent_of($student);
        $fall = fx_semester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');

        // The student has $200 of their own; the parent holds $300.
        Billing::recordManualPayment($this->ctx, $student, 20000, '2030-06-01', $fall, 'Check');
        $this->assertTrue(Billing::addParentCredit($this->ctx, $parent, 30000, 'Registration payment'));
        $this->assertSame(30000, Billing::parentCreditBalanceCents($parent));
        $this->assertSame(30000, Billing::parentCreditAvailableForStudent($student));

        // A $400 charge: the student's $200 goes first, then $200 of the parent's.
        Billing::addCustomEntry($this->ctx, $student, 'debit', 40000, $fall, 'Lessons');

        $this->assertSame(0, Billing::balanceForStudentCents($student));
        $this->assertSame(10000, Billing::parentCreditBalanceCents($parent));

        // Both sides explain it: a payment line on the student, a use on the parent.
        $lines = array_values(array_filter(
            Billing::ledgerForStudent($student),
            fn($e) => str_contains((string)$e['description'], 'Paid from parent credit')
        ));
        $this->assertCount(1, $lines);
        $last = $lines[0];
        $this->assertSame('payment', $last['entry_type']);
        $this->assertSame(20000, (int)$last['amount_cents']);
        $uses = array_values(array_filter(Billing::parentCreditEntries($parent), fn($e) => $e['accounting_type'] === 'debit'));
        $this->assertCount(1, $uses);
        $this->assertSame($student, (int)$uses[0]['applied_to_student_user_id']);
        $this->assertSame((int)$last['id'], (int)$uses[0]['ledger_entry_id']);

        // It counts as money paid, not as a reduced charge.
        $term = Billing::semesterBalancesForStudent($student, '2030-06-02')[0];
        $this->assertSame(40000, $term['charged_cents']);
        $this->assertSame(40000, $term['paid_cents']);
    }

    public function testParentCreditIsNotTouchedWhenTheStudentOwesNothing(): void
    {
        $student = fx_student();
        $parent = fx_parent_of($student);
        $fall = fx_semester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        Billing::recordManualPayment($this->ctx, $student, 50000, '2030-06-01', $fall, 'Check');
        Billing::addParentCredit($this->ctx, $parent, 30000, 'Registration payment');

        Billing::addCustomEntry($this->ctx, $student, 'debit', 40000, $fall, 'Lessons');

        $this->assertSame(-10000, Billing::balanceForStudentCents($student));
        $this->assertSame(30000, Billing::parentCreditBalanceCents($parent));
    }

    public function testAStripePaymentIsAddedToParentCreditOnlyOnce(): void
    {
        $parent = fx_parent_of(fx_student());
        $this->assertTrue(Billing::addParentCredit(null, $parent, 5000, 'Registration payment', null, 'pi_once', 'pi_once'));
        $this->assertFalse(Billing::addParentCredit(null, $parent, 5000, 'Registration payment', null, 'pi_once', 'pi_once'));
        $this->assertSame(5000, Billing::parentCreditBalanceCents($parent));
        $this->assertTrue(Billing::stripeReferenceRecorded('pi_once'));
        $this->assertFalse(Billing::stripeReferenceRecorded('pi_other'));
    }

    public function testAParentCanApplyTheirCreditToAChildsBalance(): void
    {
        $student = fx_student();
        $parent = fx_parent_of($student);
        $fall = fx_semester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        // The child already owes $210; the credit arrives afterwards, so
        // nothing has drawn on it.
        Billing::addCustomEntry($this->ctx, $student, 'debit', 21000, $fall, 'Lessons');
        Billing::addParentCredit($this->ctx, $parent, 22000, 'Registration payment');
        $this->assertSame(21000, Billing::balanceForStudentCents($student));

        $parentCtx = new UserContext($parent, false);
        $this->assertSame(21000, Billing::applyParentCreditByChoice($parentCtx, $parent, $student));
        $this->assertSame(0, Billing::balanceForStudentCents($student));
        $this->assertSame(1000, Billing::parentCreditBalanceCents($parent));

        // Somebody else's child, or somebody else's credit: refused.
        $stranger = fx_student('Other', 'Kid');
        try {
            Billing::applyParentCreditByChoice($parentCtx, $parent, $stranger);
            $this->fail('applied credit to a child who is not theirs');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(1000, Billing::parentCreditBalanceCents($parent));
        }
        $this->expectException(RuntimeException::class);
        Billing::applyParentCreditByChoice(new UserContext($stranger, false), $parent, $student);
    }

    public function testMovingAStudentsCreditToTheParent(): void
    {
        $student = fx_student();
        $parent = fx_parent_of($student);
        $fall = fx_semester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        Billing::recordManualPayment($this->ctx, $student, 46500, '2030-06-01', $fall, 'Check');

        // An only child: there is nobody to share the credit with.
        $this->assertSame([], Billing::parentsForCreditMove($student));

        $sibling = fx_student('Sib', 'Ling');
        pdo()->prepare('INSERT INTO parenthood (parent_user_id, child_user_id) VALUES (?,?)')->execute([$parent, $sibling]);
        $this->assertSame([$parent], array_column(Billing::parentsForCreditMove($student), 'id'));

        Billing::moveStudentCreditToParent($this->ctx, $student, $parent, 40000, 'for Sib');
        $this->assertSame(-6500, Billing::balanceForStudentCents($student));
        $this->assertSame(40000, Billing::parentCreditBalanceCents($parent));
        // The student paid less, rather than being charged something new.
        $term = Billing::balanceSummaryForStudent($student);
        $this->assertFalse($term['has_charges']);

        // The sibling's next charge uses it.
        Billing::addCustomEntry($this->ctx, $sibling, 'debit', 30000, $fall, 'Lessons');
        $this->assertSame(0, Billing::balanceForStudentCents($sibling));
        $this->assertSame(10000, Billing::parentCreditBalanceCents($parent));

        // Never more than the student has.
        $this->expectException(InvalidArgumentException::class);
        Billing::moveStudentCreditToParent($this->ctx, $student, $parent, 6501);
    }

    public function testNothingOwedIsOnlyPaidInFullIfThereWereCharges(): void
    {
        $student = fx_student();
        $this->assertFalse(Billing::balanceSummaryForStudent($student)['has_charges'], 'no ledger at all');

        $fall = fx_semester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        Billing::addCustomEntry($this->ctx, $student, 'debit', 30000, $fall, 'Lessons');
        Billing::addCustomEntry($this->ctx, $student, 'credit', 30000, $fall, 'Reversed');
        $summary = Billing::balanceSummaryForStudent($student);
        $this->assertSame(0, $summary['due_cents']);
        $this->assertFalse($summary['has_charges'], 'every charge reversed');

        Billing::addCustomEntry($this->ctx, $student, 'debit', 30000, $fall, 'Lessons');
        Billing::recordManualPayment($this->ctx, $student, 30000, '2030-06-01', $fall, 'Check');
        $summary = Billing::balanceSummaryForStudent($student);
        $this->assertSame(0, $summary['due_cents']);
        $this->assertTrue($summary['has_charges']);
    }

    public function testABalanceThatIsNotLateSaysWhenItIsDue(): void
    {
        $due = fn(int $balance, int $charged, int $paid, bool $plan, ?string $second = null) =>
            Billing::semesterNextDueLabel($balance, $charged, $paid, '2027-02-02', 15, $plan, $second);

        $this->assertNull($due(0, 46500, 46500, false));
        $this->assertSame('Half ($232.50) due by Jan 19, 2027', $due(46500, 46500, 0, false));
        $this->assertSame('Half ($132.50) due by Jan 19, 2027', $due(36500, 46500, 10000, false));
        $this->assertSame('Due by Feb 2, 2027', $due(20000, 46500, 26500, false));
        $this->assertSame('Due by Apr 15, 2027', $due(20000, 46500, 26500, true, '2027-04-15'));
        $this->assertSame('Due before the 7th lesson', $due(20000, 46500, 26500, true));
        $this->assertSame('Due now', Billing::semesterNextDueLabel(500, 500, 0, null, 0, false));
    }

    public function testSemesterBalancesRollASurplusCreditForward(): void
    {
        $student = fx_student();
        $spring = fx_semester($this->ctx, 'spring', 2030, '2030-01-10', '2030-05-30');
        $fall = fx_semester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');

        Billing::addCustomEntry($this->ctx, $student, 'debit', 10000, $spring, 'Spring tuition');
        Billing::addCustomEntry($this->ctx, $student, 'debit', 30000, $fall, 'Fall tuition');
        // Overpaying spring by $50 leaves nothing owed there and $250 in fall.
        Billing::recordManualPayment($this->ctx, $student, 15000, '2030-02-01', $spring, 'Check #1');

        $terms = Billing::semesterBalancesForStudent($student, '2030-10-01');
        $this->assertSame(['Spring 2030', 'Fall 2030'], array_column($terms, 'label'));
        $this->assertSame(0, $terms[0]['balance_cents']);
        $this->assertSame(25000, $terms[1]['balance_cents']);
        // The surplus counts as paid against fall, which is what decides
        // whether the family is on schedule.
        $this->assertSame(5000, $terms[1]['paid_cents']);

        $summary = Billing::balanceSummaryForStudent($student, '2030-10-01');
        $this->assertSame(25000, $summary['due_cents']);
        $this->assertSame(25000, $summary['balance_cents']);
        $this->assertSame($fall, Billing::oldestOwedSemesterIdForStudent($student, '2030-10-01'));
    }

    public function testABalanceIsBehindOnceHalfTheLessonsHaveBeenTaught(): void
    {
        $student = fx_student();
        // A term of 14 weekly lessons that started 10 weeks ago.
        $firstDate = date('Y-m-d', strtotime('-10 weeks'));
        [$semesterId] = $this->confirmReservationFor($student, $firstDate, 14);

        $summary = Billing::balanceSummaryForStudent($student);
        $term = $summary['semesters'][0];
        $this->assertSame(14, $term['lessons_total']);
        $this->assertSame(11, $term['lessons_elapsed']); // the first plus ten weeks
        $this->assertTrue($term['behind']);
        $this->assertTrue($summary['behind']);

        // Paying more than half no longer helps this far into the term.
        Billing::recordManualPayment($this->ctx, $student, 20000, date('Y-m-d'), $semesterId, 'Check #2');
        $this->assertTrue(Billing::balanceSummaryForStudent($student)['behind']);

        // Paying it off does.
        Billing::recordManualPayment($this->ctx, $student, 20000, date('Y-m-d'), $semesterId, 'Check #3');
        $summary = Billing::balanceSummaryForStudent($student);
        $this->assertSame(0, $summary['due_cents']);
        $this->assertFalse($summary['behind']);
    }

    public function testABalanceForATermStillWeeksAwayIsNotBehind(): void
    {
        $student = fx_student();
        [$semesterId] = $this->confirmReservationFor($student); // starts in 2030
        $summary = Billing::balanceSummaryForStudent($student, date('Y-m-d'));

        $this->assertSame(37500, $summary['due_cents']);
        $this->assertFalse($summary['behind']);
        $this->assertSame($semesterId, $summary['semesters'][0]['semester_id']);
    }

    public function testChargesWithNoTermAreListedButNeverCalledLate(): void
    {
        $student = fx_student();
        Billing::addCustomEntry($this->ctx, $student, 'debit', 1000, null, 'Book fee');

        $summary = Billing::balanceSummaryForStudent($student);
        $this->assertSame('Other charges', $summary['semesters'][0]['label']);
        $this->assertSame(1000, $summary['due_cents']);
        $this->assertFalse($summary['behind']);
        $this->assertNull(Billing::oldestOwedSemesterIdForStudent($student));
    }

    // ── Paying ─────────────────────────────────────────────────────────────

    public function testOutstandingChildrenAreOrderedByTheirOldestDebt(): void
    {
        $childA = fx_student('Ann', 'Kid');
        $childB = fx_student('Ben', 'Kid');
        $childC = fx_student('Cal', 'Kid');
        $parent = fx_parent_of($childA);
        pdo()->exec("INSERT INTO parenthood (parent_user_id, child_user_id) VALUES ($parent, $childB), ($parent, $childC)");

        $spring = fx_semester($this->ctx, 'spring', 2030, '2030-01-10', '2030-05-30');
        $fall = fx_semester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        Billing::addCustomEntry($this->ctx, $childA, 'debit', 10000, $fall, 'Fall tuition');
        Billing::addCustomEntry($this->ctx, $childB, 'debit', 20000, $spring, 'Spring tuition');
        // Cal owes nothing and is left out entirely.
        Billing::addCustomEntry($this->ctx, $childC, 'debit', 5000, $fall, 'Fall tuition');
        Billing::recordManualPayment($this->ctx, $childC, 5000, '2030-09-05', $fall, 'Paid up');

        $rows = Billing::outstandingByChildForParent($parent, '2030-10-01');
        $this->assertSame([$childB, $childA], array_column($rows, 'student_user_id'));
        $this->assertSame([20000, 10000], array_column($rows, 'due_cents'));
        $this->assertSame([$spring, $fall], array_column($rows, 'semester_id'));

        // A part payment fills the oldest debt first and never overshoots.
        $balances = array_column($rows, 'due_cents', 'student_user_id');
        $this->assertSame([$childB => 15000], Billing::allocatePaymentAcrossStudents($balances, 15000));
        $this->assertSame([$childB => 20000, $childA => 5000], Billing::allocatePaymentAcrossStudents($balances, 25000));
        $this->assertSame([], Billing::allocatePaymentAcrossStudents($balances, 0));
    }

    public function testStripeIntentPaymentIsIdempotentPerIntentAndStudent(): void
    {
        $student = fx_student();
        [$semesterId] = $this->confirmReservationFor($student);

        $this->assertTrue(Billing::recordStripeIntentPayment($student, 37500, 'pi_test_1', $semesterId));
        $this->assertFalse(Billing::recordStripeIntentPayment($student, 37500, 'pi_test_1', $semesterId));
        $this->assertSame(0, Billing::balanceForStudentCents($student));
    }

    public function testParentBalanceSumsChildren(): void
    {
        $childA = fx_student('Ann', 'Kid');
        $childB = fx_student('Ben', 'Kid');
        $parent = fx_parent_of($childA);
        pdo()->exec("INSERT INTO parenthood (parent_user_id, child_user_id) VALUES ($parent, $childB)");

        [$semesterId] = $this->confirmReservationFor($childA);
        Billing::addCustomEntry($this->ctx, $childB, 'debit', 1000, null, 'Book fee');

        $this->assertSame(38500, Billing::balanceForParentCents($parent));
        $byChild = Billing::balancesForParentChildren($parent);
        $this->assertSame(37500, $byChild[$childA]);
        $this->assertSame(1000, $byChild[$childB]);

        $balances = Billing::semesterBalancesByStudent($semesterId, [$childA, $childB]);
        $this->assertSame(37500, $balances[$childA]['semester_debit_cents']);
        $this->assertSame(37500, $balances[$childA]['total_balance_cents']);
        $this->assertSame(0, $balances[$childB]['semester_debit_cents']);
        $this->assertSame(1000, $balances[$childB]['total_balance_cents']);
    }

    // ── Charge previews (the confirmation dialog's data) ───────────────────

    public function testConfirmationChargesPreviewShowsWhatWouldPost(): void
    {
        $student = fx_student();
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 4);
        [$semesterId] = $setup;

        // Fresh student: all three base lines will post; installment available.
        $preview = Billing::confirmationChargesPreview($student, $semesterId, 30);
        $this->assertCount(3, $preview['lines']);
        $this->assertSame([true, true, true], array_column($preview['lines'], 'will_post'));
        $this->assertSame(37500, $preview['total_cents']);
        $this->assertSame(2500, $preview['installment_fee_cents']);
        $this->assertTrue($preview['installment_available']);
        $this->assertStringContainsString('$25.00', (string)$preview['installment_note']);

        // After charges exist, everything shows as already charged.
        Billing::postSemesterConfirmationCharges($this->ctx, $student, $semesterId, 30, true);
        $preview = Billing::confirmationChargesPreview($student, $semesterId, 30);
        $this->assertSame([false, false, false], array_column($preview['lines'], 'will_post'));
        $this->assertSame(0, $preview['total_cents']);
        $this->assertFalse($preview['installment_available']);
    }

    public function testReversalPreviewMirrorsTheReversalGuards(): void
    {
        $student = fx_student();
        [$semesterId, $reservationId] = $this->confirmReservationFor($student, '2030-09-07', 4, true);

        // The reservation being unconfirmed must not block its own preview.
        $preview = Billing::reversalPreview($student, $semesterId, $reservationId);
        $this->assertTrue($preview['will_reverse']);
        $this->assertNull($preview['blocked_reason']);
        $this->assertSame(40000, $preview['total_cents']);
        $this->assertCount(4, $preview['lines']);

        // Without the exclusion the confirmed reservation blocks it.
        $preview = Billing::reversalPreview($student, $semesterId, null);
        $this->assertFalse($preview['will_reverse']);
        $this->assertStringContainsString('another confirmed reservation', (string)$preview['blocked_reason']);
    }

    public function testReversalPreviewBlockedByAnOccurredLesson(): void
    {
        $student = fx_student();
        $pastFirst = date('Y-m-d', strtotime('-2 weeks', strtotime('last saturday')));
        [$semesterId, $reservationId] = $this->confirmReservationFor($student, $pastFirst, 5);

        $preview = Billing::reversalPreview($student, $semesterId, $reservationId);
        $this->assertFalse($preview['will_reverse']);
        $this->assertStringContainsString('already had a lesson', (string)$preview['blocked_reason']);
        // The live debits are still listed so the dialog can show what stays.
        $this->assertSame(37500, $preview['total_cents']);
    }

    // ── The daily installment-fee sweep ────────────────────────────────────

    /** A confirmed student in a semester running 2030-09-01 → 2030-12-20. */
    private function confirmedStudentInProgressSemester(bool $includeInstallmentFee = false): array
    {
        $student = fx_student();
        $setup = fx_semester_with_dates(
            $this->ctx, fx_teacher(), '2030-09-07', 4, 'fall', 2030, '2030-09-01', '2030-12-20'
        );
        [$semesterId, $locationId, $teacherId, $dayOfWeek] = $setup;
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId,
            'teacher_user_id' => $teacherId,
            'location_id' => $locationId,
            'student_user_id' => $student,
            'day_of_week' => $dayOfWeek,
            'start_time' => '10:00',
            'status' => 'confirmed',
        ], ['include_installment_fee' => $includeInstallmentFee]);
        return [$student, $semesterId];
    }

    public function testInstallmentSweepChargesUnpaidStudentsFromDayTwo(): void
    {
        [$student, $semesterId] = $this->confirmedStudentInProgressSemester();
        $this->assertSame(37500, Billing::balanceForStudentCents($student));

        // Day 1 of the semester: nothing happens.
        $result = Billing::applyAutomaticInstallmentFees(null, '2030-09-01');
        $this->assertSame([], $result['applied']);
        $this->assertSame(37500, Billing::balanceForStudentCents($student));

        // Day 2: the fee posts, as a system entry (no ctx).
        $result = Billing::applyAutomaticInstallmentFees(null, '2030-09-02');
        $this->assertCount(1, $result['applied']);
        $this->assertSame($student, $result['applied'][0]['student_user_id']);
        $this->assertSame(2500, $result['applied'][0]['amount_cents']);
        $this->assertSame(40000, Billing::balanceForStudentCents($student));

        // Idempotent: a second run (same or later day) posts nothing.
        $result = Billing::applyAutomaticInstallmentFees(null, '2030-09-03');
        $this->assertSame([], $result['applied']);
        $this->assertSame(40000, Billing::balanceForStudentCents($student));

        // After the semester ends, the sweep no longer touches it.
        $result = Billing::applyAutomaticInstallmentFees(null, '2030-12-21');
        $this->assertSame(0, $result['semesters']);
    }

    public function testInstallmentSweepSkipsPaidAndAlreadyChargedStudents(): void
    {
        [$student, $semesterId] = $this->confirmedStudentInProgressSemester();
        Billing::recordManualPayment($this->ctx, $student, 37500, '2030-09-01', $semesterId, 'Paid in full');

        // Paid in full: no fee.
        $result = Billing::applyAutomaticInstallmentFees(null, '2030-09-02');
        $this->assertSame([], $result['applied']);
        $this->assertSame(1, $result['skipped']);

        // A student opted in at confirmation already carries the fee: skipped
        // (their spring semester is the one in progress on this date).
        [$optedIn] = $this->confirmedStudentInProgressSemester2();
        $result = Billing::applyAutomaticInstallmentFees(null, '2030-02-03');
        $this->assertSame([], $result['applied']);
        $this->assertSame(40000, Billing::balanceForStudentCents($optedIn));
    }

    /** Second opted-in student in a separate in-progress semester (unique season/year). */
    private function confirmedStudentInProgressSemester2(): array
    {
        $student = fx_student('Opted', 'In');
        $setup = fx_semester_with_dates(
            $this->ctx, fx_teacher('Second', 'Teacher'), '2030-02-02', 4, 'spring', 2030, '2030-01-15', '2030-05-30'
        );
        [$semesterId, $locationId, $teacherId, $dayOfWeek] = $setup;
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId,
            'teacher_user_id' => $teacherId,
            'location_id' => $locationId,
            'student_user_id' => $student,
            'day_of_week' => $dayOfWeek,
            'start_time' => '10:00',
            'status' => 'confirmed',
        ], ['include_installment_fee' => true]);
        return [$student, $semesterId];
    }

    public function testInstallmentSweepDryRunWritesNothing(): void
    {
        [$student] = $this->confirmedStudentInProgressSemester();

        $result = Billing::applyAutomaticInstallmentFees(null, '2030-09-02', true);
        $this->assertCount(1, $result['applied']);
        $this->assertSame(37500, Billing::balanceForStudentCents($student)); // unchanged
    }

    public function testInstallmentSweepHonorsSurplusRolledForward(): void
    {
        // A credit from an earlier term that covers this term counts as paid.
        [$student, $semesterId] = $this->confirmedStudentInProgressSemester();
        $spring = fx_semester($this->ctx, 'spring', 2030, '2030-01-10', '2030-05-30');
        Billing::recordManualPayment($this->ctx, $student, 37500, '2030-02-01', $spring, 'Prepaid');

        $result = Billing::applyAutomaticInstallmentFees(null, '2030-09-02');
        $this->assertSame([], $result['applied']);
    }

    // ── Duration-change accounting ──────────────────────────────────────────

    public function testLessonsUsedAndRemainingCountsCorrectly(): void
    {
        $student = fx_student();
        [$semesterId, $reservationId, $setup] = $this->confirmReservationFor($student);

        // With 4 weeks (4 lessons generated), none attended yet.
        $result = Billing::lessonsUsedAndRemaining($reservationId, $semesterId);
        $this->assertSame(4, $result['lessons_total']);
        $this->assertSame(0, $result['lessons_used']);
        $this->assertSame(4, $result['lessons_remaining']);

        // Mark the first lesson as attended.
        $st = pdo()->prepare(
            'SELECT id FROM lessons WHERE semester_lesson_reservation_id = ? ORDER BY lesson_number LIMIT 1'
        );
        $st->execute([$reservationId]);
        $lesson = $st->fetch();
        if ($lesson) {
            pdo()->prepare('UPDATE lessons SET attended = 1 WHERE id = ?')->execute([$lesson['id']]);
            $result = Billing::lessonsUsedAndRemaining($reservationId, $semesterId);
            $this->assertSame(1, $result['lessons_used']);
            $this->assertSame(3, $result['lessons_remaining']);
        }

        // Cancel the second lesson (it counts as used).
        $st = pdo()->prepare(
            'SELECT id FROM lessons WHERE semester_lesson_reservation_id = ? ORDER BY lesson_number LIMIT 1 OFFSET 1'
        );
        $st->execute([$reservationId]);
        $lesson = $st->fetch();
        if ($lesson) {
            pdo()->prepare('UPDATE lessons SET cancelled_at = NOW() WHERE id = ?')->execute([$lesson['id']]);
            $result = Billing::lessonsUsedAndRemaining($reservationId, $semesterId);
            $this->assertSame(2, $result['lessons_used']);
            $this->assertSame(2, $result['lessons_remaining']);
        }
    }

    public function testDurationChangeLedgerCalculationRefundsAndCharges(): void
    {
        $student = fx_student();
        [$semesterId, $reservationId] = $this->confirmReservationFor($student);

        // Changing from 30 min ($300 for semester) to 60 min ($600 for semester).
        // With 4 weeks booked, no lessons attended yet. Semester has 15 lessons_per_semester.
        //   - Lessons generated: 4, lessons used: 0, lessons remaining: 4
        //   - Original fee: $300, per-lesson rate: $300/15 = $20/lesson
        //   - Amount spent: 0 * $20 = $0, refund: $300 - $0 = $300
        //   - New fee: $600, per-lesson rate: $600/15 = $40/lesson
        //   - New charge: 4 * $40 = $160
        $calc = Billing::durationChangeLedgerCalculation($reservationId, $semesterId, 30, 60);

        $this->assertSame(4, $calc['lessons_total']);
        $this->assertSame(0, $calc['lessons_used']);
        $this->assertSame(4, $calc['lessons_remaining']);
        $this->assertSame(30000, $calc['original_fee_cents']);
        $this->assertSame(0, $calc['amount_spent_cents']);
        $this->assertSame(30000, $calc['refund_cents']);
        $this->assertSame(60000, $calc['new_fee_cents']);
        // 60000 / 15 * 4 = 16000
        $this->assertSame(16000, $calc['new_charge_cents']);
    }

    public function testDurationChangeLedgerCalculationWithPartialUsage(): void
    {
        $student = fx_student();
        [$semesterId, $reservationId] = $this->confirmReservationFor($student);

        // Mark first 2 lessons as attended.
        $st = pdo()->prepare(
            'SELECT id FROM lessons WHERE semester_lesson_reservation_id = ? ORDER BY lesson_number LIMIT 2'
        );
        $st->execute([$reservationId]);
        while ($lesson = $st->fetch()) {
            pdo()->prepare('UPDATE lessons SET attended = 1 WHERE id = ?')->execute([$lesson['id']]);
        }

        // Changing from 30 min ($300) to 60 min ($600).
        // 2 lessons used out of 4, 2 remaining:
        //   - Per-lesson rate (30 min): $300/15 = $20/lesson
        //   - Amount spent: 2 * $20 = $40
        //   - Refund: $300 - $40 = $260
        //   - Per-lesson rate (60 min): $600/15 = $40/lesson
        //   - New charge: 2 * $40 = $80
        $calc = Billing::durationChangeLedgerCalculation($reservationId, $semesterId, 30, 60);

        $this->assertSame(2, $calc['lessons_used']);
        $this->assertSame(2, $calc['lessons_remaining']);
        $this->assertSame(4000, $calc['amount_spent_cents']); // 2 * 2000
        $this->assertSame(26000, $calc['refund_cents']); // 30000 - 4000
        $this->assertSame(8000, $calc['new_charge_cents']); // 2 * 4000
    }

    public function testPostDurationChangeEntriesCreatesLedgerRows(): void
    {
        $student = fx_student();
        [$semesterId, $reservationId] = $this->confirmReservationFor($student);

        $initial = Billing::balanceForStudentCents($student);

        // Post a 30-min → 60-min change with calculated amounts.
        $calc = Billing::durationChangeLedgerCalculation($reservationId, $semesterId, 30, 60);
        [$refundId, $chargeId] = Billing::postDurationChangeEntries(
            $this->ctx, $student, $semesterId, $calc['refund_cents'], $calc['new_charge_cents'], 30, 60
        );

        $this->assertIsInt($refundId);
        $this->assertIsInt($chargeId);

        // Balance change: -refund + new_charge
        $balanceChange = -$calc['refund_cents'] + $calc['new_charge_cents'];
        $updated = Billing::balanceForStudentCents($student);
        $this->assertSame($initial + $balanceChange, $updated);

        $ledger = Billing::ledgerForStudent($student, $semesterId);
        $entries = array_filter(
            $ledger,
            fn($e) => in_array($e['description'], [
                'Duration change refund: 30→60 min',
                'Duration change charge: 30→60 min'
            ])
        );
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            if ($entry['accounting_type'] === 'credit') {
                $this->assertSame($calc['refund_cents'], $entry['amount_cents']);
            } else {
                $this->assertSame($calc['new_charge_cents'], $entry['amount_cents']);
            }
        }
    }

    public function testPostDurationChangeEntriesWithZeroAmounts(): void
    {
        $student = fx_student();
        [$semesterId] = $this->confirmReservationFor($student);

        $initial = Billing::balanceForStudentCents($student);

        // Post with $0 refund and charge (should not create entries).
        [$refundId, $chargeId] = Billing::postDurationChangeEntries(
            $this->ctx, $student, $semesterId, 0, 0, 30, 60
        );

        $this->assertNull($refundId);
        $this->assertNull($chargeId);
        $this->assertSame($initial, Billing::balanceForStudentCents($student));
    }
}
