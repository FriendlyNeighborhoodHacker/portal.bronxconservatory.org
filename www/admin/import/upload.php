<?php
// Import step 1: choose a CSV file or paste CSV text, pick the delimiter.
require_once __DIR__ . '/import_ui.php';
Application::init();
require_admin();

$flow = import_current_flow();

$flash = $_SESSION['import_flash'] ?? null;
$flashError = $_SESSION['import_flash_error'] ?? null;
unset($_SESSION['import_flash'], $_SESSION['import_flash_error']);

header_html($flow['title']);
?>

<h2><?=h($flow['title'])?></h2>
<?=import_steps_html($flow, 1)?>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/import/parse_eval.php" enctype="multipart/form-data" class="stack">
    <?=import_hidden_fields_html($flow)?>
    <label>CSV file
      <input type="file" name="csv_file" accept=".csv,.tsv,.txt,text/csv">
    </label>
    <label>… or paste CSV data
      <textarea name="csv_text" rows="8" placeholder="Header line first, one row per line"></textarea>
    </label>
    <label>Delimiter
      <select name="delimiter">
        <option value="comma">Comma</option>
        <option value="tab">Tab</option>
      </select>
    </label>
    <div class="actions">
      <button type="submit" class="button primary">Parse Import File</button>
      <a class="button" href="<?=h($flow['next'])?>">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
