<?php
// Admin > Leads: the queue of public form submissions, from either the
// registration wizard or the information request.
//
// The default view is Active — new, contacted and scheduled — because
// converted and declined leads are finished work. Everything is paged, since
// this list only ever grows.
require_once __DIR__ . '/lead_ui.php';
require_once __DIR__ . '/../partials_pager.php';
Application::init();
require_admin();

const LEADS_PAGE_SIZES = [25, 50, 100];

// Views are named rather than a list of statuses in the URL: "active" has to
// mean the same thing everywhere, including in a link someone bookmarked.
$views = ['active' => LeadManagement::ACTIVE_STATUSES, 'all' => []];
foreach (LeadManagement::STATUSES as $status) {
    $views[$status] = [$status];
}

$view = (string)($_GET['view'] ?? 'active');
if (!isset($views[$view])) {
    $view = 'active';
}
$source = (string)($_GET['source'] ?? '');
if (!in_array($source, LeadManagement::SOURCES, true)) {
    $source = '';
}
$limit = (int)($_GET['limit'] ?? LEADS_PAGE_SIZES[0]);
if (!in_array($limit, LEADS_PAGE_SIZES, true)) {
    $limit = LEADS_PAGE_SIZES[0];
}
$page = max(1, (int)($_GET['page'] ?? 1));

$filters = ['statuses' => $views[$view], 'source' => $source];
$total = LeadManagement::countLeads($filters);
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);
$leads = LeadManagement::listLeads($filters, $limit, ($page - 1) * $limit);

$counts = LeadManagement::statusCounts(['source' => $source]);
$activeCount = 0;
foreach (LeadManagement::ACTIVE_STATUSES as $status) {
    $activeCount += $counts[$status];
}
$incompleteCount = InquiryManagement::countIncomplete();

// Tab and filter links keep the source but always return to page 1 — a filter
// change makes the old page number meaningless.
$baseParams = ['source' => $source, 'limit' => $limit === LEADS_PAGE_SIZES[0] ? null : $limit];
$tabUrl = fn(string $v) => pager_url('/admin/leads.php', $baseParams, ['view' => $v === 'active' ? null : $v]);

$flash = $_SESSION['lead_flash'] ?? null;
unset($_SESSION['lead_flash']);

header_html('Leads', ['wide' => true]);
?>

<div class="page-head">
  <h2>Leads</h2>
</div>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>

<div class="seg" style="margin-bottom:10px;">
  <a href="<?=h($tabUrl($view))?>" class="active">Leads (<?=array_sum($counts)?>)</a>
  <a href="/admin/incomplete_inquiries.php">Uncompleted Forms (<?=$incompleteCount?>)</a>
</div>

<p class="small">Every public submission lands here as a lead — nothing touches the real roster
or schedule until you <strong>convert</strong> it.</p>

<div class="seg" style="margin-bottom:10px;">
  <a href="<?=h($tabUrl('active'))?>" class="<?=$view === 'active' ? 'active' : ''?>">Active (<?=$activeCount?>)</a>
  <a href="<?=h($tabUrl('all'))?>" class="<?=$view === 'all' ? 'active' : ''?>">All (<?=array_sum($counts)?>)</a>
  <?php foreach (LeadManagement::STATUSES as $status): ?>
    <a href="<?=h($tabUrl($status))?>" class="<?=$view === $status ? 'active' : ''?>">
      <?=h(LeadManagement::STATUS_LABELS[$status])?> (<?=$counts[$status]?>)</a>
  <?php endforeach; ?>
</div>

<form method="get" action="/admin/leads.php" class="actions" style="margin-bottom:14px;gap:10px;align-items:flex-end;">
  <input type="hidden" name="view" value="<?=h($view)?>">
  <label class="small">Source
    <select name="source" onchange="this.form.submit()">
      <option value="">All sources</option>
      <?php foreach (LeadManagement::SOURCES as $option): ?>
      <option value="<?=h($option)?>"<?=$source === $option ? ' selected' : ''?>><?=h(LeadManagement::SOURCE_LABELS[$option])?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="small">Per page
    <select name="limit" onchange="this.form.submit()">
      <?php foreach (LEADS_PAGE_SIZES as $size): ?>
      <option value="<?=$size?>"<?=$limit === $size ? ' selected' : ''?>><?=$size?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <noscript><button type="submit" class="button">Apply</button></noscript>
</form>

<?php if (!$leads): ?>
  <p class="small">No leads in this view. They appear here when a family submits the public
  registration form or asks for information.</p>
<?php else: ?>
  <div class="card" style="overflow-x:auto;">
    <table class="list">
      <thead>
        <tr><th>Family</th><th>Contact</th><th>Students</th><th>Source</th><th>Semester</th><th>Quoted</th><th>Payment</th><th>Status</th><th>Submitted</th></tr>
      </thead>
      <tbody>
        <?php foreach ($leads as $lead): ?>
        <tr>
          <td><a href="/admin/lead.php?id=<?=(int)$lead['id']?>"><strong><?=h($lead['parent_first_name'] . ' ' . $lead['parent_last_name'])?></strong></a></td>
          <td class="small"><?=h($lead['phone'])?><br><?=h($lead['email'])?></td>
          <td class="small"><?=h(lead_students_summary($lead['students']))?></td>
          <td><?=lead_source_html($lead)?></td>
          <td class="small"><?=h(lead_is_inquiry($lead) ? ($lead['semester_label'] ?: '—') : lead_semester_label($lead))?></td>
          <td class="small"><?=lead_is_inquiry($lead) ? '—' : h(lead_dollars((int)$lead['amount_quoted_cents'])) . (!empty($lead['installment_plan']) ? '<br>installments' : '')?></td>
          <td><?=lead_paid_badge_html($lead) ?: '<span class="small">' . (lead_is_inquiry($lead) ? '—' : 'unpaid') . '</span>'?></td>
          <td><?=lead_status_html($lead['status'])?></td>
          <td class="small"><?=h(date('M j, Y', strtotime($lead['created_at'])))?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?=pager_html('/admin/leads.php', ['view' => $view === 'active' ? null : $view] + $baseParams, $page, $totalPages, $total)?>
<?php endif; ?>

<?php footer_html(); ?>
