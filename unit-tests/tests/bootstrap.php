<?php
declare(strict_types=1);

// Test bootstrap: load the app, then point every pdo() call at a dedicated
// test database (recreated from schema.sql on every run) so tests never touch
// the real database.

require_once __DIR__ . '/../../www/config.php';
require_once __DIR__ . '/../../www/lib/UserManagement.php';
require_once __DIR__ . '/../../www/lib/Application.php';
require_once __DIR__ . '/../../www/lib/InstrumentCatalog.php';
require_once __DIR__ . '/../../www/lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../../www/lib/LocationManagement.php';
require_once __DIR__ . '/../../www/lib/SemesterManagement.php';
require_once __DIR__ . '/../../www/lib/ReservationManagement.php';
require_once __DIR__ . '/../../www/lib/Billing.php';
require_once __DIR__ . '/../../www/lib/LessonManagement.php';
require_once __DIR__ . '/../../www/lib/NotesManagement.php';
require_once __DIR__ . '/../../www/lib/ResourceManagement.php';
require_once __DIR__ . '/../../www/lib/AnnouncementManagement.php';
require_once __DIR__ . '/../../www/lib/CsvImport.php';
require_once __DIR__ . '/../../www/lib/TeacherCsvImport.php';
require_once __DIR__ . '/../../www/lib/LocationDatesCsvImport.php';
require_once __DIR__ . '/../../www/lib/LocationTeachersCsvImport.php';
require_once __DIR__ . '/../../www/lib/StripeCheckout.php';
require_once __DIR__ . '/../../www/lib/MigrationRunner.php';
require_once __DIR__ . '/../../www/lib/ActivityLog.php';

const TEST_DB_NAME = 'bronx_music_conservatory_test';

$server = new PDO(
    'mysql:host=' . DB_HOST . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec('DROP DATABASE IF EXISTS `' . TEST_DB_NAME . '`');
$server->exec('CREATE DATABASE `' . TEST_DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$testPdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . TEST_DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$testPdo->exec((string)file_get_contents(__DIR__ . '/../../www/schema.sql'));

set_pdo_for_testing($testPdo);

require_once __DIR__ . '/fixtures.php';

// Helper for tests: wipe all domain tables back to a clean slate.
// (Seed reference rows — instruments, locations, settings — are kept.)
function test_reset_all(): void {
    Application::clearRolesCacheForTesting();
    $pdo = pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'activity_log', 'emails_sent', 'stripe_webhook_events',
        'ledger_entries', 'lesson_notes', 'lesson_resources', 'lessons',
        'semester_lesson_reservations', 'semester_location_teachers',
        'semester_location_dates', 'semester_locations', 'semesters',
        'announcements',
        'student_instruments', 'teacher_instruments',
        'student_profiles', 'teacher_profiles', 'parenthood',
        'private_files', 'public_files', 'users',
    ] as $table) {
        $pdo->exec('TRUNCATE TABLE ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
