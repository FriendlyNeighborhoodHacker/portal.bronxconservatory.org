<?php
// Thank-you page for the information request. Reachable only after a real
// submission — the lead id in the session is the proof.
require_once __DIR__ . '/inquiry_ui.php';
Application::init();

$leadId = inquiry_lead_id();
if ($leadId === null) {
    header('Location: /welcome.php');
    exit;
}
$lead = LeadManagement::findLead($leadId);

ApplicationUI::minimalHeaderHtml('Request Information — Thank you');
?>
<div style="max-width:560px;margin:0 auto;text-align:center;">
  <h1>Thank you!</h1>
  <p>We have your request<?=$lead ? ' and sent a confirmation to ' . h((string)$lead['email']) : ''?>.
    A member of our team will be in touch soon to talk through instruments, teachers, and times.</p>
  <p>If you would rather reach us first, call
    <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a> or email
    <a href="mailto:info@bronxconservatory.org">info@bronxconservatory.org</a>.</p>

  <p style="margin-top:28px;">Ready to sign up? <a href="/register/">Register here</a>.</p>
  <p><a class="btn-outline" href="/welcome.php">Done</a></p>
</div>
<?php ApplicationUI::minimalFooterHtml(); ?>
