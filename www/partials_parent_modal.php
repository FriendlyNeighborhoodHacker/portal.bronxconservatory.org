<?php
// "Add Parent" modal for Edit Student: two top-level tabs — Add New Parent
// (create + link) and Link Existing Parent (typeahead) — the cub_scouts
// pattern. Submits by fetch to admin/parent_create_and_link.php /
// admin/parent_link.php ({ok} JSON), then reloads.
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/partials_typeahead.php';

function render_parent_modal(int $childUserId): void {
    ?>
    <div id="addParentModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="modal-content">
        <button class="close" type="button" aria-label="Close">&times;</button>
        <h3>Add Parent</h3>
        <div class="actions" style="margin-bottom:12px;">
          <button type="button" class="button primary" id="apTabNew">Add New Parent</button>
          <button type="button" class="button" id="apTabLink">Link Existing Parent</button>
        </div>
        <div class="error small hidden" id="apErr"></div>

        <form id="apPanelNew" class="stack">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="child_user_id" value="<?=$childUserId?>">
          <div class="grid-2">
            <label>First name <input type="text" name="first_name" required></label>
            <label>Last name <input type="text" name="last_name" required></label>
            <label>Email <input type="email" name="email"></label>
            <label>Cell phone <input type="text" name="cell_phone"></label>
            <label>Relationship
              <select name="role">
                <option value="">—</option>
                <option value="mother">Mother</option>
                <option value="father">Father</option>
                <option value="guardian">Guardian</option>
              </select>
            </label>
          </div>
          <div class="actions">
            <button type="submit" class="button primary">Add &amp; Link Parent</button>
            <button type="button" class="button" data-modal-close>Cancel</button>
          </div>
        </form>

        <form id="apPanelLink" class="stack" style="display:none;">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="child_user_id" value="<?=$childUserId?>">
          <label>Find the parent
            <?php render_typeahead_field('apLink', 'parent_user_id',
                '/admin/parent_search.php?child_user_id=' . $childUserId,
                'Type the parent\'s name...'); ?>
          </label>
          <label>Relationship
            <select name="role">
              <option value="">—</option>
              <option value="mother">Mother</option>
              <option value="father">Father</option>
              <option value="guardian">Guardian</option>
            </select>
          </label>
          <div class="actions">
            <button type="submit" class="button primary">Link Parent</button>
            <button type="button" class="button" data-modal-close>Cancel</button>
          </div>
        </form>
      </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var tabNew = document.getElementById('apTabNew');
      var tabLink = document.getElementById('apTabLink');
      var panelNew = document.getElementById('apPanelNew');
      var panelLink = document.getElementById('apPanelLink');
      var errEl = document.getElementById('apErr');

      function switchTab(showNew) {
        panelNew.style.display = showNew ? '' : 'none';
        panelLink.style.display = showNew ? 'none' : '';
        tabNew.classList.toggle('primary', showNew);
        tabLink.classList.toggle('primary', !showNew);
        errEl.classList.add('hidden');
      }
      tabNew.addEventListener('click', function () { switchTab(true); });
      tabLink.addEventListener('click', function () { switchTab(false); });

      function submitTo(url, form) {
        errEl.classList.add('hidden');
        fetch(url, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (json) {
            if (json && json.ok) { window.location.reload(); }
            else {
              errEl.textContent = (json && json.error) || 'Something went wrong.';
              errEl.classList.remove('hidden');
            }
          })
          .catch(function () {
            errEl.textContent = 'Network error.';
            errEl.classList.remove('hidden');
          });
      }

      panelNew.addEventListener('submit', function (e) {
        e.preventDefault();
        submitTo('/admin/parent_create_and_link.php', this);
      });
      panelLink.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!document.getElementById('apLink_id').value) {
          errEl.textContent = 'Please select a parent to link.';
          errEl.classList.remove('hidden');
          return;
        }
        submitTo('/admin/parent_link.php', this);
      });
    });
    </script>
    <?php
}
