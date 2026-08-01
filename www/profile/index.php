<?php
// My Profile — view page. Editing happens on profile/edit.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_person_form.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/Files.php';
Application::init();
require_login();

$me = current_user();
$children = StudentTeacherManagement::childrenOfParent((int)$me['id']);

// One-shot flash from eval pages
$msg = $_SESSION['success'] ?? null;
$err = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

header_html('My Profile');

$meName = trim((string)($me['first_name'] ?? '') . ' ' . (string)($me['last_name'] ?? ''));
$meInitials = strtoupper((string)substr((string)($me['first_name'] ?? ''), 0, 1) . (string)substr((string)($me['last_name'] ?? ''), 0, 1));
$mePhotoUrl = Files::profilePhotoUrl($me['photo_public_file_id'] ?? null, 80);
?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
  <h2>My Profile</h2>
  <div style="display:flex;gap:8px;">
    <a class="button" href="/profile/edit.php">Edit Profile</a>
    <a class="button" href="/profile/edit_picture.php">Edit Picture</a>
    <a class="button" href="/profile/change_password.php">Change Password</a>
  </div>
</div>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <?php if ($mePhotoUrl !== ''): ?>
      <img class="avatar" src="<?= h($mePhotoUrl) ?>" alt="<?= h($meName) ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;">
    <?php else: ?>
      <div class="avatar avatar-initials" aria-hidden="true" style="width:80px;height:80px;border-radius:50%;background:#007bff;color:white;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:500;"><?= h($meInitials) ?></div>
    <?php endif; ?>
    <div>
      <h3 style="margin:0 0 4px 0;"><?= h($meName) ?></h3>
      <p style="margin:0;" class="small"><?= h($me['email'] ?? '') ?></p>
      <?php if (!empty($me['is_admin'])): ?>
        <p style="margin:4px 0 0 0;" class="small">Administrator</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <?= person_details_html($me) ?>
  <div class="actions"><a class="button" href="/profile/edit.php">Edit Profile</a></div>
</div>

<?php if ($children): ?>
<h3>My Children</h3>
<div class="card-grid">
  <?php foreach ($children as $child): ?>
  <?php
    $childName = trim($child['first_name'] . ' ' . $child['last_name']);
    $childPhotoUrl = Files::profilePhotoUrl($child['photo_public_file_id'] ?? null, 56);
    $childInitials = strtoupper(substr((string)$child['first_name'], 0, 1) . substr((string)$child['last_name'], 0, 1));
  ?>
  <div class="card">
    <div style="display:flex;align-items:center;gap:10px;">
      <?php if ($childPhotoUrl !== ''): ?>
        <img src="<?=h($childPhotoUrl)?>" alt="" style="width:56px;height:56px;border-radius:50%;object-fit:cover;">
      <?php else: ?>
        <span aria-hidden="true" style="width:56px;height:56px;border-radius:50%;background:var(--color-primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:700;"><?=h($childInitials)?></span>
      <?php endif; ?>
      <div>
        <h3 style="margin:0;"><a href="/parent/child_edit.php?id=<?=(int)$child['id']?>"><?=h($childName)?></a></h3>
        <div class="card-sub" style="margin:0;"><?=h(implode(', ', $child['instruments'] ?: ['No instrument yet']))?></div>
      </div>
    </div>
    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
      <a class="button" href="/parent/child_edit.php?id=<?=(int)$child['id']?>">Edit Profile</a>
      <a class="button" href="/parent/child.php?id=<?=(int)$child['id']?>">Schedule &amp; notes</a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php footer_html(); ?>
