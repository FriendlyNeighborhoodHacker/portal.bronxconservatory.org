<?php
// POST: commit the conversion. Errors (e.g. a reservation slot conflict)
// flash back to the convert form with the admin's choices preserved.
require_once __DIR__ . '/lead_ui.php';
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

$options = ['students' => []];
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
        $entry['reservation'] = [
            'teacher_user_id' => $resTeacher,
            'location_id' => $resLocation,
            'day_of_week' => (int)($row['res_day'] ?? 6),
            'start_time' => $resTime,
            'duration_minutes' => (int)($row['res_duration'] ?? 30),
            'semester_id' => !empty($lead['semester_id'])
                ? (int)$lead['semester_id'] : Application::adminSelectedSemesterId(),
        ];
    } elseif ($resLocation || $resTeacher || $resTime !== '') {
        $_SESSION['lead_flash_error'] = 'To place a reservation, pick a location, a teacher, and a time (or leave all three empty to skip).';
        $_SESSION['lead_convert_old'] = ['students' => $postedStudents];
        header('Location: /admin/lead_convert.php?id=' . $leadId);
        exit;
    }
    $options['students'][$leadStudentId] = $entry;
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
    $_SESSION['lead_flash_error'] = $e->getMessage();
    $_SESSION['lead_convert_old'] = ['students' => $postedStudents];
    header('Location: /admin/lead_convert.php?id=' . $leadId);
}
exit;
