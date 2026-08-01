<?php
// Family Calendar — the whole semester as one chronological list: every
// child's lessons with date, time and location, plus the holiday weeks when
// their location is closed and there is no lesson.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_semester_timeline.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/ScheduleTimeline.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_login();

$me = current_user();
$children = StudentTeacherManagement::childrenOfParent((int)$me['id']);
$childIds = array_map(fn($c) => (int)$c['id'], $children);

// Every semester still to come, so next semester shows up as soon as it
// is planned.
$semesters = SemesterManagement::currentAndFutureSemesters();
$entries = ScheduleTimeline::forStudents($childIds, $semesters);

header_html('Family Calendar');
?>

<div class="page-head">
  <h2>Family Calendar</h2>
</div>

<?=semester_timeline_html($entries, function (array $lesson): string {
    $child = trim(($lesson['student_preferred_name'] ?: $lesson['student_first_name'])
        . ' ' . $lesson['student_last_name']);
    // The regular teacher; a covered week names the substitute on its own line.
    return $child . ' with ' . trim($lesson['teacher_first_name'] . ' ' . $lesson['teacher_last_name']);
})?>

<?php footer_html(); ?>
