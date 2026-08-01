<?php
// Add a teacher: the essentials; everything else lives on Edit Teacher.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
Application::init();
require_admin();

$err = $_SESSION['people_flash_error'] ?? null;
$old = $_SESSION['people_form_old'] ?? [];
unset($_SESSION['people_flash_error'], $_SESSION['people_form_old']);

$instruments = InstrumentCatalog::all();

header_html('Add Teacher');
?>

<h2>Add Teacher</h2>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/teacher_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="grid-2">
      <label>First name <input type="text" name="first_name" value="<?=h($old['first_name'] ?? '')?>" required></label>
      <label>Last name <input type="text" name="last_name" value="<?=h($old['last_name'] ?? '')?>" required></label>
      <label>Email <input type="email" name="email" value="<?=h($old['email'] ?? '')?>"></label>
      <label>Cell phone <input type="text" name="cell_phone" value="<?=h($old['cell_phone'] ?? '')?>"></label>
    </div>
    <div>Teaches:</div>
    <div class="choice-grid">
      <?php foreach ($instruments as $instrument): ?>
        <label class="inline">
          <input type="checkbox" name="instrument_ids[]" value="<?=(int)$instrument['id']?>"
            <?=in_array((int)$instrument['id'], array_map('intval', $old['instrument_ids'] ?? []), true) ? 'checked' : ''?>>
          <?=h($instrument['name'])?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="actions">
      <button type="submit" class="button primary">Add Teacher</button>
      <a class="button" href="/admin/teachers.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
