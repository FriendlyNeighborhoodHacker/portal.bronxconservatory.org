<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_typeahead.php';

/**
 * The admin lesson modal (weekly calendar): reschedule within the day (to a
 * slot not occupied by another of the teacher's lessons — options fetched
 * from lesson_slots.php), mark missed/attended, assign a substitute teacher,
 * and write a lesson note (auto-saves like the teacher dashboard).
 */
class LessonUIManager {

    public static function renderModal(): void {
        ?>
        <div id="lessonModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3 id="lessonModalTitle">Lesson</h3>
            <p class="small" id="lessonModalContext"></p>
            <div class="error small hidden" id="lessonErr"></div>

            <input type="hidden" id="lessonModalId">
            <input type="hidden" id="lessonCsrf" value="<?=h(csrf_token())?>">

            <div class="stack">
              <label>Time (same day)
                <select id="lessonSlotSelect"><option value="">Loading times…</option></select>
              </label>

              <label>Attendance
                <select id="lessonAttendance">
                  <option value="">Not marked</option>
                  <option value="1">Attended</option>
                  <option value="0">Missed</option>
                </select>
              </label>

              <label>Substitute teacher
                <?php render_typeahead_field('lessonSub', 'substitute_teacher_user_id', '/admin/teacher_search.php', 'Type to pick a substitute...'); ?>
                <span class="small" id="lessonSubCurrent"></span>
              </label>
              <label class="inline">
                <input type="checkbox" id="lessonSubClear"> Remove the current substitute
              </label>

              <label>Lesson note
                <textarea id="lessonNote" rows="3" placeholder="Notes save automatically as you type."></textarea>
              </label>
              <span class="note-save-state" id="lessonNoteState"></span>

              <div class="actions actions-split">
                <span class="actions-right">
                  <button type="button" class="button" data-modal-close>Cancel</button>
                  <button type="button" class="button primary" id="lessonSave">Save</button>
                </span>
              </div>
            </div>
          </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
          var modal = document.getElementById('lessonModal');
          var errEl = document.getElementById('lessonErr');
          var noteTimer = null;

          function showError(message) {
            errEl.textContent = message;
            errEl.classList.remove('hidden');
          }
          function csrf() { return document.getElementById('lessonCsrf').value; }
          function lessonId() { return document.getElementById('lessonModalId').value; }

          function postJson(url, fields) {
            var body = new FormData();
            body.append('csrf', csrf());
            body.append('lesson_id', lessonId());
            Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
            return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
              .then(function (r) { return r.json(); });
          }

          // Open on any weekly-grid cell that carries a lesson id.
          document.addEventListener('click', function (e) {
            var cell = e.target.closest ? e.target.closest('[data-lesson-id]') : null;
            if (!cell || cell.closest('.modal')) return;
            errEl.classList.add('hidden');
            document.getElementById('lessonModalId').value = cell.dataset.lessonId;
            document.getElementById('lessonModalTitle').textContent = cell.dataset.studentName || 'Lesson';
            document.getElementById('lessonModalContext').textContent = cell.dataset.context || '';
            document.getElementById('lessonAttendance').value = cell.dataset.attended || '';
            document.getElementById('lessonSub_id').value = '';
            document.getElementById('lessonSub_input').value = '';
            document.getElementById('lessonSubClear').checked = false;
            document.getElementById('lessonSubCurrent').textContent =
              cell.dataset.substituteName ? 'Current substitute: ' + cell.dataset.substituteName : '';
            document.getElementById('lessonNote').value = cell.dataset.note || '';
            document.getElementById('lessonNoteState').textContent = '';

            var select = document.getElementById('lessonSlotSelect');
            select.innerHTML = '<option value="">Loading times…</option>';
            fetch('/admin/lesson_slots.php?lesson_id=' + encodeURIComponent(cell.dataset.lessonId), { credentials: 'same-origin' })
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

          // Notes auto-save while typing.
          document.getElementById('lessonNote').addEventListener('input', function () {
            clearTimeout(noteTimer);
            var value = this.value;
            document.getElementById('lessonNoteState').textContent = 'Saving…';
            noteTimer = setTimeout(function () {
              postJson('/admin/lesson_note_save.php', { body: value })
                .then(function (json) {
                  document.getElementById('lessonNoteState').textContent =
                    json && json.ok ? 'Saved' : 'Could not save';
                })
                .catch(function () {
                  document.getElementById('lessonNoteState').textContent = 'Could not save';
                });
            }, 600);
          });

          // Save applies time / attendance / substitute changes in sequence.
          document.getElementById('lessonSave').addEventListener('click', function () {
            errEl.classList.add('hidden');
            var steps = [];
            var slot = document.getElementById('lessonSlotSelect');
            if (slot.value && !slot.options[slot.selectedIndex].textContent.includes('(current)')) {
              steps.push(function () { return postJson('/admin/lesson_reschedule.php', { start_time: slot.value }); });
            }
            steps.push(function () {
              return postJson('/admin/lesson_missed.php', { attended: document.getElementById('lessonAttendance').value });
            });
            var subId = document.getElementById('lessonSub_id').value;
            if (document.getElementById('lessonSubClear').checked) {
              steps.push(function () { return postJson('/admin/lesson_substitute.php', { substitute_teacher_user_id: '' }); });
            } else if (subId) {
              steps.push(function () { return postJson('/admin/lesson_substitute.php', { substitute_teacher_user_id: subId }); });
            }

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
        });
        </script>
        <?php
    }
}
