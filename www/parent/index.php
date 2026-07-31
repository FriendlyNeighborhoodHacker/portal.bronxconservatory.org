<?php
// Parent home (docs/app_spec.md): My Children cards (instrument, teacher,
// next lesson), Messages (announcements), My Profile. Simple enough for a
// phone on the bus.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/AnnouncementManagement.php';
require_once __DIR__ . '/../lib/Files.php';
Application::init();
require_login();

$me = current_user();
$children = StudentTeacherManagement::childrenOfParent((int)$me['id']);
$roles = Application::rolesForUser((int)$me['id']);
$announcements = AnnouncementManagement::listForRoles($roles, 3);

header_html('My Family');
?>

<h2>Welcome, <?=h($me['preferred_name'] ?: $me['first_name'])?>!</h2>

<h3>My Children</h3>
<?php if (!$children): ?>
  <p class="small">No students are linked to your account yet. If that seems wrong,
  call us at <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
<?php else: ?>
  <div class="card-grid">
    <?php foreach ($children as $child): ?>
    <?php
      $next = LessonManagement::upcomingLessonsForStudent((int)$child['id'], date('Y-m-d'), 1);
      $nextLesson = $next[0] ?? null;
    ?>
    <div class="card">
      <h3><a href="/parent/child.php?id=<?=(int)$child['id']?>"><?=h($child['first_name'] . ' ' . $child['last_name'])?></a></h3>
      <div class="card-sub"><?=h(implode(', ', $child['instruments'] ?: ['No instrument yet']))?></div>
      <?php if ($nextLesson): ?>
        <div class="small">Next: <?=lesson_time_html($nextLesson['start_datetime'], (int)$nextLesson['duration_minutes'])?><br>
          with <?=h(trim(($nextLesson['sub_first_name'] ?? $nextLesson['teacher_first_name']) . ' ' . ($nextLesson['sub_last_name'] ?? $nextLesson['teacher_last_name'])))?>
          · <?=lesson_place_html($nextLesson)?></div>
      <?php else: ?>
        <div class="small">No upcoming lessons scheduled.</div>
      <?php endif; ?>
      <div style="margin-top:8px;"><a class="btn-outline" style="padding:6px 14px;font-size:14px;" href="/parent/child.php?id=<?=(int)$child['id']?>">Schedule &amp; notes</a></div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h3>Messages</h3>
<div class="card">
  <?php if (!$announcements): ?>
    <p class="small">No announcements right now.</p>
  <?php else: ?>
    <?php foreach ($announcements as $a): ?>
    <div style="padding:6px 0;border-bottom:1px solid var(--color-border);">
      <strong><?=h($a['title'])?></strong>
      <span class="small"> · <?=h(date('M j', strtotime($a['published_at'])))?></span>
      <div class="small"><?=nl2br(h($a['body']))?></div>
    </div>
    <?php endforeach; ?>
    <p class="small" style="margin-bottom:0;"><a href="/announcements.php">All announcements</a></p>
  <?php endif; ?>
</div>

<h3>My Profile</h3>
<div class="card">
  <div><?=person_chip_html($me['first_name'], $me['last_name'])?></div>
  <div class="small" style="margin-top:6px;"><?=h($me['email'] ?? '')?> · <?=h($me['cell_phone'] ?? '')?></div>
  <div style="margin-top:8px;"><a class="btn-outline" style="padding:6px 14px;font-size:14px;" href="/profile/">Update my info</a></div>
</div>

<?php footer_html(); ?>
