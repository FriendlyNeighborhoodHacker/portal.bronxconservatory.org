<?php
// Admin > Teachers: every teacher, filterable by keyword and — under [+] —
// by instrument. Columns: Name, Contact, Actions.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
Application::init();
require_admin();

$q = trim((string)($_GET['q'] ?? ''));
$instrumentId = (int)($_GET['instrument_id'] ?? 0) ?: null;

$teachers = StudentTeacherManagement::listTeachersFiltered($q, $instrumentId);
$instruments = InstrumentCatalog::all();
$showMoreFilters = $instrumentId !== null;

$flash = $_SESSION['people_flash'] ?? null;
$flashError = $_SESSION['people_flash_error'] ?? null;
$importFlash = $_SESSION['import_flash'] ?? null;
unset($_SESSION['people_flash'], $_SESSION['people_flash_error'], $_SESSION['import_flash']);

header_html('Teachers');
?>

<div class="page-head">
  <h2>Teachers</h2>
  <span class="actions">
    <a class="button" href="/admin/import/upload.php?flow=teachers&next=<?=h(urlencode('/admin/teachers.php'))?>">Upload CSV</a>
    <a class="button primary" href="/admin/teacher_add.php">Add Teacher</a>
  </span>
</div>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($importFlash): ?><p class="flash"><?=h($importFlash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<form method="get" action="/admin/teachers.php" data-auto-submit class="card">
  <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
    <label style="flex:1;min-width:240px;">Search
      <input type="text" name="q" value="<?=h($q)?>" placeholder="Name, phone, email...">
    </label>
    <button type="button" class="button" id="moreFiltersBtn" aria-expanded="<?=$showMoreFilters ? 'true' : 'false'?>">+</button>
  </div>
  <div id="moreFilters" class="grid-2" style="margin-top:10px;<?=$showMoreFilters ? '' : 'display:none;'?>">
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
    <thead><tr><th>Name</th><th>Instruments</th><th>Contact</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if (!$teachers): ?>
        <tr><td colspan="4" class="small" style="text-align:center;padding:20px;">No teachers match.</td></tr>
      <?php endif; ?>
      <?php foreach ($teachers as $teacher): ?>
      <tr>
        <td><strong><?=h($teacher['first_name'] . ' ' . $teacher['last_name'])?></strong></td>
        <td class="small"><?=h(implode(', ', $teacher['instruments']))?></td>
        <td class="small">
          <div><?=h((string)($teacher['email'] ?? ''))?></div>
          <div><?=h((string)($teacher['cell_phone'] ?? ''))?></div>
        </td>
        <td><a class="button" href="/admin/teacher_edit.php?id=<?=(int)$teacher['id']?>">Edit</a></td>
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
