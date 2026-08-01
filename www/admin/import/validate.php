<?php
// Import step 3: show what's valid, what will change, and what's wrong.
require_once __DIR__ . '/import_ui.php';
Application::init();
require_admin();

$flow = import_current_flow();
$state = $_SESSION[import_session_key($flow)] ?? null;
if (!$state || !isset($state['validated'])) {
    header('Location: /admin/import/upload.php' . import_query_string($flow));
    exit;
}

$validated = $state['validated'];
$validCount = count(array_filter($validated, fn($r) => $r['status'] === 'valid'));
$errorCount = count($validated) - $validCount;

$flowClass = $flow['class'];
$fieldLabels = $flowClass::targetFields();
$mappedFields = array_values(array_filter($state['mapping'], fn($f) => $f !== ''));

header_html($flow['title'] . ' — Validation');
?>

<h2><?=h($flow['title'])?></h2>
<?=import_steps_html($flow, 3)?>

<p class="small">
  <strong><?=$validCount?></strong> row<?=$validCount === 1 ? '' : 's'?> ready to import<?php
  if ($errorCount): ?>, <strong style="color:var(--color-danger);"><?=$errorCount?></strong> with problems (they will be skipped)<?php endif; ?>.
</p>

<div class="card">
  <div style="overflow-x:auto;">
  <table class="list">
    <thead>
      <tr>
        <th>#</th>
        <?php foreach ($mappedFields as $field): ?>
          <th><?=h($fieldLabels[$field] ?? $field)?></th>
        <?php endforeach; ?>
        <th>Status</th>
        <th>Changes</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($validated as $entry): ?>
      <tr>
        <td class="small"><?=(int)$entry['row']?></td>
        <?php foreach ($mappedFields as $field): ?>
          <td><?=h((string)($entry['data'][$field] ?? ''))?></td>
        <?php endforeach; ?>
        <td>
          <?php if ($entry['status'] === 'valid'): ?>
            <span class="status-verified">Valid</span>
          <?php else: ?>
            <span style="color:var(--color-danger);font-weight:600;">Error</span>
          <?php endif; ?>
          <?php foreach ($entry['messages'] as $message): ?>
            <div class="small" style="color:#b45309;"><?=h($message)?></div>
          <?php endforeach; ?>
        </td>
        <td class="small"><?=h($entry['changes'])?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <form method="post" action="/admin/import/commit_eval.php" class="stack" style="margin-top:12px;">
    <?=import_hidden_fields_html($flow)?>
    <div class="actions">
      <button type="submit" class="button primary" <?=$validCount === 0 ? 'disabled' : ''?>>Commit Import</button>
      <a class="button" href="/admin/import/map.php<?=h(import_query_string($flow))?>">Back</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
