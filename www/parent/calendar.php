<?php
// Parent Calendar — monthly view of all their children's lessons and the
// holiday weeks on those children's lesson weekdays.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_calendar_month.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
Application::init();
require_login();

$me = current_user();
$children = StudentTeacherManagement::childrenOfParent((int)$me['id']);
$childIds = array_map(fn($c) => (int)$c['id'], $children);

$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$dayContent = [];
foreach (LessonManagement::lessonsBetweenForStudents($childIds, $monthStart, $monthEnd) as $lesson) {
    $date = date('Y-m-d', strtotime((string)$lesson['start_datetime']));
    $label = date('g:i a', strtotime((string)$lesson['start_datetime'])) . ' '
        . ($lesson['student_preferred_name'] ?: $lesson['student_first_name']);
    $dayContent[$date][] = '<span class="cal-chip" title="'
        . h($label . ' · ' . $lesson['location_name']) . '">' . h($label) . '</span>';
}

$semester = SemesterManagement::resolveDefaultSemester();
if ($semester) {
    $seen = [];
    foreach ($childIds as $childId) {
        foreach (ReservationManagement::reservationsForStudent($childId, (int)$semester['id']) as $reservation) {
            foreach (SemesterManagement::inactiveDatesForLocationWeekday(
                (int)$semester['id'], (int)$reservation['location_id'], (int)$reservation['day_of_week']
            ) as $holiday) {
                $key = $holiday['date'] . ':' . $holiday['id'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if ($holiday['date'] >= $monthStart && $holiday['date'] <= $monthEnd) {
                    $dayContent[$holiday['date']][] = '<span class="cal-chip cal-inactive">'
                        . h((string)($holiday['title'] ?: 'No lessons')) . '</span>';
                }
            }
        }
    }
}

header_html('Family Calendar');
?>

<h2>Family Calendar</h2>

<div class="card">
<?=calendar_month_html($month, $dayContent, [
    'prev' => '/parent/calendar.php?month=' . date('Y-m', strtotime($monthStart . ' -1 month')),
    'next' => '/parent/calendar.php?month=' . date('Y-m', strtotime($monthStart . ' +1 month')),
])?>
</div>

<?php footer_html(); ?>
