<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/SemesterManagement.php';

/**
 * The admin lesson modal (weekly calendar): mark missed/attended, hand the
 * week to a substitute, cancel the lesson, and write a lesson note
 * (auto-saves like the teacher dashboard).
 *
 * Moving a lesson is not here — that is what Edit mode on the grid is for, and
 * dragging says where it lands far better than a list of times could.
 *
 * The substitute is a dropdown of the teachers actually working this semester,
 * grouped by where they work, rather than a free typeahead: it is the only way
 * the person choosing can see who is even at the right building.
 */
class LessonUIManager {

    public static function renderModal(?int $semesterId = null): void {
        // The teachers actually working this semester, each listed once with
        // the locations they work. One entry per teacher on purpose: only the
        // teacher is being chosen here, so listing somebody twice — once per
        // building — would offer two options that do the very same thing.
        $teachers = [];
        foreach ($semesterId !== null ? SemesterManagement::locationTeachers($semesterId) : [] as $column) {
            $teacherId = (int)$column['teacher_user_id'];
            $teachers[$teacherId]['name'] = trim(($column['teacher_preferred_name'] ?: $column['teacher_first_name'])
                . ' ' . $column['teacher_last_name']);
            $teachers[$teacherId]['locations'][(string)$column['location_name']] = true;
        }
        uasort($teachers, fn(array $a, array $b) => strcmp($a['name'], $b['name']));
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
              <label>Attendance
                <select id="lessonAttendance">
                  <option value="">Not marked</option>
                  <option value="1">Attended</option>
                  <option value="0">Missed</option>
                </select>
              </label>

              <label>Substitute teacher
                <select id="lessonSub">
                  <option value="">No substitute — the usual teacher</option>
                  <?php foreach ($teachers as $teacherId => $teacher): ?>
                    <option value="<?=(int)$teacherId?>"><?=h($teacher['name']
                        . ' — ' . implode(', ', array_keys($teacher['locations'])))?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <?php if (!$teachers): ?>
                <span class="small">No teachers are assigned to locations this semester, so there is
                nobody to offer as cover.</span>
              <?php endif; ?>

              <label>Lesson note
                <textarea id="lessonNote" rows="3" placeholder="Notes save automatically as you type."></textarea>
              </label>
              <span class="note-save-state" id="lessonNoteState"></span>

              <p class="small">To move this lesson to another time or teacher, press
              <strong>Edit</strong> above and drag it.</p>

              <div class="actions actions-split">
                <button type="button" class="button danger" id="lessonCancelLesson">Cancel lesson</button>
                <span class="actions-right">
                  <button type="button" class="button" data-modal-close>Close</button>
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
          var subSelect = document.getElementById('lessonSub');
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

          // Whoever is covering it now has to be selectable even if they no
          // longer hold a column this semester — otherwise the dropdown would
          // quietly misreport the lesson as having no substitute.
          function selectCurrentSubstitute(id, name) {
            if (!id) { subSelect.value = ''; return; }
            if (!subSelect.querySelector('option[value="' + id + '"]')) {
              var opt = document.createElement('option');
              opt.value = id;
              opt.textContent = (name || 'Current substitute') + ' (not scheduled this semester)';
              subSelect.appendChild(opt);
            }
            subSelect.value = id;
          }

          // Open on any weekly-grid cell that carries a lesson id — except in
          // edit mode, where a click is the start of a drag.
          document.addEventListener('click', function (e) {
            if (document.body.classList.contains('schedule-edit-mode')) return;
            var cell = e.target.closest ? e.target.closest('[data-lesson-id]') : null;
            if (!cell || cell.closest('.modal')) return;
            errEl.classList.add('hidden');
            document.getElementById('lessonModalId').value = cell.dataset.lessonId;
            document.getElementById('lessonModalTitle').textContent = cell.dataset.studentName || 'Lesson';
            document.getElementById('lessonModalContext').textContent = cell.dataset.context || '';
            document.getElementById('lessonAttendance').value = cell.dataset.attended || '';
            selectCurrentSubstitute(cell.dataset.substituteId || '', cell.dataset.substituteName || '');
            document.getElementById('lessonNote').value = cell.dataset.note || '';
            document.getElementById('lessonNoteState').textContent = '';

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

          // Cancelling is its own action, not part of Save: it is the one
          // thing here that cannot be undone from this screen.
          document.getElementById('lessonCancelLesson').addEventListener('click', function () {
            if (!confirm('Cancel this lesson? It disappears from this calendar and frees the slot. '
                       + 'The family and the teacher still see it, marked cancelled.')) return;
            errEl.classList.add('hidden');
            postJson('/admin/lesson_cancel.php', {})
              .then(function (json) {
                if (json && json.ok) { window.location.reload(); }
                else { showError((json && json.error) || 'Could not cancel this lesson.'); }
              })
              .catch(function () { showError('Network error.'); });
          });

          // Save applies attendance then the substitute. The substitute is sent
          // every time, so choosing "No substitute" is how you take one off.
          document.getElementById('lessonSave').addEventListener('click', function () {
            errEl.classList.add('hidden');
            postJson('/admin/lesson_missed.php', { attended: document.getElementById('lessonAttendance').value })
              .then(function (json) {
                if (json && !json.ok) throw new Error(json.error || 'Something went wrong.');
                return postJson('/admin/lesson_substitute.php', { substitute_teacher_user_id: subSelect.value });
              })
              .then(function (json) {
                if (json && !json.ok) throw new Error(json.error || 'Something went wrong.');
                window.location.reload();
              })
              .catch(function (err) { showError(err.message); });
          });
        });
        </script>
        <?php
    }
}
