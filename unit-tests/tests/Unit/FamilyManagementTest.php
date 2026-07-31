<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FamilyManagementTest extends TestCase
{
    protected function setUp(): void
    {
        test_reset_all();
    }

    private function registrationArgs(string $email = 'maria@example.com'): array
    {
        return [
            'parent' => [
                'first_name' => 'Maria', 'last_name' => 'Martinez', 'email' => $email,
                'cell_phone' => '718-555-1234', 'preferred_contact_method' => 'phone',
                'address_city' => 'Bronx', 'address_state' => 'NY',
                'emergency_contact_name' => 'Jose', 'emergency_contact_phone' => '718-555-9999',
                'relationship' => 'mother',
            ],
            'students' => [
                ['first_name' => 'Sofia', 'last_name' => 'Martinez', 'date_of_birth' => '2015-03-12',
                 'experience_level' => 'none', 'school_name' => 'PS 123', 'grade' => '5', 'instrument_ids' => [1]],
                ['first_name' => 'Diego', 'last_name' => 'Martinez', 'experience_level' => 'beginner',
                 'instrument_ids' => [1, 4]],
            ],
            'prefs' => [
                'preferred_days' => ['Sat'], 'time_window' => ['morning'],
                'preferred_location_id' => 2, 'teacher_gender_pref' => 'female',
                'constraints_text' => 'siblings need back-to-back', 'how_heard' => 'school',
                'consent_terms' => 1, 'consent_liability' => 1,
            ],
        ];
    }

    public function testTalkFirstRegistrationCreatesEverything(): void
    {
        $args = $this->registrationArgs();
        $result = FamilyManagement::createFamilyFromRegistration(null, $args['parent'], $args['students'], $args['prefs'], 'talk_first');

        $family = FamilyManagement::getFamilyDetail($result['family_id']);
        $this->assertSame('needs_follow_up', $family['status']);
        $this->assertSame('Martinez', $family['family_name']);
        $this->assertSame($result['parent_user_id'], (int)$family['primary_parent_user_id']);
        $this->assertCount(2, $family['students']);
        $this->assertSame(['Piano'], $family['students'][0]['instruments']);
        $this->assertSame(['Piano', 'Violin'], $family['students'][1]['instruments']);

        // Parenthood links + denormalized family_id on every member.
        $this->assertCount(2, StudentTeacherManagement::childrenOfParent($result['parent_user_id']));
        $familyIds = pdo()->query('SELECT DISTINCT family_id FROM users WHERE family_id IS NOT NULL')->fetchAll();
        $this->assertCount(1, $familyIds);

        // Preferences captured on the submission row.
        $this->assertSame('Sat', $family['submission']['preferred_days']);
        $this->assertSame('female', $family['submission']['teacher_gender_pref']);
        $this->assertSame('talk_first', $family['submission']['path']);
    }

    public function testCompleteEnrollmentPathIsReadyToEnroll(): void
    {
        $args = $this->registrationArgs();
        $result = FamilyManagement::createFamilyFromRegistration(null, $args['parent'], $args['students'], $args['prefs'], 'complete_enrollment');
        $family = FamilyManagement::getFamilyDetail($result['family_id']);
        $this->assertSame('ready_to_enroll', $family['status']);
    }

    public function testDuplicateEmailWithPasswordThrows(): void
    {
        pdo()->exec("INSERT INTO users (first_name, last_name, email, password_hash) VALUES ('Old', 'Account', 'maria@example.com', 'some-hash')");
        $args = $this->registrationArgs();
        $this->expectException(DuplicateAccountException::class);
        FamilyManagement::createFamilyFromRegistration(null, $args['parent'], $args['students'], $args['prefs'], 'talk_first');
    }

    public function testPasswordlessExistingEmailIsReused(): void
    {
        pdo()->exec("INSERT INTO users (first_name, last_name, email, password_hash) VALUES ('Maria', 'Martinez', 'maria@example.com', '')");
        $existingId = (int)pdo()->lastInsertId();
        $args = $this->registrationArgs();
        $result = FamilyManagement::createFamilyFromRegistration(null, $args['parent'], $args['students'], $args['prefs'], 'talk_first');
        $this->assertSame($existingId, $result['parent_user_id']);
    }

    public function testRegistrationWithNoStudentsThrowsAndRollsBack(): void
    {
        $args = $this->registrationArgs();
        try {
            FamilyManagement::createFamilyFromRegistration(null, $args['parent'], [], $args['prefs'], 'talk_first');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            // The whole transaction must roll back — no family, no parent user.
            $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM families')->fetchColumn());
            $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
        }
    }

    public function testFamilySummaryLine(): void
    {
        $args = $this->registrationArgs();
        $result = FamilyManagement::createFamilyFromRegistration(null, $args['parent'], $args['students'], $args['prefs'], 'talk_first');
        $family = FamilyManagement::getFamilyDetail($result['family_id']);
        $line = FamilyManagement::familySummaryLine($family);
        $this->assertStringContainsString('Martinez family', $line);
        $this->assertStringContainsString('2 students', $line);
        $this->assertStringContainsString('Piano', $line);
        $this->assertStringContainsString('Bronx Community College', $line);
        $this->assertStringContainsString('prefers female teacher', $line);
    }

    public function testSetStatusRequiresAdmin(): void
    {
        $args = $this->registrationArgs();
        $result = FamilyManagement::createFamilyFromRegistration(null, $args['parent'], $args['students'], $args['prefs'], 'talk_first');
        $this->expectException(RuntimeException::class);
        FamilyManagement::setStatus(new UserContext(999, false), $result['family_id'], 'enrolled');
    }

    public function testSendScheduleAssignedEmailUsesInjectedSender(): void
    {
        $args = $this->registrationArgs();
        $result = FamilyManagement::createFamilyFromRegistration(null, $args['parent'], $args['students'], $args['prefs'], 'talk_first');

        pdo()->exec("INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at) VALUES ('Admin', 'A', 'admin@example.com', 'h', 1, NOW())");
        $adminCtx = new UserContext((int)pdo()->lastInsertId(), true);
        UserContext::set($adminCtx);

        // Teacher + one lesson so there is a schedule to send.
        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Tina', 'Teacher', 'h')");
        $teacherId = (int)pdo()->lastInsertId();
        pdo()->exec('INSERT INTO teacher_profiles (user_id) VALUES (' . $teacherId . ')');
        LessonManagement::createLesson($adminCtx, [
            'lesson_type' => 'individual',
            'teacher_user_id' => $teacherId,
            'student_user_id' => $result['student_user_ids'][0],
            'instrument_id' => 1,
            'start_datetime' => date('Y-m-d', strtotime('+7 days')) . ' 09:00:00',
        ]);

        $sent = [];
        FamilyManagement::sendScheduleAssignedEmail($adminCtx, $result['family_id'],
            function ($to, $subject, $html, $toName) use (&$sent) {
                $sent[] = compact('to', 'subject', 'html', 'toName');
                return true;
            });

        $this->assertCount(1, $sent);
        $this->assertSame('maria@example.com', $sent[0]['to']);
        $this->assertStringContainsString('Great news', $sent[0]['subject']);
        $this->assertStringContainsString('/f/schedule.php?token=', $sent[0]['html']);

        $family = FamilyManagement::getFamilyDetail($result['family_id']);
        $this->assertSame('schedule_assigned', $family['status']);

        // Recorded in notification_log for idempotency/dedup.
        $this->assertSame(1, (int)pdo()->query("SELECT COUNT(*) FROM notification_log WHERE notification_type='schedule_assigned'")->fetchColumn());
    }
}
