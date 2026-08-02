<?php
declare(strict_types=1);

require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/LessonManagement.php';
require_once __DIR__ . '/NotesManagement.php';
require_once __DIR__ . '/ResourceManagement.php';

/**
 * The read-only "what about this lesson?" modal a family opens from a list of
 * upcoming lessons: when and where it is, the teacher's notes, and the
 * materials attached to it. Nothing here changes anything — a parent looking
 * at next Saturday wants to see the sheet music, not edit the calendar.
 *
 * Split the way modals are split elsewhere (docs/php-guidelines.md): the shell
 * and its script render into whatever page wants them, and the contents come
 * from /lesson_detail.php as an HTML fragment, so the same modal works on any
 * page that lists lessons.
 */
class LessonDetailUIManager {

    /** The modal shell + the script that fills it. Render once per page. */
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
    }

    /**
     * The modal's contents for one lesson. The caller has already checked
     * that this viewer may see the lesson.
     */
    public static function renderDetail(array $lesson): void {
        $lessonId = (int)$lesson['id'];
        $notes = NotesManagement::lessonNotesForLesson($lessonId);
        $resources = ResourceManagement::resourcesForLesson($lessonId);
        $teacher = trim(((string)($lesson['substitute_first_name'] ?? '') ?: (string)$lesson['teacher_first_name']) . ' '
            . ((string)($lesson['substitute_last_name'] ?? '') ?: (string)$lesson['teacher_last_name']));
        ?>
        <p style="margin-top:0;">
          <strong><?=lesson_time_html((string)$lesson['start_datetime'], (int)$lesson['duration_minutes'])?></strong><br>
          <?=h(trim((string)$lesson['student_first_name'] . ' ' . (string)$lesson['student_last_name']))?>
          with <?=h($teacher)?> · <?=h((string)$lesson['location_name'])?>
          <?php if (LessonManagement::isCancelled($lesson)): ?><span class="badge">Cancelled</span><?php endif; ?>
        </p>

        <h4>Teacher notes</h4>
        <?php if (!$notes): ?>
          <p class="small">No notes for this lesson yet — they appear after the lesson.</p>
        <?php else: ?>
          <?php foreach ($notes as $note): ?>
          <div style="padding:6px 0;border-bottom:1px solid var(--color-border);">
            <div class="small"><?=h(trim((string)$note['author_first_name'] . ' ' . (string)$note['author_last_name']))?>
              · <?=h(date('M j, Y', strtotime((string)$note['created_at'])))?></div>
            <div><?=nl2br(h((string)$note['body']))?></div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <h4>Materials</h4>
        <?php if (!$resources): ?>
          <p class="small">No materials attached to this lesson.</p>
        <?php else: ?>
          <?php foreach ($resources as $resource): ?>
          <div class="lesson-row">
            <?php if ($resource['resource_type'] === 'link'): ?>
              <span><a href="<?=h((string)$resource['url'])?>" target="_blank" rel="noopener">&#128279; <?=h((string)$resource['title'])?></a></span>
            <?php else: ?>
              <span><a href="/resource_download.php?id=<?=(int)$resource['id']?>"><?=h((string)$resource['title'])?></a></span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php
    }
}
