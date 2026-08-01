<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials.php';

/**
 * The admin hold-block modal (weekly calendar): edit ONE week of a standing
 * hold block — move it to another time that day (slots from
 * hold_block_slots.php), give that week its own title, or drop it entirely.
 * The standing reservation and its other weeks are untouched; edit those on
 * the Semester Schedule.
 */
class HoldBlockUIManager {

    public static function renderModal(): void {
        ?>
        <div id="holdBlockModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3 id="holdBlockModalTitle">Hold block</h3>
            <p class="small" id="holdBlockModalContext"></p>
            <div class="error small hidden" id="holdBlockErr"></div>

            <input type="hidden" id="holdBlockModalId">
            <input type="hidden" id="holdBlockCsrf" value="<?=h(csrf_token())?>">

            <div class="stack">
              <label>Time (same day)
                <select id="holdBlockSlotSelect"><option value="">Loading times…</option></select>
              </label>

              <label>What this week is for
                <input type="text" id="holdBlockTitle" maxlength="200">
              </label>
              <p class="small">This changes only this week. Clear it to go back to the standing
              title, or edit every week on the <a href="/admin/schedule.php">Semester Schedule</a>.</p>

              <div class="actions actions-split">
                <button type="button" class="button danger" id="holdBlockDelete">Remove this week</button>
                <span class="actions-right">
                  <button type="button" class="button" data-modal-close>Cancel</button>
                  <button type="button" class="button primary" id="holdBlockSave">Save</button>
                </span>
              </div>
            </div>
          </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
          var modal = document.getElementById('holdBlockModal');
          var errEl = document.getElementById('holdBlockErr');

          function showError(message) {
            errEl.textContent = message;
            errEl.classList.remove('hidden');
          }
          function blockId() { return document.getElementById('holdBlockModalId').value; }

          function postJson(url, fields) {
            var body = new FormData();
            body.append('csrf', document.getElementById('holdBlockCsrf').value);
            body.append('hold_block_id', blockId());
            Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
            return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
              .then(function (r) { return r.json(); });
          }

          // Open on any weekly-grid cell that carries a hold block id.
          document.addEventListener('click', function (e) {
            var cell = e.target.closest ? e.target.closest('[data-hold-block-id]') : null;
            if (!cell || cell.closest('.modal')) return;
            errEl.classList.add('hidden');
            document.getElementById('holdBlockModalId').value = cell.dataset.holdBlockId;
            document.getElementById('holdBlockModalTitle').textContent = cell.dataset.holdTitle || 'Hold block';
            document.getElementById('holdBlockModalContext').textContent = cell.dataset.context || '';
            document.getElementById('holdBlockTitle').value = cell.dataset.holdTitle || '';

            var select = document.getElementById('holdBlockSlotSelect');
            select.innerHTML = '<option value="">Loading times…</option>';
            fetch('/admin/hold_block_slots.php?hold_block_id=' + encodeURIComponent(cell.dataset.holdBlockId), { credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (json) {
                select.innerHTML = '';
                (json.items || []).forEach(function (it) {
                  var opt = document.createElement('option');
                  opt.value = it.value;
                  opt.textContent = it.label + (it.current ? ' (current)' : '');
                  if (it.current) opt.selected = true;
                  select.appendChild(opt);
                });
              })
              .catch(function () { select.innerHTML = '<option value="">Could not load times</option>'; });

            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
          });

          // Save applies the time change (if any) then the title.
          document.getElementById('holdBlockSave').addEventListener('click', function () {
            errEl.classList.add('hidden');
            var steps = [];
            var slot = document.getElementById('holdBlockSlotSelect');
            if (slot.value && !slot.options[slot.selectedIndex].textContent.includes('(current)')) {
              steps.push(function () { return postJson('/admin/hold_block_reschedule.php', { start_time: slot.value }); });
            }
            steps.push(function () {
              return postJson('/admin/hold_block_title.php', { title: document.getElementById('holdBlockTitle').value });
            });

            steps.reduce(function (chain, step) {
              return chain.then(function (prev) {
                if (prev && !prev.ok) throw new Error(prev.error || 'Something went wrong.');
                return step();
              });
            }, Promise.resolve({ ok: true }))
              .then(function (last) {
                if (last && !last.ok) throw new Error(last.error || 'Something went wrong.');
                window.location.reload();
              })
              .catch(function (err) { showError(err.message); });
          });

          document.getElementById('holdBlockDelete').addEventListener('click', function () {
            if (!confirm('Remove this hold block for this week only? The standing hold block stays.')) return;
            errEl.classList.add('hidden');
            postJson('/admin/hold_block_occurrence_delete.php', {})
              .then(function (json) {
                if (json && json.ok) { window.location.reload(); }
                else { showError((json && json.error) || 'Something went wrong.'); }
              })
              .catch(function () { showError('Network error.'); });
          });
        });
        </script>
        <?php
    }
}
