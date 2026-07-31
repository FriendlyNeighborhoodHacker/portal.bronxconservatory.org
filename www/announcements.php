<?php
// Announcements list for any signed-in user (filtered to their roles).
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/AnnouncementManagement.php';
Application::init();
require_login();

$me = current_user();
$roles = Application::rolesForUser((int)$me['id']);
$announcements = AnnouncementManagement::listForRoles($roles, 50);

header_html('Announcements');
?>

<h2>Announcements</h2>

<?php if (!$announcements): ?>
  <p class="small">No announcements right now.</p>
<?php endif; ?>

<?php foreach ($announcements as $a): ?>
<div class="card" style="margin-bottom:12px;">
  <h3><?=h($a['title'])?></h3>
  <div class="card-sub"><?=h(date('F j, Y', strtotime($a['published_at'])))?></div>
  <div><?=nl2br(h($a['body']))?></div>
</div>
<?php endforeach; ?>

<?php footer_html(); ?>
