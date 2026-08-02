<?php
// Edit one email template, with the variables it can use and a preview of what
// families actually receive. Evaluates to email_template_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/EmailTemplateManagement.php';
Application::init();
require_admin();

$key = (string)($_GET['key'] ?? '');
$template = EmailTemplateManagement::find($key);
if (!$template) {
    http_response_code(404);
    die('Email template not found');
}

// On an error round-trip, show what they were editing — not the saved copy.
$old = $_SESSION['email_template_old'] ?? [];
unset($_SESSION['email_template_old']);
$subject = (string)($old['subject'] ?? $template['subject']);
$bodyHtml = (string)($old['body_html'] ?? $template['body_html']);

$variables = EmailTemplateManagement::availableVariables($key);
$unknown = EmailTemplateManagement::unknownPlaceholders($key, $subject, $bodyHtml);
$preview = EmailTemplateManagement::render($key, EmailTemplateManagement::sampleVariables($key));

$flash = $_SESSION['email_template_flash'] ?? null;
$flashError = $_SESSION['email_template_flash_error'] ?? null;
unset($_SESSION['email_template_flash'], $_SESSION['email_template_flash_error']);

header_html('Edit — ' . $template['name'], ['wide' => true]);
?>

<div class="page-head">
  <h2><?=h($template['name'])?></h2>
  <a class="button" href="/admin/email_templates.php">Back to Email Templates</a>
</div>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<p class="small"><?=h($template['description'] ?? '')?></p>

<?php if ($unknown): ?>
  <p class="error">These placeholders are not filled in by the portal and will come out blank:
    <?=h(implode(', ', array_map(fn($v) => '{{' . $v . '}}', $unknown)))?></p>
<?php endif; ?>

<div class="card-grid">
  <div class="card" style="grid-column:span 2;">
    <form method="post" action="/admin/email_template_eval.php" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="template_key" value="<?=h($key)?>">
      <label>Subject
        <input type="text" name="subject" maxlength="255" required value="<?=h($subject)?>">
      </label>
      <label>Body (HTML)
        <textarea name="body_html" rows="20" required style="font-family:monospace;"><?=h($bodyHtml)?></textarea>
      </label>
      <div class="actions">
        <button type="submit" class="button primary">Save</button>
        <a class="button" href="/admin/email_templates.php">Cancel</a>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>Available variables</h3>
    <p class="small">Type these anywhere in the subject or body. Anything else comes out blank.</p>
    <div class="choice-grid">
      <?php foreach ($variables as $variable): ?>
        <code class="small">{{<?=h($variable)?>}}</code>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<h3>Preview</h3>
<p class="small">The saved wording, filled in with example answers — save first to see your edits here.</p>
<div class="card">
  <?php if ($preview === null): ?>
    <p class="small">Nothing to preview.</p>
  <?php else: ?>
    <p class="small"><strong>Subject:</strong> <?=h($preview['subject'])?></p>
    <div style="border:1px solid var(--color-border, #ddd);border-radius:6px;padding:14px;background:#fff;overflow-x:auto;">
      <?php
        // Rendered as HTML on purpose — this is a preview of an email body,
        // and writing HTML is the whole point of the editor. The substituted
        // values were escaped by render(); what is unescaped here is wording
        // an admin typed on this very page.
        echo $preview['body_html'];
      ?>
    </div>
    <form method="post" action="/admin/email_template_test_eval.php" style="margin-top:12px;">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="template_key" value="<?=h($key)?>">
      <button type="submit" class="button">Send this preview to me</button>
    </form>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
