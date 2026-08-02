<?php
// One uncompleted information-request form: what they told us before they
// stopped, plus somewhere to record a follow-up.
//
// There is no Convert button on purpose — without a student there is nothing
// to create. Finish the conversation by phone; if they enroll, they go through
// the public form or you add them under Users.
require_once __DIR__ . '/incomplete_inquiry_ui.php';
require_once __DIR__ . '/lead_ui.php';
Application::init();
require_admin();

$id = (int)($_GET['id'] ?? 0);
$row = InquiryManagement::find($id);
if (!$row) {
    http_response_code(404);
    die('Uncompleted form not found');
}

$flash = $_SESSION['inquiry_flash'] ?? null;
$flashError = $_SESSION['inquiry_flash_error'] ?? null;
unset($_SESSION['inquiry_flash'], $_SESSION['inquiry_flash_error']);

header_html($row['first_name'] . ' ' . $row['last_name'] . ' — uncompleted form');
?>

<div class="page-head">
  <h2><?=h($row['first_name'] . ' ' . $row['last_name'])?>
    <span class="status-pill">Uncompleted form</span></h2>
  <a class="button" href="/admin/incomplete_inquiries.php">Back to Uncompleted Forms</a>
</div>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<p class="small">They started the Request Information form on
<?=h(date('M j, Y g:i A', strtotime((string)$row['created_at'])))?> and stopped after
<strong><?=h(strtolower(incomplete_inquiry_stage_label($row)))?></strong>, so we never learned who
would be studying.</p>

<div class="card-grid">
  <div class="card">
    <h3>Contact</h3>
    <p class="small">
      <?=h($row['phone'])?><?=!empty($row['sms_consent']) ? ' (texting ok)' : ' (no texting consent)'?><br>
      <a href="mailto:<?=h($row['email'])?>"><?=h($row['email'])?></a><?=!empty($row['newsletter_opt_in']) ? ' (newsletter)' : ''?>
    </p>
  </div>

  <div class="card">
    <h3>Mailing address</h3>
    <p class="small"><?=h(lead_address_line($row))?></p>
  </div>
</div>

<h3>Internal notes</h3>
<div class="card">
  <form method="post" action="/admin/incomplete_inquiry_update_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$id?>">
    <label>Notes (admins only)
      <textarea name="admin_notes" rows="4" placeholder="Left a voicemail 8/4…"><?=h($row['admin_notes'] ?? '')?></textarea>
    </label>
    <div class="actions">
      <button type="submit" class="button primary">Save</button>
    </div>
  </form>
</div>

<div class="card">
  <form method="post" action="/admin/incomplete_inquiry_delete_eval.php">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$id?>">
    <button type="submit" class="button danger"
      data-confirm="Delete this uncompleted form? Everything they entered is removed.">Delete</button>
  </form>
</div>

<?php footer_html(); ?>
