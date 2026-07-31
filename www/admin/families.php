<?php
// All families in one table (the Action Queue shows them grouped by status).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FamilyManagement.php';
Application::init();
require_admin();

$families = FamilyManagement::listFamiliesByStatus(null);

header_html('Families');
?>

<h2>Families</h2>

<?php if (!$families): ?>
  <p class="small">No families yet. They appear here when someone registers.</p>
<?php else: ?>
  <div class="card">
    <table class="list">
      <thead>
        <tr><th>Family</th><th>Status</th><th>Parent</th><th>Students</th><th>Registered</th></tr>
      </thead>
      <tbody>
        <?php foreach ($families as $family): ?>
        <tr>
          <td><a href="/admin/family.php?id=<?=(int)$family['id']?>"><?=h($family['family_name'])?></a></td>
          <td><?=family_status_html($family['status'])?></td>
          <td><?=h(trim(($family['parent_first_name'] ?? '') . ' ' . ($family['parent_last_name'] ?? '')))?><br>
              <span class="small"><?=h($family['parent_email'] ?? '')?></span></td>
          <td><?php
            $names = array_map(fn($s) => $s['first_name'] . ' (' . implode('/', $s['instruments'] ?: ['—']) . ')', $family['students']);
            echo h(implode(', ', $names));
          ?></td>
          <td class="small"><?=h(date('M j, Y', strtotime($family['created_at'])))?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
