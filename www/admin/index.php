<?php
// Admin home: the Action Queue. Families grouped by status, follow-ups
// first, each with the one-line summary of what they asked for.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/FamilyManagement.php';
Application::init();
require_admin();

$byStatus = [];
foreach (FamilyManagement::STATUSES as $status) {
    $byStatus[$status] = FamilyManagement::listFamiliesByStatus($status);
}

header_html('Action Queue');
?>

<h2>Action Queue</h2>
<p class="small">New registrations land here. <strong>Needs Follow-Up</strong> families asked
for a call; <strong>Ready to Enroll</strong> families are waiting for a schedule.</p>

<?php foreach (FamilyManagement::STATUSES as $status): $families = $byStatus[$status]; ?>
  <h3 style="margin-bottom:6px;"><?=family_status_html($status)?>
    <span class="small">(<?=count($families)?>)</span></h3>
  <?php if (!$families): ?>
    <p class="small">None right now.</p>
  <?php else: ?>
    <div class="card-grid">
      <?php foreach ($families as $family): ?>
      <div class="card">
        <h3><a href="/admin/family.php?id=<?=(int)$family['id']?>"><?=h($family['family_name'])?> family</a></h3>
        <div class="card-sub"><?=h(FamilyManagement::familySummaryLine($family))?></div>
        <?php if (!empty($family['parent_first_name'])): ?>
          <div class="small"><?=h($family['parent_first_name'] . ' ' . $family['parent_last_name'])?>
            · <?=h($family['parent_cell_phone'] ?? '')?>
            · <?=h($family['parent_email'] ?? '')?></div>
        <?php endif; ?>
        <?php if (!empty($family['latest_note'])): ?>
          <div class="small" style="margin-top:6px;">📝 <?=h($family['latest_note'])?></div>
        <?php endif; ?>
        <div class="small" style="margin-top:6px;">Registered <?=h(date('M j, Y', strtotime($family['created_at'])))?></div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endforeach; ?>

<?php footer_html(); ?>
