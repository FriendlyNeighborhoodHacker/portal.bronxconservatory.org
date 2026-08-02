<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/SemesterManagement.php';
require_once __DIR__ . '/StudentTeacherManagement.php';
require_once __DIR__ . '/LocationManagement.php';
require_once __DIR__ . '/ReservationManagement.php';

/**
 * The admin lesson modal (weekly calendar): mark missed/attended, hand the
 * week to a substitute, cancel the lesson, and write a lesson note
 * (auto-saves like the teacher dashboard).
 *
 * Moving a lesson is not here — that is what Edit mode on the grid is for, and
 * dragging says where it lands far better than a list of times could.
 *
 * The substitute is a dropdown of everyone on staff rather than a free
 * typeahead, with the teachers already working this semester listed first and
 * labelled with their locations. Cover is often somebody without a regular
 * slot, so the list cannot be limited to this semester's teachers — but who is
 * already in the building is still the first thing you want to see.
 */
class LessonUIManager {

    public static function renderModal(?int $semesterId = null): void {
        // Every teacher in the system, not only the ones with a column this
        // semester: cover often comes from someone who is not teaching a
        // regular slot. They are split into two groups so the people already
        // in the building are the obvious first choice, and each teacher
        // appears exactly once — only the teacher is being chosen here, so
        // listing somebody per-building would offer options that do the same
        // thing.
        $locationsByTeacher = [];
        foreach ($semesterId !== null ? SemesterManagement::locationTeachers($semesterId) : [] as $column) {
            $locationsByTeacher[(int)$column['teacher_user_id']][(string)$column['location_name']] = true;
        }

        $scheduled = [];
        $others = [];
        foreach (StudentTeacherManagement::listTeachers() as $teacher) {
            $teacherId = (int)$teacher['id'];
            $name = trim($teacher['first_name'] . ' ' . $teacher['last_name']);
            if (isset($locationsByTeacher[$teacherId])) {
                $scheduled[$teacherId] = $name . ' — ' . implode(', ', array_keys($locationsByTeacher[$teacherId]));
            } else {
                $others[$teacherId] = $name;
            }
        }
        asort($scheduled);
        asort($others);

        // Where the lesson is held. Separate from the substitute on purpose:
        // a cover teacher based at the other building may still come here, so
        // choosing them must not silently move the family across the borough.
        $locations = LocationManagement::all(true);
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
              <label>Length
                <select id="lessonDuration">
                  <?php foreach (ReservationManagement::DURATION_OPTIONS as $minutes): ?>
                    <option value="<?=(int)$minutes?>"><?=(int)$minutes?> minutes</option>
                  <?php endforeach; ?>
                </select>
              </label>
              <span class="small">This week only — the standing booking keeps its own length.</span>

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
                  <?php if ($scheduled): ?>
                    <optgroup label="Teaching this semester">
                      <?php foreach ($scheduled as $teacherId => $label): ?>
                        <option value="<?=(int)$teacherId?>"><?=h($label)?></option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endif; ?>
                  <?php if ($others): ?>
                    <optgroup label="Other teachers">
                      <?php foreach ($others as $teacherId => $label): ?>
                        <option value="<?=(int)$teacherId?>"><?=h($label)?></option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endif; ?>
                </select>
              </label>
              <span class="small">Anyone on staff can cover a week. Whoever you pick has to be free
              at that hour.</span>
              <?php if (!$scheduled && !$others): ?>
                <span class="small">There are no teachers on file yet.</span>
              <?php endif; ?>

              <label>Location
                <select id="lessonLocation">
                  <option value="">The usual location for this lesson</option>
                  <?php foreach ($locations as $location): ?>
                    <option value="<?=(int)$location['id']?>"><?=h($location['name'])?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <span class="small">Changing this moves only this week, not the standing booking.</span>

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
          var locationSelect = document.getElementById('lessonLocation');
          var durationSelect = document.getElementById('lessonDuration');
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

          // An older or imported lesson may run a length the dropdown does not
          // offer. Show it as itself rather than silently rewriting it on save.
          function selectCurrentDuration(minutes) {
            if (!minutes) { return; }
            if (!durationSelect.querySelector('option[value="' + minutes + '"]')) {
              var opt = document.createElement('option');
              opt.value = minutes;
              opt.textContent = minutes + ' minutes (current)';
              durationSelect.appendChild(opt);
            }
            durationSelect.value = minutes;
          }

          // Same guard for the room: a location that has since been switched
          // off must still show as itself, or saving would quietly move the
          // lesson back to the reservation's usual one.
          function selectCurrentLocation(id, name) {
            if (!id) { locationSelect.value = ''; return; }
            if (!locationSelect.querySelector('option[value="' + id + '"]')) {
              var opt = document.createElement('option');
              opt.value = id;
              opt.textContent = (name || 'Current location') + ' (no longer in use)';
              locationSelect.appendChild(opt);
            }
            locationSelect.value = id;
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
            selectCurrentDuration(cell.dataset.duration || '');
            selectCurrentSubstitute(cell.dataset.substituteId || '', cell.dataset.substituteName || '');
            selectCurrentLocation(cell.dataset.locationId || '', cell.dataset.locationName || '');
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

          // Save applies length, then attendance, then the substitute, then
          // the location. Each is sent every time, so picking the empty option
          // is how you take one off. Length and substitute go first because
          // they are the two that can be refused: better to stop there than to
          // leave the lesson somewhere chosen for a change that did not stick.
          document.getElementById('lessonSave').addEventListener('click', function () {
            errEl.classList.add('hidden');
            postJson('/admin/lesson_duration.php', { duration_minutes: durationSelect.value })
              .then(function (json) {
                if (json && !json.ok) throw new Error(json.error || 'Something went wrong.');
                return postJson('/admin/lesson_missed.php', { attended: document.getElementById('lessonAttendance').value });
              })
              .then(function (json) {
                if (json && !json.ok) throw new Error(json.error || 'Something went wrong.');
                return postJson('/admin/lesson_substitute.php', { substitute_teacher_user_id: subSelect.value });
              })
              .then(function (json) {
                if (json && !json.ok) throw new Error(json.error || 'Something went wrong.');
                return postJson('/admin/lesson_location.php', { location_id: locationSelect.value });
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
