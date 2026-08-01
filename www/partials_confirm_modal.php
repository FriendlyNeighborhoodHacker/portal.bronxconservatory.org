<?php
// Reusable confirmation modal for destructive actions (soft deletes). Opens
// via any element with data-modal-open="<id>" (wired by main.js); the
// Confirm button submits a normal POST form (PRG + flash on the eval side).
require_once __DIR__ . '/partials.php';

function render_confirm_modal(string $id, string $title, string $bodyHtml, string $formAction, array $hiddenFields = [], string $confirmLabel = 'Yes, delete'): void {
    ?>
    <div id="<?=h($id)?>" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="modal-content">
        <button class="close" type="button" aria-label="Close">&times;</button>
        <h3><?=h($title)?></h3>
        <div class="description"><?=$bodyHtml?></div>
        <form method="post" action="<?=h($formAction)?>" data-skip-unsaved-warning>
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <?php foreach ($hiddenFields as $name => $value): ?>
            <input type="hidden" name="<?=h($name)?>" value="<?=h((string)$value)?>">
          <?php endforeach; ?>
          <div class="actions">
            <button type="submit" class="button danger"><?=h($confirmLabel)?></button>
            <button type="button" class="button" data-modal-close>Cancel</button>
          </div>
        </form>
      </div>
    </div>
    <?php
}
