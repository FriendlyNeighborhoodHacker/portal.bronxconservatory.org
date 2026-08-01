<?php
// Admin > Leads: the registration-lead queue. Status filter tabs with
// counts; working statuses (new/contacted/scheduled) list before the
// terminal ones.
require_once __DIR__ . '/lead_ui.php';
Application::init();
require_admin();

$counts = LeadManagement::statusCounts();
$filter = (string)($_GET['status'] ?? '');
if ($filter !== '' && !in_array($filter, LeadManagement::STATUSES, true)) {
    $filter = '';
}
$leads = LeadManagement::listLeads($filter !== '' ? $filter : null);

// Default view: working statuses first.
if ($filter === '') {
    $order = array_flip(LeadManagement::STATUSES);
    usort($leads, function (array $a, array $b) use ($order) {
        return ($order[$a['status']] <=> $order[$b['status']])
            ?: strcmp((string)$b['created_at'], (string)$a['created_at']);
    });
}

$flash = $_SESSION['lead_flash'] ?? null;
unset($_SESSION['lead_flash']);

header_html('Leads', ['wide' => true]);
?>

<div class="page-head">
  <h2>Registration Leads</h2>
</div>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>

<p class="small">Every public registration lands here as a lead — nothing touches the
real roster or schedule until you <strong>convert</strong> it.</p>

<div class="seg" style="margin-bottom:14px;">
  <a href="/admin/leads.php" class="<?=$filter === '' ? 'active' : ''?>">All (<?=array_sum($counts)?>)</a>
  <?php foreach (LeadManagement::STATUSES as $status): ?>
    <a href="/admin/leads.php?status=<?=$status?>" class="<?=$filter === $status ? 'active' : ''?>">
      <?=h(LeadManagement::STATUS_LABELS[$status])?> (<?=$counts[$status]?>)</a>
  <?php endforeach; ?>
</div>

<?php if (!$leads): ?>
  <p class="small">No leads<?=$filter !== '' ? ' with this status' : ' yet'?>. They appear
  here when a family submits the public registration form.</p>
<?php else: ?>
  <div class="card" style="overflow-x:auto;">
    <table class="list">
      <thead>
        <tr><th>Family</th><th>Contact</th><th>Students</th><th>Semester</th><th>Quoted</th><th>Payment</th><th>Status</th><th>Submitted</th></tr>
      </thead>
      <tbody>
        <?php foreach ($leads as $lead): ?>
        <tr>
          <td><a href="/admin/lead.php?id=<?=(int)$lead['id']?>"><strong><?=h($lead['parent_first_name'] . ' ' . $lead['parent_last_name'])?></strong></a></td>
          <td class="small"><?=h($lead['phone'])?><br><?=h($lead['email'])?></td>
          <td class="small"><?=h(lead_students_summary($lead['students']))?></td>
          <td class="small"><?=h(lead_semester_label($lead))?></td>
          <td class="small"><?=h(lead_dollars((int)$lead['amount_quoted_cents']))?><?=!empty($lead['installment_plan']) ? '<br>installments' : ''?></td>
          <td><?=lead_paid_badge_html($lead) ?: '<span class="small">unpaid</span>'?></td>
          <td><?=lead_status_html($lead['status'])?></td>
          <td class="small"><?=h(date('M j, Y', strtotime($lead['created_at'])))?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
