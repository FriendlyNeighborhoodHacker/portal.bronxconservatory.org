<?php
// My Materials: everything shared with the student this semester, in the
// chronological order of the lesson each item came from.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ResourceManagement.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
if (!in_array('student', $roles, true) && empty($me['is_admin'])) {
    http_response_code(403);
    die('Students only');
}

$semester = SemesterManagement::resolveDefaultSemester();
$resources = $semester
    ? ResourceManagement::resourcesForStudentInSemester((int)$me['id'], (int)$semester['id'])
    : [];

header_html('My Materials');
?>

<h2>My Materials<?=$semester ? ' — ' . h(SemesterManagement::label($semester)) : ''?></h2>

<div class="card">
  <?php if (!$resources): ?><p class="small">Nothing shared yet.</p><?php endif; ?>
  <?php foreach ($resources as $resource): ?>
  <div class="lesson-row">
    <span class="lesson-row-time"><?=h(date('M j', strtotime((string)$resource['lesson_datetime'])))?>
      <span class="small">Lesson #<?=(int)$resource['lesson_number']?></span></span>
    <?php if ($resource['resource_type'] === 'link'): ?>
      <span><a href="<?=h($resource['url'])?>" target="_blank" rel="noopener">🔗 <?=h($resource['title'])?></a></span>
    <?php else: ?>
      <span><a href="/resource_download.php?id=<?=(int)$resource['id']?>"><?=h($resource['title'])?></a>
        <span class="small"><?=h((string)($resource['original_filename'] ?? ''))?></span></span>
    <?php endif; ?>
    <span class="small">from <?=h(trim(($resource['uploader_first_name'] ?? '') . ' ' . ($resource['uploader_last_name'] ?? '')))?></span>
  </div>
  <?php endforeach; ?>
</div>

<?php footer_html(); ?>
