<?php
// First-time setup landing: walks a new admin through bootstrapping the
// portal — locations, teachers, then the first semester. Each step shows
// whether it looks done and links to the page that does the work.
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/LocationManagement.php';
require_once __DIR__ . '/../../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../../lib/SemesterManagement.php';
Application::init();
require_admin();

$locations = LocationManagement::all(true);
$teachers = StudentTeacherManagement::listTeachers();
$hasSemester = SemesterManagement::hasAnySemester();

$flash = $_SESSION['import_flash'] ?? null;
unset($_SESSION['import_flash']);

header_html('Get Started');
?>

<h2>Welcome! Let's set up the portal.</h2>
<p class="small">Three steps: confirm your teaching locations, load your
teachers, then create your first semester. You can come back here any time —
this page disappears once a semester exists.</p>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>

<div class="card">
  <h3>Step 1 · Locations
    <?php if ($locations): ?><span class="badge success"><?=count($locations)?> active</span><?php endif; ?>
  </h3>
  <p class="small">Confirm the places lessons happen (add or deactivate as needed).</p>
  <?php foreach ($locations as $location): ?>
    <div><?=h($location['name'])?></div>
  <?php endforeach; ?>
  <div class="actions" style="margin-top:10px;">
    <a class="button<?=$locations ? '' : ' primary'?>" href="/admin/locations.php">Manage Locations</a>
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
