<?php
// The instruments-edit modal on the admin student page: the same choice-grid
// of checkboxes the old Instruments card had, in a modal. Opened by any
// [data-modal-open="instrumentsModal"] link; plain POST to the existing
// /admin/student_instruments_eval.php (PRG back to $returnTo).
require_once __DIR__ . '/partials.php';

function render_student_instruments_modal(int $studentUserId, array $allInstruments, array $checkedNames, string $returnTo): void {
    ?>
    <div id="instrumentsModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="modal-content">
        <button class="close" type="button" aria-label="Close">&times;</button>
        <h3>Instruments</h3>
        <form method="post" action="/admin/student_instruments_eval.php" class="stack">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="id" value="<?=$studentUserId?>">
          <input type="hidden" name="return_to" value="<?=h($returnTo)?>">
          <div class="choice-grid">
            <?php foreach ($allInstruments as $instrument): ?>
              <label class="inline">
                <input type="checkbox" name="instrument_ids[]" value="<?=(int)$instrument['id']?>"
                  <?=in_array($instrument['name'], $checkedNames, true) ? 'checked' : ''?>>
                <?=h($instrument['name'])?>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="actions actions-split">
            <span class="actions-right">
              <button type="button" class="button" data-modal-close>Cancel</button>
              <button type="submit" class="button primary">Save Instruments</button>
            </span>
          </div>
        </form>
      </div>
    </div>
    <?php
}
