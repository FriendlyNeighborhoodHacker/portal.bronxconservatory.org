<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_typeahead.php';
require_once __DIR__ . '/HoldBlockManagement.php';
require_once __DIR__ . '/ReservationManagement.php';

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

    /** 30 -> "30 minutes", 90 -> "1 hour 30 minutes", 120 -> "2 hours". */
    private static function durationLabel(int $minutes): string {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . ($hours === 1 ? ' hour' : ' hours');
        }
        if ($rest > 0 || $hours === 0) {
            $parts[] = $rest . ' minutes';
        }
        return implode(' ', $parts);
    }

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
                <input type="hidden" name="charges_acknowledged" value="">
                <input type="hidden" name="include_installment_fee" value="">
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
                      <?php foreach (ReservationManagement::DURATION_OPTIONS as $minutes): ?>
                        <option value="<?=(int)$minutes?>"><?=(int)$minutes?> minutes</option>
                      <?php endforeach; ?>
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
                <p class="small">Confirming generates the semester's lessons — a dialog will
                itemize the charges for review before anything is posted.</p>
                <div class="actions actions-split">
                  <span class="actions-right">
                    <button type="button" class="button" data-modal-close>Cancel</button>
                    <button type="submit" class="button primary">Reserve</button>
                  </span>
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
                <label>Kind
                  <select name="block_type" id="holdAddBlockType">
                    <?php foreach (HoldBlockManagement::BLOCK_TYPES as $value => $label): ?>
                      <option value="<?=h($value)?>"><?=h($label)?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>Duration
                  <select name="duration_minutes">
                    <?php foreach (HoldBlockManagement::DURATION_OPTIONS as $minutes): ?>
                      <option value="<?=$minutes?>"><?=self::durationLabel($minutes)?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <p class="small">The slot is held on every class date this semester, so no student
                can be booked into it.</p>
                <div class="actions actions-split">
                  <span class="actions-right">
                    <button type="button" class="button" data-modal-close>Cancel</button>
                    <button type="submit" class="button primary">Hold</button>
                  </span>
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
              <input type="hidden" name="charges_acknowledged" value="">
              <input type="hidden" name="include_installment_fee" value="">
              <label>Length
                <select name="duration_minutes" id="resEditDuration">
                  <?php foreach (ReservationManagement::DURATION_OPTIONS as $minutes): ?>
                    <option value="<?=(int)$minutes?>"><?=(int)$minutes?> minutes</option>
                  <?php endforeach; ?>
                </select>
              </label>
              <p class="small">Changing the length moves every future week of this booking, and is
              refused if it would run into something else.</p>

              <label>Status
                <select name="status" id="resEditStatus">
                  <option value="pending_reach_out">Pending reach out</option>
                  <option value="pending_confirmation">Pending confirmation</option>
                  <option value="confirmed">Confirmed</option>
                </select>
              </label>
              <p class="small">Confirming generates the semester's lessons; reverting to pending
              deletes the future lessons. Either way, a dialog will itemize the charge or
              credit line items for review before anything is posted.</p>
              <p class="small"><a id="resEditStudentLink" href="#" target="_blank" rel="noopener"></a></p>
              <div class="actions actions-split">
                <button type="button" class="button danger" id="resEditDelete">Delete reservation</button>
                <span class="actions-right">
                  <button type="button" class="button" data-modal-close>Cancel</button>
                  <button type="submit" class="button primary">Save</button>
                </span>
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
              <label>Kind
                <select name="block_type" id="holdEditBlockType">
                  <?php foreach (HoldBlockManagement::BLOCK_TYPES as $value => $label): ?>
                    <option value="<?=h($value)?>"><?=h($label)?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Duration
                <select name="duration_minutes" id="holdEditDuration">
                  <?php foreach (HoldBlockManagement::DURATION_OPTIONS as $minutes): ?>
                    <option value="<?=$minutes?>"><?=self::durationLabel($minutes)?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <p class="small">Future blocks follow the change; any week that was edited on its own
              is left alone.</p>
              <div class="actions actions-split">
                <button type="button" class="button danger" id="holdEditDelete">Delete hold block</button>
                <span class="actions-right">
                  <button type="button" class="button" data-modal-close>Cancel</button>
                  <button type="submit" class="button primary">Save</button>
                </span>
              </div>
            </form>
          </div>
        </div>

        <!-- Charge confirmation: the line items a confirm posts / an un-confirm reverses -->
        <div id="resChargesModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3 id="resChargesTitle">Confirm charges</h3>
            <p class="small" id="resChargesContext"></p>
            <div class="error small hidden" id="resChargesWarning"></div>
            <table class="list" id="resChargesTable">
              <tbody id="resChargesLines"></tbody>
            </table>
            <label class="inline hidden" id="resChargesInstallmentRow">
              <input type="checkbox" id="resChargesInstallment">
              <span id="resChargesInstallmentLabel">Include installment fee</span>
            </label>
            <p class="small hidden" id="resChargesNote"></p>
            <div class="actions actions-split">
              <span class="actions-right">
                <button type="button" class="button" data-modal-close>Cancel</button>
                <button type="button" class="button primary" id="resChargesConfirmBtn">Post charges &amp; confirm</button>
              </span>
            </div>
          </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
          var addModal = document.getElementById('resAddModal');
          var editModal = document.getElementById('resEditModal');
          var holdEditModal = document.getElementById('holdEditModal');
          var chargesModal = document.getElementById('resChargesModal');
          // What the charge dialog's Confirm button should do: {url, form, errEl, mode}
          var pendingCharges = null;

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
                else if (json && json.needs_accounting) {
                  // Duration change on a confirmed reservation: the review
                  // page recomputes and shows the entries before posting.
                  window.location.href = '/admin/duration_change_accounting.php?'
                    + 'reservation_id=' + encodeURIComponent(json.reservation_id)
                    + '&new_duration_minutes=' + encodeURIComponent(form.elements.duration_minutes.value);
                }
                else { showError(errEl, (json && json.error) || 'Something went wrong.'); }
              })
              .catch(function () { showError(errEl, 'Network error.'); });
          }

          function closeModal(modal) {
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
          }

          // Fetch the line items a status change would post and show them for
          // review; only the dialog's Confirm button proceeds (with
          // charges_acknowledged=1 — the endpoints refuse the change without it).
          function openChargesReview(query, submit) {
            submit.errEl.classList.add('hidden');
            fetch('/admin/reservation_charges_preview.php?' + query, { credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (json) {
                if (!json || !json.ok) {
                  showError(submit.errEl, (json && json.error) || 'Something went wrong.');
                  return;
                }
                renderChargesReview(json, submit.mode);
                pendingCharges = submit;
                openModal(chargesModal);
              })
              .catch(function () { showError(submit.errEl, 'Network error.'); });
          }

          function renderChargesReview(json, mode) {
            var title = document.getElementById('resChargesTitle');
            var warning = document.getElementById('resChargesWarning');
            var table = document.getElementById('resChargesTable');
            var lines = document.getElementById('resChargesLines');
            var installmentRow = document.getElementById('resChargesInstallmentRow');
            var note = document.getElementById('resChargesNote');
            var confirmBtn = document.getElementById('resChargesConfirmBtn');

            document.getElementById('resChargesContext').textContent =
              (json.student_name || '') + (json.semester_label ? ' — ' + json.semester_label : '');
            warning.classList.add('hidden');
            installmentRow.classList.add('hidden');
            note.classList.add('hidden');
            lines.innerHTML = '';

            function addLine(label, amount, muted, mutedNote) {
              var tr = document.createElement('tr');
              var tdLabel = document.createElement('td');
              tdLabel.textContent = label + (mutedNote ? ' — ' + mutedNote : '');
              var tdAmount = document.createElement('td');
              tdAmount.style.textAlign = 'right';
              tdAmount.textContent = amount;
              if (muted) { tr.style.opacity = '0.55'; tdAmount.style.textDecoration = 'line-through'; }
              tr.appendChild(tdLabel);
              tr.appendChild(tdAmount);
              lines.appendChild(tr);
            }
            function addTotal(label, amount) {
              var tr = document.createElement('tr');
              var tdLabel = document.createElement('td');
              tdLabel.innerHTML = '<strong></strong>';
              tdLabel.firstChild.textContent = label;
              var tdAmount = document.createElement('td');
              tdAmount.style.textAlign = 'right';
              tdAmount.innerHTML = '<strong></strong>';
              tdAmount.firstChild.textContent = amount;
              tr.appendChild(tdLabel);
              tr.appendChild(tdAmount);
              lines.appendChild(tr);
            }

            if (mode === 'confirm') {
              title.textContent = 'Confirm reservation — review charges';
              confirmBtn.textContent = 'Post charges & confirm';
              (json.lines || []).forEach(function (line) {
                addLine(line.label, line.amount, !line.will_post, line.will_post ? '' : line.skip_reason);
              });
              addTotal('Total charged now', json.total);
              table.classList.remove('hidden');
              if (json.installment_available) {
                document.getElementById('resChargesInstallmentLabel').textContent =
                  'Include installment fee (' + json.installment_fee + ') — the family is paying in two installments';
                document.getElementById('resChargesInstallment').checked = false;
                installmentRow.classList.remove('hidden');
              }
              if (json.installment_note) {
                note.textContent = json.installment_note;
                note.classList.remove('hidden');
              }
            } else {
              title.textContent = mode === 'delete'
                ? 'Delete reservation — review credits'
                : 'Un-confirm reservation — review credits';
              confirmBtn.textContent = mode === 'delete' ? 'Post credits & delete' : 'Post credits & un-confirm';
              if (json.will_reverse) {
                (json.lines || []).forEach(function (line) { addLine(line.label, line.amount, false, ''); });
                addTotal('Total credited', json.total);
                table.classList.remove('hidden');
              } else {
                table.classList.add('hidden');
                warning.textContent = json.blocked_reason
                  ? 'No credits will be issued because ' + json.blocked_reason
                    + '. The existing charges remain on the ledger; make a manual adjustment if needed.'
                  : 'There are no charges left to reverse for this semester.';
                warning.classList.remove('hidden');
                confirmBtn.textContent = mode === 'delete' ? 'Delete anyway' : 'Un-confirm anyway';
              }
            }
          }

          document.getElementById('resChargesConfirmBtn').addEventListener('click', function () {
            if (!pendingCharges) return;
            var submit = pendingCharges;
            pendingCharges = null;
            submit.form.elements.charges_acknowledged.value = '1';
            if (submit.mode === 'confirm') {
              var row = document.getElementById('resChargesInstallmentRow');
              var checked = !row.classList.contains('hidden')
                && document.getElementById('resChargesInstallment').checked;
              submit.form.elements.include_installment_fee.value = checked ? '1' : '0';
            }
            closeModal(chargesModal);
            postForm(submit.url, submit.form, submit.errEl);
          });

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

          // One delegated handler for every grid cell. A filled cell holds one
          // .cell-item per commitment (usually exactly one), so the click
          // resolves to the item that was actually clicked.
          document.addEventListener('click', function (e) {
            if (!e.target.closest) return;
            if (e.target.closest('.modal')) return;
            // In edit mode a click on the grid is the start of a drag, not a
            // request to open anything.
            if (document.body.classList.contains('schedule-edit-mode')) return;

            // Anywhere in an occupied cell counts as the thing in it.
            var item = bcmCellItemFor(e.target);
            if (item && item.dataset.reservationId) {
              var editForm = document.getElementById('resEditForm');
              editForm.dataset.originalStatus = item.dataset.status || '';
              editForm.elements.charges_acknowledged.value = '';
              editForm.elements.include_installment_fee.value = '';
              document.getElementById('resEditId').value = item.dataset.reservationId;
              document.getElementById('resEditTitle').textContent = item.dataset.studentName || 'Reservation';
              document.getElementById('resEditContext').textContent = item.dataset.context || '';
              document.getElementById('resEditBalance').textContent = item.dataset.balanceText || '';
              document.getElementById('resEditStatus').value = item.dataset.status;
              // An imported or older booking may run a length the dropdown does
              // not offer; show it as itself rather than rewriting it on save.
              var durationSelect = document.getElementById('resEditDuration');
              var duration = item.dataset.duration || '';
              if (duration && !durationSelect.querySelector('option[value="' + duration + '"]')) {
                var opt = document.createElement('option');
                opt.value = duration;
                opt.textContent = duration + ' minutes (current)';
                durationSelect.appendChild(opt);
              }
              durationSelect.value = duration;
              // Way out of the grid: everything else about this student
              // (parents, instruments, charges) lives on their own page.
              var studentLink = document.getElementById('resEditStudentLink');
              studentLink.textContent = 'Open ' + (item.dataset.studentName || 'student');
              studentLink.href = '/admin/student.php?id=' + encodeURIComponent(item.dataset.studentId || '');
              document.getElementById('resEditErr').classList.add('hidden');
              openModal(editModal);
              return;
            }
            if (item && item.dataset.holdReservationId) {
              document.getElementById('holdEditId').value = item.dataset.holdReservationId;
              document.getElementById('holdEditHeading').textContent = item.dataset.holdTitle || 'Hold block';
              document.getElementById('holdEditContext').textContent = item.dataset.context || '';
              document.getElementById('holdEditTitle').value = item.dataset.holdTitle || '';
              document.getElementById('holdEditBlockType').value = item.dataset.blockType || 'hold';
              document.getElementById('holdEditDuration').value = item.dataset.duration || '30';
              document.getElementById('holdEditErr').classList.add('hidden');
              openModal(holdEditModal);
              return;
            }
            if (item) return; // some other kind of commitment

            var cell = e.target.closest('td.grid-cell');
            if (!cell) return;
            if (cell.dataset.locationId) {
              ['resAdd', 'holdAdd'].forEach(function (prefix) {
                document.getElementById(prefix + 'LocationId').value = cell.dataset.locationId;
                document.getElementById(prefix + 'TeacherId').value = cell.dataset.teacherId;
                document.getElementById(prefix + 'Day').value = cell.dataset.day;
                document.getElementById(prefix + 'Time').value = cell.dataset.time;
              });
              document.getElementById('resAddContext').textContent = cell.dataset.context || '';
              var addForm = document.getElementById('resAddForm');
              addForm.elements.charges_acknowledged.value = '';
              addForm.elements.include_installment_fee.value = '';
              document.getElementById('resAddStudent_id').value = '';
              document.getElementById('resAddStudent_input').value = '';
              document.getElementById('holdAddTitle').value = '';
              document.getElementById('holdAddBlockType').value = 'hold';
              document.getElementById('resAddErr').classList.add('hidden');
              document.getElementById('holdAddErr').classList.add('hidden');
              selectTab('resAddLessonPanel');
              openModal(addModal);
            }
          });

          document.getElementById('resAddForm').addEventListener('submit', function (e) {
            e.preventDefault();
            var errEl = document.getElementById('resAddErr');
            var studentId = document.getElementById('resAddStudent_id').value;
            if (!studentId) {
              showError(errEl, 'Please pick a student.');
              return;
            }
            // Creating directly as confirmed posts charges: review them first.
            if (this.elements.status.value === 'confirmed'
                && this.elements.charges_acknowledged.value !== '1') {
              openChargesReview(
                'action=confirm'
                  + '&semester_id=' + encodeURIComponent(this.elements.semester_id.value)
                  + '&student_user_id=' + encodeURIComponent(studentId)
                  + '&duration_minutes=' + encodeURIComponent(this.elements.duration_minutes.value),
                { url: '/admin/reservation_create.php', form: this, errEl: errEl, mode: 'confirm' }
              );
              return;
            }
            postForm('/admin/reservation_create.php', this, errEl);
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
            var errEl = document.getElementById('resEditErr');
            var original = this.dataset.originalStatus || '';
            var next = this.elements.status.value;
            if (this.elements.charges_acknowledged.value !== '1' && next !== original) {
              // Confirming posts charges; un-confirming posts reversal
              // credits. Either way, review the line items first.
              if (next === 'confirmed') {
                openChargesReview(
                  'action=confirm'
                    + '&reservation_id=' + encodeURIComponent(this.elements.reservation_id.value)
                    + '&duration_minutes=' + encodeURIComponent(this.elements.duration_minutes.value),
                  { url: '/admin/reservation_update.php', form: this, errEl: errEl, mode: 'confirm' }
                );
                return;
              }
              if (original === 'confirmed') {
                openChargesReview(
                  'action=unconfirm&reservation_id=' + encodeURIComponent(this.elements.reservation_id.value),
                  { url: '/admin/reservation_update.php', form: this, errEl: errEl, mode: 'unconfirm' }
                );
                return;
              }
            }
            postForm('/admin/reservation_update.php', this, errEl);
          });

          document.getElementById('resEditDelete').addEventListener('click', function () {
            var form = document.getElementById('resEditForm');
            var errEl = document.getElementById('resEditErr');
            // Deleting a confirmed reservation reverses its charges: review
            // the credits first. Pending reservations carry no charges.
            if ((form.dataset.originalStatus || '') === 'confirmed'
                && form.elements.charges_acknowledged.value !== '1') {
              openChargesReview(
                'action=unconfirm&reservation_id=' + encodeURIComponent(form.elements.reservation_id.value),
                { url: '/admin/reservation_delete.php', form: form, errEl: errEl, mode: 'delete' }
              );
              return;
            }
            if (!confirm('Delete this reservation? Future lessons will be removed; past lessons are kept.')) return;
            postForm('/admin/reservation_delete.php', form, errEl);
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
