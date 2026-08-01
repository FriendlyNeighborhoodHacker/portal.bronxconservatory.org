<?php
// Lead detail: everything the family entered, payment state, status +
// internal notes, and the door into the Convert flow.
require_once __DIR__ . '/lead_ui.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
Application::init();
require_admin();

$leadId = (int)($_GET['id'] ?? 0);
$lead = LeadManagement::findLead($leadId);
if (!$lead) {
    http_response_code(404);
    die('Lead not found');
}
$students = LeadManagement::studentsForLead($leadId);
$quoteLines = json_decode((string)$lead['quote_json'], true) ?: [];
$days = json_decode((string)($lead['preferred_days'] ?? '[]'), true) ?: [];
$blocks = json_decode((string)($lead['availability_blocks'] ?? '[]'), true) ?: [];
$converted = $lead['status'] === 'converted' || !empty($lead['converted_parent_user_id']);
$convertedParent = !empty($lead['converted_parent_user_id'])
    ? UserManagement::findById((int)$lead['converted_parent_user_id']) : null;

$flash = $_SESSION['lead_flash'] ?? null;
$flashError = $_SESSION['lead_flash_error'] ?? null;
unset($_SESSION['lead_flash'], $_SESSION['lead_flash_error']);

header_html($lead['parent_first_name'] . ' ' . $lead['parent_last_name'] . ' — lead');
?>

<div class="page-head">
  <h2><?=h($lead['parent_first_name'] . ' ' . $lead['parent_last_name'])?> family
    <?=lead_status_html($lead['status'])?> <?=lead_paid_badge_html($lead)?></h2>
  <div style="display:flex;gap:10px;align-items:center;">
    <a class="button" href="/admin/leads.php">Back to Leads</a>
    <?php if (!$converted): ?>
      <a class="btn-cta" href="/admin/lead_convert.php?id=<?=$leadId?>">Convert to Family…</a>
    <?php endif; ?>
  </div>
</div>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<?php if ($converted && $convertedParent): ?>
<div class="card">
  <h3>Converted</h3>
  <p class="small">Parent account:
    <a href="/admin/parent_edit.php?id=<?=(int)$convertedParent['id']?>"><?=h($convertedParent['first_name'] . ' ' . $convertedParent['last_name'])?></a>
    <?=!empty($lead['converted_at']) ? '· converted ' . h(date('M j, Y g:i A', strtotime($lead['converted_at']))) : ''?></p>
  <?php foreach ($students as $student): ?>
    <?php if (!empty($student['converted_student_user_id'])): ?>
      <p class="small">Student:
        <a href="/admin/student_edit.php?id=<?=(int)$student['converted_student_user_id']?>"><?=h($student['first_name'] . ' ' . $student['last_name'])?></a></p>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!empty($convertedParent['email']) && (string)$convertedParent['password_hash'] === ''): ?>
    <form method="post" action="/admin/lead_invite_eval.php">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="lead_id" value="<?=$leadId?>">
      <button type="submit" class="button" data-confirm="Email this parent a set-up-your-account invitation?">Send Account Invite</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card-grid">
  <div class="card">
    <h3>Parent / Guardian</h3>
    <p class="small">
      <?=h($lead['phone'])?><?=!empty($lead['sms_consent']) ? ' (SMS ok)' : ' (no SMS consent)'?><br>
      <?=h($lead['email'])?><br>
      <?=h(trim($lead['address_street_1'] . ' ' . ($lead['address_street_2'] ?? '')))?><br>
      <?=h($lead['address_city'] . ', ' . $lead['address_state'] . ' ' . $lead['address_zip'])?>
    </p>
    <p class="small">Submitted <?=h(date('M j, Y g:i A', strtotime($lead['created_at'])))?>
      for <strong><?=h(lead_semester_label($lead))?></strong><br>
      Policies agreed: <?=!empty($lead['policies_agreed_at']) ? h(date('M j, Y g:i A', strtotime($lead['policies_agreed_at']))) : '✗'?></p>
  </div>

  <div class="card">
    <h3>Scheduling preferences</h3>
    <p class="small">
      Location: <strong><?=h($lead['location_preference'] ?: 'No preference')?></strong><br>
      Days: <?=h($days ? implode(', ', $days) : '—')?><br>
      Times: <?=h($blocks ? implode(', ', array_map(
          fn($b) => LeadManagement::AVAILABILITY_BLOCKS[$b] ?? $b, $blocks)) : '—')?>
    </p>
    <?php if (trim((string)($lead['scheduling_notes'] ?? '')) !== ''): ?>
      <p class="small"><strong>Notes:</strong> <?=nl2br(h($lead['scheduling_notes']))?></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Payment</h3>
    <p class="small">
      Quoted: <strong><?=h(lead_dollars((int)$lead['amount_quoted_cents']))?></strong>
      (<?=!empty($lead['installment_plan']) ? 'installment plan — due now ' . h(lead_dollars((int)$lead['amount_due_now_cents'])) : 'full payment'?>)<br>
      <?php if ((int)$lead['amount_paid_cents'] > 0): ?>
        Paid: <strong><?=h(lead_dollars((int)$lead['amount_paid_cents']))?></strong>
        on <?=h(date('M j, Y', strtotime($lead['paid_at'])))?><br>
        <span style="word-break:break-all;">Session: <?=h($lead['stripe_checkout_session_id'])?></span>
      <?php else: ?>
        Paid: nothing yet — arrange by phone or during conversion.
      <?php endif; ?>
    </p>
    <table class="list">
      <tbody>
        <?php foreach ($quoteLines as $line): ?>
        <tr><td class="small"><?=h($line['label'])?></td>
            <td class="small" style="text-align:right;"><?=h(lead_dollars((int)$line['amount_cents']))?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<h3>Students</h3>
<div class="card">
  <table class="list">
    <thead><tr><th>Name</th><th>Class of</th><th>Instrument</th><th>Lesson</th><th>Guitar Ensemble</th><th>Shirt</th><th>Converted</th></tr></thead>
    <tbody>
      <?php foreach ($students as $student): ?>
      <tr>
        <td><?=h($student['first_name'] . ' ' . $student['last_name'])?></td>
        <td><?=h($student['class_of'] ?: '—')?></td>
        <td><?=h($student['instrument'])?></td>
        <td><?=(int)$student['lesson_length_minutes']?> min</td>
        <td><?=!empty($student['guitar_ensemble']) ? 'Yes' : '—'?></td>
        <td><?=h($student['shirt_size'] ?: '—')?></td>
        <td class="small"><?=!empty($student['converted_student_user_id'])
            ? '<a href="/admin/student_edit.php?id=' . (int)$student['converted_student_user_id'] . '">view student</a>'
            : '—'?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h3>Status &amp; internal notes</h3>
<div class="card">
  <form method="post" action="/admin/lead_update_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="lead_id" value="<?=$leadId?>">
    <div class="grid-2">
      <label>Status
        <select name="status">
          <?php foreach (LeadManagement::STATUSES as $status): ?>
          <option value="<?=$status?>"<?=$lead['status'] === $status ? ' selected' : ''?>><?=h(LeadManagement::STATUS_LABELS[$status])?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label>Internal notes (admins only)
      <textarea name="admin_notes" rows="4" placeholder="Called 8/3 — wants Saturday morning at BCC…"><?=h($lead['admin_notes'] ?? '')?></textarea>
    </label>
    <div class="actions">
      <button type="submit" class="button primary">Save</button>
    </div>
  </form>
</div>

<?php footer_html(); ?>
