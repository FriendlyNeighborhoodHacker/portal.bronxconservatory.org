<?php
// Lessons list: a date-range window over all lessons (defaults to the coming
// two weeks).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_admin();

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d', strtotime('+14 days'));
$lessons = LessonManagement::lessonsBetween($from, $to);

$flash = $_SESSION['lesson_flash'] ?? null;
unset($_SESSION['lesson_flash']);

header_html('Lessons');
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
  <h2>Lessons</h2>
  <a class="button" href="/admin/lesson_add.php">Add Lesson</a>
</div>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>

<div class="card">
  <form method="get" class="stack">
    <div class="grid-3">
      <label>From <input type="date" name="from" value="<?=h($from)?>"></label>
      <label>To <input type="date" name="to" value="<?=h($to)?>"></label>
      <div style="align-self:end;"><button type="submit" class="button">Show</button></div>
    </div>
  </form>
</div>

<?php if (!$lessons): ?>
  <p class="small">No lessons in this window.</p>
<?php else: ?>
  <div class="card">
    <?php $lastDay = null; foreach ($lessons as $lesson): ?>
      <?php $day = date('l, F j', strtotime($lesson['start_datetime'])); ?>
      <?php if ($day !== $lastDay): $lastDay = $day; ?>
        <h3 style="margin:14px 0 4px;"><?=h($day)?></h3>
      <?php endif; ?>
      <div class="lesson-row<?=$lesson['status'] === 'cancelled' ? ' lesson-cancelled' : ''?>">
        <span class="lesson-row-time"><?=h(date('g:i A', strtotime($lesson['start_datetime'])))?></span>
        <span><a href="/admin/lesson.php?id=<?=(int)$lesson['id']?>"><?=h(lesson_name_label($lesson))?></a>
          <?php if ($lesson['lesson_type'] === 'individual'): ?>
            — <?=h(trim(($lesson['student_first_name'] ?? '') . ' ' . ($lesson['student_last_name'] ?? '')))?>
          <?php endif; ?>
        </span>
        <span><?=person_chip_html($lesson['sub_first_name'] ?? $lesson['teacher_first_name'], $lesson['sub_last_name'] ?? $lesson['teacher_last_name'])?><?=!empty($lesson['substitute_teacher_user_id']) ? ' <span class="small">(sub)</span>' : ''?></span>
        <span><?=lesson_place_html($lesson)?></span>
        <?php if ($lesson['status'] === 'cancelled'): ?><span class="small">cancelled</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
