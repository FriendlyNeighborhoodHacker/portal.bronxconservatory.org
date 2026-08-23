<?php
declare(strict_types=1);

// Shared fixture builders for the test suite (loaded by bootstrap.php).
// All fx_* helpers write directly to the test database.

function fx_user(string $first, string $last, array $opts = []): int {
    $st = pdo()->prepare(
        "INSERT INTO users (first_name, last_name, email, password_hash, is_admin, is_deleted, email_verified_at)
         VALUES (?,?,?,?,?,?,?)"
    );
    $st->execute([
        $first,
        $last,
        $opts['email'] ?? null,
        $opts['password_hash'] ?? '',
        !empty($opts['is_admin']) ? 1 : 0,
        !empty($opts['is_deleted']) ? 1 : 0,
        $opts['email_verified_at'] ?? null,
    ]);
    return (int)pdo()->lastInsertId();
}

function fx_admin(): int {
    return fx_user('Ada', 'Admin', ['is_admin' => true]);
}

function fx_admin_ctx(): UserContext {
    return new UserContext(fx_admin(), true);
}

function fx_teacher(string $first = 'Tess', string $last = 'Teacher'): int {
    $id = fx_user($first, $last);
    pdo()->exec("INSERT INTO teacher_profiles (user_id) VALUES ($id)");
    return $id;
}

function fx_student(string $first = 'Sam', string $last = 'Student'): int {
    $id = fx_user($first, $last);
    pdo()->exec("INSERT INTO student_profiles (user_id) VALUES ($id)");
    return $id;
}

function fx_parent_of(int $childUserId, string $first = 'Pat', string $last = 'Parent'): int {
    $id = fx_user($first, $last);
    pdo()->exec("INSERT INTO parenthood (parent_user_id, child_user_id) VALUES ($id, $childUserId)");
    return $id;
}

function fx_location_id(): int {
    // Seeded by schema.sql and kept by test_reset_all().
    return (int)pdo()->query('SELECT id FROM locations ORDER BY id LIMIT 1')->fetchColumn();
}

function fx_second_location_id(): int {
    return (int)pdo()->query('SELECT id FROM locations ORDER BY id LIMIT 1 OFFSET 1')->fetchColumn();
}

function fx_semester(UserContext $ctx, string $season = 'fall', int $year = 2030, string $start = '2030-09-01', string $end = '2030-12-20'): int {
    return SemesterManagement::createSemester($ctx, $season, $year, $start, $end, [
        'registration_fee' => '35.00',
        'lesson_fee_30_minutes' => '420.00',
        'lesson_fee_60_minutes' => '840.00',
        'guitar_ensemble_fee' => '270.00',
        'recital_fee' => '10.00',
        'installment_plan_fee' => '20.00',
        'lessons_per_semester' => 15,
    ]);
}

/**
 * A semester wired for reservations: active location, teacher assigned there,
 * and weekly class dates starting at $firstDate (every 7 days, $count weeks).
 * Returns [semesterId, locationId, teacherId, dayOfWeek, dates[]].
 */
function fx_semester_with_dates(UserContext $ctx, int $teacherId, string $firstDate, int $count, string $season = 'fall', int $year = 2030, ?string $start = null, ?string $end = null): array {
    $firstTs = strtotime($firstDate);
    $start = $start ?? date('Y-m-d', $firstTs - 7 * 86400);
    $end = $end ?? date('Y-m-d', $firstTs + ($count + 2) * 7 * 86400);
    $semesterId = SemesterManagement::createSemester($ctx, $season, $year, $start, $end, [
        'registration_fee' => '50.00',
        'lesson_fee_30_minutes' => '300.00',
        'lesson_fee_60_minutes' => '600.00',
        'guitar_ensemble_fee' => '270.00',
        'recital_fee' => '25.00',
        'installment_plan_fee' => '25.00',
        'lessons_per_semester' => 15,
    ]);
    $locationId = fx_location_id();
    SemesterManagement::setActiveLocations($ctx, $semesterId, [$locationId]);
    SemesterManagement::setLocationWeekdays($ctx, $semesterId, $locationId, [
        [(int)date('w', $firstTs), '09:00', '17:00'],
    ]);

    $dates = [];
    for ($i = 0; $i < $count; $i++) {
        $date = date('Y-m-d', $firstTs + $i * 7 * 86400);
        SemesterManagement::upsertLocationDate($ctx, $semesterId, $locationId, $date, '09:00:00', '17:00:00', 'active', 'Day ' . ($i + 1));
        $dates[] = $date;
    }
    // After the dates, so the day-less assignment lands on $firstDate's weekday.
    SemesterManagement::setLocationTeachers($ctx, $semesterId, [[$locationId, $teacherId]]);
    return [$semesterId, $locationId, $teacherId, (int)date('w', $firstTs), $dates];
}

/** A tiny real PDF on disk, shaped like a $_FILES entry. */
function fx_uploaded_pdf(string $name = 'sheet.pdf'): array {
    $tmp = tempnam(sys_get_temp_dir(), 'bcmtest');
    file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
    return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
}
