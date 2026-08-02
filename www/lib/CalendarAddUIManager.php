<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_typeahead.php';
require_once __DIR__ . '/HoldBlockManagement.php';

/**
 * The weekly Calendar's add modal: clicking an empty cell books something on
 * that one date — a lesson or a held slot.
 *
 * Deliberately one-off. The Semester Schedule is where a standing weekly
 * booking is made; this is for the make-up lesson, the trial, the afternoon
 * somebody is away. Nothing created here has a weekly reservation behind it,
 * and a lesson booked here carries no charge.
 */
class CalendarAddUIManager {

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

    public static function renderModal(int $semesterId): void {
        ?>
        <div id="calAddModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3>Add to this day</h3>
            <p class="small" id="calAddContext"></p>

            <div class="modal-tabs" role="tablist">
              <button type="button" class="modal-tab active" role="tab" aria-selected="true"
                      data-tab-target="calAddLessonPanel">Lesson</button>
              <button type="button" class="modal-tab" role="tab" aria-selected="false"
                      data-tab-target="calAddHoldPanel">Hold Block</button>
            </div>

            <input type="hidden" id="calAddCsrf" value="<?=h(csrf_token())?>">
            <input type="hidden" id="calAddSemesterId" value="<?=$semesterId?>">
            <input type="hidden" id="calAddLocationId">
            <input type="hidden" id="calAddTeacherId">
            <input type="hidden" id="calAddDate">
            <input type="hidden" id="calAddTime">

            <div id="calAddLessonPanel" class="modal-tab-panel">
              <div class="error small hidden" id="calAddLessonErr"></div>
              <div class="stack">
                <label>Student
                  <?php render_typeahead_field('calAddStudent', 'student_user_id', '/admin/student_search.php'); ?>
                </label>
                <label>Duration
                  <select id="calAddLessonDuration">
                    <option value="30">30 minutes</option>
                    <option value="45">45 minutes</option>
                    <option value="60">60 minutes</option>
                  </select>
                </label>
                <p class="small">A one-off lesson on this date only. It is not added to the weekly
                schedule and nothing is charged for it.</p>
                <div class="actions actions-split">
                  <span class="actions-right">
                    <button type="button" class="button" data-modal-close>Cancel</button>
                    <button type="button" class="button primary" id="calAddLessonSave">Add Lesson</button>
                  </span>
                </div>
              </div>
            </div>

            <div id="calAddHoldPanel" class="modal-tab-panel hidden">
              <div class="error small hidden" id="calAddHoldErr"></div>
              <div class="stack">
                <label>What is this time for?
                  <input type="text" id="calAddHoldTitle" maxlength="200" placeholder="Away for the afternoon">
                </label>
                <label>Duration
                  <select id="calAddHoldDuration">
                    <?php foreach (HoldBlockManagement::DURATION_OPTIONS as $minutes): ?>
                      <option value="<?=$minutes?>"><?=self::durationLabel($minutes)?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <p class="small">Held on this date only — the weekly schedule is untouched.</p>
                <div class="actions actions-split">
                  <span class="actions-right">
                    <button type="button" class="button" data-modal-close>Cancel</button>
                    <button type="button" class="button primary" id="calAddHoldSave">Hold This Time</button>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
          var modal = document.getElementById('calAddModal');

          function showError(id, message) {
            var el = document.getElementById(id);
            el.textContent = message;
            el.classList.remove('hidden');
          }
          function clearErrors() {
            ['calAddLessonErr', 'calAddHoldErr'].forEach(function (id) {
              document.getElementById(id).classList.add('hidden');
            });
          }
          function val(id) { return document.getElementById(id).value; }

          var tabs = modal.querySelectorAll('.modal-tab');
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

          // An empty cell on the calendar. Edit mode owns the grid while it is
          // on, so a click there is a drag, not a request to add.
          document.addEventListener('click', function (e) {
            if (!e.target.closest) return;
            if (e.target.closest('.modal')) return;
            if (document.body.classList.contains('schedule-edit-mode')) return;
            if (e.target.closest('.cell-item')) return;

            var cell = e.target.closest('td.grid-cell');
            if (!cell || !cell.dataset.slotFree) return;

            document.getElementById('calAddLocationId').value = cell.dataset.locationId || '';
            document.getElementById('calAddTeacherId').value = cell.dataset.teacherId || '';
            document.getElementById('calAddDate').value = cell.dataset.date || '';
            document.getElementById('calAddTime').value = cell.dataset.time || '';
            document.getElementById('calAddContext').textContent = cell.dataset.context || '';
            document.getElementById('calAddStudent_id').value = '';
            document.getElementById('calAddStudent_input').value = '';
            document.getElementById('calAddHoldTitle').value = '';
            clearErrors();
            selectTab('calAddLessonPanel');
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
          });

          function post(url, fields, errId) {
            var body = new FormData();
            body.append('csrf', val('calAddCsrf'));
            body.append('semester_id', val('calAddSemesterId'));
            body.append('location_id', val('calAddLocationId'));
            body.append('teacher_user_id', val('calAddTeacherId'));
            body.append('date', val('calAddDate'));
            body.append('start_time', val('calAddTime'));
            Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });

            fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (json) {
                if (json && json.ok) { window.location.reload(); }
                else { showError(errId, (json && json.error) || 'That could not be added.'); }
              })
              .catch(function () { showError(errId, 'Network error — nothing was added.'); });
          }

          document.getElementById('calAddLessonSave').addEventListener('click', function () {
            clearErrors();
            var studentId = document.getElementById('calAddStudent_id').value;
            if (!studentId) { showError('calAddLessonErr', 'Please pick a student.'); return; }
            post('/admin/lesson_adhoc_create.php', {
              student_user_id: studentId,
              duration_minutes: val('calAddLessonDuration'),
            }, 'calAddLessonErr');
          });

          document.getElementById('calAddHoldSave').addEventListener('click', function () {
            clearErrors();
            var title = val('calAddHoldTitle').trim();
            if (!title) { showError('calAddHoldErr', 'Please say what this time is for.'); return; }
            post('/admin/hold_block_adhoc_create.php', {
              title: title,
              duration_minutes: val('calAddHoldDuration'),
            }, 'calAddHoldErr');
          });
        });
        </script>
        <?php
    }
}
