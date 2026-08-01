<?php
// Registration wizard step 3: policies and procedures (agreement required).
require_once __DIR__ . '/register_ui.php';
Application::init();
registration_require_open();
$state = registration_require_step(3);

$error = registration_flash_error();

ApplicationUI::minimalHeaderHtml('Register — Policies');
?>
<div style="max-width:720px;margin:0 auto;">
  <h1>Policies and Procedures</h1>
  <?=registration_steps_html(3)?>

  <?php if ($error): ?><p class="error"><?=h($error)?></p><?php endif; ?>

  <div class="card stack" style="max-height:420px;overflow-y:auto;">
    <h3>Bronx Conservatory Terms of Enrollment and Payment Policies</h3>

    <h4>1. Registration, Recital &amp; Logistics Fees</h4>
    <p>A non-refundable <?=registration_dollars(Settings::registrationCostCents())?> registration fee is payable upon enrollment. Only one fee
      is charged per family per semester.</p>
    <p>A <?=registration_dollars(Settings::recitalFeeCents())?> per lesson recital &amp; logistics fee will be added to each lesson
      registration per semester. Example: One violin lesson + one voice lesson = <?=registration_dollars(2 * Settings::recitalFeeCents())?> total
      fee. This supports recital venue costs, accompanists, and program printing. Thank you
      for helping us provide high-quality performance experiences!</p>

    <h4>2. Tuition and Payment Terms</h4>
    <p>Tuition for our private (one-to-one) lessons at the Bronx Conservatory is the lowest
      in our area to ensure access to all. Tuition is charged upfront on a semester basis.
      Students may register later into the semester (subject to availability) and tuition
      will be pro-rated accordingly.</p>
    <p>A payment installment plan is offered in which the fees and 50% of tuition are due
      before the first lesson, and the remaining tuition is due by week 4 of the semester.
      PLEASE NOTE: selecting this option still obligates you to payment for the full term,
      subject to the withdrawal policies described below. There is a
      <?=registration_dollars(Settings::installmentFeeCents())?> (per family) fee for the installment plan. The payer will
      provide their payment information to the Bronx Conservatory at the time of their first
      payment, and by doing so, authorize the Bronx Conservatory of Music to debit their
      credit or debit card on the fourth week of the semester for the second payment.</p>

    <h4>3. Missed and Cancelled Lessons</h4>
    <p>Make-up lessons will be offered for any lesson missed by the teacher or cancelled by
      the school. In the event make-up lessons cannot be rescheduled, credits/refunds will
      be applied. Parents and students are responsible for all other missed classes.
      Teachers are not required to make up lessons that are missed or cancelled by students.
      If students miss three or more lessons in a row without notification to the
      Conservatory, their time slot may be rescheduled with another student.</p>

    <h4>4. Withdrawal and Discontinuance</h4>
    <p>A written request for withdrawal must be submitted to be eligible for a refund. There
      will be a refund of 50% of the term's unused tuition if withdrawal occurs within the
      first three weeks of the semester. No refunds will be given after that time. A $30
      withdrawal fee will be deducted from any refunds due. The Conservatory reserves the
      right to discontinue any student whose behavior is unsatisfactory; this includes
      frequent absences (two or more consecutive absences without notice), tardiness,
      disruptive behavior, or failure to observe the authority of teachers or
      administrators. In such cases, tuition for remaining weeks will be assessed according
      to the withdrawal policy as of the date lessons are discontinued by the Conservatory.</p>

    <h4>5. Instrument and Student Material Policy</h4>
    <p>Students are required to acquire instruments and materials necessary for study by the
      second week of enrollment at the Bronx Conservatory of Music. Failure to acquire
      instruments and materials may result in disqualification from study at the
      Conservatory. The Conservatory has a lending library of instruments available and can
      loan instruments to students when possible. For information about where to rent or
      purchase instruments, please contact your child's teacher or the school office.</p>

    <h4>6. Distance Learning Policy</h4>
    <p>While we strongly believe that in-person study is always the best way to learn, we
      understand that it is sometimes not possible to study in person. In person study is
      also of course always subject to the determinations of the Bronx Conservatory of Music
      Board, as guided by the NYC Department of Health and the US CDC. If at any time the
      Bronx Conservatory of Music board deems it necessary to return to distance learning
      due to emergent public health concerns, the following terms apply.</p>
    <p>For online learning, students and/or parents are responsible for setting up an
      account on the Zoom application and for obtaining the appropriate devices in order to
      successfully participate in distance learning. Students must be prompt to their
      distance learning lesson time. Teachers are not required to make up lessons if
      students or parents are absent from agreed online lesson times. BCM is not responsible
      for technical issues that arise from the student's or guardian's devices; however, we
      will do our best to accommodate situations if they occur. Lessons that are disrupted
      due to a teacher's technological complications will be made up by the teacher at a
      mutually convenient time. Students under the age of 18 must have a parent or guardian
      present for all virtual lessons. If an adult is not present during a distance learning
      lesson, the teacher reserves the right to end the lesson immediately.</p>

    <h4>7. Release of Liability</h4>
    <p>As the legal parent, guardian, or adult student, I release, indemnify, and hold
      harmless Bronx Conservatory of Music Inc., its directors, officers, managers,
      employees, contractors and agents ("Bronx Conservatory of Music Parties") from and
      against any and all liability, claims, demands and causes of action whatsoever,
      arising out of or related to any loss, damage, or injury that may be sustained by the
      student and/or the undersigned, while in or upon the premises or any premises under
      the control and supervision of any of the Bronx Conservatory of Music Parties, in
      route to or from any said premises, or during any session of distance learning
      regardless of the location of the student during such session.</p>

    <h4>8. Media Release</h4>
    <p>I, on behalf of myself as parent, legal guardian, or adult student and on behalf of
      the minor(s) named above, grant to The Bronx Conservatory of Music, Inc. its
      licensees, assignees and successors the right to reproduce, manufacture, sell,
      distribute, and exhibit photographs and/or likeness of the minor(s) to advertise its
      activities and programs as it shall determine on printed advertisements and publicity
      material of all types including, without limitation, flyers, posters, brochures, press
      releases and press kits, videos, broadcasts, and the internet.</p>
  </div>

  <form method="post" action="/register/policies_eval.php" class="stack" style="margin-top:16px;">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <label class="inline">
      <input type="checkbox" name="agree" value="1"<?=!empty($state['policies_agreed']) ? ' checked' : ''?>>
      <strong>I have read and agree to these policies and procedures.</strong>
    </label>
    <div class="actions">
      <button type="submit" name="action" value="back" class="btn-outline">Back</button>
      <button type="submit" name="action" value="next" class="btn-cta">Continue</button>
    </div>
  </form>
</div>
<?php ApplicationUI::minimalFooterHtml(); ?>
