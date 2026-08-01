<?php
// Admin > Students: every student, filterable by keyword (prefix tokens over
// student + parent names, phones, address) and — under [+] — by teacher and
// instrument. Parents show name on one line, phone/email on the next.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
Application::init();
require_admin();

$q = trim((string)($_GET['q'] ?? ''));
$teacherId = (int)($_GET['teacher_id'] ?? 0) ?: null;
$instrumentId = (int)($_GET['instrument_id'] ?? 0) ?: null;
$semesterId = Application::adminSelectedSemesterId();

$students = StudentTeacherManagement::listStudentsFiltered($q, $teacherId, $instrumentId, $semesterId);
$teachers = StudentTeacherManagement::listTeachers();
$instruments = InstrumentCatalog::all();
$showMoreFilters = $teacherId !== null || $instrumentId !== null;

$flash = $_SESSION['people_flash'] ?? null;
$flashError = $_SESSION['people_flash_error'] ?? null;
unset($_SESSION['people_flash'], $_SESSION['people_flash_error']);

header_html('Students');
?>

<div class="page-head">
  <h2>Students</h2>
  <a class="button primary" href="/admin/student_add.php">Add Student</a>
</div>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<form method="get" action="/admin/students.php" data-auto-submit class="card">
  <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
    <label style="flex:1;min-width:240px;">Search
      <input type="text" name="q" value="<?=h($q)?>" placeholder="Student or parent name, phone, address...">
    </label>
    <button type="button" class="button" id="moreFiltersBtn" aria-expanded="<?=$showMoreFilters ? 'true' : 'false'?>">+</button>
  </div>
  <div id="moreFilters" class="grid-2" style="margin-top:10px;<?=$showMoreFilters ? '' : 'display:none;'?>">
    <label>Teacher
      <select name="teacher_id">
        <option value="">Any teacher</option>
        <?php foreach ($teachers as $teacher): ?>
          <option value="<?=(int)$teacher['id']?>" <?=$teacherId === (int)$teacher['id'] ? 'selected' : ''?>>
            <?=h($teacher['first_name'] . ' ' . $teacher['last_name'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Instrument
      <select name="instrument_id">
        <option value="">Any instrument</option>
        <?php foreach ($instruments as $instrument): ?>
          <option value="<?=(int)$instrument['id']?>" <?=$instrumentId === (int)$instrument['id'] ? 'selected' : ''?>>
            <?=h($instrument['name'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
</form>

<div class="card">
  <table class="list">
    <thead><tr><th>Name</th><th>Instruments</th><th>Parent(s)</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if (!$students): ?>
        <tr><td colspan="4" class="small" style="text-align:center;padding:20px;">No students match.</td></tr>
      <?php endif; ?>
      <?php foreach ($students as $student): ?>
      <tr>
        <td><strong><?=h($student['first_name'] . ' ' . $student['last_name'])?></strong></td>
        <td class="small"><?=h(implode(', ', $student['instruments']))?></td>
        <td>
          <?php foreach ($student['parents'] as $parent): ?>
            <div>
              <a href="/admin/parent_edit.php?id=<?=(int)$parent['id']?>"><?=h($parent['first_name'] . ' ' . $parent['last_name'])?></a>
              <div class="small"><?=h(trim(($parent['cell_phone'] ?? '') . '  ' . ($parent['email'] ?? '')))?></div>
            </div>
          <?php endforeach; ?>
        </td>
        <td><a class="button" href="/admin/student_edit.php?id=<?=(int)$student['id']?>">Edit</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var btn = document.getElementById('moreFiltersBtn');
  var panel = document.getElementById('moreFilters');
  btn.addEventListener('click', function () {
    var open = panel.style.display !== 'none';
    panel.style.display = open ? 'none' : '';
    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
  });
});
</script>

<?php footer_html(); ?>
