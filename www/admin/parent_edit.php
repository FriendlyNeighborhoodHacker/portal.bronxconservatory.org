<?php
// Edit Parent: photo, basic information, children (+Add Child modal with
// Add New / Link Existing tabs), and soft delete.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_person_form.php';
require_once __DIR__ . '/../partials_child_modal.php';
require_once __DIR__ . '/../partials_confirm_modal.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/Billing.php';
require_once __DIR__ . '/../lib/LedgerUIManager.php';
Application::init();
require_admin();

$userId = (int)($_GET['id'] ?? 0);
$user = $userId > 0 ? UserManagement::findById($userId) : null;
if (!$user) {
    header('Location: /admin/students.php');
    exit;
}

$children = StudentTeacherManagement::childrenOfParent($userId);
// Parent credit: money this parent paid that no child's charges have used yet.
$parentCreditCents = Billing::parentCreditBalanceCents($userId);
$parentCreditEntries = Billing::parentCreditEntries($userId);
$returnTo = '/admin/parent_edit.php?id=' . $userId;

$flash = $_SESSION['people_flash'] ?? null;
$flashError = $_SESSION['people_flash_error'] ?? null;
unset($_SESSION['people_flash'], $_SESSION['people_flash_error']);
if (isset($_GET['uploaded'])) { $flash = 'Photo uploaded.'; }
if (isset($_GET['deleted'])) { $flash = 'Photo removed.'; }
if (isset($_GET['err'])) { $flashError = 'Photo upload failed.'; }

$name = trim($user['first_name'] . ' ' . $user['last_name']);
header_html('Edit ' . $name);
?>

<div class="page-head">
  <h2>Edit Parent — <?=h($name)?><?=$user['is_deleted'] ? ' <span class="badge">Deleted</span>' : ''?></h2>
  <span class="actions">
    <?php // Developers only, as on the student page: see the portal exactly as this parent does. ?>
    <?php if (is_developer() && empty($user['is_deleted']) && (int)$user['id'] !== (int)current_user()['id']): ?>
      <form method="post" action="/admin/login_as_eval.php" style="display:inline;">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="user_id" value="<?=$userId?>">
        <input type="hidden" name="return_to" value="<?=h($returnTo)?>">
        <button class="button">Login as this user</button>
      </form>
    <?php endif; ?>
    <a class="button" href="/admin/students.php">Back to Students</a>
  </span>
</div>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<?=person_photo_card_html($user, $returnTo)?>

<div class="card">
  <form method="post" action="/admin/parent_edit_eval.php" class="stack" data-warn-unsaved>
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$userId?>">
    <?php person_basic_info_fields_html($user); ?>
    <div class="actions">
      <button type="submit" class="button primary">Save Parent</button>
    </div>
  </form>
</div>

<div class="card">
  <div class="page-head">
    <h3>Children</h3>
    <button type="button" class="button" data-modal-open="addChildModal">Add Child</button>
  </div>
  <?php if (!$children): ?><p class="small">No children linked yet.</p><?php endif; ?>
  <?php foreach ($children as $child): ?>
    <div class="lesson-row">
      <span>
        <a href="/admin/student.php?id=<?=(int)$child['id']?>">
          <strong><?=h($child['first_name'] . ' ' . $child['last_name'])?></strong></a>
        <div class="small"><?=h(implode(', ', $child['instruments']))?></div>
        <?php // A child in credit, in a family with siblings: point at where it can be moved up. ?>
        <?php $childCredit = count($children) > 1 ? -Billing::balanceForStudentCents((int)$child['id']) : 0; ?>
        <?php if ($childCredit > 0): ?>
          <div class="small"><?=h(Billing::formatCents($childCredit))?> credit on this child&rsquo;s account &mdash;
            <a href="/admin/student.php?id=<?=(int)$child['id']?>">open <?=h($child['first_name'])?></a>
            and use &ldquo;Move Credit to Parent&rdquo; to share it with the family.</div>
        <?php endif; ?>
      </span>
      <form method="post" action="/admin/parent_unlink_eval.php" style="margin-left:auto;"
            onsubmit="return confirm('Unlink this child from <?=h($name)?>?');">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="parent_user_id" value="<?=$userId?>">
        <input type="hidden" name="child_user_id" value="<?=(int)$child['id']?>">
        <input type="hidden" name="return_to" value="<?=h($returnTo)?>">
        <button type="submit" class="button">Unlink</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($parentCreditEntries): ?>
<div class="card">
  <h3>Parent credit</h3>
  <p><?php if ($parentCreditCents > 0): ?>
      <strong><?=h(Billing::formatCents($parentCreditCents))?></strong> credit, not yet used.
      It is applied automatically the next time one of the children is charged
      (after that child&rsquo;s own credit).
    <?php else: ?>
      No credit remaining &mdash; all of it has been used.
    <?php endif; ?></p>
  <div style="overflow-x:auto;">
  <table class="list">
    <thead><tr><th>Date</th><th>Description</th><th>Entered by</th>
      <th style="text-align:right;">Added</th><th style="text-align:right;">Used</th></tr></thead>
    <tbody>
      <?php foreach ($parentCreditEntries as $entry): ?>
      <tr>
        <td class="small"><?=h($entry['entry_date'])?></td>
        <td><?=h((string)$entry['description'])?>
          <?php if (!empty($entry['applied_to_student_user_id'])): ?>
            <div class="small"><a href="/admin/student.php?id=<?=(int)$entry['applied_to_student_user_id']?>">Open
              <?=h(trim($entry['student_first_name'] . ' ' . $entry['student_last_name']))?></a></div>
          <?php endif; ?>
          <?php if ($stripe = LedgerUIManager::stripeReference($entry, ApplicationUI::paymentTestMode())): ?>
            <div class="small">Stripe:
              <?php if ($stripe['url'] !== null): ?>
                <a href="<?=h($stripe['url'])?>" target="_blank" rel="noopener"><code><?=h($stripe['id'])?></code></a>
              <?php else: ?><code><?=h($stripe['id'])?></code><?php endif; ?>
            </div>
          <?php endif; ?>
        </td>
        <td class="small"><?=h(LedgerUIManager::enteredByLabel($entry))?></td>
        <td style="text-align:right;"><?=$entry['accounting_type'] === 'credit' ? h(Billing::formatCents((int)$entry['amount_cents'])) : ''?></td>
        <td style="text-align:right;"><?=$entry['accounting_type'] === 'debit' ? h(Billing::formatCents((int)$entry['amount_cents'])) : ''?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <button type="button" class="button danger" data-modal-open="deleteParentModal">Delete this parent</button>
</div>

<?php render_child_modal($userId); ?>
<?php render_confirm_modal(
    'deleteParentModal',
    'Delete ' . $name . '?',
    '<p>The parent is flagged as deleted (nothing is erased): they disappear from lists and can no longer sign in.</p>',
    '/admin/parent_delete_eval.php',
    ['id' => $userId]
); ?>

<?php footer_html(); ?>
