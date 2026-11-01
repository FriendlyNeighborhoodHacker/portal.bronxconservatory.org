<?php
// First-time setup landing: walks a new admin through bootstrapping the
// portal — locations, teachers, then the first semester. Each step shows
// whether it looks done and links to the page that does the work.
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/LocationManagement.php';
require_once __DIR__ . '/../../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../../lib/SemesterManagement.php';
require_once __DIR__ . '/../../lib/SampleDataLoader.php';
Application::init();
require_admin();

$locations = LocationManagement::all(true);
$teachers = StudentTeacherManagement::listTeachers();
$hasSemester = SemesterManagement::hasAnySemester();

$flash = $_SESSION['import_flash'] ?? null;
$flashError = $_SESSION['import_flash_error'] ?? null;
unset($_SESSION['import_flash'], $_SESSION['import_flash_error']);

// Dev instances only (DEV_SITE in config.local.php): one click loads the whole sample_data/ set.
$sampleDataAvailable = SampleDataLoader::isAvailable();

header_html('Get Started');
?>

<h2>Welcome! Let's set up the portal.</h2>
<p class="small">Three steps: confirm your teaching locations, load your
teachers, then create your first semester. You can come back here any time —
this page disappears once a semester exists.</p>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<?php if ($sampleDataAvailable): ?>
<div class="card">
  <h3>Dev · Load Sample Data <span class="badge">dev site only</span></h3>
  <p class="small">Resets the database to a fresh demo, then loads everything in
  <code>sample_data/</code> in the order its README prescribes: locations, teachers,
  students and parents, then Fall 2026 (class days, dates, teachers, hold blocks, the
  schedule, the opening ledger and a set of leads) and Spring 2027 pre-populated from
  fall. The same importers the wizard uses, so every row is validated. Run it as often
  as you like: everything from the previous load is cleared first. Developer accounts,
  settings and announcements are kept.</p>
  <form method="post" action="/admin/setup/load_sample_data_eval.php" class="actions" style="margin-top:10px;"
        onsubmit="return confirm('Reset this database and load all sample data? Every semester, family, lead and ledger entry currently here will be deleted first.');">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <button type="submit" class="button primary">Reset &amp; Load All Sample Data</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Step 1 · Locations
    <?php if ($locations): ?><span class="badge success"><?=count($locations)?> active</span><?php endif; ?>
  </h3>
  <p class="small">Confirm the places lessons happen (add or deactivate as needed).
  The names entered here are what the class-dates and teacher-assignment CSVs
  reference later, so importing them from a CSV keeps everything consistent.</p>
  <?php foreach ($locations as $location): ?>
    <div><?=h($location['name'])?></div>
  <?php endforeach; ?>
  <div class="actions" style="margin-top:10px;">
    <a class="button<?=$locations ? '' : ' primary'?>"
       href="/admin/import/upload.php?flow=locations&next=<?=h(urlencode('/admin/setup/index.php'))?>">Upload Locations CSV</a>
    <a class="button" href="/admin/locations.php">Manage Locations</a>
  </div>
</div>

<div class="card">
  <h3>Step 2 · Teachers
    <?php if ($teachers): ?><span class="badge success"><?=count($teachers)?> loaded</span><?php endif; ?>
  </h3>
  <p class="small">Upload your teachers from a CSV (or add them one at a time under Teachers).</p>
  <div class="actions" style="margin-top:10px;">
    <a class="button<?=($locations && !$teachers) ? ' primary' : ''?>"
       href="/admin/import/upload.php?flow=teachers&next=<?=h(urlencode('/admin/setup/index.php'))?>">Upload Teachers CSV</a>
    <a class="button" href="/admin/teachers.php">Browse Teachers</a>
  </div>
</div>

<?php
$students = StudentTeacherManagement::listStudents();
$parentCount = (int)pdo()->query('SELECT COUNT(DISTINCT parent_user_id) FROM parenthood')->fetchColumn();
?>
<div class="card">
  <h3>Optional · Students &amp; Parents
    <?php if ($students): ?><span class="badge success"><?=count($students)?> student<?=count($students) === 1 ? '' : 's'?></span><?php endif; ?>
    <?php if ($parentCount): ?><span class="badge success"><?=$parentCount?> parent<?=$parentCount === 1 ? '' : 's'?></span><?php endif; ?>
  </h3>
  <p class="small">Load your roster now so the schedule grid can fill quickly — or add
  people one at a time later. One file carries both: student rows list their
  parents by name or email, and the parents' own rows hold their contact details.</p>
  <div class="actions" style="margin-top:10px;">
    <a class="button" href="/admin/import/upload.php?flow=people&next=<?=h(urlencode('/admin/setup/index.php'))?>">Upload Students &amp; Parents CSV</a>
    <a class="button" href="/admin/students.php">Browse Students</a>
  </div>
</div>

<div class="card">
  <h3>Step 3 · Create your first semester</h3>
  <p class="small">Dates, locations, the class calendar, and which teachers teach where.</p>
  <div class="actions" style="margin-top:10px;">
    <?php if ($locations && $teachers): ?>
      <a class="btn-cta" href="/admin/semester/new.php">Create Semester</a>
    <?php else: ?>
      <span class="small">Finish steps 1 and 2 first.</span>
    <?php endif; ?>
  </div>
</div>

<?php if ($hasSemester): ?>
<p><a href="/admin/schedule.php">Go to the Semester Schedule &rarr;</a></p>
<?php endif; ?>

<?php footer_html(); ?>
