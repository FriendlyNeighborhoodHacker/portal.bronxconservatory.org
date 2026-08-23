<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/LessonManagement.php';
require_once __DIR__ . '/NotesManagement.php';
require_once __DIR__ . '/ResourceManagement.php';

/**
 * A lesson's notes and materials, wherever they are shown: inline on the
 * teacher's cards for the day, and in a modal from the family's schedule.
 *
 * One component because it is one thing. A note written by a teacher after
 * the lesson and a note written by a parent before it belong in the same
 * thread, and both sides should see the same list rendered the same way —
 * so the list, the "add a note" form and the materials list each have
 * exactly one implementation here, and the ajax endpoints return these same
 * fragments (docs/php-guidelines.md).
 */
class LessonDetailUIManager {

    // ── Fragments ─────────────────────────────────────────────────────────

    /** A lesson's notes, oldest first, each a chat bubble signed underneath. */
    public static function notesHtml(int $lessonId): string {
        $notes = NotesManagement::lessonNotesForLesson($lessonId);
        if (!$notes) {
            return '<p class="small lesson-notes-empty">No notes on this lesson yet.</p>';
        }
        $html = '';
        foreach ($notes as $note) {
            $html .= self::noteBubbleHtml($note);
        }
        return $html;
    }

    /** One note as a speech bubble: the message, then who said it and when. */
    public static function noteBubbleHtml(array $note): string {
        $author = trim((string)$note['author_first_name'] . ' ' . (string)$note['author_last_name']);
        return '<div class="lesson-note">'
            . '<div>' . nl2br(h((string)$note['body'])) . '</div>'
            . '<div class="lesson-note-by">— ' . h($author) . ', '
            . h(date('M j, Y g:i A', strtotime((string)$note['created_at']))) . '</div>'
            . '</div>';
    }

    /**
     * The notes block for a lesson: the thread, then a box to add to it.
     * $canWrite is the caller's answer to "may this person write here?" —
     * NotesManagement enforces it again on save.
     */
    public static function notesBlockHtml(int $lessonId, bool $canWrite, string $placeholder = 'Add a note about this lesson…'): string {
        $html = '<div class="lesson-notes" id="lesson-notes-' . $lessonId . '">' . self::notesHtml($lessonId) . '</div>';
        if (!$canWrite) {
            return $html;
        }
        return $html
            . '<form class="lesson-note-form" data-note-form data-lesson-id="' . $lessonId . '">'
            . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
            . '<input type="hidden" name="lesson_id" value="' . $lessonId . '">'
            . '<textarea name="body" rows="2" placeholder="' . h($placeholder) . '"></textarea>'
            . '<div class="actions">'
            . '<button type="submit" class="button primary">Save Note</button>'
            . '<span class="note-save-state" aria-live="polite"></span>'
            . '</div>'
            . '</form>';
    }

    /** A lesson's materials. The whole element, so a save can swap it out. */
    public static function resourcesListHtml(int $lessonId): string {
        $resources = ResourceManagement::resourcesForLesson($lessonId);
        $html = '<div class="lesson-resources" id="lesson-resources-' . $lessonId . '">';
        if (!$resources) {
            $html .= '<p class="small">No materials on this lesson yet.</p>';
        }
        foreach ($resources as $resource) {
            $html .= '<div class="lesson-row">';
            if ($resource['resource_type'] === 'link') {
                $html .= '<span><a href="' . h((string)$resource['url']) . '" target="_blank" rel="noopener">&#128279; '
                    . h((string)$resource['title']) . '</a></span>';
            } else {
                $html .= '<span><a href="/resource_download.php?id=' . (int)$resource['id'] . '">'
                    . h((string)$resource['title']) . '</a></span>';
                $html .= '<span class="small">' . h((string)($resource['original_filename'] ?? '')) . '</span>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    /** The materials list, plus the button that edits it where allowed. */
    public static function resourcesBlockHtml(int $lessonId, bool $canEdit): string {
        $html = self::resourcesListHtml($lessonId);
        if ($canEdit) {
            $html .= '<div class="actions" style="margin-top:6px;">'
                . '<button type="button" class="button" data-resource-edit="' . $lessonId . '">Edit resources</button>'
                . '</div>';
        }
        return $html;
    }

    /**
     * The same materials, as the editable rows inside the Edit resources
     * modal: each one with a Remove tickbox, ticked ones deleted on save.
     *
     * A Remove control appears only where pressing it would actually work —
     * ResourceManagement lets you remove your own materials (an admin, any) —
     * so a teacher sees who added the ones they cannot touch instead of a
     * tickbox that would fail on save.
     */
    public static function resourcesEditRowsHtml(int $lessonId, ?UserContext $ctx): string {
        $resources = ResourceManagement::resourcesForLesson($lessonId);
        if (!$resources) {
            return '<p class="small">No materials on this lesson yet — add one below.</p>';
        }
        $html = '<div class="resource-edit-list">';
        foreach ($resources as $resource) {
            $id = (int)$resource['id'];
            if ($resource['resource_type'] === 'link') {
                $label = '<a href="' . h((string)$resource['url']) . '" target="_blank" rel="noopener">&#128279; '
                    . h((string)$resource['title']) . '</a>';
            } else {
                $label = '<a href="/resource_download.php?id=' . $id . '">' . h((string)$resource['title']) . '</a>'
                    . ' <span class="small">' . h((string)($resource['original_filename'] ?? '')) . '</span>';
            }

            $html .= '<div class="resource-edit-row">'
                . '<span class="resource-edit-title">' . $label . '</span>';
            if (ResourceManagement::canUserDelete($ctx, $resource)) {
                $html .= '<label class="inline resource-remove">'
                    . '<input type="checkbox" name="remove[]" value="' . $id . '"> Remove'
                    . '</label>';
            } else {
                $adder = trim((string)($resource['uploader_first_name'] ?? '') . ' ' . (string)($resource['uploader_last_name'] ?? ''));
                $html .= '<span class="small">' . ($adder !== '' ? 'added by ' . h($adder) : 'added by someone else') . '</span>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    // ── The family's read/write modal ──────────────────────────────────────

    /**
     * The modal shell + the script that fills it. Openers are links carrying
     * data-lesson-detail="<lessonId>". Render once per page.
     */
    public static function renderModal(): void {
        ?>
        <div id="lessonDetailModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true"
             aria-labelledby="lessonDetailTitle">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3 id="lessonDetailTitle">Lesson</h3>
            <div id="lessonDetailBody"><p class="small">Loading…</p></div>
          </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
          var modal = document.getElementById('lessonDetailModal');
          var body = document.getElementById('lessonDetailBody');
          if (!modal || !body) return;

          document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('[data-lesson-detail]') : null;
            if (!link) return;
            e.preventDefault();

            body.innerHTML = '<p class="small">Loading…</p>';
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');

            fetch('/lesson_detail.php?lesson_id=' + encodeURIComponent(link.getAttribute('data-lesson-detail')),
                  { credentials: 'same-origin' })
              .then(function (r) { return r.text(); })
              .then(function (html) { body.innerHTML = html; })
              .catch(function () {
                body.innerHTML = '<p class="error">We could not load this lesson just now. Please try again.</p>';
              });
          });

          // A note or material added in the modal changes what the page
          // behind it should show (an "Add Note" link becomes a Notes &
          // Materials button, counts change), so when the modal closes after
          // a save — however it is closed — the page refreshes itself.
          new MutationObserver(function () {
            if (modal.classList.contains('hidden') && window.bcmLessonDataChanged) {
              window.location.reload();
            }
          }).observe(modal, { attributes: true, attributeFilter: ['class'] });
        });
        </script>
        <?php
        self::renderNotesScript();
        self::renderResourceModal();
    }

    /**
     * The modal's contents for one lesson. The caller has already checked
     * that this viewer may see the lesson; anyone who may see it may add a
     * note to it.
     */
    public static function renderDetail(array $lesson): void {
        $lessonId = (int)$lesson['id'];
        $teacher = trim(((string)($lesson['substitute_first_name'] ?? '') ?: (string)$lesson['teacher_first_name']) . ' '
            . ((string)($lesson['substitute_last_name'] ?? '') ?: (string)$lesson['teacher_last_name']));
        ?>
        <p style="margin-top:0;">
          <strong><?=lesson_time_html((string)$lesson['start_datetime'], (int)$lesson['duration_minutes'])?></strong><br>
          <?=h(trim((string)$lesson['student_first_name'] . ' ' . (string)$lesson['student_last_name']))?>
          with <?=h($teacher)?> · <?=h((string)$lesson['location_name'])?>
          <?php if (LessonManagement::isCancelled($lesson)): ?><span class="badge">Cancelled</span><?php endif; ?>
        </p>

        <h4>Notes</h4>
        <?=self::notesBlockHtml($lessonId, true, 'Add a note — a question for the teacher, or how practice went…')?>

        <h4>Materials</h4>
        <?=self::resourcesBlockHtml($lessonId, true)?>
        <?php
    }

    // ── Adding notes and resources ────────────────────────────────────────

    /**
     * Saves any [data-note-form] on the page (including one loaded into the
     * modal) and swaps the lesson's note list for what came back. Included by
     * renderModal(); render it directly on a page with inline note forms.
     */
    public static function renderNotesScript(): void {
        ?>
        <script>
        (function () {
          if (window.bcmLessonNotesWired) return;
          window.bcmLessonNotesWired = true;

          document.addEventListener('submit', function (e) {
            var form = e.target.closest ? e.target.closest('[data-note-form]') : null;
            if (!form) return;
            e.preventDefault();

            var lessonId = form.getAttribute('data-lesson-id');
            var state = form.querySelector('.note-save-state');
            var button = form.querySelector('button[type=submit]');
            var box = form.querySelector('textarea[name=body]');
            if (box && !box.value.trim()) {
              if (state) state.textContent = 'Write something first.';
              return;
            }
            if (state) state.textContent = 'Saving…';
            if (button) button.disabled = true;

            fetch('/lesson_note_add.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
              .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, text: t }; }); })
              .then(function (res) {
                if (button) button.disabled = false;
                if (!res.ok) {
                  if (state) state.textContent = res.text;
                  return;
                }
                var list = document.getElementById('lesson-notes-' + lessonId);
                if (list) list.innerHTML = res.text;
                if (box) box.value = '';
                if (state) state.textContent = 'Note saved.';
                window.bcmLessonDataChanged = true;
              })
              .catch(function () {
                if (button) button.disabled = false;
                if (state) state.textContent = 'Could not save — check your connection.';
              });
          });
        })();
        </script>
        <?php
    }

    /**
     * The "Edit resources" modal for a teacher's own lessons: the materials
     * already on the lesson, each with a Remove tickbox, plus one file or
     * link to add. Openers are buttons carrying data-resource-edit="<lessonId>".
     * Render once per page.
     *
     * One form, one save: ticking Remove stages a deletion rather than firing
     * it, so a teacher can clear out last week's recording and attach this
     * week's in a single trip, and back out of the whole thing with Cancel.
     *
     * Render-once guarded, because renderModal() includes it for the family
     * pages while the teacher pages also call it directly.
     */
    private static bool $resourceModalRendered = false;

    public static function renderResourceModal(): void {
        if (self::$resourceModalRendered) {
            return;
        }
        self::$resourceModalRendered = true;
        ?>
        <div id="lessonResourceModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true"
             aria-labelledby="lrTitle">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3 id="lrTitle">Edit resources</h3>
            <div class="error small hidden" id="lrErr"></div>

            <form id="lrForm" class="stack">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="lesson_id" value="">
              <input type="hidden" name="resource_type" value="file">

              <div id="lrCurrent"><p class="small">Loading…</p></div>

              <h4 style="margin-bottom:0;">Add a material</h4>
              <p class="small" style="margin:0;">Optional — leave blank if you are only removing.</p>
              <div class="modal-tabs">
                <button type="button" class="modal-tab active" id="lrTabFile">Upload a file</button>
                <button type="button" class="modal-tab" id="lrTabLink">Share a link</button>
              </div>
              <label>Title <input type="text" name="title" placeholder="Week 3 recording"></label>
              <label id="lrFieldFile">File (audio, PDF, image, or video)
                <input type="file" name="resource"></label>
              <label id="lrFieldLink" style="display:none;">Link (http/https)
                <input type="url" name="url" placeholder="https://..."></label>

              <div class="actions actions-split">
                <div class="actions-right">
                  <button type="button" class="button" data-modal-close>Cancel</button>
                  <button type="submit" class="button primary">Save changes</button>
                </div>
              </div>
            </form>
          </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
          var modal = document.getElementById('lessonResourceModal');
          if (!modal) return;
          var form = document.getElementById('lrForm');
          var current = document.getElementById('lrCurrent');
          var fieldFile = document.getElementById('lrFieldFile');
          var fieldLink = document.getElementById('lrFieldLink');
          var tabFile = document.getElementById('lrTabFile');
          var tabLink = document.getElementById('lrTabLink');
          var errEl = document.getElementById('lrErr');
          var typeInput = form.querySelector('input[name=resource_type]');
          var fileInput = form.querySelector('input[name=resource]');
          var urlInput = form.querySelector('input[name=url]');

          // The inactive field is disabled, not just hidden, so the browser
          // never submits it and never validates a url the teacher can't see.
          function switchTab(showFile) {
            fieldFile.style.display = showFile ? '' : 'none';
            fieldLink.style.display = showFile ? 'none' : '';
            tabFile.classList.toggle('active', showFile);
            tabLink.classList.toggle('active', !showFile);
            fileInput.disabled = !showFile;
            urlInput.disabled = showFile;
            typeInput.value = showFile ? 'file' : 'link';
            errEl.classList.add('hidden');
          }
          tabFile.addEventListener('click', function () { switchTab(true); });
          tabLink.addEventListener('click', function () { switchTab(false); });

          // Opening loads that lesson's materials, so the list is never a
          // stale copy of whichever lesson was edited last.
          document.addEventListener('click', function (e) {
            var opener = e.target.closest ? e.target.closest('[data-resource-edit]') : null;
            if (!opener) return;
            e.preventDefault();
            var lessonId = opener.getAttribute('data-resource-edit');
            form.reset();
            form.querySelector('input[name=lesson_id]').value = lessonId;
            switchTab(true);
            errEl.classList.add('hidden');
            current.innerHTML = '<p class="small">Loading…</p>';
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');

            fetch('/teacher/lesson_resources_edit.php?lesson_id=' + encodeURIComponent(lessonId),
                  { credentials: 'same-origin' })
              .then(function (r) { return r.text(); })
              .then(function (html) { current.innerHTML = html; })
              .catch(function () {
                current.innerHTML = '<p class="error small">We could not load the current materials.</p>';
              });
          });

          form.addEventListener('submit', function (e) {
            e.preventDefault();
            var lessonId = form.querySelector('input[name=lesson_id]').value;
            var button = form.querySelector('button[type=submit]');
            errEl.classList.add('hidden');

            var removing = form.querySelectorAll('input[name="remove[]"]:checked').length;
            var addingFile = !fileInput.disabled && fileInput.files && fileInput.files.length > 0;
            var addingLink = !urlInput.disabled && urlInput.value.trim() !== '';
            if (!removing && !addingFile && !addingLink) {
              errEl.textContent = 'Nothing to save — tick a material to remove, or add one.';
              errEl.classList.remove('hidden');
              return;
            }
            if (removing && !window.confirm(removing === 1
                ? 'Remove this material? This cannot be undone.'
                : 'Remove ' + removing + ' materials? This cannot be undone.')) {
              return;
            }

            button.disabled = true;
            fetch('/teacher/lesson_resources_save.php', {
              method: 'POST', body: new FormData(form), credentials: 'same-origin'
            })
              .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, text: t }; }); })
              .then(function (res) {
                button.disabled = false;
                if (!res.ok) {
                  errEl.textContent = res.text;
                  errEl.classList.remove('hidden');
                  return;
                }
                var list = document.getElementById('lesson-resources-' + lessonId);
                if (list) {
                  list.outerHTML = res.text;
                } else {
                  window.location.reload();
                }
                window.bcmLessonDataChanged = true;
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');
              })
              .catch(function () {
                button.disabled = false;
                errEl.textContent = 'Could not save — check your connection.';
                errEl.classList.remove('hidden');
              });
          });
        });
        </script>
        <?php
    }
}
