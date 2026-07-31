<?php
// Token-authenticated schedule review page — the link inside the "Great
// news" email. No login required: the token authenticates one parent for one
// family, only within /f/. Shows the assigned schedule and a gold "Confirm
// Enrollment" button.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FamilyAccessTokens.php';
require_once __DIR__ . '/../lib/FamilyManagement.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();

$rawToken = (string)($_GET['token'] ?? '');
$auth = FamilyAccessTokens::verify($rawToken);

ApplicationUI::minimalHeaderHtml('Your Schedule');

if (!$auth) {
    ?>
    <div class="register-page" style="text-align:center;">
      <h1>This link has expired</h1>
      <p>For your family's security, schedule links only work for a limited time.</p>
      <p>You can <a href="/login.php">sign in to your BCM Family Portal account</a>
        (or use <a href="/forgot_password.php">forgot password</a> if you haven't set one),
        or call us at <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a> and
        we'll help right away.</p>
    </div>
    <?php
    ApplicationUI::minimalFooterHtml();
    exit;
}

$family = FamilyManagement::getFamilyDetail((int)$auth['family_id']);
$lessons = $family ? LessonManagement::lessonsForFamily((int)$family['id'], date('Y-m-d')) : [];
$confirmed = !empty($_GET['confirmed']) || ($family && $family['status'] === 'enrolled');
?>
<div class="register-page">
  <?php if ($confirmed): ?>
    <h1>You're enrolled! 🎉</h1>
    <p>Welcome to the Bronx Conservatory of Music, <?=h($family['family_name'] ?? '')?> family.
      Here is your schedule — we'll see you at your first lesson!</p>
  <?php else: ?>
    <h1>Great news, <?=h($family['family_name'] ?? '')?> family!</h1>
    <p>We have a spot for your family at BCM. Review your schedule below and
      confirm your enrollment. Nothing to pay online — we'll go over tuition
      with you at your first visit or by phone.</p>
  <?php endif; ?>

  <?php if (!$lessons): ?>
    <p class="small">Your schedule isn't ready yet — call us at
      <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
  <?php else: ?>
    <div class="card">
      <?php foreach ($lessons as $lesson): ?>
      <div class="lesson-row">
        <span class="lesson-row-time"><?=lesson_time_html($lesson['start_datetime'], (int)$lesson['duration_minutes'])?></span>
        <span><?=h(LessonManagement::lessonSummaryLine($lesson))?></span>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$confirmed && $lessons): ?>
    <form method="post" action="/f/confirm_eval.php" class="register-actions">
      <input type="hidden" name="token" value="<?=h($rawToken)?>">
      <button type="submit" class="btn-cta">Confirm Enrollment</button>
    </form>
    <p class="small">Something not right about the schedule? Call us at
      <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a> and we'll adjust it.</p>
  <?php endif; ?>
</div>
<?php ApplicationUI::minimalFooterHtml(); ?>
