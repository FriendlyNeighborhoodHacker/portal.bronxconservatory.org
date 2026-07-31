<?php
// Recurring (weekly) lesson templates: list, generate occurrences, toggle
// active. New templates via recurring_add.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LessonManagement.php';
Application::init();
require_admin();

$templates = LessonManagement::listRecurring();
$days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

$flash = $_SESSION['recurring_flash'] ?? null;
$flashError = $_SESSION['recurring_flash_error'] ?? null;
unset($_SESSION['recurring_flash'], $_SESSION['recurring_flash_error']);

header_html('Recurring Lessons');
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
  <h2>Recurring Lessons</h2>
  <a class="button" href="/admin/recurring_add.php">Add Recurring Lesson</a>
</div>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<?php if (!$templates): ?>
  <p class="small">No recurring lessons yet.</p>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead><tr><th>When</th><th>Lesson</th><th>Teacher</th><th>Runs</th><th>Active</th><th>Generate through…</th></tr></thead>
      <tbody>
        <?php foreach ($templates as $t): ?>
        <tr>
          <td><?=h($days[(int)$t['day_of_week']] . ' ' . date('g:i A', strtotime((string)$t['start_time'])))?></td>
          <td><?=h(lesson_name_label($t))?><?php if ($t['lesson_type'] === 'individual'): ?>
            — <?=h(trim(($t['student_first_name'] ?? '') . ' ' . ($t['student_last_name'] ?? '')))?><?php endif; ?>
            <span class="small"><?=lesson_place_html($t)?></span></td>
          <td><?=h($t['teacher_first_name'] . ' ' . $t['teacher_last_name'])?></td>
          <td class="small"><?=h($t['start_date'])?> → <?=h($t['end_date'] ?? 'open-ended')?></td>
          <td>
            <form method="post" action="/admin/recurring_active_eval.php">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="id" value="<?=(int)$t['id']?>">
              <input type="hidden" name="active" value="<?=$t['is_active'] ? 0 : 1?>">
              <button type="submit" class="button"><?=$t['is_active'] ? 'Active — deactivate' : 'Inactive — activate'?></button>
            </form>
          </td>
          <td>
            <form method="post" action="/admin/recurring_generate_eval.php" style="display:flex;gap:6px;">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="id" value="<?=(int)$t['id']?>">
              <input type="date" name="through" value="<?=h(date('Y-m-d', strtotime('+8 weeks')))?>">
              <button type="submit" class="button">Generate</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small">"Generate" materializes the weekly occurrences into real lessons through the
  chosen date. It's safe to run again later to extend the horizon — existing dates are skipped.</p>
<?php endif; ?>

<?php footer_html(); ?>
