<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InquiryManagementTest extends TestCase
{
    protected function setUp(): void
    {
        test_reset_all();
    }

    // ===== Fixtures =====

    private function contact(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Maria', 'last_name' => 'Delgado',
            'email' => 'maria.delgado@example.com',
            'confirm_email' => 'maria.delgado@example.com',
            'phone' => '718-555-0142',
            'newsletter_opt_in' => true, 'sms_consent' => false,
        ];
    }

    private function address(array $overrides = []): array
    {
        return $overrides + [
            'address_country' => 'United States',
            'address_street_1' => '1234 Grand Concourse',
            'address_street_2' => 'Apt 5B',
            'address_city' => 'Bronx',
            'address_state' => 'NY',
            'address_province' => '',
            'address_zip' => '10456',
        ];
    }

    private function student(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Luis', 'last_name' => 'Delgado', 'age' => 9,
            'enrollment_status' => 'new',
            'instruments_of_interest' => ['Piano', 'Violin'],
            'instruments_other' => '',
        ];
    }

    // ===== Contact validation =====

    public function testValidateContactAcceptsAGoodPayload(): void
    {
        $this->assertNull(InquiryManagement::validateContact($this->contact()));
    }

    public function testValidateContactCatchesEachProblem(): void
    {
        $cases = [
            'blank name' => ['first_name' => ''],
            'malformed email' => ['email' => 'not-an-email', 'confirm_email' => 'not-an-email'],
            'mismatched confirmation' => ['confirm_email' => 'other@example.com'],
            'short phone' => ['phone' => '555'],
        ];
        foreach ($cases as $label => $overrides) {
            $error = InquiryManagement::validateContact($this->contact($overrides));
            $this->assertNotNull($error, $label);
            $this->assertNotSame('', $error, $label);
        }
    }

    public function testConfirmEmailComparisonIgnoresCase(): void
    {
        // Someone whose keyboard capitalised the first letter has not made a
        // mistake worth stopping them for.
        $this->assertNull(InquiryManagement::validateContact($this->contact([
            'email' => 'maria.delgado@example.com',
            'confirm_email' => 'Maria.Delgado@Example.com',
        ])));
    }

    // ===== Address validation =====

    public function testValidateAddressAcceptsAUsAddress(): void
    {
        $this->assertNull(InquiryManagement::validateAddress($this->address()));
        $this->assertNull(InquiryManagement::validateAddress($this->address(['address_zip' => '10456-1234'])));
    }

    public function testValidateAddressEnforcesUsRules(): void
    {
        $cases = [
            'no street' => ['address_street_1' => ''],
            'no city' => ['address_city' => ''],
            'no state' => ['address_state' => ''],
            'state off the list' => ['address_state' => 'ZZ'],
            'no zip' => ['address_zip' => ''],
            'zip is not a zip' => ['address_zip' => 'abcde'],
        ];
        foreach ($cases as $label => $overrides) {
            $this->assertNotNull(InquiryManagement::validateAddress($this->address($overrides)), $label);
        }
    }

    public function testValidateAddressIsLenientOutsideTheUs(): void
    {
        $abroad = $this->address([
            'address_country' => 'Canada',
            'address_state' => '', 'address_province' => '',
            'address_zip' => 'M5V 2T6',
        ]);
        // No province and a postal code that is not a ZIP are both fine.
        $this->assertNull(InquiryManagement::validateAddress($abroad));

        // Street, city and postal code are still asked for everywhere.
        $this->assertNotNull(InquiryManagement::validateAddress(array_merge($abroad, ['address_street_1' => ''])));
        $this->assertNotNull(InquiryManagement::validateAddress(array_merge($abroad, ['address_city' => ''])));
        $this->assertNotNull(InquiryManagement::validateAddress(array_merge($abroad, ['address_zip' => ''])));
    }

    public function testNormalizeAddressPicksStateOrProvinceByCountry(): void
    {
        $us = InquiryManagement::normalizeAddress($this->address([
            'address_state' => 'ny', 'address_province' => 'Ontario',
        ]));
        $this->assertSame('NY', $us['address_state'], 'the US dropdown wins, upper-cased');

        $abroad = InquiryManagement::normalizeAddress($this->address([
            'address_country' => 'Canada', 'address_state' => 'NY', 'address_province' => 'Ontario',
        ]));
        $this->assertSame('Ontario', $abroad['address_state'], 'the free-text province wins abroad');

        $this->assertNull(
            InquiryManagement::normalizeAddress($this->address(['address_street_2' => '  ']))['address_street_2']
        );
    }

    // ===== Student validation =====

    public function testValidateStudentCatchesEachProblem(): void
    {
        $cases = [
            'blank name' => ['last_name' => ''],
            'age zero' => ['age' => 0],
            'impossible age' => ['age' => 200],
            'unknown enrollment status' => ['enrollment_status' => 'maybe'],
            'no instrument chosen' => ['instruments_of_interest' => []],
            'nothing recognisable chosen' => ['instruments_of_interest' => ['Kazoo']],
            'Other with no specifics' => ['instruments_of_interest' => ['Other'], 'instruments_other' => ' '],
        ];
        foreach ($cases as $label => $overrides) {
            $this->assertNotNull(InquiryManagement::validateStudent($this->student($overrides)), $label);
        }
        $this->assertNull(InquiryManagement::validateStudent($this->student()));
        $this->assertNull(InquiryManagement::validateStudent($this->student([
            'instruments_of_interest' => ['Other'], 'instruments_other' => 'Harp',
        ])));
    }

    // ===== Details validation =====

    public function testValidateDetailsRequiresATermFromTheConfiguredList(): void
    {
        $options = ['Fall 2026', 'Spring 2027'];
        $this->assertNotNull(InquiryManagement::validateDetails([], $options));
        $this->assertNotNull(InquiryManagement::validateDetails(['semester_label' => 'Winter 3000'], $options));
        $this->assertNull(InquiryManagement::validateDetails(['semester_label' => 'Fall 2026'], $options));
    }

    public function testValidateDetailsChecksTheChoiceFieldsOnly(): void
    {
        $options = ['Fall 2026'];
        $base = ['semester_label' => 'Fall 2026'];

        $this->assertNotNull(InquiryManagement::validateDetails(
            $base + ['owned_instruments' => ['Other'], 'owned_instruments_other' => ''], $options
        ));
        $this->assertNotNull(InquiryManagement::validateDetails($base + ['theory_knowledge' => 'expert'], $options));
        $this->assertNotNull(InquiryManagement::validateDetails($base + ['referral_source' => 'Skywriting'], $options));

        // Every free-text answer is genuinely optional.
        $this->assertNull(InquiryManagement::validateDetails(
            $base + ['music_background' => '', 'comments' => '', 'theory_program_interest' => ''], $options
        ));
    }

    // ===== Progressive save =====

    public function testStartInquirySavesContactSoStaffCanFollowUp(): void
    {
        $id = InquiryManagement::startInquiry(null, $this->contact());

        $row = InquiryManagement::find($id);
        $this->assertSame('Maria', $row['first_name']);
        $this->assertSame('maria.delgado@example.com', $row['email']);
        $this->assertSame(1, (int)$row['newsletter_opt_in']);
        $this->assertSame(0, (int)$row['sms_consent']);
        $this->assertSame(1, (int)$row['last_step_completed']);
        $this->assertNull($row['address_city'], 'the address has not been asked for yet');
    }

    public function testStartInquiryRejectsAPayloadItWouldNotBeAbleToActOn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InquiryManagement::startInquiry(null, $this->contact(['email' => 'nope', 'confirm_email' => 'nope']));
    }

    public function testUpdateContactEditsInPlaceRatherThanForking(): void
    {
        $id = InquiryManagement::startInquiry(null, $this->contact());
        InquiryManagement::updateContact(null, $id, $this->contact([
            'email' => 'new.address@example.com', 'confirm_email' => 'new.address@example.com',
        ]));

        $this->assertSame(1, InquiryManagement::countIncomplete());
        $this->assertSame('new.address@example.com', InquiryManagement::find($id)['email']);
    }

    public function testSaveAddressAdvancesTheRecordedStage(): void
    {
        $id = InquiryManagement::startInquiry(null, $this->contact());
        InquiryManagement::saveAddress(null, $id, $this->address());

        $row = InquiryManagement::find($id);
        $this->assertSame('1234 Grand Concourse', $row['address_street_1']);
        $this->assertSame('Bronx', $row['address_city']);
        $this->assertSame('NY', $row['address_state']);
        $this->assertSame(2, (int)$row['last_step_completed']);
    }

    // ===== Registration-wizard drop-off drafts =====

    private function family(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Rosa', 'last_name' => 'Ramos',
            'email' => 'rosa.ramos@example.com', 'phone' => '718-555-0199',
            'sms_consent' => 1,
            'address_street_1' => '900 Walton Ave', 'address_street_2' => '',
            'address_city' => 'Bronx', 'address_state' => 'NY', 'address_zip' => '10451',
        ];
    }

    public function testRegistrationDraftIsSavedUpdatedAndStaged(): void
    {
        $id = InquiryManagement::recordRegistrationDraft(null, null, $this->family());
        $row = InquiryManagement::find($id);
        $this->assertSame('registration', $row['source']);
        $this->assertSame('rosa.ramos@example.com', $row['email']);
        $this->assertSame('900 Walton Ave', $row['address_street_1']);
        $this->assertSame(2, (int)$row['last_step_completed'], 'family step = contact and address');

        // Going Back and resubmitting updates in place, never forks.
        $again = InquiryManagement::recordRegistrationDraft(null, $id, $this->family(['phone' => '718-555-0200']));
        $this->assertSame($id, $again);
        $this->assertSame(1, InquiryManagement::countIncomplete());
        $this->assertSame('718-555-0200', InquiryManagement::find($id)['phone']);

        // Later steps move the marker forward, never backward.
        InquiryManagement::recordRegistrationStep($id, 4);
        InquiryManagement::recordRegistrationStep($id, 3);
        $this->assertSame(4, (int)InquiryManagement::find($id)['last_step_completed']);
    }

    public function testFinishRegistrationDraftCarriesNotesToTheLeadAndDeletes(): void
    {
        $id = InquiryManagement::recordRegistrationDraft(null, null, $this->family());
        $admin = fx_admin_ctx();
        InquiryManagement::addNote($admin, $id, 'Left a voicemail about the missing payment step.');

        $leadId = LeadManagement::createLead(null, fx_semester(fx_admin_ctx()), [
            'first_name' => 'Rosa', 'last_name' => 'Ramos',
            'email' => 'rosa.ramos@example.com', 'phone' => '718-555-0199',
        ], [['first_name' => 'Lucia', 'last_name' => 'Ramos', 'instrument' => 'Piano', 'lesson_length_minutes' => 30]], [], false);

        InquiryManagement::finishRegistrationDraft(null, $id, $leadId);

        $this->assertNull(InquiryManagement::find($id), 'the lead is the record now');
        $notes = LeadManagement::notesForLead($leadId);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('missing payment step', $notes[0]['body']);

        // A stale draft id (double submit) is a quiet no-op.
        InquiryManagement::finishRegistrationDraft(null, $id, $leadId);
        $this->assertCount(1, LeadManagement::notesForLead($leadId));
    }

    public function testPromoteToLeadCreatesTheLeadAndRemovesTheDraft(): void
    {
        $id = InquiryManagement::startInquiry(null, $this->contact());
        InquiryManagement::saveAddress(null, $id, $this->address());

        $leadId = InquiryManagement::promoteToLead(null, $id, $this->student());

        $lead = LeadManagement::findLead($leadId);
        $this->assertSame('inquiry', $lead['source']);
        $this->assertSame('maria.delgado@example.com', $lead['email']);
        $this->assertSame('Bronx', $lead['address_city'], 'the address carries across');
        $this->assertSame(1, (int)$lead['newsletter_opt_in']);

        $students = LeadManagement::studentsForLead($leadId);
        $this->assertCount(1, $students);
        $this->assertSame(['Piano', 'Violin'], json_decode($students[0]['instruments_of_interest'], true));

        // A row in incomplete_inquiries means "never finished" — so it is gone.
        $this->assertNull(InquiryManagement::find($id));
        $this->assertSame(0, InquiryManagement::countIncomplete());
    }

    public function testPromoteToLeadOnAMissingDraftLeavesNoLeadBehind(): void
    {
        try {
            InquiryManagement::promoteToLead(null, 999999, $this->student());
            $this->fail('Expected an exception for a draft that is not there');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM leads')->fetchColumn());
    }

    public function testPromoteToLeadValidatesBeforeTouchingAnything(): void
    {
        $id = InquiryManagement::startInquiry(null, $this->contact());
        try {
            InquiryManagement::promoteToLead(null, $id, $this->student(['instruments_of_interest' => []]));
            $this->fail('Expected an exception for a student with no instrument');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM leads')->fetchColumn());
        $this->assertNotNull(InquiryManagement::find($id), 'the draft survives so staff can still call');
    }

    // ===== Reads and admin actions =====

    public function testListIncompleteIsNewestFirstAndPages(): void
    {
        $ids = [];
        foreach (['a', 'b', 'c'] as $letter) {
            $ids[] = InquiryManagement::startInquiry(null, $this->contact([
                'first_name' => strtoupper($letter),
                'email' => $letter . '@example.com', 'confirm_email' => $letter . '@example.com',
            ]));
        }

        $this->assertSame(3, InquiryManagement::countIncomplete());
        $firstPage = InquiryManagement::listIncomplete(2, 0);
        $secondPage = InquiryManagement::listIncomplete(2, 2);
        $this->assertCount(2, $firstPage);
        $this->assertCount(1, $secondPage);
        $this->assertSame(end($ids), (int)$firstPage[0]['id'], 'newest first');
    }

    public function testAdminActionsRequireAnAdmin(): void
    {
        $id = InquiryManagement::startInquiry(null, $this->contact());
        $notAnAdmin = new UserContext(fx_user('Nel', 'Nobody'), false);

        foreach ([
            'addNote' => fn() => InquiryManagement::addNote($notAnAdmin, $id, 'sneaky'),
            'delete' => fn() => InquiryManagement::delete($notAnAdmin, $id),
            'saveContactAndAddress' => fn() => InquiryManagement::saveContactAndAddress(
                $notAnAdmin, $id, $this->contact(), $this->address()
            ),
            'completeAsLead' => fn() => InquiryManagement::completeAsLead(
                $notAnAdmin, $id, $this->contact(), $this->address(), $this->student(), [], ['Fall 2026']
            ),
        ] as $label => $call) {
            try {
                $call();
                $this->fail("Expected $label to refuse a non-admin");
            } catch (RuntimeException $e) {
                $this->assertSame('Admins only', $e->getMessage(), $label);
            }
        }
        $this->assertNotNull(InquiryManagement::find($id));
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM leads')->fetchColumn());
    }

    public function testAdminCanDelete(): void
    {
        $ctx = fx_admin_ctx();
        $id = InquiryManagement::startInquiry(null, $this->contact());

        InquiryManagement::delete($ctx, $id);
        $this->assertNull(InquiryManagement::find($id));
    }

    // ===== Notes =====

    public function testNotesAppendWithAuthorAndTimestamp(): void
    {
        $ctx = fx_admin_ctx();
        $id = InquiryManagement::startInquiry(null, $this->contact());

        InquiryManagement::addNote($ctx, $id, 'Left a voicemail 8/4.');
        InquiryManagement::addNote($ctx, $id, 'Reached Maria — calling back Monday.');

        $notes = InquiryManagement::notesFor($id);
        $this->assertCount(2, $notes, 'notes append, they never replace each other');
        $this->assertSame('Left a voicemail 8/4.', $notes[0]['body']);
        $this->assertSame('Reached Maria — calling back Monday.', $notes[1]['body']);
        $this->assertSame($ctx->id, (int)$notes[0]['created_by_user_id']);
        $this->assertNotSame('', trim((string)$notes[0]['author_first_name']));
    }

    public function testAddNoteRejectsAnEmptyBody(): void
    {
        $ctx = fx_admin_ctx();
        $id = InquiryManagement::startInquiry(null, $this->contact());

        $this->expectException(InvalidArgumentException::class);
        InquiryManagement::addNote($ctx, $id, '   ');
    }

    public function testNotesTravelToTheLeadAsOneEntry(): void
    {
        $ctx = fx_admin_ctx();
        $id = InquiryManagement::startInquiry(null, $this->contact());
        InquiryManagement::addNote($ctx, $id, 'Left a voicemail 8/4.');
        InquiryManagement::addNote($ctx, $id, 'Reached Maria — wants Saturdays.');

        $leadId = InquiryManagement::promoteToLead($ctx, $id, $this->student());

        $leadNotes = LeadManagement::notesForLead($leadId);
        $this->assertCount(1, $leadNotes, 'the chase arrives as a single entry');
        $this->assertStringContainsString('Left a voicemail 8/4.', $leadNotes[0]['body']);
        $this->assertStringContainsString('Reached Maria — wants Saturdays.', $leadNotes[0]['body']);
        $this->assertStringContainsString('Notes from the uncompleted form', $leadNotes[0]['body']);
        // Each keeps the name it was written under.
        $this->assertStringContainsString('Ada Admin', $leadNotes[0]['body']);
        $this->assertSame($ctx->id, (int)$leadNotes[0]['created_by_user_id']);
    }

    public function testPromotingWithNoNotesLeavesTheLeadHistoryEmpty(): void
    {
        $id = InquiryManagement::startInquiry(null, $this->contact());
        $leadId = InquiryManagement::promoteToLead(null, $id, $this->student());

        $this->assertSame([], LeadManagement::notesForLead($leadId));
    }

    public function testNotesFromAdminsSurviveAFamilyFinishingTheFormThemselves(): void
    {
        // The public flow has nobody signed in, so the carried note has no
        // author — but the original names are inside its body.
        $ctx = fx_admin_ctx();
        $id = InquiryManagement::startInquiry(null, $this->contact());
        InquiryManagement::addNote($ctx, $id, 'Spoke to them at the open house.');

        $leadId = InquiryManagement::promoteToLead(null, $id, $this->student());

        $leadNotes = LeadManagement::notesForLead($leadId);
        $this->assertCount(1, $leadNotes);
        $this->assertNull($leadNotes[0]['created_by_user_id']);
        $this->assertStringContainsString('Spoke to them at the open house.', $leadNotes[0]['body']);
    }

    // ===== Admin: finishing a form on the family's behalf =====

    public function testSaveContactAndAddressKeepsEditsWithoutFinishing(): void
    {
        $ctx = fx_admin_ctx();
        $id = InquiryManagement::startInquiry(null, $this->contact());

        InquiryManagement::saveContactAndAddress(
            $ctx, $id,
            $this->contact(['first_name' => 'Mariana', 'phone' => '718-555-9999']),
            $this->address()
        );

        $row = InquiryManagement::find($id);
        $this->assertNotNull($row, 'saving is not finishing');
        $this->assertSame('Mariana', $row['first_name']);
        $this->assertSame('718-555-9999', $row['phone']);
        $this->assertSame('Bronx', $row['address_city']);
        $this->assertSame(2, (int)$row['last_step_completed']);
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM leads')->fetchColumn());
    }

    public function testAdminNeedsNoEmailConfirmationOrAddress(): void
    {
        $ctx = fx_admin_ctx();
        $id = InquiryManagement::startInquiry(null, $this->contact());

        // No confirm_email (an admin transcribing has no typo to guard) and no
        // address at all (they may only have had a phone call).
        $contact = $this->contact();
        unset($contact['confirm_email']);
        InquiryManagement::saveContactAndAddress($ctx, $id, $contact, []);

        $row = InquiryManagement::find($id);
        $this->assertNull($row['address_city']);
        $this->assertSame(1, (int)$row['last_step_completed'], 'still contact-only');
    }

    public function testAHalfFilledAddressIsStillHeldToTheRules(): void
    {
        $ctx = fx_admin_ctx();
        $id = InquiryManagement::startInquiry(null, $this->contact());

        $this->expectException(InvalidArgumentException::class);
        InquiryManagement::saveContactAndAddress($ctx, $id, $this->contact(), $this->address([
            'address_zip' => '', // started an address, so it has to be a real one
        ]));
    }

    public function testCompleteAsLeadFinishesTheFormAndCarriesEverything(): void
    {
        $ctx = fx_admin_ctx();
        $id = InquiryManagement::startInquiry(null, $this->contact());
        InquiryManagement::addNote($ctx, $id, 'Called back on 8/5.');

        $leadId = InquiryManagement::completeAsLead(
            $ctx, $id,
            $this->contact(['first_name' => 'Mariana']),
            $this->address(),
            $this->student(),
            [
                'semester_label' => 'Fall 2026',
                'owned_instruments' => ['Piano'],
                'theory_knowledge' => 'beginner',
                'referral_source' => 'Word of Mouth',
            ],
            ['Fall 2026', 'Spring 2027']
        );

        $lead = LeadManagement::findLead($leadId);
        $this->assertSame('inquiry', $lead['source']);
        $this->assertSame('Mariana', $lead['parent_first_name'], 'the edit made on the call carries across');
        $this->assertSame('Bronx', $lead['address_city']);
        $this->assertSame('Fall 2026', $lead['semester_label']);
        $this->assertSame('beginner', $lead['theory_knowledge']);
        $this->assertSame('Word of Mouth', $lead['referral_source']);

        $this->assertCount(1, LeadManagement::studentsForLead($leadId));
        $this->assertCount(1, LeadManagement::notesForLead($leadId));
        $this->assertNull(InquiryManagement::find($id), 'the uncompleted form is gone');
    }

    public function testCompleteAsLeadWorksWithNothingButAStudent(): void
    {
        $ctx = fx_admin_ctx();
        $id = InquiryManagement::startInquiry(null, $this->contact());

        // No address, no page-4 answers — a short phone call is enough.
        $leadId = InquiryManagement::completeAsLead(
            $ctx, $id, $this->contact(), [], $this->student(), [], ['Fall 2026']
        );

        $lead = LeadManagement::findLead($leadId);
        $this->assertNull($lead['address_city']);
        $this->assertNull($lead['semester_label']);
        $this->assertNull($lead['theory_knowledge']);
        $this->assertNull(InquiryManagement::find($id));
    }

    public function testCompleteAsLeadWritesNothingWhenItCannotFinish(): void
    {
        $ctx = fx_admin_ctx();
        $id = InquiryManagement::startInquiry(null, $this->contact());

        $cases = [
            'no student' => [$this->student(['first_name' => '']), []],
            'bad term' => [$this->student(), ['semester_label' => 'Winter 3000']],
            'unknown theory level' => [$this->student(), ['theory_knowledge' => 'expert']],
        ];
        foreach ($cases as $label => [$student, $details]) {
            try {
                InquiryManagement::completeAsLead(
                    $ctx, $id,
                    $this->contact(['first_name' => 'Edited']),
                    $this->address(), $student, $details, ['Fall 2026']
                );
                $this->fail("Expected $label to be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage(), $label);
            }
        }

        // Nothing was written by any of the failed attempts.
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM leads')->fetchColumn());
        $row = InquiryManagement::find($id);
        $this->assertNotNull($row);
        $this->assertSame('Maria', $row['first_name'], 'a refused attempt does not half-save the edits');
    }

    public function testValidateDetailsCanSkipTheTermForAnAdmin(): void
    {
        $this->assertNotNull(InquiryManagement::validateDetails([], ['Fall 2026'], true));
        $this->assertNull(InquiryManagement::validateDetails([], ['Fall 2026'], false));
        // A term that is given still has to be one of ours.
        $this->assertNotNull(
            InquiryManagement::validateDetails(['semester_label' => 'Winter 3000'], ['Fall 2026'], false)
        );
    }
}
