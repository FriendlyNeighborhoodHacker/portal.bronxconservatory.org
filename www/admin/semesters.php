<?php
// Admin > Semesters: every semester with its setup data, plus links to
// re-run any wizard step (locations, class-dates import, teachers import).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
Application::init();
require_admin();

$semesters = SemesterManagement::listSemesters();
$defaultSemesterId = Application::adminSelectedSemesterId();

$flash = $_SESSION['import_flash'] ?? null;
unset($_SESSION['import_flash']);

header_html('Semesters');
?>

<div class="page-head">
  <h2>Semesters</h2>
  <a class="button primary" href="/admin/semester/new.php">New Semester</a>
</div>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>

<?php if (!$semesters): ?>
  <div class="card"><p>No semesters yet. <a href="/admin/setup/index.php">Start the setup wizard.</a></p></div>
<?php endif; ?>

<?php foreach ($semesters as $semester): ?>
<?php
  $semesterId = (int)$semester['id'];
  $locations = SemesterManagement::activeLocations($semesterId);
  $dates = SemesterManagement::locationDates($semesterId);
  $columns = SemesterManagement::locationTeachers($semesterId);
  $reservations = ReservationManagement::reservationsForSemester($semesterId);
?>
<div class="card">
  <h3><?=h(SemesterManagement::label($semester))?>
    <?php if ($semesterId === $defaultSemesterId): ?><span class="badge success">Selected</span><?php endif; ?>
  </h3>
  <div class="card-sub">
    <?=h($semester['start_date'])?> &ndash; <?=h($semester['end_date'])?>
    &middot; <?=count($locations)?> location<?=count($locations) === 1 ? '' : 's'?>
    &middot; <?=count($dates)?> class date<?=count($dates) === 1 ? '' : 's'?>
    &middot; <?=count($columns)?> teacher assignment<?=count($columns) === 1 ? '' : 's'?>
    &middot; <?=count($reservations)?> reservation<?=count($reservations) === 1 ? '' : 's'?>
  </div>
  <div class="actions">
    <a class="button" href="/admin/semester/edit.php?semester_id=<?=$semesterId?>">Edit</a>
    <a class="button" href="/admin/semester/locations.php?semester_id=<?=$semesterId?>">Locations</a>
    <a class="button" href="/admin/import/upload.php?flow=location_dates&semester_id=<?=$semesterId?>&next=<?=h(urlencode('/admin/semesters.php'))?>">Import Class Dates</a>
    <a class="button" href="/admin/import/upload.php?flow=location_teachers&semester_id=<?=$semesterId?>&next=<?=h(urlencode('/admin/semesters.php'))?>">Import Location Teachers</a>
    <a class="button" href="/admin/import/upload.php?flow=hold_blocks&semester_id=<?=$semesterId?>&next=<?=h(urlencode('/admin/semesters.php'))?>">Import Hold Blocks</a>
    <a class="button" href="/admin/import/upload.php?flow=reservations&semester_id=<?=$semesterId?>&next=<?=h(urlencode('/admin/semesters.php'))?>">Import Schedule</a>
    <?php if (count($semesters) > 1): ?>
      <a class="button" href="/admin/semester/prepopulate.php?semester_id=<?=$semesterId?>">Pre-populate from Previous</a>
    <?php endif; ?>
    <a class="button" href="/admin/semester_select.php?semester_id=<?=$semesterId?>&return=<?=h(urlencode('/admin/schedule.php'))?>">Open Schedule</a>
  </div>
</div>
<?php endforeach; ?>

<?php footer_html(); ?>
