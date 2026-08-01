<?php
// Admin > Maintenance: the directory of developer-only tools. Reachable only
// by an admin who is also flagged as a developer (users.is_developer), which
// is the same gate each tool below applies for itself.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/MigrationRunner.php';
Application::init();
require_developer();

$pendingMigrations = count(MigrationRunner::pendingFilenames());

$tools = [
    [
        'path' => '/admin/migrations.php',
        'label' => 'Database Migrations',
        'blurb' => 'Apply pending schema migrations from db_migrations/.',
        'note' => $pendingMigrations > 0
            ? $pendingMigrations . ' pending'
            : 'Up to date',
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
    <h3 style="margin:0 0 4px 0;"><a href="<?=h($tool['path'])?>"><?=h($tool['label'])?></a></h3>
    <div class="card-sub" style="margin:0;"><?=h($tool['blurb'])?></div>
    <?php if ($tool['note'] !== null): ?>
      <div class="small" style="margin-top:8px;"><?=h($tool['note'])?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php footer_html(); ?>
