<?php
// Logged-out landing page: the doors into the portal — Register (the public
// wizard), Login, and the lower-commitment Request Information flow. Not the
// site's front door any more (index.php sends signed-out visitors to login);
// this is the page the organization hands out. Signed-in visitors go straight
// to their dashboard via index.php's router.
require_once __DIR__ . '/partials.php';
Application::init();

if (current_user()) {
    header('Location: /index.php');
    exit;
}

ApplicationUI::minimalHeaderHtml('Welcome');
?>
<div style="max-width:560px;margin:0 auto;text-align:center;">
  <h1>Welcome to the Bronx Conservatory of Music</h1>
  <p>Private music instruction of the highest quality, in your own neighborhood,
    at the lowest possible tuition — making conservatory training accessible to all.</p>

  <div class="actions" style="justify-content:center;margin-top:28px;">
    <a class="btn-cta" href="/register/">Register</a>
    <a class="btn-outline" href="/login.php">Login</a>
  </div>

  <p class="small" style="margin-top:18px;">Not ready to register?
    <a href="/inquiry/">Request more information about our programs</a>.</p>

  <p class="small" style="margin-top:28px;">Questions? Call us at
    <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a> or email
    <a href="mailto:info@bronxconservatory.org">info@bronxconservatory.org</a>.</p>
</div>
<?php ApplicationUI::minimalFooterHtml(); ?>
