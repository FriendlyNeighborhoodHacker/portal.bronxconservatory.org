<?php
// Shared lesson form fields, used by lesson_add.php and lesson_edit.php (and
// their evals via lesson_data_from_post) so the form and its parsing live in
// one place.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
require_once __DIR__ . '/../lib/LocationManagement.php';

// Normalize a lesson form POST into the $fields array LessonManagement
// expects.
function lesson_data_from_post(array $post): array {
    return [
        'lesson_type' => ($post['lesson_type'] ?? 'individual') === 'group' ? 'group' : 'individual',
        'name' => $post['name'] ?? null,
        'instrument_id' => $post['instrument_id'] ?? null,
        'teacher_user_id' => (int)($post['teacher_user_id'] ?? 0),
        'student_user_id' => $post['student_user_id'] ?? null,
        'location_id' => $post['location_id'] ?? null,
        'room' => $post['room'] ?? null,
        'is_online' => !empty($post['is_online']),
        'start_datetime' => trim((string)($post['start_date'] ?? '')) . ' ' . trim((string)($post['start_time'] ?? '')),
        'duration_minutes' => (int)($post['duration_minutes'] ?? 30),
        'status' => $post['status'] ?? 'scheduled',
        'student_user_ids' => (array)($post['student_user_ids'] ?? []),
        'teacher_user_ids' => (array)($post['teacher_user_ids'] ?? []),
    ];
}

// Render the shared fields. $values holds current values (lesson row shape,
// with start_datetime split allowed via start_date/start_time keys).
function render_lesson_form_fields(array $values): void {
    $teachers = StudentTeacherManagement::listTeachers();
    $students = StudentTeacherManagement::listStudents();
    $instruments = InstrumentCatalog::all();
    $locations = LocationManagement::all(true);

    $type = ($values['lesson_type'] ?? 'individual') === 'group' ? 'group' : 'individual';
    $startDate = $values['start_date'] ?? (isset($values['start_datetime']) ? date('Y-m-d', strtotime($values['start_datetime'])) : '');
    $startTime = $values['start_time'] ?? (isset($values['start_datetime']) ? date('H:i', strtotime($values['start_datetime'])) : '');
    $groupStudentIds = array_map('intval', (array)($values['student_user_ids'] ?? []));
    ?>
    <div class="grid-2">
      <label>Type
        <select name="lesson_type" onchange="document.querySelectorAll('.individual-only,.group-only').forEach(function(el){el.style.display='none';});document.querySelectorAll('.'+this.value+'-only').forEach(function(el){el.style.display='';});">
          <option value="individual"<?=$type === 'individual' ? ' selected' : ''?>>Individual</option>
          <option value="group"<?=$type === 'group' ? ' selected' : ''?>>Group</option>
        </select>
      </label>
      <label class="group-only" style="<?=$type === 'group' ? '' : 'display:none;'?>">Class name
        <input type="text" name="name" value="<?=h($values['name'] ?? '')?>" placeholder="Saturday Violin Ensemble">
      </label>
      <label>Teacher
        <select name="teacher_user_id" required>
          <option value="">Choose…</option>
          <?php foreach ($teachers as $t): ?>
          <option value="<?=(int)$t['id']?>"<?=(int)($values['teacher_user_id'] ?? 0) === (int)$t['id'] ? ' selected' : ''?>><?=h($t['first_name'] . ' ' . $t['last_name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="individual-only" style="<?=$type === 'individual' ? '' : 'display:none;'?>">Student
        <select name="student_user_id">
          <option value="">Choose…</option>
          <?php foreach ($students as $s): ?>
          <option value="<?=(int)$s['id']?>"<?=(int)($values['student_user_id'] ?? 0) === (int)$s['id'] ? ' selected' : ''?>><?=h($s['first_name'] . ' ' . $s['last_name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Instrument
        <select name="instrument_id">
          <option value="">—</option>
          <?php foreach ($instruments as $inst): ?>
          <option value="<?=(int)$inst['id']?>"<?=(int)($values['instrument_id'] ?? 0) === (int)$inst['id'] ? ' selected' : ''?>><?=h($inst['name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Location
        <select name="location_id">
          <option value="">—</option>
          <?php foreach ($locations as $loc): ?>
          <option value="<?=(int)$loc['id']?>"<?=(int)($values['location_id'] ?? 0) === (int)$loc['id'] ? ' selected' : ''?>><?=h($loc['name'])?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Room
        <input type="text" name="room" value="<?=h($values['room'] ?? '')?>">
      </label>
      <label class="inline" style="align-self:end;">
        <input type="checkbox" name="is_online" value="1"<?=!empty($values['is_online']) ? ' checked' : ''?>> Online lesson
      </label>
      <label>Date
        <input type="date" name="start_date" required value="<?=h($startDate)?>">
      </label>
      <label>Time
        <input type="time" name="start_time" required step="300" value="<?=h($startTime)?>">
      </label>
      <label>Length (minutes)
        <input type="number" name="duration_minutes" min="15" step="15" value="<?=(int)($values['duration_minutes'] ?? 30)?>">
      </label>
      <?php if (isset($values['status'])): ?>
      <label>Status
        <select name="status">
          <?php foreach (['scheduled', 'completed', 'cancelled'] as $status): ?>
          <option value="<?=$status?>"<?=($values['status'] ?? '') === $status ? ' selected' : ''?>><?=ucfirst($status)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
    </div>
    <div class="group-only" style="<?=$type === 'group' ? '' : 'display:none;'?>">
      <div>Group roster:</div>
      <div class="choice-grid">
        <?php foreach ($students as $s): ?>
        <label class="inline"><input type="checkbox" name="student_user_ids[]" value="<?=(int)$s['id']?>"<?=in_array((int)$s['id'], $groupStudentIds, true) ? ' checked' : ''?>> <?=h($s['first_name'] . ' ' . $s['last_name'])?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}
