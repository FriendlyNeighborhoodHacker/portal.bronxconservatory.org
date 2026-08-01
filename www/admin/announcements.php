<?php
// Manage announcements: list + add form. Edits in announcement_edit.php.
// Announcements show on dashboards until their valid-until date passes.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/AnnouncementManagement.php';
Application::init();
require_admin();

$announcements = AnnouncementManagement::listAll();

$flash = $_SESSION['announcement_flash'] ?? null;
$flashError = $_SESSION['announcement_flash_error'] ?? null;
unset($_SESSION['announcement_flash'], $_SESSION['announcement_flash_error']);

header_html('Announcements');
?>

<h2>Announcements</h2>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card">
  <h3>New announcement</h3>
  <form method="post" action="/admin/announcement_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="grid-2">
      <label>Title
        <input type="text" name="title" required placeholder="Holiday schedule">
      </label>
      <label>Show until
        <input type="date" name="valid_until" required value="<?=h(date('Y-m-d', strtotime('+2 weeks')))?>">
      </label>
    </div>
    <label>Text
      <textarea name="body" rows="3" required></textarea>
    </label>
    <button type="submit" class="button">Publish</button>
  </form>
</div>

<?php foreach ($announcements as $a): ?>
<?php $expired = $a['valid_until'] < date('Y-m-d'); ?>
<div class="card" style="margin-bottom:12px;<?=$expired ? 'opacity:0.6;' : ''?>">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:baseline;flex-wrap:wrap;">
    <h3 style="margin:0;"><?=h($a['title'])?></h3>
    <span class="small"><?=$expired ? 'expired' : 'shows until'?> <?=h(date('M j, Y', strtotime($a['valid_until'])))?>
      · <a href="/admin/announcement_edit.php?id=<?=(int)$a['id']?>">Edit</a></span>
  </div>
  <div class="small"><?=nl2br(h($a['body']))?></div>
</div>
<?php endforeach; ?>

<?php footer_html(); ?>
