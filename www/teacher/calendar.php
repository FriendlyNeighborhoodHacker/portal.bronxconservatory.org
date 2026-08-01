<?php
// Teacher Calendar — monthly view: each day shows the teacher's lesson count
// and locations. Clicking a day opens the weekly list.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_calendar_month.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('teacher', $roles, true) && !in_array('admin', $roles, true)) {
    http_response_code(403);
    die('Teachers only');
}

$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$dayContent = [];
foreach (LessonManagement::lessonsBetweenForTeacher((int)$me['id'], $monthStart, $monthEnd) as $lesson) {
    $date = date('Y-m-d', strtotime((string)$lesson['start_datetime']));
    $label = date('g:i a', strtotime((string)$lesson['start_datetime'])) . ' '
        . trim(($lesson['student_preferred_name'] ?: $lesson['student_first_name']) . ' ' . $lesson['student_last_name']);
    $missed = $lesson['attended'] !== null && (int)$lesson['attended'] === 0;
    $dayContent[$date][] = '<span class="cal-chip' . ($missed ? ' cal-missed' : '') . '" title="'
        . h($label . ' · ' . $lesson['location_name']) . '">' . h($label) . '</span>';
}

header_html('My Calendar');
?>

<h2>My Calendar</h2>

<div class="card">
<?=calendar_month_html($month, $dayContent, [
    'prev' => '/teacher/calendar.php?month=' . date('Y-m', strtotime($monthStart . ' -1 month')),
    'next' => '/teacher/calendar.php?month=' . date('Y-m', strtotime($monthStart . ' +1 month')),
], [
    'dayLinkFn' => fn(string $date): string => '/teacher/calendar_week.php?date=' . $date,
])?>
</div>

<p class="small">Click a day to see that week's lessons as a list.</p>

<?php footer_html(); ?>
