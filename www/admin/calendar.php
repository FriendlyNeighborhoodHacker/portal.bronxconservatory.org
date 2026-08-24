<?php
// Admin Calendar — semester-wide view, two renderings of the same dates:
// month grids (default) showing every month of the semester at once, and a
// chronological list (?view=list). Either way: active days in green, breaks
// and holidays in purple, each date linking to that week's lessons.
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

$view = ($_GET['view'] ?? 'month') === 'list' ? 'list' : 'month';

/** "9:00 am–5:00 pm" */
$timeRange = function (string $start, string $end): string {
    return date('g:i a', strtotime($start)) . '–' . date('g:i a', strtotime($end));
};

header_html('Calendar');
?>

<div class="page-head">
  <h2>Calendar — <?=h(SemesterManagement::label($semester))?></h2>
  <span class="actions view-toggle">
    <a class="button<?=$view === 'month' ? ' active' : ''?>" href="/admin/calendar.php">Month</a>
    <a class="button<?=$view === 'list' ? ' active' : ''?>" href="/admin/calendar.php?view=list">List</a>
  </span>
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

<?php if ($view === 'month'): ?>

<?php
// Every month of the semester at once, one mini-grid per month. A date is
// highlighted when it has an entry (green class day / purple break) and
// links to that week's lessons, same as the list view.
$byDate = [];
foreach ($entries as $entry) {
    $byDate[$entry['date']][] = $entry;
}

/** "Bronx Community College" -> "BCC" */
$abbrev = function (string $name): string {
    $letters = '';
    foreach (preg_split('/\s+/', trim($name)) ?: [] as $word) {
        if ($word !== '') {
            $letters .= mb_strtoupper(mb_substr($word, 0, 1));
        }
    }
    return $letters;
};
$dates = array_keys($byDate);
$cursor = strtotime(date('Y-m-01', strtotime(min($dates))));
$lastMonth = strtotime(date('Y-m-01', strtotime(max($dates))));
?>
<div class="cal-months">
  <?php while ($cursor <= $lastMonth): ?>
  <div class="card cal-month">
    <h3><?=h(date('F Y', $cursor))?></h3>
    <div class="cal-month-grid">
      <?php foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $dow): ?>
        <span class="cal-dow"><?=$dow?></span>
      <?php endforeach; ?>
      <?php for ($i = 0; $i < (int)date('w', $cursor); $i++): ?>
        <span></span>
      <?php endfor; ?>
      <?php for ($day = 1; $day <= (int)date('t', $cursor); $day++):
        $date = date('Y-m-', $cursor) . sprintf('%02d', $day);
        $dayEntries = $byDate[$date] ?? null;
        if ($dayEntries):
            $anyActive = (bool)array_filter($dayEntries, fn(array $e) => $e['status'] === 'active');
            $tipParts = [];
            $titles = [];
            $locationInitials = [];
            foreach ($dayEntries as $e) {
                $tip = $e['title'] !== '' ? $e['title'] : ($e['status'] === 'active' ? 'Class day' : 'Break');
                $locations = implode(', ', array_column($e['locations'], 'name'));
                if ($e['status'] === 'active' && $locations !== '') {
                    $tip .= ' — ' . $locations;
                }
                $tipParts[] = $tip;
                if ($e['title'] !== '' && !in_array($e['title'], $titles, true)) {
                    $titles[] = $e['title'];
                }
                if ($e['status'] === 'active') {
                    foreach ($e['locations'] as $location) {
                        $initials = $abbrev($location['name']);
                        if ($initials !== '' && !in_array($initials, $locationInitials, true)) {
                            $locationInitials[] = $initials;
                        }
                    }
                }
            }
      ?>
        <a class="cal-day <?=$anyActive ? 'cal-day-active' : 'cal-day-inactive'?>"
           href="/admin/calendar_week.php?date=<?=h($date)?>"
           title="<?=h(implode('; ', $tipParts))?>">
          <span class="cal-day-num"><?=$day?></span>
          <?php if ($titles): ?>
            <span class="cal-day-title"><?=h(implode(' / ', $titles))?></span>
          <?php endif; ?>
          <?php if ($locationInitials): ?>
            <span class="cal-day-locs"><?=h(implode(', ', $locationInitials))?></span>
          <?php endif; ?>
        </a>
      <?php else: ?>
        <span class="cal-day"><?=$day?></span>
      <?php endif; endfor; ?>
    </div>
  </div>
  <?php $cursor = strtotime('+1 month', $cursor); endwhile; ?>
</div>

<p class="small">Click a highlighted date to open that week's lessons.
Hover a date for its title and locations.</p>

<?php else: ?>

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

<?php endif; ?>

<?php footer_html(); ?>
