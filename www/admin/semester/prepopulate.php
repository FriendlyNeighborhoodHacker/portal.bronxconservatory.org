<?php
// Semester wizard step 5, the other way in: carry a previous semester's
// schedule forward instead of uploading one. Same shape as the CSV imports'
// validation step — every row, what will happen, what's in the way — with a
// single Commit at the bottom.
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/SemesterManagement.php';
require_once __DIR__ . '/../../lib/ReservationManagement.php';
Application::init();
require_admin();

$semesterId = (int)($_GET['semester_id'] ?? 0);
$semester = SemesterManagement::find($semesterId);
if (!$semester) {
    header('Location: /admin/semesters.php');
    exit;
}

$others = array_values(array_filter(
    SemesterManagement::listSemesters(),
    fn(array $s) => (int)$s['id'] !== $semesterId
));
$previous = SemesterManagement::previousSemester($semesterId);
$sourceId = (int)($_GET['source_semester_id'] ?? ($previous['id'] ?? ($others[0]['id'] ?? 0)));
$source = SemesterManagement::find($sourceId);

$err = $_SESSION['import_flash_error'] ?? null;
unset($_SESSION['import_flash_error']);

$preview = [];
$validCount = 0;
if ($source && $sourceId !== $semesterId) {
    $preview = ReservationManagement::carryForwardPreview($semesterId, $sourceId);
    $validCount = count(array_filter(
        $preview,
        fn(array $e) => $e['status'] === 'valid' && $e['changes'] !== 'Already carried over (no change)'
    ));
}

header_html('Pre-populate Schedule');
?>

<h2><?=h(SemesterManagement::label($semester))?> — Pre-populate Schedule</h2>

<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <p class="small">Start this semester from a previous one's schedule: every student keeps
  their teacher, location, day and time, and comes across as <strong>pending reach
  out</strong> — the list to call through. Nothing is confirmed, no lessons are
  generated and nobody is charged until you confirm each reservation.</p>
  <form method="get" action="/admin/semester/prepopulate.php" class="stack">
    <input type="hidden" name="semester_id" value="<?=$semesterId?>">
    <label>Carry forward from
      <select name="source_semester_id" onchange="this.form.submit()">
        <?php foreach ($others as $other): ?>
          <option value="<?=(int)$other['id']?>" <?=(int)$other['id'] === $sourceId ? 'selected' : ''?>>
            <?=h(SemesterManagement::label($other))?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if (!$others): ?>
      <p class="small">There is no other semester to copy from yet.</p>
    <?php endif; ?>
  </form>
</div>

<?php if ($source): ?>
<?php
  $skipCount = count(array_filter($preview, fn(array $e) => $e['changes'] === 'Already carried over (no change)'));
  $errorCount = count(array_filter($preview, fn(array $e) => $e['status'] !== 'valid'));
?>
<p class="small">
  <strong><?=$validCount?></strong> reservation<?=$validCount === 1 ? '' : 's'?> to carry over<?php
  if ($skipCount): ?>, <strong><?=$skipCount?></strong> already here<?php endif;
  if ($errorCount): ?>, <strong style="color:var(--color-danger);"><?=$errorCount?></strong> that can't be placed (they will be skipped)<?php endif; ?>.
</p>

<div class="card">
  <?php if (!$preview): ?>
    <p class="small"><?=h(SemesterManagement::label($source))?> has no reservations to carry forward.</p>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="list">
    <thead>
      <tr>
        <th>#</th><th>Student</th><th>Teacher</th><th>Location</th>
        <th>Day</th><th>Time</th><th>Minutes</th><th>Was</th><th>Status</th><th>Changes</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($preview as $entry): ?>
      <tr>
        <td class="small"><?=(int)$entry['row']?></td>
        <td><?=h($entry['data']['student_name'])?></td>
        <td><?=h($entry['data']['teacher_name'])?></td>
        <td><?=h($entry['data']['location_name'])?></td>
        <td><?=h($entry['data']['day'])?></td>
        <td><?=h($entry['data']['start_time'])?></td>
        <td><?=h($entry['data']['duration_minutes'])?></td>
        <td class="small"><?=h(str_replace('_', ' ', $entry['data']['status']))?></td>
        <td>
          <?php if ($entry['status'] === 'valid'): ?>
            <span class="status-verified">Valid</span>
          <?php else: ?>
            <span style="color:var(--color-danger);font-weight:600;">Error</span>
          <?php endif; ?>
          <?php foreach ($entry['messages'] as $message): ?>
            <div class="small" style="color:var(--color-spice-text);"><?=h($message)?></div>
          <?php endforeach; ?>
        </td>
        <td class="small"><?=h($entry['changes'])?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>

  <form method="post" action="/admin/semester/prepopulate_eval.php" class="stack" style="margin-top:12px;">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="semester_id" value="<?=$semesterId?>">
    <input type="hidden" name="source_semester_id" value="<?=$sourceId?>">
    <div class="actions">
      <button type="submit" class="button primary" <?=$validCount === 0 ? 'disabled' : ''?>>
        Carry <?=$validCount?> Reservation<?=$validCount === 1 ? '' : 's'?> Forward
      </button>
      <a class="button" href="/admin/semesters.php">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php footer_html(); ?>
