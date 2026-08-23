<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EmailTemplateManagementTest extends TestCase
{
    private const KEY = EmailTemplateManagement::KEY_INQUIRY_FAMILY;

    protected function setUp(): void
    {
        test_reset_all();
        // email_templates is seeded reference data (like settings and
        // instruments), so it is not truncated. Each test writes its own
        // baseline instead, and cannot be affected by what a sibling left.
        pdo()->prepare('UPDATE email_templates SET subject = ?, body_markdown = ?, updated_by_user_id = NULL WHERE template_key = ?')
            ->execute(['Hello {{parent_first_name}}', 'Hi {{parent_first_name}}, about {{student_first_name}}.', self::KEY]);
    }

    // ===== Rendering =====

    public function testRenderSubstitutesSuppliedVariables(): void
    {
        $rendered = EmailTemplateManagement::render(self::KEY, [
            'parent_first_name' => 'Maria', 'student_first_name' => 'Luis',
        ]);

        $this->assertSame('Hello Maria', $rendered['subject']);
        $this->assertSame("<p>Hi Maria, about Luis.</p>\n", $rendered['body_html']);
    }

    public function testUnsuppliedPlaceholderRendersEmptyRatherThanLeaking(): void
    {
        $rendered = EmailTemplateManagement::render(self::KEY, ['parent_first_name' => 'Maria']);

        $this->assertSame("<p>Hi Maria, about .</p>\n", $rendered['body_html']);
        $this->assertStringNotContainsString('{{', $rendered['body_html']);
    }

    public function testRenderOnAnUnknownKeyReturnsNull(): void
    {
        $this->assertNull(EmailTemplateManagement::render('no_such_template', []));
    }

    public function testSubstitutedValuesCannotInjectMarkup(): void
    {
        $rendered = EmailTemplateManagement::render(self::KEY, [
            'parent_first_name' => '<script>alert(1)</script>',
            'student_first_name' => 'Luis',
        ]);

        $this->assertStringNotContainsString('<script', $rendered['body_html']);
        $this->assertStringContainsString('&lt;script&gt;', $rendered['body_html']);
    }

    public function testNewlinesInAValueBecomeLineBreaksInTheBody(): void
    {
        $rendered = EmailTemplateManagement::render(self::KEY, [
            'parent_first_name' => 'Maria', 'student_first_name' => "Luis\nand Ana",
        ]);
        $this->assertStringContainsString('<br', $rendered['body_html']);
    }

    public function testSubjectCannotCarryAnInjectedHeader(): void
    {
        $rendered = EmailTemplateManagement::render(self::KEY, [
            'parent_first_name' => "Maria\r\nBcc: attacker@example.com",
        ]);

        $this->assertStringNotContainsString("\r", $rendered['subject']);
        $this->assertStringNotContainsString("\n", $rendered['subject']);
        $this->assertSame('Hello Maria Bcc: attacker@example.com', $rendered['subject']);
    }

    public function testSubjectIsNotHtmlEscaped(): void
    {
        // A subject line is plain text; escaping it would put "&amp;" in front
        // of a family.
        $rendered = EmailTemplateManagement::render(self::KEY, ['parent_first_name' => 'Smith & Sons']);
        $this->assertSame('Hello Smith & Sons', $rendered['subject']);
    }

    // ===== Editing =====

    public function testUpdateStoresTheWordingAndWhoChangedIt(): void
    {
        $ctx = fx_admin_ctx();
        EmailTemplateManagement::update($ctx, self::KEY, 'A new subject', 'New body');

        $template = EmailTemplateManagement::find(self::KEY);
        $this->assertSame('A new subject', $template['subject']);
        $this->assertSame('New body', $template['body_markdown']);
        $this->assertSame($ctx->id, (int)$template['updated_by_user_id']);
        $this->assertNotSame('', trim((string)$template['updated_by_first_name']));

        $logged = pdo()->query("SELECT COUNT(*) FROM activity_log WHERE action_type = 'email_template.updated'")->fetchColumn();
        $this->assertSame(1, (int)$logged);
    }

    public function testUpdateRefusesEmptyWordingAndUnknownKeys(): void
    {
        $ctx = fx_admin_ctx();
        foreach ([['   ', '<p>body</p>'], ['Subject', '  ']] as [$subject, $body]) {
            try {
                EmailTemplateManagement::update($ctx, self::KEY, $subject, $body);
                $this->fail('Expected an exception for empty wording');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        EmailTemplateManagement::update($ctx, 'no_such_template', 'Subject', 'Body');
    }

    public function testUpdateRequiresAnAdmin(): void
    {
        $this->expectException(RuntimeException::class);
        EmailTemplateManagement::update(
            new UserContext(fx_user('Nel', 'Nobody'), false), self::KEY, 'Subject', 'Body'
        );
    }

    // ===== Placeholder checking =====

    public function testUnknownPlaceholdersFlagsOnlyWhatTheCodeCannotFill(): void
    {
        $unknown = EmailTemplateManagement::unknownPlaceholders(
            self::KEY, 'Hi {{parent_first_name}}', '<p>{{nope}} and {{student_first_name}}</p>'
        );
        $this->assertSame(['nope'], array_values($unknown));
    }

    // ===== The inquiry variable map =====

    public function testInquiryVariablesSuppliesEveryDeclaredVariable(): void
    {
        $leadId = LeadManagement::createInquiryLead(null, [
            'first_name' => 'Maria', 'last_name' => 'Delgado',
            'email' => 'maria@example.com', 'phone' => '718-555-0142',
            'newsletter_opt_in' => 1, 'sms_consent' => 1,
            'address_country' => 'United States', 'address_street_1' => '1234 Grand Concourse',
            'address_city' => 'Bronx', 'address_state' => 'NY', 'address_zip' => '10456',
        ], [
            'first_name' => 'Luis', 'last_name' => 'Delgado', 'age' => 9,
            'enrollment_status' => 'new',
            'instruments_of_interest' => ['Piano', 'Other'], 'instruments_other' => 'Harp',
        ]);
        LeadManagement::updateInquiryDetails(null, $leadId, [
            'semester_label' => 'Fall 2026',
            'owned_instruments' => ['Piano'],
            'music_background' => 'Two years of recorder.',
            'theory_program_interest' => 'need_info',
            'theory_knowledge' => 'beginner',
            'referral_source' => 'Word of Mouth',
            'comments' => 'Saturday mornings?',
        ]);

        $lead = LeadManagement::findLead($leadId);
        $student = LeadManagement::studentsForLead($leadId)[0];
        $variables = EmailTemplateManagement::inquiryVariables($lead, $student);

        // A template that names a variable the code never passes would render
        // a blank in front of a family — so every declared name must exist.
        foreach (EmailTemplateManagement::availableVariables(self::KEY) as $name) {
            $this->assertArrayHasKey($name, $variables, $name . ' is declared but never supplied');
        }

        $this->assertSame('Maria', $variables['parent_first_name']);
        $this->assertSame('Luis', $variables['student_first_name']);
        $this->assertSame('9', $variables['student_age']);
        $this->assertSame('New student', $variables['enrollment_status']);
        $this->assertSame('Piano, Other: Harp', $variables['instruments_of_interest']);
        $this->assertSame('Fall 2026', $variables['semester_label']);
        $this->assertSame('Need more information', $variables['theory_program_interest']);
        $this->assertSame('Beginner (Note reading, Clefs, Basic Rhythm)', $variables['theory_knowledge']);
        $this->assertSame('Yes', $variables['newsletter_opt_in']);
        $this->assertStringContainsString('1234 Grand Concourse', $variables['mailing_address']);
        $this->assertStringContainsString('Bronx', $variables['mailing_address']);
        $this->assertStringContainsString('/admin/lead.php?id=' . $leadId, $variables['lead_admin_url']);
    }

    public function testSampleVariablesCoverEveryDeclaredVariable(): void
    {
        $samples = EmailTemplateManagement::sampleVariables(self::KEY);
        foreach (EmailTemplateManagement::availableVariables(self::KEY) as $name) {
            $this->assertArrayHasKey($name, $samples, $name);
            $this->assertNotSame('', (string)$samples[$name], $name);
        }
    }

    public function testShippedTemplatesRenderWithoutLeftoverPlaceholders(): void
    {
        foreach ([EmailTemplateManagement::KEY_INQUIRY_FAMILY, EmailTemplateManagement::KEY_INQUIRY_STAFF] as $key) {
            $template = EmailTemplateManagement::find($key);
            $this->assertNotNull($template, $key . ' should ship with schema.sql');
            $this->assertSame([], EmailTemplateManagement::unknownPlaceholders(
                $key, (string)$template['subject'], (string)$template['body_markdown']
            ), $key . ' uses a variable the code does not supply');

            $rendered = EmailTemplateManagement::render($key, EmailTemplateManagement::sampleVariables($key));
            $this->assertStringNotContainsString('{{', $rendered['body_html'], $key);
            $this->assertStringNotContainsString('{{', $rendered['subject'], $key);
        }
    }
}
