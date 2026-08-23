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

    private function confirmReservationFor(int $studentId, string $firstDate = '2030-09-07', int $weeks = 4): array
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
        ]);
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

    public function testConfirmationPostsAllFourChargesOnce(): void
    {
        $student = fx_student();
        [$semesterId] = $this->confirmReservationFor($student);

        // Registration 5000 + lessons 30000 + recital 2500 + installment 2500.
        $this->assertSame(40000, Billing::balanceForStudentCents($student));
        $this->assertSame(40000, Billing::balanceForStudentSemesterCents($student, $semesterId));

        // Idempotent — reposting (e.g. a second instrument confirmed) adds nothing.
        Billing::postSemesterConfirmationCharges($this->ctx, $student, $semesterId, 30);
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
        $this->assertCount(8, $ledger); // 4 debits + 4 offsetting credits
        $credits = array_filter($ledger, fn($e) => $e['accounting_type'] === 'credit');
        foreach ($credits as $credit) {
            $this->assertSame('other', $credit['entry_type']);
            $this->assertStringStartsWith('Reversal:', (string)$credit['description']);
        }

        // Confirming again re-posts the charges (the earlier debits were
        // fully reversed, so hasDebit still short-circuits... it must NOT:
        // the balance must come back).
        ReservationManagement::setStatus($this->ctx, $reservationId, 'confirmed');
        $this->assertSame(40000, Billing::balanceForStudentCents($student));
    }

    public function testUnconfirmAfterALessonOccurredPostsNothing(): void
    {
        $student = fx_student();
        $pastFirst = date('Y-m-d', strtotime('-2 weeks', strtotime('last saturday')));
        [$semesterId, $reservationId] = $this->confirmReservationFor($student, $pastFirst, 5);

        ReservationManagement::setStatus($this->ctx, $reservationId, 'pending_confirmation');

        // No reversal: the student already had lessons this semester.
        $this->assertSame(40000, Billing::balanceForStudentCents($student));
        $this->assertCount(4, Billing::ledgerForStudent($student, $semesterId));
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
        $this->assertSame(40000, Billing::balanceForStudentCents($student)); // charged once

        ReservationManagement::setStatus($this->ctx, $reservationId, 'pending_confirmation');
        $this->assertSame(40000, Billing::balanceForStudentCents($student)); // still owed
    }

    public function testManualPaymentScholarshipAndCustomEntry(): void
    {
        $student = fx_student();
        [$semesterId] = $this->confirmReservationFor($student);

        Billing::recordManualPayment($this->ctx, $student, 20000, '2030-09-10', $semesterId, 'Check #123');
        $this->assertSame(20000, Billing::balanceForStudentCents($student));

        Billing::applyScholarship($this->ctx, $student, $semesterId, 10000, 'Sliding scale');
        $this->assertSame(10000, Billing::balanceForStudentCents($student));

        Billing::addCustomEntry($this->ctx, $student, 'credit', 10000, $semesterId, 'Recital opt-out adjustment');
        $this->assertSame(0, Billing::balanceForStudentCents($student));

        $this->expectException(InvalidArgumentException::class);
        Billing::addCustomEntry($this->ctx, $student, 'credit', 100, $semesterId, '');
    }

    public function testStripePaymentIsIdempotentPerSessionAndStudent(): void
    {
        $student = fx_student();
        [$semesterId] = $this->confirmReservationFor($student);

        $this->assertTrue(Billing::recordStripePayment($student, 40000, 'cs_test_1', 'pi_1', $semesterId));
        $this->assertFalse(Billing::recordStripePayment($student, 40000, 'cs_test_1', 'pi_1', $semesterId));
        $this->assertSame(0, Billing::balanceForStudentCents($student));
    }

    // ── Balances by semester, and being behind ────────────────────────────

    public function testIsSemesterPaymentBehindFollowsTheTwoDeadlines(): void
    {
        $start = '2030-09-07';
        // More than two weeks out: nothing is late, however little is paid.
        $this->assertFalse(Billing::isSemesterPaymentBehind(37500, 37500, 0, $start, 0, 14, '2030-08-01'));
        // Inside two weeks with nothing paid: behind.
        $this->assertTrue(Billing::isSemesterPaymentBehind(37500, 37500, 0, $start, 0, 14, '2030-08-30'));
        // Half paid by then: on schedule.
        $this->assertFalse(Billing::isSemesterPaymentBehind(18750, 37500, 18750, $start, 0, 14, '2030-08-30'));
        // Half paid, but the 6th of 14 lessons has come: the rest is due.
        $this->assertTrue(Billing::isSemesterPaymentBehind(18750, 37500, 18750, $start, 6, 14, '2030-10-20'));
        $this->assertFalse(Billing::isSemesterPaymentBehind(18750, 37500, 18750, $start, 5, 14, '2030-10-13'));
        // Paid in full is never behind, whatever the date.
        $this->assertFalse(Billing::isSemesterPaymentBehind(0, 37500, 37500, $start, 14, 14, '2031-01-01'));
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

        $this->assertSame(40000, $summary['due_cents']);
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

        $this->assertTrue(Billing::recordStripeIntentPayment($student, 40000, 'pi_test_1', $semesterId));
        $this->assertFalse(Billing::recordStripeIntentPayment($student, 40000, 'pi_test_1', $semesterId));
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

        $this->assertSame(41000, Billing::balanceForParentCents($parent));
        $byChild = Billing::balancesForParentChildren($parent);
        $this->assertSame(40000, $byChild[$childA]);
        $this->assertSame(1000, $byChild[$childB]);

        $balances = Billing::semesterBalancesByStudent($semesterId, [$childA, $childB]);
        $this->assertSame(40000, $balances[$childA]['semester_debit_cents']);
        $this->assertSame(40000, $balances[$childA]['total_balance_cents']);
        $this->assertSame(0, $balances[$childB]['semester_debit_cents']);
        $this->assertSame(1000, $balances[$childB]['total_balance_cents']);
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
