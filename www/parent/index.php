<?php
// Parent home (docs/app_spec.md): Announcements, My Children cards (photo,
// instrument, teacher, next lesson), Balance & Payments, My Profile. Simple
// enough for a phone on the bus.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
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
$balance = Billing::balanceForParentCents((int)$me['id']);
$semester = SemesterManagement::resolveDefaultSemester();
$semesterId = $semester ? (int)$semester['id'] : null;

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
  <div class="card-grid">
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
        <div class="small" style="margin-top:8px;">Next: <?=lesson_time_html($nextLesson['start_datetime'], (int)$nextLesson['duration_minutes'])?>
          · <?=h($nextLesson['location_name'])?></div>
      <?php else: ?>
        <div class="small" style="margin-top:8px;">No upcoming lessons scheduled.</div>
      <?php endif; ?>
      <div style="margin-top:8px;"><a class="btn-outline" style="padding:6px 14px;font-size:14px;" href="/parent/child.php?id=<?=(int)$child['id']?>">Schedule &amp; notes</a></div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h3>Balance &amp; Payments</h3>
<div class="card">
  <?php if ($balance <= 0): ?>
    <p style="margin:0;"><strong>You are paid in full. Thank you!</strong></p>
  <?php else: ?>
    <p>You have a balance of <strong><?=h(Billing::formatCents($balance))?></strong>.</p>
    <a class="btn-cta" href="/parent/billing.php">Pay the balance</a>
  <?php endif; ?>
</div>

<h3>My Profile</h3>
<div class="card">
  <div><?=person_chip_html($me['first_name'], $me['last_name'])?></div>
  <div class="small" style="margin-top:6px;"><?=h($me['email'] ?? '')?> · <?=h($me['cell_phone'] ?? '')?></div>
  <div style="margin-top:8px;"><a class="btn-outline" style="padding:6px 14px;font-size:14px;" href="/profile/">Update my info</a></div>
</div>

<?php footer_html(); ?>
