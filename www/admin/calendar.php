<?php
// Admin Calendar — semester-wide view: every class date in the semester as
// one chronological list. A month grid wastes most of its space on a
// semester that only meets one day a week, so this lists the dates
// themselves: active days in green, breaks and holidays in purple.
// Locations sharing a date, status and title are listed together.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/SemesterManagement.php';
Application::init();
require_admin();

$semesterId = Application::adminSelectedSemesterId();
if ($semesterId === null) {
    header('Location: /admin/setup/index.php');
    exit;
}
$semester = SemesterManagement::find($semesterId);
$entries = SemesterManagement::locationDatesGroupedForSemester($semesterId);

$activeCount = count(array_filter($entries, fn(array $e) => $e['status'] === 'active'));
$inactiveCount = count($entries) - $activeCount;

/** "9:00 am–5:00 pm" */
$timeRange = function (string $start, string $end): string {
    return date('g:i a', strtotime($start)) . '–' . date('g:i a', strtotime($end));
};

header_html('Calendar');
?>

<div class="page-head">
  <h2>Calendar — <?=h(SemesterManagement::label($semester))?></h2>
</div>

<?php if (!$entries): ?>
  <div class="card">
    <p>No class dates for this semester yet.
    <a href="/admin/import/upload.php?flow=location_dates&semester_id=<?=$semesterId?>">Import class dates</a>
    to build the calendar.</p>
  </div>
<?php else: ?>

<div class="grid-legend">
  <span><span class="swatch swatch-line sem-date-active"></span>
    <?=$activeCount?> class day<?=$activeCount === 1 ? '' : 's'?></span>
  <span><span class="swatch swatch-line sem-date-inactive"></span>
    <?=$inactiveCount?> break<?=$inactiveCount === 1 ? '' : 's'?></span>
</div>

<div class="card sem-dates">
  <?php foreach ($entries as $entry): ?>
  <div class="sem-date <?=$entry['status'] === 'active' ? 'sem-date-active' : 'sem-date-inactive'?>">
    <div class="sem-date-head">
      <a href="/admin/calendar_week.php?date=<?=h($entry['date'])?>">
        <strong><?=h(date('D M j, Y', strtotime($entry['date'])))?></strong></a><?php
        if ($entry['title'] !== ''): ?>: <?=h($entry['title'])?><?php endif; ?>
      <?php if ($entry['uniform_time']): ?>
        <span class="sem-date-time"><?=h($timeRange($entry['start_time'], $entry['end_time']))?></span>
      <?php endif; ?>
    </div>
    <div class="sem-date-locations">Locations:
      <?php $parts = [];
        foreach ($entry['locations'] as $location) {
            $parts[] = $entry['uniform_time']
                ? $location['name']
                : $location['name'] . ' (' . $timeRange($location['start_time'], $location['end_time']) . ')';
        }
        echo h(implode(', ', $parts));
      ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<p class="small">Click a date to open that week's lessons.
A date appears twice when its locations disagree on the title or on whether it is a class day.</p>

<?php endif; ?>

<?php footer_html(); ?>
