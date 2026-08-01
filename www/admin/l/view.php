<?php
declare(strict_types=1);
// Admin — Server Log viewer: tails one log file (?log=<slug>), paged from the
// end of the file by byte offset (?end=<int>). Older/Newer/Newest move between
// pages. The slug is looked up in LogViewer's fixed table, so a request can
// never point this at an arbitrary path.
require_once __DIR__ . '/../../partials.php';
require_once __DIR__ . '/../../lib/LogViewer.php';
Application::init();
require_developer();

$slug = (string)($_GET['log'] ?? '');
$log  = LogViewer::get($slug);
if ($log === null) {
    $_SESSION['logs_flash_error'] = 'Unknown log file.';
    header('Location: /admin/l/index.php');
    exit;
}

$end  = isset($_GET['end']) ? max(0, (int)$_GET['end']) : null;
$res  = LogViewer::tail($log['path'], $end);
$stat = LogViewer::stat($log['path']);

// Navigation targets (byte offsets). Older ends where this page starts;
// Newer re-adds a chunk, or becomes "Newest" when it reaches EOF.
$olderUrl = null;
if ($res['ok'] && $res['start'] > 0) {
    $olderUrl = '/admin/l/view.php?log=' . urlencode($slug) . '&end=' . $res['start'];
}
$newerUrl = null;
if ($res['ok'] && $res['end'] < $res['size']) {
    $newerEnd = min($res['end'] + LogViewer::CHUNK_BYTES, $res['size']);
    $newerUrl = '/admin/l/view.php?log=' . urlencode($slug)
        . ($newerEnd >= $res['size'] ? '' : '&end=' . $newerEnd);
}
$newestUrl = '/admin/l/view.php?log=' . urlencode($slug);
$atNewest  = $res['ok'] && $res['end'] >= $res['size'];

// The Older / Newer / Newest nav bar, shown above and below the log text.
$renderNav = function () use ($olderUrl, $newerUrl, $newestUrl, $atNewest, $res): void {
    ?>
    <div class="actions" style="margin:10px 0;flex-wrap:wrap;">
      <?php if ($olderUrl): ?>
        <a class="button" href="<?=h($olderUrl)?>">&laquo; Older</a>
      <?php else: ?>
        <button type="button" disabled>&laquo; Older</button>
      <?php endif; ?>
      <?php if ($newerUrl): ?>
        <a class="button" href="<?=h($newerUrl)?>">Newer &raquo;</a>
      <?php else: ?>
        <button type="button" disabled>Newer &raquo;</button>
      <?php endif; ?>
      <a class="button primary" href="<?=h($newestUrl)?>"><?=$atNewest ? 'Refresh' : 'Newest'?> &#x27F3;</a>
      <?php if ($res['ok']): ?>
        <span class="small">
          Showing bytes <?=number_format($res['start'])?>&ndash;<?=number_format($res['end'])?>
          of <?=number_format($res['size'])?>
        </span>
      <?php endif; ?>
    </div>
    <?php
};

header_html($log['label'] . ' Log', ['wide' => true]);
?>

<div class="page-head">
  <h2><?=h($log['label'])?> Log</h2>
  <a class="button" href="/admin/l/index.php">&larr; All Logs</a>
</div>
<p class="small" style="word-break:break-all;">
  <code><?=h($log['path'])?></code>
  <?php if ($stat['readable']): ?>
    &middot; <?=h(LogViewer::formatBytes($stat['size']))?>
    &middot; modified <?=h(date('Y-m-d H:i:s', $stat['mtime']))?>
  <?php endif; ?>
</p>

<?php if (!$res['ok']): ?>

  <p class="error"><?=h($res['error'] ?? 'Could not read the log file.')?></p>

<?php else: ?>

  <?php if ($res['clamped']): ?>
    <p class="small">The file shrank since this link was made (rotated or truncated) — jumped to the newest entries.</p>
  <?php endif; ?>
  <?php if ($res['noNewline']): ?>
    <p class="small">A single log line is longer than one page — it is truncated at the page boundary.</p>
  <?php endif; ?>

  <?php $renderNav(); ?>

  <pre id="log-content" style="background:#1e1e1e;color:#d4d4d4;border-radius:10px;
      padding:16px;font-size:12px;line-height:1.5;white-space:pre-wrap;
      word-break:break-all;overflow-x:auto;max-height:70vh;overflow-y:auto;
      margin:0;"><?=$res['text'] === '' ? '(empty)' : h($res['text'])?></pre>

  <?php $renderNav(); ?>

<?php endif; ?>

<?php if ($res['ok'] && $atNewest): ?>
<script>
// On the newest page, start scrolled to the most recent lines.
(function () {
  var pre = document.getElementById('log-content');
  if (pre) pre.scrollTop = pre.scrollHeight;
})();
</script>
<?php endif; ?>

<?php footer_html(); ?>
