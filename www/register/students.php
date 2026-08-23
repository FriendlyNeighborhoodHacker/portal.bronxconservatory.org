<?php
// Registration wizard step 2: students + scheduling preferences. All buttons
// (add/remove student, back, continue) post to students_eval.php so answers
// survive without JavaScript.
require_once __DIR__ . '/register_ui.php';
Application::init();
$semester = registration_require_open();
$state = registration_require_step(2);

$error = registration_flash_error();
$students = array_values((array)$state['students']) ?: [[]];
$scheduling = (array)$state['scheduling'];

$dollars = fn(int $c) => registration_money($c);

ApplicationUI::minimalHeaderHtml('Register — Students');
?>
<div style="max-width:720px;margin:0 auto;">
  <h1>Lesson Preferences</h1>
  <?=registration_steps_html(2)?>

  <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

  <form method="post" action="/register/students_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

    <h3>Student Information</h3>
    <p class="small">Please tell us about the student(s) who will be studying.</p>

    <?php foreach ($students as $i => $student): ?>
    <fieldset class="card stack" style="position:relative;">
      <legend style="font-weight:700;">Student <?=$i + 1?></legend>
      <?php if (count($students) > 1): ?>
        <button type="submit" name="action" value="remove:<?=$i?>" class="btn-outline"
          style="position:absolute;top:8px;right:8px;padding:2px 10px;font-size:13px;">Remove</button>
      <?php endif; ?>
      <div class="grid-2">
        <label>First name
          <input type="text" name="students[<?=$i?>][first_name]" value="<?=h($student['first_name'] ?? '')?>">
        </label>
        <label>Last name
          <input type="text" name="students[<?=$i?>][last_name]" value="<?=h($student['last_name'] ?? '')?>">
        </label>
        <label>Class of <span class="small">(graduation year, optional)</span>
          <input type="number" name="students[<?=$i?>][class_of]" min="1900" max="2200" step="1"
            placeholder="<?=(int)date('Y') + 6?>" value="<?=h($student['class_of'] ?? '')?>">
        </label>
        <label>Instrument
          <select name="students[<?=$i?>][instrument]">
            <option value="">Choose…</option>
            <?php foreach (LeadManagement::INSTRUMENT_CHOICES as $choice): ?>
            <option value="<?=h($choice)?>"<?=($student['instrument'] ?? '') === $choice ? ' selected' : ''?>><?=h($choice)?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Shirt size <span class="small">(optional)</span>
          <select name="students[<?=$i?>][shirt_size]">
            <option value="">Choose…</option>
            <?php foreach (LeadManagement::SHIRT_SIZES as $size): ?>
            <option value="<?=h($size)?>"<?=($student['shirt_size'] ?? '') === $size ? ' selected' : ''?>><?=h($size)?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Lesson length
          <select name="students[<?=$i?>][lesson_length_minutes]">
            <option value="30"<?=(int)($student['lesson_length_minutes'] ?? 30) === 30 ? ' selected' : ''?>>30 minutes (<?=$dollars(SemesterManagement::lessonFeeCents($semester, 30))?>/semester)</option>
            <option value="60"<?=(int)($student['lesson_length_minutes'] ?? 0) === 60 ? ' selected' : ''?>>60 minutes (<?=$dollars(SemesterManagement::lessonFeeCents($semester, 60))?>/semester)</option>
          </select>
        </label>
      </div>
      <label class="inline">
        <input type="checkbox" name="students[<?=$i?>][guitar_ensemble]" value="1"<?=!empty($student['guitar_ensemble']) ? ' checked' : ''?>>
        Also interested in the 30-minute Guitar Ensemble (<?=$dollars(SemesterManagement::guitarEnsembleFeeCents($semester))?>/semester)
      </label>
    </fieldset>
    <?php endforeach; ?>

    <div>
      <button type="submit" name="action" value="add" class="btn-outline">+ Add another student</button>
    </div>

    <h3>Location Preference</h3>
    <p class="form-question" id="q-location">Bronx Conservatory of Music has two locations.
      Please indicate your preference here.
      <span class="small">PLEASE NOTE: if you want a weekday evening, select
      Bronx Community College.</span></p>
    <div class="choice-list choice-indent" role="radiogroup" aria-labelledby="q-location">
      <label class="inline">
        <input type="radio" name="location_preference" value="Bronx Community College"<?=($scheduling['location_preference'] ?? '') === 'Bronx Community College' ? ' checked' : ''?>>
        <span><strong>Bronx Community College</strong>, 200 Hall of Fame Terrace (ample parking) —
          Saturdays 9:00am–6:00pm; Tuesday evenings 3:30pm–8:00pm</span>
      </label>
      <label class="inline">
        <input type="radio" name="location_preference" value="Access Bronx Charter School"<?=($scheduling['location_preference'] ?? '') === 'Access Bronx Charter School' ? ' checked' : ''?>>
        <span><strong>Access Bronx Charter Elementary School</strong>, 388 Willis Ave —
          Saturdays 9:00am–2:00pm <em>(Saturdays only)</em></span>
      </label>
    </div>

    <h3>Preferred Day(s)</h3>
    <div class="choice-grid">
      <?php foreach (['Saturday', 'Tuesday'] as $day): ?>
      <label class="inline">
        <input type="checkbox" name="preferred_days[]" value="<?=$day?>"<?=in_array($day, (array)($scheduling['preferred_days'] ?? []), true) ? ' checked' : ''?>>
        <?=$day?>
      </label>
      <?php endforeach; ?>
    </div>

    <h3>Scheduling Information</h3>
    <p>Please check ALL boxes representing (in whole or in part) times that you are able to
      attend BCM.
      <span class="small">Please note that studios fill quickly, and while we take this
      information into consideration, preferred times are not always available.</span></p>
    <p class="form-question" id="q-availability">Check All That Apply <span class="required">*</span></p>
    <div class="choice-list" role="group" aria-labelledby="q-availability">
      <?php foreach (LeadManagement::AVAILABILITY_BLOCKS as $value => $label): ?>
      <label class="inline">
        <input type="checkbox" name="availability_blocks[]" value="<?=h($value)?>"<?=in_array($value, (array)($scheduling['availability_blocks'] ?? []), true) ? ' checked' : ''?>>
        <?=h($label)?>
      </label>
      <?php endforeach; ?>
    </div>

    <h3>Additional Information</h3>
    <label><span class="label-prose">Are you only available Tuesdays after 5:00? Need to leave
      by 10:30 on Saturday? Please use this (optional) space to let us know any particular
      constraints/requests that you have, or further clarify the info given above.</span>
      <textarea name="scheduling_notes" rows="3"><?=h($scheduling['notes'] ?? '')?></textarea>
    </label>

    <div class="card">
      <h3 style="margin-top:0;">Recital &amp; Logistics Fee</h3>
      <p class="small">A <?=$dollars(SemesterManagement::recitalFeeCents($semester))?> per lesson block, per student, per semester fee will be
        added at registration. This is not <?=$dollars(SemesterManagement::recitalFeeCents($semester))?> per individual lesson, but a one-time
        fee per subject.</p>
      <p class="small">Examples:<br>
        • 1 student taking piano = <?=$dollars(SemesterManagement::recitalFeeCents($semester))?> total<br>
        • 1 student taking both piano &amp; violin = <?=$dollars(2 * SemesterManagement::recitalFeeCents($semester))?> total<br>
        • 2 siblings taking 1 lesson each = <?=$dollars(2 * SemesterManagement::recitalFeeCents($semester))?> total<br>
        • 3 siblings taking 1 lesson each = <?=$dollars(3 * SemesterManagement::recitalFeeCents($semester))?>.</p>
      <p class="small">This supports recital venue costs, accompanists, program printing, and
        other logistical fees. Thank you for helping us provide high-quality performance
        experiences!</p>
    </div>

    <div class="actions">
      <button type="submit" name="action" value="back" class="btn-outline">Back</button>
      <button type="submit" name="action" value="next" class="btn-cta">Continue</button>
    </div>
  </form>
</div>
<?php ApplicationUI::minimalFooterHtml(); ?>
