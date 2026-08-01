<?php
// Edit a semester's season, year, and date range. The class calendar that
// drives lessons lives in semester_location_dates and is imported separately,
// so this form only changes the semester's own identity and range.
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/SemesterManagement.php';
Application::init();
require_admin();

$semesterId = (int)($_GET['semester_id'] ?? 0);
$semester = $semesterId > 0 ? SemesterManagement::find($semesterId) : null;
if (!$semester) {
    $_SESSION['import_flash'] = 'That semester no longer exists.';
    header('Location: /admin/semesters.php');
    exit;
}

$err = $_SESSION['semester_flash_error'] ?? null;
$old = $_SESSION['semester_form_old'] ?? [];
unset($_SESSION['semester_flash_error'], $_SESSION['semester_form_old']);

$outsideRange = SemesterManagement::countLocationDatesOutsideRange(
    $semesterId, (string)$semester['start_date'], (string)$semester['end_date']
);

header_html('Edit Semester');
?>

<div class="page-head">
  <h2>Edit <?=h(SemesterManagement::label($semester))?></h2>
</div>

<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/semester/edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="semester_id" value="<?=$semesterId?>">
    <div class="grid-2">
      <label>Season
        <select name="season" required>
          <?php $selectedSeason = (string)($old['season'] ?? $semester['season']); ?>
          <?php foreach (SemesterManagement::SEASONS as $season): ?>
            <option value="<?=h($season)?>" <?=$selectedSeason === $season ? 'selected' : ''?>>
              <?=h(ucfirst($season))?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Year
        <input type="number" name="year" min="2020" max="2100" required
               value="<?=h((string)($old['year'] ?? $semester['year']))?>">
      </label>
      <label>Start date
        <input type="date" name="start_date" required
               value="<?=h((string)($old['start_date'] ?? $semester['start_date']))?>">
      </label>
      <label>End date
        <input type="date" name="end_date" required
               value="<?=h((string)($old['end_date'] ?? $semester['end_date']))?>">
      </label>
    </div>
    <p class="small">The date range decides which semester the portal treats as current.
    Lessons come from the imported class dates, so changing it here does not add or remove any.
    <?php if ($outsideRange > 0): ?>
      <strong><?=$outsideRange?> class date<?=$outsideRange === 1 ? '' : 's'?></strong>
      already fall outside this range.
    <?php endif; ?>
    </p>
    <div class="actions">
      <button type="submit" class="button primary">Save</button>
      <a class="button" href="/admin/semesters.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
