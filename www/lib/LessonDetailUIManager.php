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

    /** A lesson's notes, oldest first, each signed and dated. */
    public static function notesHtml(int $lessonId): string {
        $notes = NotesManagement::lessonNotesForLesson($lessonId);
        if (!$notes) {
            return '<p class="small lesson-notes-empty">No notes on this lesson yet.</p>';
        }
        $html = '';
        foreach ($notes as $note) {
            $author = trim((string)$note['author_first_name'] . ' ' . (string)$note['author_last_name']);
            $html .= '<div class="lesson-note">'
                . '<div class="lesson-note-by">' . h($author) . ' · '
                . h(date('M j, Y g:i A', strtotime((string)$note['created_at']))) . '</div>'
                . '<div>' . nl2br(h((string)$note['body'])) . '</div>'
                . '</div>';
        }
        return $html;
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

    /** The materials list, plus the button that adds to it where allowed. */
    public static function resourcesBlockHtml(int $lessonId, bool $canAdd): string {
        $html = self::resourcesListHtml($lessonId);
        if ($canAdd) {
            $html .= '<div class="actions" style="margin-top:6px;">'
                . '<button type="button" class="button" data-resource-add="' . $lessonId . '">Add a resource</button>'
                . '</div>';
        }
        return $html;
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
        });
        </script>
        <?php
        self::renderNotesScript();
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
        <?=self::resourcesBlockHtml($lessonId, false)?>
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
     * The "Add a resource" modal for a teacher's own lessons: a file or a
     * link, each with a title. Openers are buttons carrying
     * data-resource-add="<lessonId>". Render once per page.
     */
    public static function renderResourceModal(): void {
        ?>
        <div id="lessonResourceModal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
          <div class="modal-content">
            <button class="close" type="button" aria-label="Close">&times;</button>
            <h3>Add a resource</h3>
            <div class="actions" style="margin-bottom:12px;">
              <button type="button" class="button primary" id="lrTabFile">Upload a file</button>
              <button type="button" class="button" id="lrTabLink">Share a link</button>
            </div>
            <div class="error small hidden" id="lrErr"></div>

            <form id="lrPanelFile" class="stack" data-resource-form>
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="lesson_id" value="">
              <input type="hidden" name="resource_type" value="file">
              <label>Title <input type="text" name="title" placeholder="Week 3 recording"></label>
              <label>File (audio, PDF, image, or video)
                <input type="file" name="resource" required></label>
              <div class="actions">
                <button type="submit" class="button primary">Upload</button>
                <button type="button" class="button" data-modal-close>Cancel</button>
              </div>
            </form>

            <form id="lrPanelLink" class="stack" style="display:none;" data-resource-form>
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="lesson_id" value="">
              <input type="hidden" name="resource_type" value="link">
              <label>Title <input type="text" name="title" placeholder="Practice video"></label>
              <label>Link (http/https) <input type="url" name="url" placeholder="https://..." required></label>
              <div class="actions">
                <button type="submit" class="button primary">Share Link</button>
                <button type="button" class="button" data-modal-close>Cancel</button>
              </div>
            </form>
          </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
          var modal = document.getElementById('lessonResourceModal');
          var panelFile = document.getElementById('lrPanelFile');
          var panelLink = document.getElementById('lrPanelLink');
          var tabFile = document.getElementById('lrTabFile');
          var tabLink = document.getElementById('lrTabLink');
          var errEl = document.getElementById('lrErr');
          if (!modal) return;

          function switchTab(showFile) {
            panelFile.style.display = showFile ? '' : 'none';
            panelLink.style.display = showFile ? 'none' : '';
            tabFile.classList.toggle('primary', showFile);
            tabLink.classList.toggle('primary', !showFile);
            errEl.classList.add('hidden');
          }
          tabFile.addEventListener('click', function () { switchTab(true); });
          tabLink.addEventListener('click', function () { switchTab(false); });

          // Opening remembers which lesson the resource is for.
          document.addEventListener('click', function (e) {
            var opener = e.target.closest ? e.target.closest('[data-resource-add]') : null;
            if (!opener) return;
            e.preventDefault();
            var lessonId = opener.getAttribute('data-resource-add');
            panelFile.reset();
            panelLink.reset();
            modal.querySelectorAll('input[name=lesson_id]').forEach(function (input) { input.value = lessonId; });
            switchTab(true);
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
          });

          document.addEventListener('submit', function (e) {
            var form = e.target.closest ? e.target.closest('[data-resource-form]') : null;
            if (!form) return;
            e.preventDefault();
            var lessonId = form.querySelector('input[name=lesson_id]').value;
            errEl.classList.add('hidden');

            fetch('/teacher/lesson_resource_add.php', {
              method: 'POST', body: new FormData(form), credentials: 'same-origin'
            })
              .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, text: t }; }); })
              .then(function (res) {
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
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');
              })
              .catch(function () {
                errEl.textContent = 'Could not save — check your connection.';
                errEl.classList.remove('hidden');
              });
          });
        });
        </script>
        <?php
    }
}
