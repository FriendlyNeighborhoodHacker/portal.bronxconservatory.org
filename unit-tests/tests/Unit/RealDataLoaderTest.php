<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The launch loader: a reset that leaves the database holding exactly what
 * the real-data directory describes, without touching the accounts that must
 * survive. The fixture directory is sample_data/ plus the two files only a
 * real-data directory has (semesters.json and general/admins.csv).
 */
final class RealDataLoaderTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        test_reset_all();
        $sample = dirname(__DIR__, 3) . '/sample_data';
        $this->root = sys_get_temp_dir() . '/bcm_real_data_' . bin2hex(random_bytes(6));
        foreach (['general', 'fall_semester', 'spring_semester'] as $dir) {
            mkdir($this->root . '/' . $dir, 0700, true);
            foreach (glob("$sample/$dir/*.csv") as $file) {
                // The real directory has no ledger file: nobody owes anything.
                if (basename($file) !== 'ledger_entries.csv') {
                    copy($file, $this->root . '/' . $dir . '/' . basename($file));
                }
            }
        }
        file_put_contents($this->root . '/general/admins.csv',
            "First Name,Last Name,Email,Cell Phone Number\n"
            . "Dana,Director,Dana.Director@example.org,718-555-0100\n"
            . "Kept,Admin,kept.admin@example.org,\n");
        $pricing = ['registration_fee' => '35', 'lesson_fee_30_minutes' => '420', 'lesson_fee_60_minutes' => '840',
            'guitar_ensemble_fee' => '270', 'lesson_fee_30_minutes_nonresident' => '450',
            'lesson_fee_60_minutes_nonresident' => '900', 'guitar_ensemble_fee_nonresident' => '300',
            'recital_fee' => '10', 'installment_plan_fee' => '20', 'lessons_per_semester' => 15];
        file_put_contents($this->root . '/semesters.json', json_encode([
            'locations' => ['Access Bronx Charter School', 'Bronx Community College'],
            'semesters' => [
                SampleDataLoader::FALL + ['pricing' => $pricing],
                SampleDataLoader::SPRING + ['pricing' => $pricing],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/*') as $file) {
            unlink($file);
        }
        foreach (glob($this->root . '/*') as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->root);
    }

    public function testRefusesNonAdmins(): void
    {
        $this->expectException(RuntimeException::class);
        RealDataLoader::load(new UserContext(fx_user('Plain', 'Parent'), false), $this->root);
    }

    public function testIsOfferedOnlyWhereTheConfigConstantPointsAtADirectory(): void
    {
        // There is no fallback path: without REAL_DATA_DIR the loader does not exist.
        if (!defined('REAL_DATA_DIR')) {
            $this->assertSame('', RealDataLoader::dataDir());
            $this->assertFalse(RealDataLoader::isAvailable());
        } else {
            $this->assertSame(is_dir((string)REAL_DATA_DIR), RealDataLoader::dataDir() !== '');
            $this->assertSame(is_file(rtrim((string)REAL_DATA_DIR, '/') . '/semesters.json'), RealDataLoader::isAvailable());
        }
    }

    public function testABrokenManifestFailsBeforeAnythingIsDeleted(): void
    {
        $stray = fx_student('Stray', 'Student');
        file_put_contents($this->root . '/semesters.json', '{"locations": []}');
        try {
            RealDataLoader::load(fx_admin_ctx(), $this->root);
            $this->fail('expected the load to refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('semesters.json', $e->getMessage());
        }
        $this->assertNotNull(UserManagement::findById($stray));
    }

    public function testResetLoadsTheDirectoryAndKeepsTheNamedAccountsUntouched(): void
    {
        $ctx = fx_admin_ctx();
        $stray = fx_student('Stray', 'Student');
        $developer = fx_user('Dev', 'Eloper', ['is_admin' => true, 'email' => 'dev@example.org', 'password_hash' => 'dev-hash']);
        pdo()->exec("UPDATE users SET is_developer = 1 WHERE id = $developer");
        // Already has an account and a password: must come through unchanged.
        $kept = fx_user('Kept', 'Original-Name', ['is_admin' => true, 'email' => 'Kept.Admin@example.org', 'password_hash' => 'kept-hash']);
        $before = pdo()->query("SELECT * FROM users WHERE id = $kept")->fetch();
        $emailsBefore = (int)pdo()->query('SELECT COUNT(*) FROM emails_sent')->fetchColumn();

        $result = RealDataLoader::load($ctx, $this->root);

        $this->assertSame([
            'Reset',
            'general/locations.csv', 'general/teachers.csv', 'general/people.csv', 'general/admins.csv',
            'Fall 2026 semester',
            'fall_semester/location_weekdays.csv', 'fall_semester/location_dates.csv',
            'fall_semester/location_teachers.csv', 'fall_semester/hold_blocks.csv',
            'fall_semester/semester_location_reservations.csv',
            'Spring 2027 semester',
            'spring_semester/location_weekdays.csv', 'spring_semester/location_dates.csv',
            'spring_semester/location_teachers.csv', 'spring_semester/hold_blocks.csv',
        ], array_column($result['steps'], 'label'));

        [$fall, $spring] = $result['semesters'];
        $this->assertCount(17, ReservationManagement::reservationsForSemester($fall));
        $this->assertCount(0, ReservationManagement::reservationsForSemester($spring));
        $this->assertSame('270.00', (string)SemesterManagement::find($fall)['guitar_ensemble_fee']);
        // Nobody owes anything, and nothing was emailed to anyone.
        $this->assertSame(0, (int)pdo()->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn());
        $this->assertSame($emailsBefore, (int)pdo()->query('SELECT COUNT(*) FROM emails_sent')->fetchColumn());

        // Gone: whatever was there. Untouched: developer, acting admin, the listed admin.
        $this->assertNull(UserManagement::findById($stray));
        $this->assertSame('dev-hash', pdo()->query("SELECT password_hash FROM users WHERE id = $developer")->fetchColumn());
        $this->assertNotNull(UserManagement::findById($ctx->id));
        $this->assertSame($before, pdo()->query("SELECT * FROM users WHERE id = $kept")->fetch());

        // The listed admin with no account gets one: admin, no password, phone set.
        $dana = UserManagement::findByEmailAnyState('dana.director@example.org');
        $this->assertSame(1, (int)$dana['is_admin']);
        $this->assertSame('', (string)$dana['password_hash']);
        $this->assertSame('718-555-0100', (string)$dana['cell_phone']);
        $this->assertSame(['created' => 1, 'skipped' => 1],
            array_intersect_key($result['steps'][4], ['created' => 0, 'skipped' => 0]));

        // A second run is the same clean state, not a doubled one, and Dana is now kept as-is.
        pdo()->exec("UPDATE users SET password_hash = 'dana-hash' WHERE id = " . (int)$dana['id']);
        $again = RealDataLoader::load($ctx, $this->root);
        $this->assertCount(2, SemesterManagement::listSemesters());
        $this->assertCount(17, ReservationManagement::reservationsForSemester($again['semesters'][0]));
        $this->assertSame('dana-hash', (string)UserManagement::findByEmailAnyState('dana.director@example.org')['password_hash']);
        $this->assertStringStartsWith('Real data loaded. Reset: ', RealDataLoader::summarize($again));
    }

    public function testDescribeCountsWithoutLoading(): void
    {
        $summary = RealDataLoader::describe($this->root);
        $this->assertSame(['Dana Director', 'Kept Admin'], $summary['admins']);
        $this->assertSame(6, $summary['teachers']);
        $this->assertSame(['Fall 2026', 'Spring 2027'], array_column($summary['semesters'], 'label'));
        $this->assertSame(17, $summary['semesters'][0]['slots']);
        $this->assertNull($summary['semesters'][1]['slots']);
        $this->assertNull($summary['semesters'][0]['ledger_rows']);
        $this->assertFalse(SemesterManagement::hasAnySemester());
    }

    public function testEmailRedirectStatusReflectsTheConfig(): void
    {
        // The test config leaves the redirect off.
        $this->assertSame(['on' => false, 'to' => '', 'allowlist' => []], email_redirect_status());
    }

    public function testTheTopStripSaysDevModeAndWhetherEmailIsSuppressed(): void
    {
        $off = ['on' => false, 'to' => '', 'allowlist' => []];
        $on = ['on' => true, 'to' => 'Dev@example.org', 'allowlist' => ['dev@example.org', 'director@example.org']];

        // Nothing to say on a live site sending mail normally.
        $this->assertSame([], ApplicationUI::siteBarParts(false, $off, true));
        $this->assertSame(['<strong>DEV MODE</strong>'], ApplicationUI::siteBarParts(true, $off, false));
        $this->assertSame(
            ['<strong>PAYMENT TEST MODE</strong> &mdash; no real charges'],
            ApplicationUI::siteBarParts(false, $off, false, true)
        );

        // Suppressed email shows on a live site too; addresses only for admins.
        $this->assertSame(['Emails suppressed'], ApplicationUI::siteBarParts(false, $on, false));
        $this->assertSame(
            ['Emails suppressed: all mail goes to Dev@example.org, except to director@example.org'],
            ApplicationUI::siteBarParts(false, $on, true)
        );
        $this->assertSame(
            ['<strong>DEV MODE</strong>', 'Emails suppressed: all mail goes to a@example.org (no exceptions)'],
            ApplicationUI::siteBarParts(true, ['on' => true, 'to' => 'a@example.org', 'allowlist' => []], true)
        );
    }
}
