<?php
// Edit or delete an announcement. Evaluates to announcement_edit_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/AnnouncementManagement.php';
Application::init();
require_admin();

$id = (int)($_GET['id'] ?? 0);
$announcement = AnnouncementManagement::find($id);
if (!$announcement) {
    header('Location: /admin/announcements.php');
    exit;
}

$flashError = $_SESSION['announcement_flash_error'] ?? null;
unset($_SESSION['announcement_flash_error']);

header_html('Edit Announcement');
?>

<h2>Edit Announcement</h2>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/announcement_edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$id?>">
    <div class="grid-2">
      <label>Title
        <input type="text" name="title" required value="<?=h($announcement['title'])?>">
      </label>
      <label>Show until
        <input type="date" name="valid_until" required value="<?=h($announcement['valid_until'])?>">
      </label>
    </div>
    <label>Text
      <textarea name="body" rows="4" required><?=h($announcement['body'])?></textarea>
    </label>
    <div class="actions">
      <button type="submit" class="primary">Save</button>
      <button type="submit" name="action" value="delete" class="button" data-confirm="Delete this announcement?">Delete</button>
      <a class="button" href="/admin/announcements.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
