<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_typeahead.php';

/**
 * The Semester Schedule's cell modals and the delegated cell-click JS.
 *
 * An empty cell opens the add modal, which has two tabs: a student lesson
 * reservation or a hold block (the teacher's lunch, an errand). A filled cell
 * opens the edit modal for whichever kind it holds. Endpoints:
 * reservation_{create,update,delete}.php and hold_block_{create,update,delete}.php
 * (POST, JSON {ok} | {ok:false, error}); the page reloads on success so the
 * grid and its color coding recompute server-side.
 */
class ReservationUIManager {

    public static function renderModals(int $semesterId): void {
        ?>
        <!-- Add (empty cell): student lesson or hold block -->
        <div id="resAddModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3>Reserve this slot</h3>
            <p class="small" id="resAddContext"></p>

            <div class="modal-tabs" role="tablist">
              <button type="button" class="modal-tab active" role="tab" aria-selected="true"
                      data-tab-target="resAddLessonPanel">Student Lesson</button>
              <button type="button" class="modal-tab" role="tab" aria-selected="false"
                      data-tab-target="resAddHoldPanel">Hold Block</button>
            </div>

            <div id="resAddLessonPanel" class="modal-tab-panel">
              <div class="error small hidden" id="resAddErr"></div>
              <form id="resAddForm" class="stack">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="semester_id" value="<?=$semesterId?>">
                <input type="hidden" name="location_id" id="resAddLocationId">
                <input type="hidden" name="teacher_user_id" id="resAddTeacherId">
                <input type="hidden" name="day_of_week" id="resAddDay">
                <input type="hidden" name="start_time" id="resAddTime">
                <label>Student
                  <?php render_typeahead_field('resAddStudent', 'student_user_id', '/admin/student_search.php'); ?>
                </label>
                <div class="grid-2">
                  <label>Duration
                    <select name="duration_minutes">
                      <option value="30">30 minutes</option>
                      <option value="45">45 minutes</option>
                      <option value="60">60 minutes</option>
                    </select>
                  </label>
                  <label>Status
                    <select name="status">
                      <option value="pending_reach_out">Pending reach out</option>
                      <option value="pending_confirmation">Pending confirmation</option>
                      <option value="confirmed">Confirmed</option>
                    </select>
                  </label>
                </div>
                <p class="small">Confirming generates the semester's lessons and posts its charges.</p>
                <div class="actions">
                  <button type="submit" class="button primary">Reserve</button>
                  <button type="button" class="button" data-modal-close>Cancel</button>
                </div>
              </form>
            </div>

            <div id="resAddHoldPanel" class="modal-tab-panel hidden">
              <div class="error small hidden" id="holdAddErr"></div>
              <form id="holdAddForm" class="stack">
                <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
                <input type="hidden" name="semester_id" value="<?=$semesterId?>">
                <input type="hidden" name="location_id" id="holdAddLocationId">
                <input type="hidden" name="teacher_user_id" id="holdAddTeacherId">
                <input type="hidden" name="day_of_week" id="holdAddDay">
                <input type="hidden" name="start_time" id="holdAddTime">
                <label>What is this time for?
                  <input type="text" name="title" id="holdAddTitle" maxlength="200" placeholder="Lunch">
                </label>
                <label>Duration
                  <select name="duration_minutes">
                    <option value="30">30 minutes</option>
                    <option value="45">45 minutes</option>
                    <option value="60">60 minutes</option>
                  </select>
                </label>
                <p class="small">The slot is held on every class date this semester, so no student
                can be booked into it.</p>
                <div class="actions">
                  <button type="submit" class="button primary">Hold</button>
                  <button type="button" class="button" data-modal-close>Cancel</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- Edit reservation (filled cell) -->
        <div id="resEditModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3 id="resEditTitle">Reservation</h3>
            <p class="small" id="resEditContext"></p>
            <p class="small" id="resEditBalance"></p>
            <div class="error small hidden" id="resEditErr"></div>
            <form id="resEditForm" class="stack">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="reservation_id" id="resEditId">
              <label>Status
                <select name="status" id="resEditStatus">
                  <option value="pending_reach_out">Pending reach out</option>
                  <option value="pending_confirmation">Pending confirmation</option>
                  <option value="confirmed">Confirmed</option>
                </select>
              </label>
              <p class="small">Confirming generates the semester's lessons and posts its charges;
              reverting to pending deletes the future lessons.</p>
              <div class="actions">
                <button type="submit" class="button primary">Save</button>
                <button type="button" class="button danger" id="resEditDelete">Delete reservation</button>
                <button type="button" class="button" data-modal-close>Cancel</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Edit hold block (filled hold cell) -->
        <div id="holdEditModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3 id="holdEditHeading">Hold block</h3>
            <p class="small" id="holdEditContext"></p>
            <div class="error small hidden" id="holdEditErr"></div>
            <form id="holdEditForm" class="stack">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="hold_block_reservation_id" id="holdEditId">
              <label>What is this time for?
                <input type="text" name="title" id="holdEditTitle" maxlength="200">
              </label>
              <label>Duration
                <select name="duration_minutes" id="holdEditDuration">
                  <option value="30">30 minutes</option>
                  <option value="45">45 minutes</option>
                  <option value="60">60 minutes</option>
                </select>
              </label>
              <p class="small">Future blocks follow the change; any week that was edited on its own
              is left alone.</p>
              <div class="actions">
                <button type="submit" class="button primary">Save</button>
                <button type="button" class="button danger" id="holdEditDelete">Delete hold block</button>
                <button type="button" class="button" data-modal-close>Cancel</button>
              </div>
            </form>
          </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
          var addModal = document.getElementById('resAddModal');
          var editModal = document.getElementById('resEditModal');
          var holdEditModal = document.getElementById('holdEditModal');

          function openModal(modal) {
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
          }
          function showError(el, message) {
            el.textContent = message;
            el.classList.remove('hidden');
          }
          function postForm(url, form, errEl) {
            errEl.classList.add('hidden');
            fetch(url, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (json) {
                if (json && json.ok) { window.location.reload(); }
                else { showError(errEl, (json && json.error) || 'Something went wrong.'); }
              })
              .catch(function () { showError(errEl, 'Network error.'); });
          }

          // Add-modal tabs: one visible panel at a time.
          var tabs = addModal.querySelectorAll('.modal-tab');
          function selectTab(target) {
            Array.prototype.forEach.call(tabs, function (tab) {
              var on = tab.dataset.tabTarget === target;
              tab.classList.toggle('active', on);
              tab.setAttribute('aria-selected', on ? 'true' : 'false');
              document.getElementById(tab.dataset.tabTarget).classList.toggle('hidden', !on);
            });
          }
          Array.prototype.forEach.call(tabs, function (tab) {
            tab.addEventListener('click', function () { selectTab(tab.dataset.tabTarget); });
          });

          // One delegated handler for every grid cell.
          document.addEventListener('click', function (e) {
            if (!e.target.closest) return;
            if (e.target.closest('.modal')) return;
            var cell = e.target.closest('td.grid-cell');
            if (!cell) return;

            if (cell.dataset.reservationId) {
              document.getElementById('resEditId').value = cell.dataset.reservationId;
              document.getElementById('resEditTitle').textContent = cell.dataset.studentName || 'Reservation';
              document.getElementById('resEditContext').textContent = cell.dataset.context || '';
              document.getElementById('resEditBalance').textContent = cell.dataset.balanceText || '';
              document.getElementById('resEditStatus').value = cell.dataset.status;
              document.getElementById('resEditErr').classList.add('hidden');
              openModal(editModal);
            } else if (cell.dataset.holdReservationId) {
              document.getElementById('holdEditId').value = cell.dataset.holdReservationId;
              document.getElementById('holdEditHeading').textContent = cell.dataset.holdTitle || 'Hold block';
              document.getElementById('holdEditContext').textContent = cell.dataset.context || '';
              document.getElementById('holdEditTitle').value = cell.dataset.holdTitle || '';
              document.getElementById('holdEditDuration').value = cell.dataset.duration || '30';
              document.getElementById('holdEditErr').classList.add('hidden');
              openModal(holdEditModal);
            } else if (cell.dataset.locationId) {
              ['resAdd', 'holdAdd'].forEach(function (prefix) {
                document.getElementById(prefix + 'LocationId').value = cell.dataset.locationId;
                document.getElementById(prefix + 'TeacherId').value = cell.dataset.teacherId;
                document.getElementById(prefix + 'Day').value = cell.dataset.day;
                document.getElementById(prefix + 'Time').value = cell.dataset.time;
              });
              document.getElementById('resAddContext').textContent = cell.dataset.context || '';
              document.getElementById('resAddStudent_id').value = '';
              document.getElementById('resAddStudent_input').value = '';
              document.getElementById('holdAddTitle').value = '';
              document.getElementById('resAddErr').classList.add('hidden');
              document.getElementById('holdAddErr').classList.add('hidden');
              selectTab('resAddLessonPanel');
              openModal(addModal);
            }
          });

          document.getElementById('resAddForm').addEventListener('submit', function (e) {
            e.preventDefault();
            if (!document.getElementById('resAddStudent_id').value) {
              showError(document.getElementById('resAddErr'), 'Please pick a student.');
              return;
            }
            postForm('/admin/reservation_create.php', this, document.getElementById('resAddErr'));
          });

          document.getElementById('holdAddForm').addEventListener('submit', function (e) {
            e.preventDefault();
            if (!document.getElementById('holdAddTitle').value.trim()) {
              showError(document.getElementById('holdAddErr'), 'Please say what this time is for.');
              return;
            }
            postForm('/admin/hold_block_create.php', this, document.getElementById('holdAddErr'));
          });

          document.getElementById('resEditForm').addEventListener('submit', function (e) {
            e.preventDefault();
            postForm('/admin/reservation_update.php', this, document.getElementById('resEditErr'));
          });

          document.getElementById('resEditDelete').addEventListener('click', function () {
            if (!confirm('Delete this reservation? Future lessons will be removed; past lessons are kept.')) return;
            postForm('/admin/reservation_delete.php', document.getElementById('resEditForm'),
                     document.getElementById('resEditErr'));
          });

          document.getElementById('holdEditForm').addEventListener('submit', function (e) {
            e.preventDefault();
            if (!document.getElementById('holdEditTitle').value.trim()) {
              showError(document.getElementById('holdEditErr'), 'Please say what this time is for.');
              return;
            }
            postForm('/admin/hold_block_update.php', this, document.getElementById('holdEditErr'));
          });

          document.getElementById('holdEditDelete').addEventListener('click', function () {
            if (!confirm('Delete this hold block? Future blocks will be removed; past ones are kept.')) return;
            postForm('/admin/hold_block_delete.php', document.getElementById('holdEditForm'),
                     document.getElementById('holdEditErr'));
          });
        });
        </script>
        <?php
    }
}
