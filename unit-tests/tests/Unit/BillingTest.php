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
        // Deterministic pricing for every test.
        Settings::set('registration_cost', '50.00');
        Settings::set('semester_lesson_cost', '300.00');
        Settings::set('recital_fee', '25.00');
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

    public function testSettingsCostHelpersParseDollars(): void
    {
        $this->assertSame(5000, Settings::registrationCostCents());
        $this->assertSame(30000, Settings::semesterLessonCostCents());
        $this->assertSame(2500, Settings::recitalFeeCents());
        Settings::set('recital_fee', '$1,234.56');
        $this->assertSame(123456, Settings::recitalFeeCents());
    }

    public function testConfirmationPostsAllThreeChargesOnce(): void
    {
        $student = fx_student();
        [$semesterId] = $this->confirmReservationFor($student);

        $this->assertSame(37500, Billing::balanceForStudentCents($student));
        $this->assertSame(37500, Billing::balanceForStudentSemesterCents($student, $semesterId));

        // Idempotent — reposting (e.g. a second instrument confirmed) adds nothing.
        Billing::postSemesterConfirmationCharges($this->ctx, $student, $semesterId);
        $this->assertSame(37500, Billing::balanceForStudentCents($student));
        $this->assertCount(3, Billing::ledgerForStudent($student, $semesterId));
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
}
