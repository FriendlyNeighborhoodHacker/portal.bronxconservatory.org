<?php
// POST: commit the conversion. Errors (e.g. a reservation slot conflict)
// flash back to the convert form with the admin's choices preserved.
//
// Reservations are checked for conflicts BEFORE convertLead runs. convertLead
// creates the parent and the students first and only then books slots, so a
// clash discovered there leaves half-made people behind; checking up front
// means a bad pick costs nothing.
require_once __DIR__ . '/lead_ui.php';
require_once __DIR__ . '/../lib/ScheduleConflicts.php';
Application::init();
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/leads.php');
    exit;
}
require_csrf();

$leadId = (int)($_POST['lead_id'] ?? 0);
$postedStudents = (array)($_POST['students'] ?? []);
$ctx = UserContext::getLoggedInUserContext();

$lead = LeadManagement::findLead($leadId);
if (!$lead) {
    header('Location: /admin/leads.php');
    exit;
}

// Lesson lengths, for the duration fallback. An inquiry lead student has none.
$lessonLengths = [];
foreach (LeadManagement::studentsForLead($leadId) as $leadStudent) {
    $lessonLengths[(int)$leadStudent['id']] = (int)($leadStudent['lesson_length_minutes'] ?? 0);
}

$convertFail = function (string $message) use ($leadId, $postedStudents): void {
    $_SESSION['lead_flash_error'] = $message;
    $_SESSION['lead_convert_old'] = ['students' => $postedStudents];
    header('Location: /admin/lead_convert.php?id=' . $leadId);
    exit;
};

$options = ['students' => []];
$picks = [];
foreach ($postedStudents as $leadStudentId => $row) {
    $leadStudentId = (int)$leadStudentId;
    $entry = [
        'instrument_id' => (int)($row['instrument_id'] ?? 0),
        'date_of_birth' => trim((string)($row['date_of_birth'] ?? '')) ?: null,
    ];
    $resLocation = (int)($row['res_location_id'] ?? 0);
    $resTeacher = (int)($row['res_teacher_user_id'] ?? 0);
    $resTime = trim((string)($row['res_start_time'] ?? ''));
    if ($resLocation && $resTeacher && $resTime !== '') {
        // Fall through zero as well as missing: an empty duration field posts
        // as "0", which ?? would happily accept as a zero-length lesson.
        $duration = (int)($row['res_duration'] ?? 0)
            ?: ($lessonLengths[$leadStudentId] ?? 0)
            ?: 30;
        $entry['reservation'] = [
            'teacher_user_id' => $resTeacher,
            'location_id' => $resLocation,
            'day_of_week' => (int)($row['res_day'] ?? 6),
            'start_time' => $resTime,
            'duration_minutes' => $duration,
            'semester_id' => !empty($lead['semester_id'])
                ? (int)$lead['semester_id'] : Application::adminSelectedSemesterId(),
        ];
        $picks[$leadStudentId] = $entry['reservation'];
    } elseif ($resLocation || $resTeacher || $resTime !== '') {
        $convertFail('Pick a time from the schedule grid, or clear the selection.');
    }
    $options['students'][$leadStudentId] = $entry;
}

// Two siblings picking the same slot would each look free on its own, so check
// the picks against each other before checking them against the schedule.
$seen = [];
foreach ($picks as $pick) {
    $start = (int)round(strtotime('1970-01-01 ' . $pick['start_time']) / 60);
    $end = $start + (int)$pick['duration_minutes'];
    $key = $pick['teacher_user_id'] . ':' . $pick['day_of_week'];
    foreach ($seen[$key] ?? [] as [$otherStart, $otherEnd]) {
        if ($start < $otherEnd && $otherStart < $end) {
            $convertFail('Two of these students are booked into the same teacher at the same time.');
        }
    }
    $seen[$key][] = [$start, $end];

    $conflict = ScheduleConflicts::weeklySlotConflict(
        (int)$pick['semester_id'],
        (int)$pick['teacher_user_id'],
        (int)$pick['day_of_week'],
        (string)$pick['start_time'],
        (int)$pick['duration_minutes']
    );
    if ($conflict !== null) {
        $convertFail($conflict);
    }
}
if (!empty($_POST['payment_target_lead_student_id'])) {
    $options['payment_target_lead_student_id'] = (int)$_POST['payment_target_lead_student_id'];
}

try {
    $result = LeadManagement::convertLead($ctx, $leadId, $options);
    $bits = [];
    $bits[] = $result['parent_existed'] ? 'Adopted the existing parent account' : 'Created the parent account';
    $bits[] = count($result['student_user_ids']) . ' student' . (count($result['student_user_ids']) === 1 ? '' : 's');
    if ($result['reservation_ids']) {
        $bits[] = count($result['reservation_ids']) . ' reservation' . (count($result['reservation_ids']) === 1 ? '' : 's') . ' placed (pending reach out)';
    }
    if ($result['payment_recorded']) {
        $bits[] = 'payment recorded on the ledger';
    } elseif (!empty($result['payment_notice'])) {
        $bits[] = $result['payment_notice'];
    }
    $_SESSION['lead_flash'] = 'Converted! ' . implode(' · ', $bits) . '.';
    header('Location: /admin/lead.php?id=' . $leadId);
} catch (\Throwable $e) {
    $convertFail($e->getMessage());
}
exit;
