<?php
// Admin > Email Templates: the transactional emails whose wording staff own.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EmailTemplateManagement.php';
Application::init();
require_admin();

$templates = EmailTemplateManagement::listTemplates();

$flash = $_SESSION['email_template_flash'] ?? null;
$flashError = $_SESSION['email_template_flash_error'] ?? null;
unset($_SESSION['email_template_flash'], $_SESSION['email_template_flash_error']);

header_html('Email Templates', ['wide' => true]);
?>

<div class="page-head">
  <h2>Email Templates</h2>
</div>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<p class="small">Change what these emails say without waiting for a deploy. The portal fills in the
<code>{{placeholders}}</code>; everything else is yours.</p>

<?php if (!$templates): ?>
  <p class="small">No templates are installed.</p>
<?php else: ?>
<div class="card" style="overflow-x:auto;">
  <table class="list">
    <thead><tr><th>Template</th><th>Subject</th><th>Last edited</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($templates as $template): ?>
      <?php $editor = trim(($template['updated_by_first_name'] ?? '') . ' ' . ($template['updated_by_last_name'] ?? '')); ?>
      <tr>
        <td>
          <a href="/admin/email_template.php?key=<?=h(urlencode((string)$template['template_key']))?>"><strong><?=h($template['name'])?></strong></a>
          <div class="small"><?=h($template['description'] ?? '')?></div>
        </td>
        <td class="small"><?=h($template['subject'])?></td>
        <td class="small">
          <?=h(date('M j, Y', strtotime((string)$template['updated_at'])))?>
          <?=$editor !== '' ? '<br>by ' . h($editor) : '<br>(shipped wording)'?>
        </td>
        <td><a class="button" href="/admin/email_template.php?key=<?=h(urlencode((string)$template['template_key']))?>">Edit</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php footer_html(); ?>
