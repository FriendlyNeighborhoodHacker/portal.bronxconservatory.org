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
