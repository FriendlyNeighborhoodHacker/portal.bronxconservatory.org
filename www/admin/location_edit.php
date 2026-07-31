<?php
// Edit a location. Evaluates to location_edit_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_admin();

$id = (int)($_GET['id'] ?? 0);
$location = LocationManagement::find($id);
if (!$location) {
    header('Location: /admin/locations.php');
    exit;
}

$flashError = $_SESSION['location_flash_error'] ?? null;
unset($_SESSION['location_flash_error']);

header_html('Edit ' . $location['name']);
?>

<h2>Edit <?=h($location['name'])?></h2>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/admin/location_edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?=$id?>">
    <div class="grid-2">
      <label>Name
        <input type="text" name="name" required value="<?=h($location['name'])?>">
      </label>
      <label>Address
        <input type="text" name="address" value="<?=h($location['address'] ?? '')?>">
      </label>
    </div>
    <label class="inline">
      <input type="checkbox" name="is_active" value="1"<?=$location['is_active'] ? ' checked' : ''?>>
      Active (offered on the registration form and lesson forms)
    </label>
    <div class="actions">
      <button type="submit" class="primary">Save</button>
      <a class="button" href="/admin/locations.php">Cancel</a>
    </div>
  </form>
</div>

<?php footer_html(); ?>
