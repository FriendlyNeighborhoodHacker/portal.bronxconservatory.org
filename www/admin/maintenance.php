<?php
// Admin > Maintenance: the directory of developer-only tools. Reachable only
// by an admin who is also flagged as a developer (users.is_developer), which
// is the same gate each tool below applies for itself.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/MigrationRunner.php';
Application::init();
require_developer();

// Migrations are only usable where MIGRATIONS_DIR points at this deployment's
// db_migrations directory; elsewhere the card is shown inert with a hint.
$migrationsConfigured = MigrationRunner::isConfigured();
$pendingMigrations = $migrationsConfigured ? count(MigrationRunner::pendingFilenames()) : 0;

$tools = [
    [
        'path' => $migrationsConfigured ? '/admin/migrations.php' : null,
        'label' => 'Database Migrations',
        'blurb' => 'Apply pending schema migrations from the db_migrations directory.',
        'note' => $migrationsConfigured
            ? ($pendingMigrations > 0 ? $pendingMigrations . ' pending' : 'Up to date')
            : 'Not enabled here — set MIGRATIONS_DIR in config.local.php',
    ],
    [
        'path' => '/admin/l/index.php',
        'label' => 'Server Logs',
        'blurb' => 'Tail the deploy, Apache, and PHP log files on this server.',
        'note' => null,
    ],
    [
        'path' => '/admin/activity_log.php',
        'label' => 'Activity Log',
        'blurb' => 'Every write action and login, with who did it and when.',
        'note' => null,
    ],
    [
        'path' => '/admin/email_log.php',
        'label' => 'Email Log',
        'blurb' => 'Every email the portal has sent, with delivery status.',
        'note' => null,
    ],
    [
        'path' => '/admin/installment_fees.php',
        'label' => 'Installment Fee Sweep',
        'blurb' => 'Preview and run the daily job that charges the installment plan fee to unpaid accounts.',
        'note' => null,
    ],
];

header_html('Maintenance');
?>

<div class="page-head">
  <h2>Maintenance</h2>
</div>
<p class="small">Developer tools. Admins see this section only when their account is flagged as a developer.</p>

<div class="card-grid">
  <?php foreach ($tools as $tool): ?>
  <div class="card">
    <h3 style="margin:0 0 4px 0;">
      <?php if ($tool['path'] !== null): ?>
        <a href="<?=h($tool['path'])?>"><?=h($tool['label'])?></a>
      <?php else: ?>
        <?=h($tool['label'])?>
      <?php endif; ?>
    </h3>
    <div class="card-sub" style="margin:0;"><?=h($tool['blurb'])?></div>
    <?php if ($tool['note'] !== null): ?>
      <div class="small" style="margin-top:8px;"><?=h($tool['note'])?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php footer_html(); ?>
