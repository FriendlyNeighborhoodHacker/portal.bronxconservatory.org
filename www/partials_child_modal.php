<?php
// "Add Child" modal for Edit Parent: two top-level tabs — Add New Child
// (first/last/suffix/preferred/grade) and Link Existing Child (typeahead) —
// the cub_scouts pattern. Submits by fetch to admin/child_create_and_link.php
// / admin/child_link.php ({ok} JSON), then reloads.
require_once __DIR__ . '/partials.php';
require_once __DIR__ . '/partials_typeahead.php';

function render_child_modal(int $parentUserId): void {
    ?>
    <div id="addChildModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="modal-content">
        <button class="close" type="button" aria-label="Close">&times;</button>
        <h3>Add Child</h3>
        <div class="actions" style="margin-bottom:12px;">
          <button type="button" class="button primary" id="acTabNew">Add New Child</button>
          <button type="button" class="button" id="acTabLink">Link Existing Child</button>
        </div>
        <div class="error small hidden" id="acErr"></div>

        <form id="acPanelNew" class="stack">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="parent_user_id" value="<?=$parentUserId?>">
          <div class="grid-2">
            <label>First name <input type="text" name="first_name" required></label>
            <label>Last name <input type="text" name="last_name" required></label>
            <label>Suffix <input type="text" name="suffix"></label>
            <label>Preferred name <input type="text" name="preferred_name"></label>
            <label>Class of <span class="small">(graduation year)</span>
              <input type="number" name="class_of" min="2020" max="2050"></label>
          </div>
          <div class="actions">
            <button type="submit" class="button primary">Add &amp; Link Child</button>
            <button type="button" class="button" data-modal-close>Cancel</button>
          </div>
        </form>

        <form id="acPanelLink" class="stack" style="display:none;">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="parent_user_id" value="<?=$parentUserId?>">
          <label>Find the child
            <?php render_typeahead_field('acLink', 'child_user_id', '/admin/student_search.php', 'Type the child\'s name...'); ?>
          </label>
          <div class="actions">
            <button type="submit" class="button primary">Link Child</button>
            <button type="button" class="button" data-modal-close>Cancel</button>
          </div>
        </form>
      </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var tabNew = document.getElementById('acTabNew');
      var tabLink = document.getElementById('acTabLink');
      var panelNew = document.getElementById('acPanelNew');
      var panelLink = document.getElementById('acPanelLink');
      var errEl = document.getElementById('acErr');

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
        submitTo('/admin/child_create_and_link.php', this);
      });
      panelLink.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!document.getElementById('acLink_id').value) {
          errEl.textContent = 'Please select a child to link.';
          errEl.classList.remove('hidden');
          return;
        }
        submitTo('/admin/child_link.php', this);
      });
    });
    </script>
    <?php
}
