<?php
// Admin > Leads > Uncompleted Forms: people who started the public information
// request and stopped before telling us about a student.
//
// Nothing here is a lead — there is no student and nothing to convert. It is a
// call list, and the whole reason page 1 of the flow saves early.
require_once __DIR__ . '/lead_ui.php';
require_once __DIR__ . '/incomplete_inquiry_ui.php';
require_once __DIR__ . '/../partials_pager.php';
Application::init();
require_admin();

const INCOMPLETE_PAGE_SIZE = 25;

$total = InquiryManagement::countIncomplete();
$totalPages = max(1, (int)ceil($total / INCOMPLETE_PAGE_SIZE));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$rows = InquiryManagement::listIncomplete(INCOMPLETE_PAGE_SIZE, ($page - 1) * INCOMPLETE_PAGE_SIZE);

$leadCount = array_sum(LeadManagement::statusCounts());

$flash = $_SESSION['inquiry_flash'] ?? null;
$flashError = $_SESSION['inquiry_flash_error'] ?? null;
unset($_SESSION['inquiry_flash'], $_SESSION['inquiry_flash_error']);

header_html('Uncompleted Forms', ['wide' => true]);
?>

<div class="page-head">
  <h2>Uncompleted Forms</h2>
</div>
<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="seg" style="margin-bottom:10px;">
  <a href="/admin/leads.php">Leads (<?=$leadCount?>)</a>
  <a href="/admin/incomplete_inquiries.php" class="active">Uncompleted Forms (<?=$total?>)</a>
</div>

<p class="small">Someone started one of the public forms — Request Information or Registration —
and stopped before finishing. They may still enroll — a phone call is usually all it takes.</p>

<?php if (!$rows): ?>
  <p class="small">Nothing here right now.</p>
<?php else: ?>
  <div class="card" style="overflow-x:auto;">
    <table class="list">
      <thead>
        <tr><th>Name</th><th>Contact</th><th>Address</th><th>Form</th><th>Stopped after</th><th>Started</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
          <td><a href="/admin/incomplete_inquiry.php?id=<?=(int)$row['id']?>"><strong><?=h($row['first_name'] . ' ' . $row['last_name'])?></strong></a></td>
          <td class="small"><?=h($row['phone'])?><br><?=h($row['email'])?></td>
          <td class="small"><?=h(lead_address_line($row))?></td>
          <td class="small"><span class="badge"><?=h(incomplete_inquiry_source_label($row))?></span></td>
          <td class="small"><?=h(incomplete_inquiry_stage_label($row))?></td>
          <td class="small"><?=h(date('M j, Y', strtotime((string)$row['created_at'])))?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?=pager_html('/admin/incomplete_inquiries.php', [], $page, $totalPages, $total)?>
<?php endif; ?>

<?php footer_html(); ?>
