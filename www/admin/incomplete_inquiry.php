<?php
// One uncompleted information-request form — and the means to finish it.
//
// Staff call these families. Everything the visitor entered is editable so a
// correction from that call can be recorded, and the rest of the form (the
// student, and the questions page 4 would have asked) is here too, so a call
// that goes well ends in a real lead rather than in a note saying it did.
//
// One form, two buttons: Save keeps the contact details, Complete finishes the
// job. Both carry every field, so nothing typed is ever lost by picking the
// wrong one.
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
$notes = InquiryManagement::notesFor($id);
$semesterOptions = Settings::inquirySemesterOptions();

$flash = $_SESSION['inquiry_flash'] ?? null;
$flashError = $_SESSION['inquiry_flash_error'] ?? null;
$old = (array)($_SESSION['incomplete_inquiry_old'] ?? []);
$noteOld = $_SESSION['incomplete_inquiry_note_old'] ?? '';
unset(
    $_SESSION['inquiry_flash'], $_SESSION['inquiry_flash_error'],
    $_SESSION['incomplete_inquiry_old'], $_SESSION['incomplete_inquiry_note_old']
);

// What was typed on a failed attempt wins over what is stored — an admin must
// never have to retype a form the server rejected.
$v = fn(string $key, $default = '') => (string)($old[$key] ?? $row[$key] ?? $default);
$checked = fn(string $key, string $value) => in_array($value, (array)($old[$key] ?? []), true) ? ' checked' : '';
$radio = fn(string $key, string $value) => (string)($old[$key] ?? '') === $value ? ' checked' : '';
$country = $v('address_country', InquiryManagement::DEFAULT_COUNTRY) ?: InquiryManagement::DEFAULT_COUNTRY;
$isUs = $country === InquiryManagement::DEFAULT_COUNTRY;
$boxChecked = fn(string $key) => (
    $old ? !empty($old[$key]) : !empty($row[$key])
) ? ' checked' : '';

header_html($row['first_name'] . ' ' . $row['last_name'] . ' — uncompleted form', ['wide' => true]);
?>

<div class="page-head">
  <h2><?=h($row['first_name'] . ' ' . $row['last_name'])?>
    <span class="status-pill">Uncompleted form</span></h2>
  <a class="button" href="/admin/incomplete_inquiries.php">Back to Uncompleted Forms</a>
</div>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<p class="small">Started the
<strong><?=($row['source'] ?? 'inquiry') === 'registration' ? 'Registration' : 'Request Information'?></strong> form on
<?=h(date('M j, Y g:i A', strtotime((string)$row['created_at'])))?> and stopped after
<strong><?=h(strtolower(incomplete_inquiry_stage_label($row)))?></strong>. Fill in the rest —
a student is all it takes — and <strong>Complete</strong> turns this into a lead.</p>

<form method="post" action="/admin/incomplete_inquiry_save_eval.php" class="stack">
  <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
  <input type="hidden" name="id" value="<?=$id?>">

  <div class="card stack">
    <h3>Contact</h3>
    <div class="grid-2">
      <label>First name
        <input type="text" name="first_name" required value="<?=h($v('first_name'))?>">
      </label>
      <label>Last name
        <input type="text" name="last_name" required value="<?=h($v('last_name'))?>">
      </label>
      <label>Email
        <input type="email" name="email" required value="<?=h($v('email'))?>">
      </label>
      <label>Phone
        <input type="tel" name="phone" required value="<?=h($v('phone'))?>">
      </label>
    </div>
    <label class="inline">
      <input type="checkbox" name="newsletter_opt_in" value="1"<?=$boxChecked('newsletter_opt_in')?>>
      Subscribe to the newsletter
    </label>
    <label class="inline">
      <input type="checkbox" name="sms_consent" value="1"<?=$boxChecked('sms_consent')?>>
      We may text them with important updates
    </label>
  </div>

  <div class="card stack">
    <h3>Mailing address <span class="small">(optional)</span></h3>
    <label>Country
      <select name="address_country" id="inqCountry">
        <?php foreach (InquiryManagement::COUNTRIES as $option): ?>
        <option value="<?=h($option)?>"<?=$option === $country ? ' selected' : ''?>><?=h($option)?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Street address
      <input type="text" name="address_street_1" value="<?=h($v('address_street_1'))?>">
    </label>
    <label>Address line 2
      <input type="text" name="address_street_2" value="<?=h($v('address_street_2'))?>">
    </label>
    <div class="grid-3">
      <label>City
        <input type="text" name="address_city" value="<?=h($v('address_city'))?>">
      </label>
      <label id="inqStateWrap"<?=$isUs ? '' : ' class="hidden"'?>>State
        <select name="address_state">
          <option value="">— choose —</option>
          <?php $savedState = strtoupper($isUs ? $v('address_state') : ''); ?>
          <?php foreach (InquiryManagement::US_STATES as $code => $name): ?>
          <option value="<?=h($code)?>"<?=$code === $savedState ? ' selected' : ''?>><?=h($name)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label id="inqProvinceWrap"<?=$isUs ? ' class="hidden"' : ''?>>State / Province / Region
        <input type="text" name="address_province"
               value="<?=h($old['address_province'] ?? ($isUs ? '' : (string)($row['address_state'] ?? '')))?>">
      </label>
      <label>Postal code
        <input type="text" name="address_zip" value="<?=h($v('address_zip'))?>">
      </label>
    </div>
  </div>

  <div class="card stack">
    <h3>Student <span class="small">(needed to complete the form)</span></h3>
    <div class="grid-2">
      <label>Student first name
        <input type="text" name="student_first_name" value="<?=h($old['student_first_name'] ?? '')?>">
      </label>
      <label>Student last name
        <input type="text" name="student_last_name" value="<?=h($old['student_last_name'] ?? '')?>">
      </label>
      <label>Student age
        <input type="number" name="student_age" min="1" max="120" value="<?=h($old['student_age'] ?? '')?>">
      </label>
    </div>

    <div class="form-question">
      <div>New student or continuing student?</div>
      <div class="choice-list">
        <?php foreach (LeadManagement::ENROLLMENT_STATUSES as $value => $label): ?>
        <label class="inline">
          <input type="radio" name="enrollment_status" value="<?=h($value)?>"<?=$radio('enrollment_status', $value)?>>
          <?=h($label)?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-question">
      <div>Instruments of interest</div>
      <div class="choice-grid">
        <?php foreach (LeadManagement::INQUIRY_INSTRUMENT_INTERESTS as $instrument): ?>
        <label class="inline">
          <input type="checkbox" name="instruments_of_interest[]" value="<?=h($instrument)?>"<?=$checked('instruments_of_interest', $instrument)?>>
          <?=h($instrument)?>
        </label>
        <?php endforeach; ?>
      </div>
      <label>If Other, which instrument?
        <input type="text" name="instruments_other" value="<?=h($old['instruments_other'] ?? '')?>">
      </label>
    </div>
  </div>

  <div class="card stack">
    <h3>Anything else they told you <span class="small">(all optional)</span></h3>

    <div class="form-question">
      <div>Term</div>
      <div class="choice-list">
        <label class="inline">
          <input type="radio" name="semester_label" value=""<?=$radio('semester_label', '')?>> Not discussed
        </label>
        <?php foreach ($semesterOptions as $option): ?>
        <label class="inline">
          <input type="radio" name="semester_label" value="<?=h($option)?>"<?=$radio('semester_label', $option)?>>
          <?=h($option)?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-question">
      <div>Instruments they own</div>
      <div class="choice-grid">
        <?php foreach (LeadManagement::OWNED_INSTRUMENT_CHOICES as $instrument): ?>
        <label class="inline">
          <input type="checkbox" name="owned_instruments[]" value="<?=h($instrument)?>"<?=$checked('owned_instruments', $instrument)?>>
          <?=h($instrument)?>
        </label>
        <?php endforeach; ?>
      </div>
      <label>If Other, which instrument?
        <input type="text" name="owned_instruments_other" value="<?=h($old['owned_instruments_other'] ?? '')?>">
      </label>
    </div>

    <label>Have they studied music?
      <textarea name="music_background" rows="3" placeholder="Level and length of study."><?=h($old['music_background'] ?? '')?></textarea>
    </label>

    <div class="form-question">
      <div>Interested in the free music theory program?</div>
      <div class="choice-list">
        <?php foreach (LeadManagement::THEORY_INTEREST_LABELS as $value => $label): ?>
        <label class="inline">
          <input type="radio" name="theory_program_interest" value="<?=h($value)?>"<?=$radio('theory_program_interest', $value)?>>
          <?=h($label)?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-question">
      <div>Music theory knowledge</div>
      <div class="choice-list">
        <?php foreach (LeadManagement::THEORY_KNOWLEDGE_LABELS as $value => $label): ?>
        <label class="inline">
          <input type="radio" name="theory_knowledge" value="<?=h($value)?>"<?=$radio('theory_knowledge', $value)?>>
          <?=h($label)?>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <label>Comments
      <textarea name="comments" rows="3" placeholder="Questions or concerns."><?=h($old['comments'] ?? '')?></textarea>
    </label>

    <label>How did they hear about us?
      <select name="referral_source">
        <option value="">— not discussed —</option>
        <?php foreach (InquiryManagement::REFERRAL_SOURCES as $source): ?>
        <option value="<?=h($source)?>"<?=(string)($old['referral_source'] ?? '') === $source ? ' selected' : ''?>><?=h($source)?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <div class="actions actions-split">
    <button type="submit" class="button">Save Changes</button>
    <span class="actions-right">
      <button type="submit" class="btn-cta"
              formaction="/admin/incomplete_inquiry_complete_eval.php">Complete and Create Lead</button>
    </span>
  </div>
  <p class="small">Completing moves this family into the Leads queue and removes it from
  Uncompleted Forms. Any notes below travel with them.</p>
</form>

<h3>Add a note</h3>
<div class="card">
  <form method="post" action="/admin/incomplete_inquiry_note_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$id?>">
    <label>Note (admins only)
      <textarea name="note_body" rows="3" placeholder="Left a voicemail 8/4…"><?=h($noteOld)?></textarea>
    </label>
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
      <?php $author = trim(($note['author_first_name'] ?? '') . ' ' . ($note['author_last_name'] ?? '')); ?>
      <div>
        <p class="small" style="margin-bottom:2px;">
          <strong><?=h(date('M j, Y g:i A', strtotime((string)$note['created_at'])))?></strong>
          · <?=h($author !== '' ? $author : 'Imported')?>
        </p>
        <p class="small" style="margin-top:0;"><?=nl2br(h($note['body']))?></p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="card">
  <form method="post" action="/admin/incomplete_inquiry_delete_eval.php">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$id?>">
    <button type="submit" class="button danger"
      data-confirm="Delete this uncompleted form? Everything they entered, and every note on it, is removed.">Delete</button>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var country = document.getElementById('inqCountry');
  function sync() {
    var isUs = country.value === <?=json_encode(InquiryManagement::DEFAULT_COUNTRY)?>;
    document.getElementById('inqStateWrap').classList.toggle('hidden', !isUs);
    document.getElementById('inqProvinceWrap').classList.toggle('hidden', isUs);
  }
  country.addEventListener('change', sync);
  sync();
});
</script>

<?php footer_html(); ?>
