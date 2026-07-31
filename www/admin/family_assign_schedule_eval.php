<?php
// POST: create the lessons for a family's assigned schedule. Rows with a
// repeat-until date become weekly recurring lessons with materialized
// occurrences; rows without become a single lesson. Status moves to
// schedule_assigned; the admin then sends the "Great news" email from the
// family page.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FamilyManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}
require_csrf();

$familyId = (int)($_POST['family_id'] ?? 0);
$rows = (array)($_POST['rows'] ?? []);
$ctx = UserContext::getLoggedInUserContext();

$created = 0;
try {
    foreach ($rows as $row) {
        $teacherId = (int)($row['teacher_user_id'] ?? 0);
        $studentId = (int)($row['student_user_id'] ?? 0);
        if (!$teacherId || !$studentId) {
            continue; // "skip this student"
        }
        $firstDate = trim((string)($row['first_date'] ?? ''));
        $startTime = trim((string)($row['start_time'] ?? ''));
        if ($firstDate === '' || $startTime === '') {
            throw new InvalidArgumentException('Each scheduled student needs a first lesson date and time.');
        }

        $common = [
            'lesson_type' => 'individual',
            'instrument_id' => $row['instrument_id'] ?? null,
            'teacher_user_id' => $teacherId,
            'student_user_id' => $studentId,
            'location_id' => $row['location_id'] ?? null,
            'room' => $row['room'] ?? null,
            'is_online' => !empty($row['is_online']),
            'duration_minutes' => (int)($row['duration_minutes'] ?? 30),
        ];

        $repeatUntil = trim((string)($row['repeat_until'] ?? ''));
        if ($repeatUntil !== '') {
            $recurringId = LessonManagement::createRecurring($ctx, $common + [
                'day_of_week' => (int)date('w', strtotime($firstDate)),
                'start_time' => $startTime,
                'start_date' => $firstDate,
                'end_date' => $repeatUntil,
            ]);
            $created += LessonManagement::generateOccurrencesThrough($ctx, $recurringId, $repeatUntil);
        } else {
            LessonManagement::createLesson($ctx, $common + [
                'start_datetime' => $firstDate . ' ' . $startTime,
            ]);
            $created++;
        }
    }

    if ($created === 0) {
        throw new InvalidArgumentException('No lessons were created — pick a teacher for at least one student.');
    }

    FamilyManagement::setStatus($ctx, $familyId, 'schedule_assigned');
    $_SESSION['family_flash'] = $created . ' lesson' . ($created === 1 ? '' : 's')
        . ' created. Review below, then send the "Great News" email.';
    header('Location: /admin/family.php?id=' . $familyId);
} catch (\Throwable $e) {
    $_SESSION['family_flash_error'] = $e->getMessage();
    header('Location: /admin/family_assign_schedule.php?id=' . $familyId);
}
exit;
