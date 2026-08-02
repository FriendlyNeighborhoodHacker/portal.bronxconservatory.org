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

        try {
            InquiryManagement::saveAdminNotes($notAnAdmin, $id, 'sneaky');
            $this->fail('Expected saveAdminNotes to refuse a non-admin');
        } catch (RuntimeException $e) {
            $this->assertSame('Admins only', $e->getMessage());
        }
        try {
            InquiryManagement::delete($notAnAdmin, $id);
            $this->fail('Expected delete to refuse a non-admin');
        } catch (RuntimeException $e) {
            $this->assertSame('Admins only', $e->getMessage());
        }
        $this->assertNotNull(InquiryManagement::find($id));
    }

    public function testAdminCanNoteAndDelete(): void
    {
        $ctx = fx_admin_ctx();
        $id = InquiryManagement::startInquiry(null, $this->contact());

        InquiryManagement::saveAdminNotes($ctx, $id, '  Left a voicemail 8/4.  ');
        $this->assertSame('Left a voicemail 8/4.', InquiryManagement::find($id)['admin_notes']);

        InquiryManagement::delete($ctx, $id);
        $this->assertNull(InquiryManagement::find($id));
    }
}
