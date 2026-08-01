<?php
declare(strict_types=1);
// Admin — Server Logs: lists the server log files that can be tailed in the
// browser. Paths come from LogViewer (overridable via ADMIN_LOG_FILES in
// config.local.php); the request never names a path, only a slug.
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/LogViewer.php';
Application::init();
require_developer();

$logs = LogViewer::logs();

$flashError = $_SESSION['logs_flash_error'] ?? null;
unset($_SESSION['logs_flash_error']);

header_html('Server Logs');
?>

<div class="page-head">
  <h2>Server Logs</h2>
  <a class="button" href="/admin/maintenance.php">&larr; Maintenance</a>
</div>

<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>
<p class="small">
  Tail the most recent contents of each server log file. Viewing starts at the
  end of the file; you can page back to older entries. Paths are per-machine
  configuration (<code>ADMIN_LOG_FILES</code> in <code>config.local.php</code>).
</p>

<div class="card">
  <table class="list">
    <thead>
      <tr>
        <th style="width:20%;">Log</th>
        <th>Path</th>
        <th style="width:90px;text-align:right;">Size</th>
        <th style="width:150px;">Modified</th>
        <th style="width:90px;text-align:right;"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($logs as $slug => $log): ?>
        <?php $stat = LogViewer::stat($log['path']); ?>
        <tr>
          <td><strong><?=h($log['label'])?></strong></td>
          <td class="small" style="word-break:break-all;"><code><?=h($log['path'])?></code></td>
          <td style="text-align:right;white-space:nowrap;">
            <?=$stat['readable'] ? h(LogViewer::formatBytes($stat['size'])) : '—'?>
          </td>
          <td class="small" style="white-space:nowrap;">
            <?=$stat['readable'] ? h(date('Y-m-d H:i:s', $stat['mtime'])) : '—'?>
          </td>
          <td style="text-align:right;white-space:nowrap;">
            <?php if ($stat['readable']): ?>
              <a class="button primary" href="/admin/l/view.php?log=<?=h(urlencode($slug))?>">View</a>
            <?php elseif ($stat['exists']): ?>
              <span class="small">not readable</span>
            <?php else: ?>
              <span class="small">not found</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($logs)): ?>
        <tr><td colspan="5" class="small" style="text-align:center;padding:24px;">
          No log files are defined.
        </td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php footer_html(); ?>
