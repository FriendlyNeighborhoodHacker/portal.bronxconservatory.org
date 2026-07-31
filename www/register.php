<?php
// Public family registration form (docs/registration_flow.md). One form, two
// paths: "Register & Complete Enrollment" (gold) and "Register — I'd Like to
// Talk First" (outlined). Evaluates to register_eval.php.
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/InstrumentCatalog.php';
require_once __DIR__ . '/lib/LocationManagement.php';

Application::init();

// One-shot flash values set by register_eval.php on validation failure —
// the form repopulates so nothing has to be retyped.
$error = $_SESSION['register_error'] ?? null;
$old = $_SESSION['register_old'] ?? [];
unset($_SESSION['register_error'], $_SESSION['register_old']);

$instruments = InstrumentCatalog::all();
$locations = LocationManagement::all(true);

$oldParent = $old['parent'] ?? [];
$oldStudents = array_values($old['students'] ?? []);
if (!$oldStudents) {
    $oldStudents = [[]]; // one empty student block to start
}
$oldPrefs = $old['prefs'] ?? [];

function checked_in_csv($value, $list): string {
    return in_array($value, (array)$list, true) ? ' checked' : '';
}
function sel($a, $b): string {
    return (string)$a === (string)$b ? ' selected' : '';
}

ApplicationUI::minimalHeaderHtml('Register');
?>
<div class="register-page">
  <h1>Register with the Bronx Conservatory of Music</h1>
  <p class="register-intro">
    Private music lessons of the highest quality, in your own neighborhood, at the
    lowest possible tuition. Fill in the form below — or call us at
    <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a> and we'll walk
    through it together.
  </p>

  <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

  <form method="post" action="/register_eval.php" class="stack" data-skip-unsaved-warning>
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">

    <fieldset class="register-section">
      <legend>Parent / Guardian</legend>
      <div class="grid-2">
        <label>First name
          <input type="text" name="parent[first_name]" required value="<?=h($oldParent['first_name'] ?? '')?>">
        </label>
        <label>Last name
          <input type="text" name="parent[last_name]" required value="<?=h($oldParent['last_name'] ?? '')?>">
        </label>
        <label>Email
          <input type="email" name="parent[email]" required value="<?=h($oldParent['email'] ?? '')?>">
        </label>
        <label>Cell phone
          <input type="tel" name="parent[cell_phone]" required value="<?=h($oldParent['cell_phone'] ?? '')?>">
        </label>
        <label>Relationship to student(s)
          <select name="parent[relationship]">
            <option value="">Choose…</option>
            <option value="mother"<?=sel('mother', $oldParent['relationship'] ?? '')?>>Mother</option>
            <option value="father"<?=sel('father', $oldParent['relationship'] ?? '')?>>Father</option>
            <option value="guardian"<?=sel('guardian', $oldParent['relationship'] ?? '')?>>Guardian</option>
          </select>
        </label>
        <label>How should we contact you?
          <select name="parent[preferred_contact_method]">
            <option value="phone"<?=sel('phone', $oldParent['preferred_contact_method'] ?? 'phone')?>>Phone call</option>
            <option value="text"<?=sel('text', $oldParent['preferred_contact_method'] ?? '')?>>Text message</option>
            <option value="email"<?=sel('email', $oldParent['preferred_contact_method'] ?? '')?>>Email</option>
          </select>
        </label>
      </div>
      <label>Street address
        <input type="text" name="parent[address_street_1]" value="<?=h($oldParent['address_street_1'] ?? '')?>">
      </label>
      <label>Apt / unit (optional)
        <input type="text" name="parent[address_street_2]" value="<?=h($oldParent['address_street_2'] ?? '')?>">
      </label>
      <div class="grid-3">
        <label>City
          <input type="text" name="parent[address_city]" value="<?=h($oldParent['address_city'] ?? 'Bronx')?>">
        </label>
        <label>State
          <input type="text" name="parent[address_state]" value="<?=h($oldParent['address_state'] ?? 'NY')?>">
        </label>
        <label>ZIP
          <input type="text" name="parent[address_zip]" value="<?=h($oldParent['address_zip'] ?? '')?>">
        </label>
      </div>
      <label class="inline">
        <input type="checkbox" name="parent[parent_is_student]" value="1"<?=!empty($oldParent['parent_is_student']) ? ' checked' : ''?>>
        I'm registering for lessons for myself (adult student)
      </label>
    </fieldset>

    <fieldset class="register-section">
      <legend>Students</legend>
      <div id="studentBlocks">
        <?php foreach ($oldStudents as $i => $s): ?>
        <div class="student-block">
          <button type="button" class="remove-student btn-outline" style="padding:2px 10px;font-size:13px;" onclick="removeStudent(this)">Remove</button>
          <div class="grid-2">
            <label>First name
              <input type="text" name="students[<?=$i?>][first_name]" value="<?=h($s['first_name'] ?? '')?>">
            </label>
            <label>Last name
              <input type="text" name="students[<?=$i?>][last_name]" value="<?=h($s['last_name'] ?? '')?>">
            </label>
            <label>Date of birth
              <input type="date" name="students[<?=$i?>][date_of_birth]" value="<?=h($s['date_of_birth'] ?? '')?>">
            </label>
            <label>Previous experience
              <select name="students[<?=$i?>][experience_level]">
                <option value="none"<?=sel('none', $s['experience_level'] ?? 'none')?>>None</option>
                <option value="beginner"<?=sel('beginner', $s['experience_level'] ?? '')?>>Beginner</option>
                <option value="intermediate"<?=sel('intermediate', $s['experience_level'] ?? '')?>>Intermediate</option>
                <option value="advanced"<?=sel('advanced', $s['experience_level'] ?? '')?>>Advanced</option>
              </select>
            </label>
            <label>School name
              <input type="text" name="students[<?=$i?>][school_name]" value="<?=h($s['school_name'] ?? '')?>">
            </label>
            <label>Grade
              <input type="text" name="students[<?=$i?>][grade]" value="<?=h($s['grade'] ?? '')?>">
            </label>
          </div>
          <div>Instrument(s) of interest:</div>
          <div class="choice-grid">
            <?php foreach ($instruments as $inst): ?>
            <label class="inline">
              <input type="checkbox" name="students[<?=$i?>][instrument_ids][]" value="<?=(int)$inst['id']?>"<?=checked_in_csv((string)$inst['id'], array_map('strval', (array)($s['instrument_ids'] ?? [])))?>>
              <?=h($inst['name'])?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn-outline" onclick="addStudent()">+ Add another student</button>
    </fieldset>

    <fieldset class="register-section">
      <legend>Scheduling Preferences</legend>
      <div>Preferred day(s):</div>
      <div class="choice-grid">
        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $day): ?>
        <label class="inline"><input type="checkbox" name="prefs[preferred_days][]" value="<?=$day?>"<?=checked_in_csv($day, $oldPrefs['preferred_days'] ?? [])?>> <?=$day?></label>
        <?php endforeach; ?>
      </div>
      <div>Preferred time window:</div>
      <div class="choice-grid">
        <?php foreach (['morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening'] as $val => $label): ?>
        <label class="inline"><input type="checkbox" name="prefs[time_window][]" value="<?=$val?>"<?=checked_in_csv($val, $oldPrefs['time_window'] ?? [])?>> <?=$label?></label>
        <?php endforeach; ?>
      </div>
      <div class="grid-2">
        <label>Preferred location
          <select name="prefs[preferred_location_id]">
            <option value="">No preference</option>
            <?php foreach ($locations as $loc): ?>
            <option value="<?=(int)$loc['id']?>"<?=sel($loc['id'], $oldPrefs['preferred_location_id'] ?? '')?>><?=h($loc['name'])?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Teacher preference
          <select name="prefs[teacher_gender_pref]">
            <option value="none"<?=sel('none', $oldPrefs['teacher_gender_pref'] ?? 'none')?>>No preference</option>
            <option value="female"<?=sel('female', $oldPrefs['teacher_gender_pref'] ?? '')?>>Female teacher</option>
            <option value="male"<?=sel('male', $oldPrefs['teacher_gender_pref'] ?? '')?>>Male teacher</option>
          </select>
        </label>
      </div>
      <label>Anything we should know about scheduling? (e.g. "siblings need back-to-back")
        <textarea name="prefs[constraints_text]" rows="2"><?=h($oldPrefs['constraints_text'] ?? '')?></textarea>
      </label>
    </fieldset>

    <fieldset class="register-section">
      <legend>Emergency &amp; Medical</legend>
      <div class="grid-2">
        <label>Emergency contact name
          <input type="text" name="parent[emergency_contact_name]" required value="<?=h($oldParent['emergency_contact_name'] ?? '')?>">
        </label>
        <label>Emergency contact phone
          <input type="tel" name="parent[emergency_contact_phone]" required value="<?=h($oldParent['emergency_contact_phone'] ?? '')?>">
        </label>
      </div>
      <label>Medical conditions or allergies (optional)
        <textarea name="parent[medical_notes]" rows="2"><?=h($oldParent['medical_notes'] ?? '')?></textarea>
      </label>
      <div class="checkbox-row"><input type="checkbox" id="c_photo" name="prefs[consent_photo_release]" value="1"<?=!empty($oldPrefs['consent_photo_release']) ? ' checked' : ''?>>
        <label for="c_photo">I give BCM permission to use photos of my student(s) in its materials (photo release)</label></div>
      <div class="checkbox-row"><input type="checkbox" id="c_terms" name="prefs[consent_terms]" value="1" required<?=!empty($oldPrefs['consent_terms']) ? ' checked' : ''?>>
        <label for="c_terms">I agree to BCM's terms and conditions</label></div>
      <div class="checkbox-row"><input type="checkbox" id="c_liability" name="prefs[consent_liability]" value="1" required<?=!empty($oldPrefs['consent_liability']) ? ' checked' : ''?>>
        <label for="c_liability">I agree to the liability waiver</label></div>
    </fieldset>

    <fieldset class="register-section">
      <legend>How did you hear about us?</legend>
      <select name="prefs[how_heard]">
        <option value="">Choose…</option>
        <option value="friend_family"<?=sel('friend_family', $oldPrefs['how_heard'] ?? '')?>>Friend or family</option>
        <option value="school"<?=sel('school', $oldPrefs['how_heard'] ?? '')?>>School</option>
        <option value="community_org"<?=sel('community_org', $oldPrefs['how_heard'] ?? '')?>>Community organization</option>
        <option value="social_media"<?=sel('social_media', $oldPrefs['how_heard'] ?? '')?>>Social media</option>
        <option value="website"<?=sel('website', $oldPrefs['how_heard'] ?? '')?>>Website</option>
        <option value="other"<?=sel('other', $oldPrefs['how_heard'] ?? '')?>>Other</option>
      </select>
    </fieldset>

    <div class="register-actions">
      <button type="submit" name="submit_action" value="complete_enrollment" class="btn-cta">Register &amp; Complete Enrollment</button>
      <button type="submit" name="submit_action" value="talk_first" class="btn-outline">Register — I'd Like to Talk First</button>
    </div>
    <p class="small">Either way, there's nothing to pay right now — we'll confirm your
      schedule first. Questions? Call <a href="tel:+17188417415"><?=h(Settings::contactPhone())?></a>.</p>
  </form>
</div>

<template id="studentTemplate">
  <div class="student-block">
    <button type="button" class="remove-student btn-outline" style="padding:2px 10px;font-size:13px;" onclick="removeStudent(this)">Remove</button>
    <div class="grid-2">
      <label>First name <input type="text" name="students[__N__][first_name]"></label>
      <label>Last name <input type="text" name="students[__N__][last_name]"></label>
      <label>Date of birth <input type="date" name="students[__N__][date_of_birth]"></label>
      <label>Previous experience
        <select name="students[__N__][experience_level]">
          <option value="none">None</option>
          <option value="beginner">Beginner</option>
          <option value="intermediate">Intermediate</option>
          <option value="advanced">Advanced</option>
        </select>
      </label>
      <label>School name <input type="text" name="students[__N__][school_name]"></label>
      <label>Grade <input type="text" name="students[__N__][grade]"></label>
    </div>
    <div>Instrument(s) of interest:</div>
    <div class="choice-grid">
      <?php foreach ($instruments as $inst): ?>
      <label class="inline"><input type="checkbox" name="students[__N__][instrument_ids][]" value="<?=(int)$inst['id']?>"> <?=h($inst['name'])?></label>
      <?php endforeach; ?>
    </div>
  </div>
</template>

<script>
var nextStudentIndex = <?=count($oldStudents)?>;
function addStudent() {
  var tpl = document.getElementById('studentTemplate').innerHTML.replace(/__N__/g, nextStudentIndex++);
  var wrap = document.createElement('div');
  wrap.innerHTML = tpl;
  document.getElementById('studentBlocks').appendChild(wrap.firstElementChild);
}
function removeStudent(btn) {
  var blocks = document.querySelectorAll('#studentBlocks .student-block');
  if (blocks.length <= 1) {
    // Keep at least one block; just clear it.
    blocks[0].querySelectorAll('input[type=text],input[type=date]').forEach(function (el) { el.value = ''; });
    blocks[0].querySelectorAll('input[type=checkbox]').forEach(function (el) { el.checked = false; });
    return;
  }
  btn.closest('.student-block').remove();
}
</script>
<?php ApplicationUI::minimalFooterHtml(); ?>
