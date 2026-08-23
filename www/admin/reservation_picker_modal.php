<?php
// The "pick a time from the schedule" modal used when converting a lead.
//
// One modal serves the whole page rather than one per student: the grid is
// wide, and duplicating it (and its element ids) per student would be both
// heavy and fragile. Which student a pick belongs to is held in a variable the
// "Choose a time…" button sets.
//
// Occupied cells are shown but not clickable — the point of picking from the
// grid instead of typing a time is that you can see what the teacher already
// has before you choose.
require_once __DIR__ . '/schedule_grid.php';
require_once __DIR__ . '/../lib/ScheduleGridData.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';

/** "SA 10:00 am · 45 min · Marisol Vega · Bronx Community College" */
function reservation_pick_summary(array $column, int $day, string $startTime, int $duration): string {
    $teacher = trim((string)($column['teacher_preferred_name'] ?: $column['teacher_first_name'])
        . ' ' . $column['teacher_last_name']);
    return schedule_day_prefix($day) . ' ' . date('g:i a', strtotime($startTime))
        . ' · ' . $duration . ' min'
        . ' · ' . $teacher
        . ' · ' . (string)$column['location_name'];
}

/** The column row for a (location, teacher) pair, or null. */
function reservation_pick_column(array $columns, int $locationId, int $teacherUserId): ?array {
    foreach ($columns as $column) {
        if ((int)$column['location_id'] === $locationId && (int)$column['teacher_user_id'] === $teacherUserId) {
            return $column;
        }
    }
    return null;
}

/**
 * $grid is ScheduleGridData::semesterWeeklyGrid() output. $durations are the
 * lesson lengths the picker offers.
 */
function render_reservation_picker_modal(int $semesterId, array $grid, ?array $durations = null): void {
    $durations = $durations ?? ReservationManagement::DURATION_OPTIONS;
    $cellIndex = $grid['cellIndex'];

    $cellFn = function (array $column, array $row) use ($cellIndex) {
        $columnKey = $column['location_id'] . ':' . $column['teacher_user_id'];
        $slotKey = $columnKey . ':' . $row['day'] . ':' . $row['minutes'];
        $occupants = ScheduleGridData::occupantsAt($cellIndex, $column, $row);

        $teacherLabel = ($column['teacher_preferred_name'] ?: $column['teacher_first_name'])
            . ' ' . $column['teacher_last_name'];

        if (!$occupants) {
            if (ScheduleGridData::coveredByEarlierOccupant($cellIndex, $columnKey, (int)$row['day'], (int)$row['minutes'])) {
                return ['skip' => true];
            }
            $context = schedule_day_prefix($row['day'])
                . ' ' . date('g:i a', mktime(intdiv($row['minutes'], 60), $row['minutes'] % 60))
                . ' · ' . $teacherLabel
                . ' · ' . $column['location_name'];
            return [
                'html' => '',
                'class' => 'pickable',
                'attrs' => [
                    // data-slot-free is what the fit check looks for: a longer
                    // lesson needs every following row to carry it too.
                    'data-slot-free' => '1',
                    'data-slot-key' => $slotKey,
                    'data-location-id' => $column['location_id'],
                    'data-teacher-id' => $column['teacher_user_id'],
                    'data-day' => $row['day'],
                    'data-time' => substr($row['time'], 0, 5),
                    'data-minutes' => $row['minutes'],
                    'data-context' => $context,
                ],
            ];
        }

        // Busy: show who has it, carrying no ids, so the delegated handler
        // treats it as unpickable.
        $rowspan = 1;
        $items = '';
        foreach ($occupants as $occupant) {
            $rowspan = max($rowspan, (int)ceil((int)$occupant['duration_minutes'] / ScheduleGridData::SLOT_MINUTES));
            $title = $occupant['kind'] === 'hold'
                ? (string)$occupant['title']
                : trim($occupant['student_first_name'] . ' ' . $occupant['student_last_name']);
            $items .= schedule_cell_item_html($title, $occupant['kind'] === 'hold' ? 'Held' : 'Booked', []);
        }
        return ['html' => $items, 'class' => 'res-busy', 'rowspan' => $rowspan, 'attrs' => ['data-slot-key' => $slotKey]];
    };
    ?>
    <div id="slotPickModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
      <div class="modal-content wide">
        <button class="close" type="button" aria-label="Close">&times;</button>
        <h3>Pick a time — <span id="slotPickWho"></span></h3>
        <p class="small">Click any empty cell. Grey cells are already taken.</p>
        <div class="actions" style="gap:14px;align-items:flex-end;">
          <label>Lesson length
            <select id="slotPickDuration">
              <?php foreach ($durations as $minutes): ?>
              <option value="<?=(int)$minutes?>"><?=(int)$minutes?> minutes</option>
              <?php endforeach; ?>
            </select>
          </label>
          <p class="small" id="slotPickChoice">No time chosen yet.</p>
        </div>
        <div class="error small hidden" id="slotPickErr"></div>
        <div style="overflow:auto;max-height:60vh;">
          <?=schedule_day_grids_html($grid['bands'], $cellFn, 'picker')?>
        </div>
        <div class="actions actions-split">
          <span class="actions-right">
            <button type="button" class="button" data-modal-close>Cancel</button>
          </span>
        </div>
      </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var modal = document.getElementById('slotPickModal');
      var durationSelect = document.getElementById('slotPickDuration');
      var errEl = document.getElementById('slotPickErr');
      var choiceEl = document.getElementById('slotPickChoice');
      var semesterId = <?=json_encode($semesterId)?>;
      var activeStudentId = null;

      function showError(message) {
        errEl.textContent = message;
        errEl.classList.remove('hidden');
      }
      function clearError() { errEl.classList.add('hidden'); }
      function openModal() {
        clearError();
        choiceEl.textContent = 'No time chosen yet.';
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
      }
      function closeModal() {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
      }

      // Does a lesson of this length fit here? Every half-hour row it would
      // cover has to exist AND be free. A row covered by an earlier booking's
      // rowspan emits no cell at all, so "missing" correctly means "taken".
      function fits(cell, duration) {
        var needed = Math.ceil(duration / 30);
        var parts = cell.dataset.slotKey.split(':');
        var prefix = parts[0] + ':' + parts[1] + ':' + parts[2] + ':';
        var start = parseInt(parts[3], 10);
        for (var i = 1; i < needed; i++) {
          var next = modal.querySelector('[data-slot-key="' + prefix + (start + i * 30) + '"]');
          if (!next) return 'That runs past the end of the scheduled day.';
          if (!next.dataset.slotFree) return 'That would run into the next booking. Try a shorter lesson or another time.';
        }
        return null;
      }

      function choose(cell) {
        var duration = parseInt(durationSelect.value, 10);
        var problem = fits(cell, duration);
        if (problem) { showError(problem); return; }
        clearError();

        // The server has the last word — and gives us the label to show, so
        // there is only one implementation of that sentence.
        var params = new URLSearchParams({
          semester_id: semesterId,
          location_id: cell.dataset.locationId,
          teacher_user_id: cell.dataset.teacherId,
          day_of_week: cell.dataset.day,
          start_time: cell.dataset.time,
          duration_minutes: duration
        });
        fetch('/admin/schedule_slot_check.php?' + params.toString(), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (json) {
            if (!json || !json.ok) { showError((json && json.error) || 'Something went wrong.'); return; }
            if (!json.available) { showError(json.conflict || 'That slot is taken.'); return; }
            var p = 'pick' + activeStudentId + '_';
            document.getElementById(p + 'loc').value = cell.dataset.locationId;
            document.getElementById(p + 'teacher').value = cell.dataset.teacherId;
            document.getElementById(p + 'day').value = cell.dataset.day;
            document.getElementById(p + 'time').value = cell.dataset.time;
            document.getElementById(p + 'dur').value = duration;
            document.getElementById(p + 'summary').textContent = json.summary;
            document.getElementById(p + 'clear').classList.remove('hidden');
            closeModal();
          })
          .catch(function () { showError('Network error.'); });
      }

      document.addEventListener('click', function (e) {
        if (!e.target.closest) return;

        var open = e.target.closest('[data-pick-slot]');
        if (open) {
          activeStudentId = open.dataset.leadStudentId;
          document.getElementById('slotPickWho').textContent = open.dataset.studentName || '';
          durationSelect.value = open.dataset.defaultDuration || '30';
          openModal();
          return;
        }

        var clear = e.target.closest('[data-pick-clear]');
        if (clear) {
          e.preventDefault();
          var p = 'pick' + clear.dataset.leadStudentId + '_';
          ['loc', 'teacher', 'day', 'time', 'dur'].forEach(function (f) {
            document.getElementById(p + f).value = '';
          });
          document.getElementById(p + 'summary').textContent =
            'not placed — convert now and place them from the Schedule later';
          clear.classList.add('hidden');
          return;
        }

        if (e.target.closest('[data-modal-close]') || e.target.closest('#slotPickModal .close')) {
          closeModal();
          return;
        }

        var cell = e.target.closest('#slotPickModal td.grid-cell');
        if (cell && cell.dataset.slotFree) {
          choose(cell);
        }
      });

      // Changing the length after picking re-checks the same cell would be
      // ambiguous, so we just clear the warning and let them click again.
      durationSelect.addEventListener('change', clearError);
    });
    </script>
    <?php
}
