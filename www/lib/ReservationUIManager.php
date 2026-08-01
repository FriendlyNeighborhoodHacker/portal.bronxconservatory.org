<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_typeahead.php';

/**
 * The Semester Schedule's reservation modals (add on an empty cell, edit on
 * a filled one) and the delegated cell-click JS. Endpoints:
 * reservation_create.php / reservation_update.php / reservation_delete.php
 * (POST, JSON {ok} | {ok:false, error}); the page reloads on success so the
 * grid and its color coding recompute server-side.
 */
class ReservationUIManager {

    public static function renderModals(int $semesterId): void {
        ?>
        <!-- Add reservation (empty cell) -->
        <div id="resAddModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3>Reserve this slot</h3>
            <p class="small" id="resAddContext"></p>
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

        <script>
        document.addEventListener('DOMContentLoaded', function () {
          var addModal = document.getElementById('resAddModal');
          var editModal = document.getElementById('resEditModal');

          function openModal(modal) {
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
          }
          function closeModal(modal) {
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
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

          // One delegated handler for every grid cell.
          document.addEventListener('click', function (e) {
            var cell = e.target.closest ? e.target.closest('td.grid-cell') : null;
            if (!cell) return;

            if (cell.dataset.reservationId) {
              document.getElementById('resEditId').value = cell.dataset.reservationId;
              document.getElementById('resEditTitle').textContent = cell.dataset.studentName || 'Reservation';
              document.getElementById('resEditContext').textContent = cell.dataset.context || '';
              document.getElementById('resEditBalance').textContent = cell.dataset.balanceText || '';
              document.getElementById('resEditStatus').value = cell.dataset.status;
              editModal.querySelector('#resEditErr').classList.add('hidden');
              openModal(editModal);
            } else if (cell.dataset.locationId) {
              document.getElementById('resAddLocationId').value = cell.dataset.locationId;
              document.getElementById('resAddTeacherId').value = cell.dataset.teacherId;
              document.getElementById('resAddDay').value = cell.dataset.day;
              document.getElementById('resAddTime').value = cell.dataset.time;
              document.getElementById('resAddContext').textContent = cell.dataset.context || '';
              document.getElementById('resAddStudent_id').value = '';
              document.getElementById('resAddStudent_input').value = '';
              addModal.querySelector('#resAddErr').classList.add('hidden');
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

          document.getElementById('resEditForm').addEventListener('submit', function (e) {
            e.preventDefault();
            postForm('/admin/reservation_update.php', this, document.getElementById('resEditErr'));
          });

          document.getElementById('resEditDelete').addEventListener('click', function () {
            if (!confirm('Delete this reservation? Future lessons will be removed; past lessons are kept.')) return;
            postForm('/admin/reservation_delete.php', document.getElementById('resEditForm'),
                     document.getElementById('resEditErr'));
          });
        });
        </script>
        <?php
    }
}
