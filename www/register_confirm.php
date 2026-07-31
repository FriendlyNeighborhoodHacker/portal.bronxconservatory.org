<?php
// Confirmation page after a registration form submission. Copy differs by
// path: fast path ("we'll send your schedule") vs. conversation path ("we'll
// call you").
require_once __DIR__ . '/partials.php';

Application::init();

$path = ($_GET['path'] ?? '') === 'complete_enrollment' ? 'complete_enrollment' : 'talk_first';

ApplicationUI::minimalHeaderHtml('Registration Received');
?>
<div class="register-page" style="text-align:center;">
  <h1>Thank you — we can't wait to make music with you!</h1>
  <?php if ($path === 'talk_first'): ?>
    <p>Your registration is in. Since you asked to <strong>talk first</strong>, someone
    from BCM will call you within <strong>two business days</strong> to answer your
    questions and find the right schedule for your family.</p>
  <?php else: ?>
    <p>Your registration is in. We're matching your family with a teacher and time
    that fit your preferences — you'll get an email with your
    <strong>schedule</strong> shortly.</p>
  <?php endif; ?>
  <p>We just sent you an email with a link to <strong>set up your BCM Family Portal
  account</strong>, where you'll see your schedule, teacher notes, and materials.</p>
  <p>Can't wait? Call us at <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
  <p style="margin-top:24px;"><a class="btn-outline" href="/login.php">Go to login</a></p>
</div>
<?php ApplicationUI::minimalFooterHtml(); ?>
