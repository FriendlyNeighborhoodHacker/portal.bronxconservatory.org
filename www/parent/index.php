<?php
// Parent home (docs/app_spec.md): Announcements, My Children cards (photo,
// instrument, teacher, next lesson, balance), Upcoming Lessons, Balance &
// Payments, My Profile. Simple enough for a phone on the bus.
//
// What a parent came here to find out is when the next lesson is, so it is
// the loudest thing on each card and the family's next four lessons get a
// section of their own.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
require_once __DIR__ . '/../lib/LessonDetailUIManager.php';
require_once __DIR__ . '/../lib/AnnouncementManagement.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/Billing.php';
require_once __DIR__ . '/../lib/Files.php';
Application::init();
require_login();

$me = current_user();
$children = StudentTeacherManagement::childrenOfParent((int)$me['id']);
$announcements = AnnouncementManagement::listActive(3);
$semester = SemesterManagement::resolveDefaultSemester();
$semesterId = $semester ? (int)$semester['id'] : null;
$upcoming = LessonManagement::upcomingLessonsForStudents(
    array_map(fn($child) => (int)$child['id'], $children), date('Y-m-d'), 4
);

// One balance summary per child — the cards and the family total below read
// from these, so both say the same thing about what is owed.
$summaries = [];
$totalDue = 0;
foreach ($children as $child) {
    $summaries[(int)$child['id']] = Billing::balanceSummaryForStudent((int)$child['id']);
    $totalDue += $summaries[(int)$child['id']]['due_cents'];
}

header_html('My Family');
?>

<h2>Welcome, <?=h($me['preferred_name'] ?: $me['first_name'])?>!</h2>

<?php if ($announcements): ?>
<h3>Announcements</h3>
<div class="card">
  <?php foreach ($announcements as $a): ?>
  <div style="padding:6px 0;border-bottom:1px solid var(--color-border);">
    <strong><?=h($a['title'])?></strong>
    <span class="small"> · <?=h(date('M j', strtotime($a['created_at'])))?></span>
    <div class="small"><?=nl2br(h($a['body']))?></div>
  </div>
  <?php endforeach; ?>
  <p class="small" style="margin-bottom:0;"><a href="/announcements.php">All announcements</a></p>
</div>
<?php endif; ?>

<h3>My Children</h3>
<?php if (!$children): ?>
  <p class="small">No students are linked to your account yet. If that seems wrong,
  call us at <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
<?php else: ?>
  <div class="card-grid"<?php if (count($children) === 1): ?> style="grid-template-columns:1fr;"<?php endif; ?>>
    <?php foreach ($children as $child): ?>
    <?php
      $next = LessonManagement::upcomingLessonsForStudent((int)$child['id'], date('Y-m-d'), 1);
      $nextLesson = $next[0] ?? null;
      $reservations = $semesterId !== null
          ? ReservationManagement::reservationsForStudent((int)$child['id'], $semesterId)
          : [];
      $teacherNames = array_values(array_unique(array_map(
          fn($r) => trim($r['teacher_first_name'] . ' ' . $r['teacher_last_name']),
          $reservations
      )));
      $photoUrl = Files::profilePhotoUrl($child['photo_public_file_id'] ?? null, 56);
      $initials = strtoupper(substr((string)$child['first_name'], 0, 1) . substr((string)$child['last_name'], 0, 1));
      $childBalance = $summaries[(int)$child['id']];
    ?>
    <div class="card">
      <div style="display:flex;align-items:center;gap:10px;">
        <?php if ($photoUrl !== ''): ?>
          <img src="<?=h($photoUrl)?>" alt="" style="width:56px;height:56px;border-radius:50%;object-fit:cover;">
        <?php else: ?>
          <span aria-hidden="true" style="width:56px;height:56px;border-radius:50%;background:var(--color-primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700;"><?=h($initials)?></span>
        <?php endif; ?>
        <div>
          <h3 style="margin:0;"><a href="/parent/child.php?id=<?=(int)$child['id']?>"><?=h($child['first_name'] . ' ' . $child['last_name'])?></a></h3>
          <div class="card-sub" style="margin:0;">
            <?=h(implode(', ', $child['instruments'] ?: ['No instrument yet']))?>
            <?php if ($teacherNames): ?> · with <?=h(implode(', ', $teacherNames))?><?php endif; ?>
          </div>
        </div>
      </div>
      <?php if ($nextLesson): ?>
        <div class="next-lesson">Next: <?=lesson_time_html($nextLesson['start_datetime'], (int)$nextLesson['duration_minutes'])?>
          · <?=h($nextLesson['location_name'])?>
          <?php if (LessonManagement::isCancelled($nextLesson)): ?><span class="badge">Cancelled</span><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="small" style="margin-top:8px;">No upcoming lessons scheduled.</div>
      <?php endif; ?>
      <?php if ($childBalance['due_cents'] > 0): ?>
        <div style="margin-top:6px;">
          <a class="balance-due<?=$childBalance['behind'] ? ' balance-behind' : ''?>"
             href="/parent/billing.php#child-<?=(int)$child['id']?>">Balance due:
            <?=h(Billing::formatCents($childBalance['due_cents']))?></a>
        </div>
      <?php endif; ?>
      <div style="margin-top:8px;"><a class="btn-outline" style="padding:6px 14px;font-size:14px;" href="/parent/child.php?id=<?=(int)$child['id']?>">Schedule &amp; notes</a></div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($upcoming): ?>
<h3>Upcoming Lessons</h3>
<div class="card">
  <?php foreach ($upcoming as $lesson): ?>
  <?php $cancelled = LessonManagement::isCancelled($lesson); ?>
  <div style="padding:12px 0;border-bottom:1px solid var(--color-border);<?=$cancelled ? 'opacity:0.6;' : ''?>">
    <div style="display:flex;justify-content:space-between;align-items:start;gap:12px;">
      <div>
        <div style="font-weight:500;margin-bottom:4px;">
          <?php
            $start = strtotime($lesson['start_datetime']);
            $end = $start + ((int)$lesson['duration_minutes'] * 60);
            echo h(date('D, M j · g:i', $start) . '–' . date('g:i A', $end));
          ?>
        </div>
        <div style="font-size:14px;color:var(--color-text-secondary);margin-bottom:4px;">
          <?=h($lesson['location_name'])?>
        </div>
        <div style="font-size:14px;">
          <?php if (count($children) > 1): ?>
            <strong><?=h((string)($lesson['student_preferred_name'] ?: $lesson['student_first_name']))?></strong> ·
          <?php endif; ?>
          Teacher: <?=h(trim(($lesson['substitute_first_name'] ?? null ?: $lesson['teacher_first_name']) . ' '
            . ($lesson['substitute_last_name'] ?? null ?: $lesson['teacher_last_name'])))?>
          <?php if ($cancelled): ?><span class="badge">Cancelled</span><?php endif; ?>
        </div>
      </div>
      <a href="#" data-lesson-detail="<?=(int)$lesson['id']?>" class="button" style="white-space:nowrap;margin-top:4px;">Notes & Materials</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h3>Balance &amp; Payments</h3>
<div class="card">
  <?php if ($totalDue <= 0): ?>
    <p style="margin:0;"><strong>You are paid in full. Thank you!</strong></p>
  <?php else: ?>
    <p>You have a balance of <strong><?=h(Billing::formatCents($totalDue))?></strong>.</p>
    <a class="btn-outline" style="padding:6px 14px;font-size:14px;" href="/parent/billing.php">Pay the balance</a>
  <?php endif; ?>
</div>

<h3>My Profile</h3>
<div class="card">
  <div><?=person_chip_html($me['first_name'], $me['last_name'])?></div>
  <div class="small" style="margin-top:6px;"><?=h($me['email'] ?? '')?> · <?=h($me['cell_phone'] ?? '')?></div>
  <div style="margin-top:8px;"><a class="btn-outline" style="padding:6px 14px;font-size:14px;" href="/profile/">Update my info</a></div>
</div>

<?php LessonDetailUIManager::renderModal(); ?>
<?php footer_html(); ?>
