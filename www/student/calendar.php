<?php
// Student Calendar — the whole semester as one chronological list: every
// lesson with its date, time and location, plus the holiday weeks when the
// student's location is closed and there is no lesson.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_semester_timeline.php';
require_once __DIR__ . '/../lib/ScheduleTimeline.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('student', $roles, true) && empty($me['is_admin'])) {
    http_response_code(403);
    die('Students only');
}

// Every semester still to come, so next semester shows up as soon as it
// is planned.
$semesters = SemesterManagement::currentAndFutureSemesters();
$entries = ScheduleTimeline::forStudents([(int)$me['id']], $semesters);

header_html('My Calendar');
?>

<div class="page-head">
  <h2>My Calendar</h2>
</div>

<?=semester_timeline_html($entries, function (array $lesson): string {
    // Their regular teacher; a week someone else is covering says so on its
    // own line, so both names are visible rather than one silently swapped.
    return 'with ' . trim($lesson['teacher_first_name'] . ' ' . $lesson['teacher_last_name']);
})?>

<?php footer_html(); ?>
