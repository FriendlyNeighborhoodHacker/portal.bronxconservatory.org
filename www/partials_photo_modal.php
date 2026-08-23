<?php
// The profile-photo modal on the admin student page: upload (or replace) the
// photo, and remove it when one exists. Opened by any
// [data-modal-open="photoModal"] button; plain POST forms to the existing
// /upload_photo.php endpoint (PRG back to $returnTo).
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/lib/Files.php';

function render_photo_modal(array $user, string $returnTo): void {
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $photoUrl = Files::profilePhotoUrl($user['photo_public_file_id'] ?? null);
    $action = '/upload_photo.php?user_id=' . (int)$user['id'] . '&return_to=' . urlencode($returnTo);
    ?>
    <div id="photoModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="modal-content">
        <button class="close" type="button" aria-label="Close">&times;</button>
        <h3><?=$photoUrl !== '' ? 'Edit Profile Photo' : 'Add Photo'?></h3>
        <?php if ($photoUrl !== ''): ?>
          <img src="<?=h($photoUrl)?>" alt="<?=h($name)?>"
               style="width:120px;height:120px;border-radius:50%;object-fit:cover;display:block;margin:0 auto 12px;">
        <?php endif; ?>
        <form method="post" action="<?=h($action)?>" enctype="multipart/form-data" class="stack">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <label><?=$photoUrl !== '' ? 'Upload a new photo' : 'Choose a photo'?>
            <input type="file" name="photo" accept="image/*" required>
          </label>
          <div class="actions actions-split">
            <?php if ($photoUrl !== ''): ?>
              <button class="button danger" type="submit" form="photoModalDeleteForm"
                      onclick="return confirm('Remove this photo?');">Remove Photo</button>
            <?php endif; ?>
            <span class="actions-right">
              <button type="button" class="button" data-modal-close>Cancel</button>
              <button class="button primary" type="submit">Upload Photo</button>
            </span>
          </div>
        </form>
        <?php if ($photoUrl !== ''): ?>
          <form id="photoModalDeleteForm" method="post" action="<?=h($action)?>">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="delete">
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php
}
