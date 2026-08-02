<?php
// Drag-and-drop editing for the two schedule grids.
//
// Off by default: an admin reading the schedule should not be able to move
// somebody's lesson by brushing the trackpad. Pressing Edit turns the boxes
// into draggable things and the empty cells into targets; pressing it again
// puts the grid back to read-only.
//
// The two grids move different things — the Semester Schedule moves a standing
// weekly reservation, the weekly Calendar moves one lesson — so what a drop
// posts is configured per page. The rules themselves are entirely server-side;
// the only check here is the cheap one (does the box even fit in the gap?), so
// the answer is instant for the common mistake.
require_once __DIR__ . '/../partials.php';

/** The Edit toggle, for the page head. */
function schedule_edit_toggle_html(): string {
    return '<button type="button" class="button" id="scheduleEditToggle" aria-pressed="false">Edit</button>';
}

/**
 * $config:
 *   'endpoint'  string  where a drop posts to
 *   'item_attr' string  the dataset key that marks a draggable box
 *                       ('reservationId' | 'lessonId')
 *   'id_field'  string  the POST field that carries it
 *   'fields'    array   POST field => dataset key on the target cell
 *   'noun'      string  what is being moved, for the messages
 */
function render_schedule_edit_mode(array $config): void {
    ?>
    <div class="edit-mode-bar hidden" id="scheduleEditBar">
      <span><strong>Edit mode.</strong> Drag a <?=h($config['noun'])?> to an empty slot to move it.
        Press <strong>Done</strong> when you have finished.</span>
      <span class="small hidden" id="scheduleEditSaved">Moved.</span>
      <span class="error small hidden" id="scheduleEditErr"></span>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var config = <?=json_encode([
          'endpoint' => $config['endpoint'],
          'itemAttr' => $config['item_attr'],
          'idField' => $config['id_field'],
          'fields' => $config['fields'],
      ])?>;
      var toggle = document.getElementById('scheduleEditToggle');
      var bar = document.getElementById('scheduleEditBar');
      var errEl = document.getElementById('scheduleEditErr');
      var csrf = <?=json_encode(csrf_token())?>;
      var dragged = null;
      // A successful move reloads the page so the grid recomputes server-side.
      // This flag carries edit mode across that reload, so you can move several
      // things and stop when you say Done — rather than being dropped out after
      // every single drag. It is one-shot on purpose: set just before the
      // reload and cleared on the way back in, so edit mode never lingers when
      // you leave the page and come back later.
      var resumeKey = 'scheduleEditResume:' + location.pathname;

      if (!toggle) return;

      function showError(message) {
        errEl.textContent = message;
        errEl.classList.remove('hidden');
      }
      function clearError() { errEl.classList.add('hidden'); }

      function setEditMode(on) {
        document.body.classList.toggle('schedule-edit-mode', on);
        bar.classList.toggle('hidden', !on);
        toggle.textContent = on ? 'Done' : 'Edit';
        toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        toggle.classList.toggle('primary', on);
        clearError();
        document.querySelectorAll('.cell-item[data-' + dashed(config.itemAttr) + ']').forEach(function (item) {
          if (on) { item.setAttribute('draggable', 'true'); }
          else { item.removeAttribute('draggable'); }
        });
      }

      function reloadStayingInEditMode() {
        try { sessionStorage.setItem(resumeKey, '1'); } catch (e) { /* private mode: just reload */ }
        window.location.reload();
      }

      // Coming back from a move: pick edit mode up where it was left.
      try {
        if (sessionStorage.getItem(resumeKey)) {
          sessionStorage.removeItem(resumeKey);
          setEditMode(true);
          document.getElementById('scheduleEditSaved').classList.remove('hidden');
        }
      } catch (e) { /* no sessionStorage: edit mode simply starts off */ }

      // 'reservationId' -> 'reservation-id', so the same config names both the
      // dataset key and the attribute selector.
      function dashed(name) {
        return name.replace(/[A-Z]/g, function (c) { return '-' + c.toLowerCase(); });
      }

      toggle.addEventListener('click', function () {
        setEditMode(!document.body.classList.contains('schedule-edit-mode'));
      });

      // Would the box fit here? Every half-hour row it would cover has to
      // exist and be free. A row covered by an earlier booking's rowspan emits
      // no cell at all, so "missing" correctly means "taken".
      function fits(cell, duration) {
        var needed = Math.ceil(duration / 30);
        if (needed <= 1) return null;
        var parts = (cell.dataset.slotKey || '').split(':');
        if (parts.length < 4) return null;
        var prefix = parts[0] + ':' + parts[1] + ':' + parts[2] + ':';
        var start = parseInt(parts[3], 10);
        for (var i = 1; i < needed; i++) {
          var next = document.querySelector('td.grid-cell[data-slot-key="' + prefix + (start + i * 30) + '"]');
          if (!next) return 'That runs past the end of the scheduled day.';
          if (!next.dataset.slotFree) return 'That would run into the next booking.';
        }
        return null;
      }

      document.addEventListener('dragstart', function (e) {
        if (!document.body.classList.contains('schedule-edit-mode')) return;
        var item = e.target.closest ? e.target.closest('.cell-item') : null;
        if (!item || !item.dataset[config.itemAttr]) return;
        dragged = item;
        item.classList.add('dragging');
        clearError();
        document.getElementById('scheduleEditSaved').classList.add('hidden');
        e.dataTransfer.effectAllowed = 'move';
        // Firefox will not start a drag without data on the transfer.
        e.dataTransfer.setData('text/plain', item.dataset[config.itemAttr]);
      });

      document.addEventListener('dragend', function () {
        if (dragged) dragged.classList.remove('dragging');
        dragged = null;
        document.querySelectorAll('td.grid-cell.drop-target').forEach(function (c) {
          c.classList.remove('drop-target');
        });
      });

      document.addEventListener('dragover', function (e) {
        if (!dragged) return;
        var cell = e.target.closest ? e.target.closest('td.grid-cell') : null;
        if (!cell || !cell.dataset.slotFree) return;
        e.preventDefault(); // this is what marks the cell as a valid target
        e.dataTransfer.dropEffect = 'move';
        cell.classList.add('drop-target');
      });

      document.addEventListener('dragleave', function (e) {
        var cell = e.target.closest ? e.target.closest('td.grid-cell') : null;
        if (cell) cell.classList.remove('drop-target');
      });

      document.addEventListener('drop', function (e) {
        if (!dragged) return;
        var cell = e.target.closest ? e.target.closest('td.grid-cell') : null;
        if (!cell || !cell.dataset.slotFree) return;
        e.preventDefault();
        cell.classList.remove('drop-target');

        var item = dragged;
        var problem = fits(cell, parseInt(item.dataset.duration || '30', 10));
        if (problem) { showError(problem); return; }

        var body = new FormData();
        body.append('csrf', csrf);
        body.append(config.idField, item.dataset[config.itemAttr]);
        Object.keys(config.fields).forEach(function (field) {
          body.append(field, cell.dataset[config.fields[field]] || '');
        });

        // Held until the server answers, so a refused move never looks like it
        // worked.
        item.classList.add('saving');
        fetch(config.endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (json) {
            if (json && json.ok) { reloadStayingInEditMode(); }
            else {
              item.classList.remove('saving');
              showError((json && json.error) || 'That move could not be made.');
            }
          })
          .catch(function () {
            item.classList.remove('saving');
            showError('Network error — nothing was changed.');
          });
      });
    });
    </script>
    <?php
}
