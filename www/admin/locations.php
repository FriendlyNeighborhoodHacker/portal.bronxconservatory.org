<?php
// BCM teaching locations: list + inline add form. Edits happen in
// location_edit.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/LocationManagement.php';
Application::init();
require_admin();

$locations = LocationManagement::all();

$flash = $_SESSION['location_flash'] ?? null;
$flashError = $_SESSION['location_flash_error'] ?? null;
unset($_SESSION['location_flash'], $_SESSION['location_flash_error']);

header_html('Locations');
?>

<h2>Locations</h2>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card">
  <table class="list">
    <thead><tr><th>Name</th><th>Address</th><th>Active</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($locations as $loc): ?>
      <tr>
        <td><?=h($loc['name'])?></td>
        <td class="small"><?=h($loc['address'] ?? '')?></td>
        <td><?=$loc['is_active'] ? 'Yes' : 'No'?></td>
        <td><a href="/admin/location_edit.php?id=<?=(int)$loc['id']?>">Edit</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3>Add a location</h3>
  <form method="post" action="/admin/location_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="grid-2">
      <label>Name
        <input type="text" name="name" required>
      </label>
      <label>Address
        <input type="text" name="address">
      </label>
    </div>
    <button type="submit" class="button">Add Location</button>
  </form>
</div>

<?php footer_html(); ?>
