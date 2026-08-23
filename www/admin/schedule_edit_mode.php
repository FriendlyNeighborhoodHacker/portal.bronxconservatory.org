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
 *   'hold'      array?  a second draggable kind (same endpoint/item_attr/
 *                       id_field/fields keys) — the Semester Schedule uses it
 *                       to move hold blocks alongside student reservations.
 */
function render_schedule_edit_mode(array $config): void {
    // The script treats every draggable kind alike; a drop posts to the
    // endpoint of whichever kind the dragged box belongs to.
    $kinds = [[
        'itemAttr' => $config['item_attr'],
        'idField' => $config['id_field'],
        'endpoint' => $config['endpoint'],
        'fields' => $config['fields'],
    ]];
    if (isset($config['hold'])) {
        $kinds[] = [
            'itemAttr' => $config['hold']['item_attr'],
            'idField' => $config['hold']['id_field'],
            'endpoint' => $config['hold']['endpoint'],
            'fields' => $config['hold']['fields'],
        ];
    }
    ?>
    <div class="edit-mode-bar hidden" id="scheduleEditBar">
      <span><strong>Edit mode.</strong> Drag a <?=h($config['noun'])?> to an empty slot to move it.
        Press <strong>&#8984;Z</strong> (Ctrl+Z) to undo a move,
        <strong>Done</strong> when you have finished.</span>
      <span class="small hidden" id="scheduleEditSaved">Moved.</span>
      <span class="error small hidden" id="scheduleEditErr"></span>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var kinds = <?=json_encode($kinds)?>;
      var toggle = document.getElementById('scheduleEditToggle');
      var bar = document.getElementById('scheduleEditBar');
      var errEl = document.getElementById('scheduleEditErr');
      var csrf = <?=json_encode(csrf_token())?>;
      var dragged = null;
      var draggedKind = null;
      // A successful move reloads the page so the grid recomputes server-side.
      // This flag carries edit mode across that reload, so you can move several
      // things and stop when you say Done — rather than being dropped out after
      // every single drag. It is one-shot on purpose: set just before the
      // reload and cleared on the way back in, so edit mode never lingers when
      // you leave the page and come back later.
      var resumeKey = 'scheduleEditResume:' + location.pathname;

      if (!toggle) return;

      var savedEl = document.getElementById('scheduleEditSaved');

      function showError(message) {
        errEl.textContent = message;
        errEl.classList.remove('hidden');
      }
      function clearError() { errEl.classList.add('hidden'); }
      function showStatus(message) {
        savedEl.textContent = message;
        savedEl.classList.remove('hidden');
      }

      // Each successful move records the POST that would put the box back
      // where it came from — an undo is just another move, checked by the
      // server like any other. The stack lives in sessionStorage because a
      // successful move reloads the page; it is emptied on pressing Edit, so
      // Ctrl+Z never replays moves from an earlier editing session.
      var undoKey = 'scheduleEditUndo:' + location.pathname;
      function readUndoStack() {
        try { return JSON.parse(sessionStorage.getItem(undoKey) || '[]') || []; }
        catch (e) { return []; }
      }
      function writeUndoStack(stack) {
        try { sessionStorage.setItem(undoKey, JSON.stringify(stack.slice(-20))); }
        catch (e) { /* private mode: moves simply are not undoable */ }
      }

      // Every draggable box in this cell, across all kinds. When there is
      // exactly one, the whole cell is its drag handle — grabbing the box's
      // padding or the cell's whitespace must work, not just the text. A
      // double-booked cell keeps per-box dragging so you can pick which one.
      function draggablesIn(cell) {
        var selector = kinds.map(function (kind) {
          return '.cell-item[data-' + dashed(kind.itemAttr) + ']';
        }).join(', ');
        return cell.querySelectorAll(selector);
      }

      function setEditMode(on) {
        document.body.classList.toggle('schedule-edit-mode', on);
        bar.classList.toggle('hidden', !on);
        toggle.textContent = on ? 'Done' : 'Edit';
        toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        toggle.classList.toggle('primary', on);
        clearError();
        kinds.forEach(function (kind) {
          document.querySelectorAll('.cell-item[data-' + dashed(kind.itemAttr) + ']').forEach(function (item) {
            if (on) { item.setAttribute('draggable', 'true'); }
            else { item.removeAttribute('draggable'); }
            var cell = item.closest('td.grid-cell');
            if (cell && draggablesIn(cell).length === 1) {
              if (on) { cell.setAttribute('draggable', 'true'); }
              else { cell.removeAttribute('draggable'); }
            }
          });
        });
      }

      function reloadStayingInEditMode(message) {
        try { sessionStorage.setItem(resumeKey, message); } catch (e) { /* private mode: just reload */ }
        window.location.reload();
      }

      // Coming back from a move: pick edit mode up where it was left.
      try {
        var resumeMessage = sessionStorage.getItem(resumeKey);
        if (resumeMessage) {
          sessionStorage.removeItem(resumeKey);
          setEditMode(true);
          showStatus(resumeMessage);
        }
      } catch (e) { /* no sessionStorage: edit mode simply starts off */ }

      // 'reservationId' -> 'reservation-id', so the same config names both the
      // dataset key and the attribute selector.
      function dashed(name) {
        return name.replace(/[A-Z]/g, function (c) { return '-' + c.toLowerCase(); });
      }

      toggle.addEventListener('click', function () {
        var turningOn = !document.body.classList.contains('schedule-edit-mode');
        // A fresh editing session starts with nothing to undo.
        if (turningOn) writeUndoStack([]);
        setEditMode(turningOn);
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
        if (!item && e.target.closest) {
          // The drag began on the cell itself (its padding, or beside the
          // box) — resolve to the cell's sole draggable occupant.
          var startCell = e.target.closest('td.grid-cell');
          var boxes = startCell ? draggablesIn(startCell) : [];
          if (boxes.length === 1) item = boxes[0];
        }
        if (!item) return;
        var kind = null;
        for (var i = 0; i < kinds.length; i++) {
          if (item.dataset[kinds[i].itemAttr]) { kind = kinds[i]; break; }
        }
        if (!kind) return;
        dragged = item;
        draggedKind = kind;
        item.classList.add('dragging');
        clearError();
        savedEl.classList.add('hidden');
        e.dataTransfer.effectAllowed = 'move';
        // Firefox will not start a drag without data on the transfer.
        e.dataTransfer.setData('text/plain', item.dataset[kind.itemAttr]);
      });

      document.addEventListener('dragend', function () {
        if (dragged) dragged.classList.remove('dragging');
        dragged = null;
        draggedKind = null;
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
        var kind = draggedKind;
        var problem = fits(cell, parseInt(item.dataset.duration || '30', 10));
        if (problem) { showError(problem); return; }

        // The move that would put this box back where it sits right now,
        // recorded before anything changes. The box carries its own position
        // under the same dataset keys the target cells use, so one mapping
        // serves both directions.
        var reverse = { endpoint: kind.endpoint, fields: {} };
        reverse.fields[kind.idField] = item.dataset[kind.itemAttr];
        var reversible = Object.keys(kind.fields).every(function (field) {
          reverse.fields[field] = item.dataset[kind.fields[field]];
          return reverse.fields[field] !== undefined;
        });

        var body = new FormData();
        body.append('csrf', csrf);
        body.append(kind.idField, item.dataset[kind.itemAttr]);
        Object.keys(kind.fields).forEach(function (field) {
          body.append(field, cell.dataset[kind.fields[field]] || '');
        });

        // Held until the server answers, so a refused move never looks like it
        // worked.
        item.classList.add('saving');
        fetch(kind.endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (json) {
            if (json && json.ok) {
              var stack = readUndoStack();
              // A box whose origin could not be read leaves the stack empty
              // rather than letting Ctrl+Z skip past it to an older move.
              if (reversible) { stack.push(reverse); } else { stack = []; }
              writeUndoStack(stack);
              reloadStayingInEditMode('Moved.');
            }
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

      // Ctrl+Z / Cmd+Z: undo the most recent move by posting it back where it
      // came from. The server applies the same rules as any other move, so an
      // undo whose slot has since been taken is refused with its reason
      // rather than forced through.
      var undoInFlight = false;
      document.addEventListener('keydown', function (e) {
        if (!(e.metaKey || e.ctrlKey) || e.shiftKey || e.altKey) return;
        if (e.key !== 'z' && e.key !== 'Z') return;
        if (!document.body.classList.contains('schedule-edit-mode')) return;
        var t = e.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
        e.preventDefault(); // in edit mode the grid owns undo, not the browser
        if (undoInFlight) return;
        var stack = readUndoStack();
        if (!stack.length) { showStatus('Nothing to undo.'); return; }
        var last = stack.pop();
        writeUndoStack(stack);
        undoInFlight = true;
        clearError();
        savedEl.classList.add('hidden');
        var body = new FormData();
        body.append('csrf', csrf);
        Object.keys(last.fields).forEach(function (field) {
          body.append(field, last.fields[field]);
        });
        function restore(message) {
          // A failed undo keeps its record: losing it would silently orphan
          // the move it stands for.
          undoInFlight = false;
          var s = readUndoStack();
          s.push(last);
          writeUndoStack(s);
          showError(message);
        }
        fetch(last.endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (json) {
            if (json && json.ok) { reloadStayingInEditMode('Move undone.'); }
            else { restore((json && json.error) || 'That move could not be undone.'); }
          })
          .catch(function () {
            restore('Network error — nothing was changed.');
          });
      });
    });
    </script>
    <?php
}
