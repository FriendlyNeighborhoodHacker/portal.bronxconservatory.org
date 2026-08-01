<?php
// Student Calendar — monthly view of their lessons and holiday weeks.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_calendar_month.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('student', $roles, true) && empty($me['is_admin'])) {
    http_response_code(403);
    die('Students only');
}

$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$dayContent = [];
foreach (LessonManagement::lessonsBetweenForStudents([(int)$me['id']], $monthStart, $monthEnd) as $lesson) {
    $date = date('Y-m-d', strtotime((string)$lesson['start_datetime']));
    $teacher = trim(($lesson['substitute_first_name'] ?? null ?: $lesson['teacher_first_name']) . ' '
        . ($lesson['substitute_last_name'] ?? null ?: $lesson['teacher_last_name']));
    $label = date('g:i a', strtotime((string)$lesson['start_datetime'])) . ' with ' . $teacher;
    $dayContent[$date][] = '<span class="cal-chip" title="' . h($label . ' · ' . $lesson['location_name']) . '">'
        . h($label) . '</span>';
}

// Holiday weeks on the student's lesson weekdays.
$semester = SemesterManagement::resolveDefaultSemester();
if ($semester) {
    foreach (ReservationManagement::reservationsForStudent((int)$me['id'], (int)$semester['id']) as $reservation) {
        foreach (SemesterManagement::inactiveDatesForLocationWeekday(
            (int)$semester['id'], (int)$reservation['location_id'], (int)$reservation['day_of_week']
        ) as $holiday) {
            if ($holiday['date'] >= $monthStart && $holiday['date'] <= $monthEnd) {
                $dayContent[$holiday['date']][] = '<span class="cal-chip cal-inactive">'
                    . h((string)($holiday['title'] ?: 'No lessons')) . '</span>';
            }
        }
    }
}

header_html('My Calendar');
?>

<h2>My Calendar</h2>

<div class="card">
<?=calendar_month_html($month, $dayContent, [
    'prev' => '/student/calendar.php?month=' . date('Y-m', strtotime($monthStart . ' -1 month')),
    'next' => '/student/calendar.php?month=' . date('Y-m', strtotime($monthStart . ' +1 month')),
])?>
</div>

<?php footer_html(); ?>
