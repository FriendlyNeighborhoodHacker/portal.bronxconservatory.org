<?php
// Import step 2: map CSV columns to fields, with sample data for comfort.
require_once __DIR__ . '/import_ui.php';
Application::init();
require_admin();

$flow = import_current_flow();
$state = $_SESSION[import_session_key($flow)] ?? null;
if (!$state || empty($state['headers'])) {
    header('Location: /admin/import/upload.php' . import_query_string($flow));
    exit;
}

$flowClass = $flow['class'];
$targetFields = $flowClass::targetFields();
$samples = array_slice($state['rows'], 0, 3);

$flashError = $_SESSION['import_flash_error'] ?? null;
unset($_SESSION['import_flash_error']);

header_html($flow['title'] . ' — Mapping');
?>

<h2><?=h($flow['title'])?></h2>
<?=import_steps_html($flow, 2)?>

<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>
<p class="small"><?=count($state['rows'])?> data row<?=count($state['rows']) === 1 ? '' : 's'?> parsed.
Choose which field each column maps to (columns mapped to "— ignore —" are skipped).</p>

<div class="card">
  <form method="post" action="/admin/import/map_eval.php" class="stack">
    <?=import_hidden_fields_html($flow)?>
    <table class="list">
      <thead><tr><th>CSV column</th><th>Maps to</th><th>Sample data</th></tr></thead>
      <tbody>
        <?php foreach ($state['headers'] as $i => $header): ?>
        <tr>
          <td><strong><?=h($header)?></strong></td>
          <td>
            <select name="mapping[<?=$i?>]">
              <option value="">— ignore —</option>
              <?php foreach ($targetFields as $field => $label): ?>
                <option value="<?=h($field)?>" <?=($state['mapping'][$i] ?? '') === $field ? 'selected' : ''?>>
                  <?=h($label)?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="small">
            <?php foreach ($samples as $sample): ?>
              <div><?=h((string)($sample[$i] ?? ''))?></div>
            <?php endforeach; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="actions">
      <button type="submit" class="button primary">Continue to Validation</button>
      <a class="button" href="/admin/import/upload.php<?=h(import_query_string($flow))?>">Back</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
