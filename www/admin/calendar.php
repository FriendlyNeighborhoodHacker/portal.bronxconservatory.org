<?php
// Admin Calendar — monthly view: each day shows the semester's
// location-dates ("Location · Title"; holidays grayed out). Month navigation
// stays within the selected semester; clicking a day opens that week.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_calendar_month.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_admin();

$semesterId = Application::adminSelectedSemesterId();
if ($semesterId === null) {
    header('Location: /admin/setup/index.php');
    exit;
}
$semester = SemesterManagement::find($semesterId);

$firstMonth = substr((string)$semester['start_date'], 0, 7);
$lastMonth = substr((string)$semester['end_date'], 0, 7);
$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$month = max($firstMonth, min($lastMonth, $month));

$dayContent = [];
foreach (SemesterManagement::locationDates($semesterId) as $dateRow) {
    $label = $dateRow['location_name'] . ($dateRow['title'] ? ' · ' . $dateRow['title'] : '');
    $class = $dateRow['status'] === 'inactive' ? 'cal-chip cal-inactive' : 'cal-chip';
    $dayContent[$dateRow['date']][] = '<span class="' . $class . '" title="'
        . h($label . ' · ' . date('g:i a', strtotime($dateRow['start_time'])) . '–' . date('g:i a', strtotime($dateRow['end_time'])))
        . '">' . h($label) . '</span>';
}

$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

header_html('Calendar');
?>

<h2>Calendar — <?=h(SemesterManagement::label($semester))?></h2>

<div class="card">
<?=calendar_month_html($month, $dayContent, [
    'prev' => $prevMonth >= $firstMonth ? '/admin/calendar.php?month=' . $prevMonth : null,
    'next' => $nextMonth <= $lastMonth ? '/admin/calendar.php?month=' . $nextMonth : null,
], [
    'dayLinkFn' => fn(string $date): string => '/admin/calendar_week.php?date=' . $date,
])?>
</div>

<p class="small">Click a day to open that week's lessons.</p>

<?php footer_html(); ?>
