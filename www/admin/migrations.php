<?php
// Admin — Database Migrations: lists every migration in the configured
// migrations directory with its applied/not-applied status (from
// schema_migrations) and lets an admin select and apply the pending ones.
// The page only works where MIGRATIONS_DIR is configured (see config.local.php).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/MigrationRunner.php';
Application::init();
require_developer();

if (!MigrationRunner::isConfigured()) {
    header_html('Database Migrations');
    ?>
    <h2>Database Migrations</h2>
    <div class="card">
      <p class="error"><strong>Migrations are not enabled on this server.</strong>
      Set <code>MIGRATIONS_DIR</code> in <code>config.local.php</code> to the
      absolute path of the <code>db_migrations</code> directory for this
      deployment, then reload this page.</p>
      <p class="small"><a href="/admin/maintenance.php">&larr; Back to Maintenance</a></p>
    </div>
    <?php
    footer_html();
    exit;
}

$trackingReady = MigrationRunner::trackingTableExists();
$rows          = MigrationRunner::status();
$pendingCount  = 0;
foreach ($rows as $r) {
    if (!$r['applied']) { $pendingCount++; }
}

$flash = $_SESSION['migrations_flash'] ?? null;
$flashError = $_SESSION['migrations_flash_error'] ?? null;
unset($_SESSION['migrations_flash'], $_SESSION['migrations_flash_error']);

header_html('Database Migrations');
?>

<h2>Database Migrations</h2>
<p class="small">Applies pending schema migrations from <code><?=h(MigrationRunner::dir())?></code>.</p>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<?php if (!$trackingReady): ?>
<div class="card">
  <p class="error"><strong>Migration tracking isn't initialized.</strong>
  The <code>schema_migrations</code> table is missing — load the current
  <code>schema.sql</code> (which creates it). Applying from here is disabled
  until then.</p>
</div>
<?php endif; ?>

<div class="card">
  <form method="post" action="/admin/migrations_apply_eval.php" id="migrationsForm">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <table class="list">
      <thead>
        <tr>
          <th style="width:36px;text-align:center;">
            <input type="checkbox" id="migrationsSelectAll" title="Select all pending"
                   <?=($trackingReady && $pendingCount > 0) ? '' : 'disabled'?>>
          </th>
          <th>Migration</th>
          <th style="width:220px;">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td style="text-align:center;">
            <?php if (!$r['applied'] && !$r['missing_file']): ?>
              <input type="checkbox" class="migration-check" name="filenames[]"
                     value="<?=h($r['filename'])?>" <?=$trackingReady ? '' : 'disabled'?>>
            <?php endif; ?>
          </td>
          <td>
            <code><?=h($r['filename'])?></code>
            <?php if ($r['missing_file']): ?>
              <span class="small">(file no longer on disk)</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['applied']): ?>
              <strong>Applied</strong>
              <?php if ($r['applied_at']): ?>
                <span class="small"><?=h(substr((string)$r['applied_at'], 0, 16))?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="small">Not applied</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
        <tr><td colspan="3" class="small" style="text-align:center;padding:24px;">
          No migration files found in <code><?=h(MigrationRunner::dir())?></code>.
        </td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="actions">
      <button type="submit" class="button primary"
              <?=($trackingReady && $pendingCount > 0) ? '' : 'disabled'?>>
        Apply selected
      </button>
      <span class="small"><?=$pendingCount?> migration<?=$pendingCount === 1 ? '' : 's'?> pending.</span>
    </div>
  </form>
</div>

<script>
(function () {
  var selectAll = document.getElementById('migrationsSelectAll');
  var checks    = Array.prototype.slice.call(document.querySelectorAll('.migration-check:not([disabled])'));
  var form      = document.getElementById('migrationsForm');

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      checks.forEach(function (c) { c.checked = selectAll.checked; });
    });
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      var chosen = checks.filter(function (c) { return c.checked; });
      if (chosen.length === 0) {
        e.preventDefault();
        alert('Select at least one migration to apply.');
        return;
      }
      var names = chosen.map(function (c) { return '  • ' + c.value; }).join('\n');
      if (!confirm('Apply ' + chosen.length + ' migration' + (chosen.length === 1 ? '' : 's') +
                   ' to the database?\n\n' + names)) {
        e.preventDefault();
      }
    });
  }
})();
</script>

<?php footer_html(); ?>
