<?php
// Lead detail: everything the family entered, payment state, the internal note
// history, and the door into the Convert flow.
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
$notes = LeadManagement::notesForLead($leadId);
$quoteLines = json_decode((string)$lead['quote_json'], true) ?: [];
$days = json_decode((string)($lead['preferred_days'] ?? '[]'), true) ?: [];
$blocks = json_decode((string)($lead['availability_blocks'] ?? '[]'), true) ?: [];
$converted = $lead['status'] === 'converted' || !empty($lead['converted_parent_user_id']);
$convertedParent = !empty($lead['converted_parent_user_id'])
    ? UserManagement::findById((int)$lead['converted_parent_user_id']) : null;
$isInquiry = lead_is_inquiry($lead);

$flash = $_SESSION['lead_flash'] ?? null;
$flashError = $_SESSION['lead_flash_error'] ?? null;
$noteOld = $_SESSION['lead_note_old'] ?? '';
unset($_SESSION['lead_flash'], $_SESSION['lead_flash_error'], $_SESSION['lead_note_old']);

header_html($lead['parent_first_name'] . ' ' . $lead['parent_last_name'] . ' — lead');
?>

<div class="page-head">
  <h2><?=h($lead['parent_first_name'] . ' ' . $lead['parent_last_name'])?> family
    <?=lead_status_html($lead['status'])?> <?=lead_source_html($lead)?> <?=lead_paid_badge_html($lead)?></h2>
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
      <button type="submit" class="button" data-confirm="This will send an email to <?=h($convertedParent['email'])?> inviting them to set up their account. Send it?">Send Account Invite</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card-grid">
  <div class="card">
    <h3>Parent / Guardian</h3>
    <p class="small">
      <?=h($lead['phone'])?><?=!empty($lead['sms_consent']) ? ' (SMS ok)' : ' (no SMS consent)'?><br>
      <?=h($lead['email'])?><?=!empty($lead['newsletter_opt_in']) ? ' (newsletter)' : ''?><br>
      <?=h(lead_address_line($lead))?>
    </p>
    <p class="small">Submitted <?=h(date('M j, Y g:i A', strtotime($lead['created_at'])))?>
      <?php if ($isInquiry): ?>
        <?=trim((string)($lead['semester_label'] ?? '')) !== '' ? 'for <strong>' . h($lead['semester_label']) . '</strong>' : ''?>
      <?php else: ?>
        for <strong><?=h(lead_semester_label($lead))?></strong><br>
        Policies agreed: <?=!empty($lead['policies_agreed_at']) ? h(date('M j, Y g:i A', strtotime($lead['policies_agreed_at']))) : '✗'?>
      <?php endif; ?>
    </p>
  </div>

  <?php if ($isInquiry): ?>
  <div class="card">
    <h3>Inquiry details</h3>
    <p class="small">
      Term: <strong><?=h($lead['semester_label'] ?: '—')?></strong><br>
      Owns: <?=h(lead_json_list($lead['owned_instruments'] ?? null, $lead['owned_instruments_other'] ?? null) ?: '—')?><br>
      Theory program: <?=h(LeadManagement::THEORY_INTEREST_LABELS[(string)($lead['theory_program_interest'] ?? '')] ?? '—')?><br>
      Theory level: <?=h(LeadManagement::THEORY_KNOWLEDGE_LABELS[(string)($lead['theory_knowledge'] ?? '')] ?? '—')?><br>
      Heard about us: <?=h($lead['referral_source'] ?: '—')?>
    </p>
    <?php if (trim((string)($lead['music_background'] ?? '')) !== ''): ?>
      <p class="small"><strong>Prior study:</strong> <?=nl2br(h($lead['music_background']))?></p>
    <?php endif; ?>
    <?php if (trim((string)($lead['inquiry_comments'] ?? '')) !== ''): ?>
      <p class="small"><strong>Comments:</strong> <?=nl2br(h($lead['inquiry_comments']))?></p>
    <?php endif; ?>
  </div>
  <?php else: ?>
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
  <?php endif; ?>

  <?php if (!$isInquiry): ?>
  <div class="card">
    <h3>Payment</h3>
    <p class="small">
      Quoted: <strong><?=h(lead_dollars((int)$lead['amount_quoted_cents']))?></strong>
      (<?=!empty($lead['installment_plan']) ? 'installment plan — due now ' . h(lead_dollars((int)$lead['amount_due_now_cents'])) : 'full payment'?>)<br>
      <?php if ((int)$lead['amount_paid_cents'] > 0): ?>
        Paid: <strong><?=h(lead_dollars((int)$lead['amount_paid_cents']))?></strong>
        on <?=h(date('M j, Y', strtotime($lead['paid_at'])))?><br>
        <span style="word-break:break-all;">Ref: <?=h(LeadManagement::paymentReference($lead))?></span>
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
  <?php endif; ?>
</div>

<h3>Students</h3>
<div class="card">
  <table class="list">
    <thead>
      <tr>
        <th>Name</th>
        <?php if ($isInquiry): ?>
          <th>Age</th><th>New or continuing</th><th>Instruments of interest</th>
        <?php else: ?>
          <th>Class of</th><th>Instrument</th><th>Lesson</th><th>Guitar Ensemble</th><th>Shirt</th>
        <?php endif; ?>
        <th>Converted</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($students as $student): ?>
      <tr>
        <td><?=h($student['first_name'] . ' ' . $student['last_name'])?></td>
        <?php if ($isInquiry): ?>
          <td><?=h($student['age'] ?: '—')?></td>
          <td><?=h(LeadManagement::ENROLLMENT_STATUSES[(string)($student['enrollment_status'] ?? '')] ?? '—')?></td>
          <td><?=h(lead_json_list($student['instruments_of_interest'] ?? null, $student['instruments_other'] ?? null) ?: '—')?></td>
        <?php else: ?>
          <td><?=h($student['class_of'] ?: '—')?></td>
          <td><?=h($student['instrument'] ?: '—')?></td>
          <td><?=!empty($student['lesson_length_minutes']) ? (int)$student['lesson_length_minutes'] . ' min' : '—'?></td>
          <td><?=!empty($student['guitar_ensemble']) ? 'Yes' : '—'?></td>
          <td><?=h($student['shirt_size'] ?: '—')?></td>
        <?php endif; ?>
        <td class="small"><?=!empty($student['converted_student_user_id'])
            ? '<a href="/admin/student_edit.php?id=' . (int)$student['converted_student_user_id'] . '">view student</a>'
            : '—'?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<h3>Add a note</h3>
<div class="card">
  <form method="post" action="/admin/lead_note_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="lead_id" value="<?=$leadId?>">
    <label>Note (admins only)
      <textarea name="note_body" rows="4" placeholder="Called 8/3 — wants Saturday morning at BCC…"><?=h($noteOld)?></textarea>
    </label>
    <div class="grid-2">
      <label>Status
        <select name="status">
          <?php foreach (LeadManagement::STATUSES as $status): ?>
          <option value="<?=$status?>"<?=$lead['status'] === $status ? ' selected' : ''?>><?=h(LeadManagement::STATUS_LABELS[$status])?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <p class="small">Notes are kept, never replaced — leave the status alone if nothing has changed.</p>
    <div class="actions">
      <button type="submit" class="button primary">Add Note</button>
    </div>
  </form>
</div>

<h3>History</h3>
<div class="card stack">
  <?php if (!$notes): ?>
    <p class="small">No notes yet.</p>
  <?php else: ?>
    <?php foreach ($notes as $note): ?>
      <?php
        $author = trim(($note['author_first_name'] ?? '') . ' ' . ($note['author_last_name'] ?? ''));
        $body = trim((string)$note['body']);
      ?>
      <div>
        <p class="small" style="margin-bottom:2px;">
          <strong><?=h(date('M j, Y g:i A', strtotime((string)$note['created_at'])))?></strong>
          · <?=h($author !== '' ? $author : 'Imported')?>
          <?=!empty($note['status_after']) ? '· ' . lead_status_html((string)$note['status_after']) : ''?>
        </p>
        <?php if ($body !== ''): ?>
          <p class="small" style="margin-top:0;"><?=nl2br(h($body))?></p>
        <?php elseif (!empty($note['status_after'])): ?>
          <p class="small" style="margin-top:0;">Marked
            <strong><?=h(LeadManagement::STATUS_LABELS[(string)$note['status_after']] ?? $note['status_after'])?></strong>.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php footer_html(); ?>
