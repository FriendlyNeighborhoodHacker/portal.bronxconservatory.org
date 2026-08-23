<?php
// The admin's student page — the read-oriented hub the schedule links into:
// parents (with big, tappable contact info) first, then the semester's lesson
// reservations with a lesson-notes summary, then charges & payments, then the
// read-only student details. Editing profile fields lives on
// student_edit.php ("Edit Profile" top right); the photo and instruments are
// edited right here in modals.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../partials_person_form.php';
require_once __DIR__ . '/../partials_parent_modal.php';
require_once __DIR__ . '/../partials_photo_modal.php';
require_once __DIR__ . '/../partials_instruments_modal.php';
require_once __DIR__ . '/../lib/UserManagement.php';
require_once __DIR__ . '/../lib/StudentTeacherManagement.php';
require_once __DIR__ . '/../lib/InstrumentCatalog.php';
require_once __DIR__ . '/../lib/ReservationManagement.php';
require_once __DIR__ . '/../lib/LedgerUIManager.php';
require_once __DIR__ . '/../lib/NotesManagement.php';
require_once __DIR__ . '/../lib/Files.php';
Application::init();
require_admin();

$userId = (int)($_GET['id'] ?? 0);
$user = $userId > 0 ? UserManagement::findById($userId) : null;
if (!$user) {
    header('Location: /admin/students.php');
    exit;
}

$returnTo = '/admin/student.php?id=' . $userId;
$parents = StudentTeacherManagement::parentsOfStudent($userId);
$demographic = StudentTeacherManagement::demographicForStudent(UserContext::getLoggedInUserContext(), $userId);
$allInstruments = InstrumentCatalog::all();
$studentInstruments = InstrumentCatalog::namesForStudent($userId);
$semesterId = Application::adminSelectedSemesterId();
$reservations = ReservationManagement::reservationsForStudent($userId, $semesterId);
$noteSummary = NotesManagement::lessonNoteSummaryForStudent($userId, $semesterId);
$photoUrl = Files::profilePhotoUrl($user['photo_public_file_id'] ?? null);

$flash = $_SESSION['people_flash'] ?? null;
$flashError = $_SESSION['people_flash_error'] ?? null;
unset($_SESSION['people_flash'], $_SESSION['people_flash_error']);
if (isset($_GET['uploaded'])) { $flash = 'Photo uploaded.'; }
if (isset($_GET['deleted'])) { $flash = 'Photo removed.'; }
if (isset($_GET['err'])) { $flashError = 'Photo upload failed: ' . (string)$_GET['err']; }

$name = trim($user['first_name'] . ' ' . $user['last_name']);
header_html($name);
?>

<div class="page-head">
  <h2 style="display:flex;align-items:center;gap:12px;">
    <?php if ($photoUrl !== ''): ?>
      <img src="<?=h($photoUrl)?>" alt="<?=h($name)?>"
           style="width:56px;height:56px;border-radius:50%;object-fit:cover;">
    <?php endif; ?>
    <?=h($name)?><?=$user['is_deleted'] ? ' <span class="badge">Deleted</span>' : ''?>
  </h2>
  <span class="actions">
    <button type="button" class="button" data-modal-open="photoModal">
      <?=$photoUrl !== '' ? 'Edit Profile Photo' : 'Add Photo'?>
    </button>
    <a class="button" href="/admin/student_edit.php?id=<?=$userId?>">Edit Profile</a>
    <a class="button" href="/admin/students.php">Back to Students</a>
  </span>
</div>

<?php if ($flash): ?><p class="flash"><?=h($flash)?></p><?php endif; ?>
<?php if ($flashError): ?><p class="error"><?=h($flashError)?></p><?php endif; ?>

<div class="card">
  <div class="page-head">
    <h3>Parents</h3>
    <button type="button" class="button" data-modal-open="addParentModal">Add Parent</button>
  </div>
  <?php if (!$parents): ?><p class="small">No parents linked yet.</p><?php endif; ?>
  <?php foreach ($parents as $parent): ?>
    <div class="lesson-row" style="align-items:flex-start;">
      <span>
        <a href="/admin/parent_edit.php?id=<?=(int)$parent['id']?>"><strong><?=h($parent['first_name'] . ' ' . $parent['last_name'])?></strong></a>
        <?php if ($parent['role']): ?><span class="small">(<?=h($parent['role'])?>)</span><?php endif; ?>
        <div class="contact-big">
          <?php $phone = trim((string)($parent['cell_phone'] ?? '')); $email = trim((string)($parent['email'] ?? '')); ?>
          <?php if ($phone !== ''): ?>
            <a href="tel:<?=h(preg_replace('/[^0-9+]/', '', $phone))?>"><?=h($phone)?></a>
          <?php endif; ?>
          <?php if ($phone !== '' && $email !== ''): ?><span class="contact-sep">·</span><?php endif; ?>
          <?php if ($email !== ''): ?>
            <a href="mailto:<?=h($email)?>"><?=h($email)?></a>
          <?php endif; ?>
          <?php if ($phone === '' && $email === ''): ?><span class="small">No contact info recorded.</span><?php endif; ?>
        </div>
      </span>
      <form method="post" action="/admin/parent_unlink_eval.php" style="margin-left:auto;"
            onsubmit="return confirm('Unlink this parent from <?=h($name)?>?');">
        <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
        <input type="hidden" name="parent_user_id" value="<?=(int)$parent['id']?>">
        <input type="hidden" name="child_user_id" value="<?=$userId?>">
        <input type="hidden" name="return_to" value="<?=h($returnTo)?>">
        <button type="submit" class="button">Unlink</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h3>Lesson Reservation<?=count($reservations) === 1 ? '' : 's'?></h3>
  <?php if (!$reservations): ?>
    <p class="small">No lesson reservations this semester.</p>
  <?php endif; ?>
  <?php foreach ($reservations as $reservation): ?>
    <?php $instruments = InstrumentCatalog::likelyLessonInstruments((int)$reservation['teacher_user_id'], $userId); ?>
    <div class="lesson-row">
      <span class="lesson-row-time"><?=h(['SU','MO','TU','WE','TH','FR','SA'][(int)$reservation['day_of_week']] . ' ' . date('g:i a', strtotime((string)$reservation['start_time'])))?></span>
      <span><?=h($reservation['teacher_first_name'] . ' ' . $reservation['teacher_last_name'])?><?php
        if ($instruments): ?> (<?=h(implode(', ', $instruments))?>)<?php endif; ?> · <?=h($reservation['location_name'])?></span>
      <span class="small"><?=h(str_replace('_', ' ', (string)$reservation['status']))?></span>
    </div>
  <?php endforeach; ?>
  <p class="small" style="margin-top:8px;">
    <?php if ($noteSummary['count'] > 0): ?>
      <?=(int)$noteSummary['count']?> lesson note<?=$noteSummary['count'] === 1 ? '' : 's'?> this semester.
      Last lesson note: <?=h(date('M j, Y', strtotime((string)$noteSummary['last_lesson_date'])))?>.
    <?php else: ?>
      No lesson notes this semester.
    <?php endif; ?>
    <a href="/admin/student_notes.php?id=<?=$userId?>">More</a>
  </p>
</div>

<?php LedgerUIManager::renderSection($userId, $semesterId, $returnTo); ?>

<div class="card">
  <h3>Student Details</h3>
  <?=person_details_html($user)?>
  <p>Demographics: <strong><?=$demographic !== null && $demographic !== ''
      ? h($demographic . ' — ' . (StudentTeacherManagement::DEMOGRAPHIC_LABELS[$demographic] ?? ''))
      : 'Not recorded'?></strong>
    <span class="small">(admin-only; never shown to the family, student, or teacher)</span></p>
  <p>Instruments: <strong><?=$studentInstruments ? h(implode(', ', $studentInstruments)) : 'None'?></strong>
    — <a href="#" data-modal-open="instrumentsModal">edit</a></p>
</div>

<?php render_parent_modal($userId); ?>
<?php render_photo_modal($user, $returnTo); ?>
<?php render_student_instruments_modal($userId, $allInstruments, $studentInstruments, $returnTo); ?>

<?php footer_html(); ?>
