<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/StudentTeacherManagement.php';
require_once __DIR__ . '/InstrumentCatalog.php';

/**
 * Leads: submissions from either public form — the registration wizard
 * (source 'registration', which quotes and may take payment) or the
 * information request (source 'inquiry', which asks about interest and never
 * quotes). Deliberately quarantined from live data — a lead only becomes real
 * users / student profiles / reservations when an admin runs convertLead()
 * from Admin > Leads. Payments made at registration time are held on the lead
 * and moved to a student's ledger during conversion.
 */
class LeadManagement {

    public const STATUSES = ['new', 'contacted', 'scheduled', 'converted', 'declined'];

    public const STATUS_LABELS = [
        'new' => 'New',
        'contacted' => 'Contacted',
        'scheduled' => 'Scheduled',
        'converted' => 'Converted',
        'declined' => 'Declined',
    ];

    // The default Leads view: everyone still worth working. Converted and
    // declined leads are done, so they stay out of the way until asked for.
    public const ACTIVE_STATUSES = ['new', 'contacted', 'scheduled'];

    public const SOURCES = ['registration', 'inquiry'];

    public const SOURCE_LABELS = [
        'registration' => 'Registration',
        'inquiry' => 'Info Request',
    ];

    // The wizard's instrument choices, verbatim from the paper/WuFoo form.
    // "Cello/Bass" is resolved to a real instruments row at convert time.
    public const INSTRUMENT_CHOICES = ['Voice', 'Piano', 'Violin', 'Viola', 'Guitar', 'Cello/Bass'];

    public const LESSON_LENGTHS = [30, 60];

    // Recital/performance shirt sizes. Kept within users.shirt_size's
    // VARCHAR(10) so a converted student's size copies across as-is.
    public const SHIRT_SIZES = [
        'Youth XS', 'Youth S', 'Youth M', 'Youth L',
        'Adult S', 'Adult M', 'Adult L', 'Adult XL', 'Adult XXL',
    ];

    public const AVAILABILITY_BLOCKS = [
        '9-11' => '9:00AM–11:00AM',
        '11-1' => '11:00AM–1:00PM',
        '1-3' => '1:00PM–3:00PM',
        '3-5' => '3:00PM–5:00PM',
        '5-7' => '5:00PM–7:00PM',
    ];

    // ===== Information-request vocabularies =====
    // The inquiry form asks what a family is curious about, so its instrument
    // list is wider (and multi-select) than the registration wizard's, where a
    // single instrument is being signed up for. "Other" carries a free-text
    // companion field rather than sitting inside the JSON array, which keeps
    // the array a closed, validatable vocabulary.

    public const INQUIRY_INSTRUMENT_INTERESTS = [
        'Piano', 'Violin', 'Cello', 'Voice', 'Viola', 'Bass', 'Guitar', 'Guitar Ensemble', 'Other',
    ];

    public const OWNED_INSTRUMENT_CHOICES = [
        'Bass', 'Percussion', 'Violin/Viola', 'Guitar', 'Cello', 'Piano', 'Other',
    ];

    public const ENROLLMENT_STATUSES = [
        'new' => 'New student',
        'continuing' => 'Continuing student',
    ];

    public const THEORY_INTEREST_LABELS = [
        'yes' => 'Yes',
        'no' => 'No',
        'need_info' => 'Need more information',
    ];

    public const THEORY_KNOWLEDGE_LABELS = [
        'none' => 'None',
        'beginner' => 'Beginner (Note reading, Clefs, Basic Rhythm)',
        'intermediate' => 'Intermediate (Scales, Intervals, Key Signatures, Triads)',
        'advanced' => 'Advanced (Four Part Harmony, Roman Numeral Analysis, Musical Form, Composition)',
    ];

    private static function pdo(): PDO {
        return pdo();
    }

    // ===== Pricing =====

    /**
     * The itemized quote for a set of students, straight from Settings.
     * $students: [['first_name','instrument','lesson_length_minutes','guitar_ensemble'], ...]
     * Returns ['lines' => [['label','amount_cents'],...], 'total_cents', 'due_now_cents'].
     *
     * Registration fee once per family; per student: tuition (30/60) plus one
     * recital fee per lesson block (private lesson = one block, ensemble = a
     * second block); installment plan adds its fee. Due now = everything for
     * full pay; for installment, all fees plus half of each tuition line.
     */
    public static function priceQuote(array $students, bool $installmentPlan): array {
        $lines = [];
        $tuitionCents = 0;
        $feeCents = 0;

        $registration = Settings::registrationCostCents();
        if ($registration > 0) {
            $lines[] = ['label' => 'Registration fee (one per family per semester)', 'amount_cents' => $registration];
            $feeCents += $registration;
        }

        $recital = Settings::recitalFeeCents();
        foreach ($students as $student) {
            $name = trim((string)($student['first_name'] ?? '')) ?: 'Student';
            $length = (int)($student['lesson_length_minutes'] ?? 30);
            $tuition = $length === 60 ? Settings::tuition60Cents() : Settings::tuition30Cents();
            $lines[] = [
                'label' => $name . ' — ' . $length . '-minute private lessons (full semester)',
                'amount_cents' => $tuition,
            ];
            $tuitionCents += $tuition;
            if ($recital > 0) {
                $lines[] = ['label' => $name . ' — Recital & Logistics fee', 'amount_cents' => $recital];
                $feeCents += $recital;
            }

            if (!empty($student['guitar_ensemble'])) {
                $ensemble = Settings::tuitionEnsembleCents();
                $lines[] = [
                    'label' => $name . ' — Guitar Ensemble (full semester)',
                    'amount_cents' => $ensemble,
                ];
                $tuitionCents += $ensemble;
                if ($recital > 0) {
                    $lines[] = ['label' => $name . ' — Recital & Logistics fee (Guitar Ensemble)', 'amount_cents' => $recital];
                    $feeCents += $recital;
                }
            }
        }

        if ($installmentPlan) {
            $installmentFee = Settings::installmentFeeCents();
            if ($installmentFee > 0) {
                $lines[] = ['label' => 'Installment plan fee', 'amount_cents' => $installmentFee];
                $feeCents += $installmentFee;
            }
        }

        $total = $tuitionCents + $feeCents;
        // Installment: all fees now + half the tuition now (round up the odd cent).
        $dueNow = $installmentPlan ? $feeCents + intdiv($tuitionCents + 1, 2) : $total;

        return ['lines' => $lines, 'total_cents' => $total, 'due_now_cents' => $dueNow];
    }

    // ===== Create (public wizard; $ctx is null) =====

    /**
     * Persist a completed wizard submission as a lead + its students, with
     * the quote frozen as computed right now. Throws InvalidArgumentException
     * on bad input (callers flash the message back to the form).
     */
    public static function createLead(?UserContext $ctx, ?int $semesterId, array $parent, array $students, array $scheduling, bool $installmentPlan): int {
        $first = trim((string)($parent['first_name'] ?? ''));
        $last = trim((string)($parent['last_name'] ?? ''));
        $email = strtolower(trim((string)($parent['email'] ?? '')));
        $phone = trim((string)($parent['phone'] ?? ''));
        if ($first === '' || $last === '') {
            throw new InvalidArgumentException('Parent first and last name are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email address is required.');
        }
        if (strlen(preg_replace('/\D/', '', $phone)) < 10) {
            throw new InvalidArgumentException('A valid phone number is required.');
        }

        $cleanStudents = [];
        foreach ($students as $student) {
            $sFirst = trim((string)($student['first_name'] ?? ''));
            $sLast = trim((string)($student['last_name'] ?? ''));
            if ($sFirst === '' && $sLast === '') {
                continue;
            }
            if ($sFirst === '' || $sLast === '') {
                throw new InvalidArgumentException('Each student needs both a first and last name.');
            }
            $instrument = (string)($student['instrument'] ?? '');
            if (!in_array($instrument, self::INSTRUMENT_CHOICES, true)) {
                throw new InvalidArgumentException('Please choose an instrument for ' . $sFirst . '.');
            }
            $length = (int)($student['lesson_length_minutes'] ?? 0);
            if (!in_array($length, self::LESSON_LENGTHS, true)) {
                throw new InvalidArgumentException('Please choose a lesson length for ' . $sFirst . '.');
            }
            $shirtSize = trim((string)($student['shirt_size'] ?? ''));
            if ($shirtSize !== '' && !in_array($shirtSize, self::SHIRT_SIZES, true)) {
                throw new InvalidArgumentException('Please choose a shirt size for ' . $sFirst . ' from the list.');
            }
            $cleanStudents[] = [
                'first_name' => $sFirst,
                'last_name' => $sLast,
                'class_of' => self::normalizeClassOf($student['class_of'] ?? null),
                'instrument' => $instrument,
                'lesson_length_minutes' => $length,
                'guitar_ensemble' => !empty($student['guitar_ensemble']) ? 1 : 0,
                'shirt_size' => $shirtSize !== '' ? $shirtSize : null,
            ];
        }
        if (!$cleanStudents) {
            throw new InvalidArgumentException('At least one student is required.');
        }

        $quote = self::priceQuote($cleanStudents, $installmentPlan);

        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO leads
                   (semester_id, status, source, parent_first_name, parent_last_name, email, phone, sms_consent,
                    address_country, address_street_1, address_street_2, address_city, address_state, address_zip,
                    location_preference, preferred_days, availability_blocks, scheduling_notes,
                    policies_agreed_at, installment_plan, quote_json,
                    amount_quoted_cents, amount_due_now_cents)
                 VALUES (?,\'new\',\'registration\',?,?,?,?,?,\'United States\',?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?)'
            )->execute([
                $semesterId,
                $first,
                $last,
                $email,
                $phone,
                !empty($parent['sms_consent']) ? 1 : 0,
                trim((string)($parent['address_street_1'] ?? '')),
                self::orNull($parent['address_street_2'] ?? null),
                trim((string)($parent['address_city'] ?? '')),
                trim((string)($parent['address_state'] ?? '')),
                trim((string)($parent['address_zip'] ?? '')),
                self::orNull($scheduling['location_preference'] ?? null),
                json_encode(array_values((array)($scheduling['preferred_days'] ?? []))),
                json_encode(array_values((array)($scheduling['availability_blocks'] ?? []))),
                self::orNull($scheduling['notes'] ?? null),
                $installmentPlan ? 1 : 0,
                json_encode($quote['lines']),
                $quote['total_cents'],
                $quote['due_now_cents'],
            ]);
            $leadId = (int)$pdo->lastInsertId();

            $insert = $pdo->prepare(
                'INSERT INTO lead_students (lead_id, first_name, last_name, class_of, instrument, lesson_length_minutes, guitar_ensemble, shirt_size)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            foreach ($cleanStudents as $student) {
                $insert->execute([
                    $leadId, $student['first_name'], $student['last_name'], $student['class_of'],
                    $student['instrument'], $student['lesson_length_minutes'], $student['guitar_ensemble'],
                    $student['shirt_size'],
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::log($ctx, 'lead.created', [
            'lead_id' => $leadId,
            'semester_id' => $semesterId,
            'student_count' => count($cleanStudents),
            'amount_quoted_cents' => $quote['total_cents'],
        ]);
        return $leadId;
    }

    // ===== Reads =====

    public static function findLead(int $leadId): ?array {
        $st = self::pdo()->prepare(
            'SELECT l.*, s.season, s.year
             FROM leads l LEFT JOIN semesters s ON s.id = l.semester_id
             WHERE l.id = ? LIMIT 1'
        );
        $st->execute([$leadId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function studentsForLead(int $leadId): array {
        $st = self::pdo()->prepare('SELECT * FROM lead_students WHERE lead_id = ? ORDER BY id');
        $st->execute([$leadId]);
        return $st->fetchAll();
    }

    /**
     * The students of many leads in one query, as [lead_id => rows]. The queue
     * page uses this instead of one studentsForLead() per row, so listing a
     * page of leads costs two queries however long the queue grows.
     */
    public static function studentsForLeads(array $leadIds): array {
        $ids = array_values(array_unique(array_map('intval', $leadIds)));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = self::pdo()->prepare(
            "SELECT * FROM lead_students WHERE lead_id IN ($placeholders) ORDER BY lead_id, id"
        );
        $st->execute($ids);
        $byLead = [];
        foreach ($st->fetchAll() as $row) {
            $byLead[(int)$row['lead_id']][] = $row;
        }
        return $byLead;
    }

    /**
     * One page of queue rows, newest first, each with its students attached.
     *
     * $filters: ['statuses' => string[], 'source' => string]. An omitted or
     * empty value means "no restriction", so listLeads() with no arguments is
     * still every lead. Mirrors ActivityLog::list()'s shape.
     */
    public static function listLeads(array $filters = [], int $limit = 25, int $offset = 0): array {
        $params = [];
        $where = self::leadFilterSql($filters, $params);
        // MySQL will not bind LIMIT/OFFSET with emulated prepares off, so they
        // are clamped ints interpolated into the SQL instead.
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $st = self::pdo()->prepare(
            'SELECT l.*, s.season, s.year
             FROM leads l LEFT JOIN semesters s ON s.id = l.semester_id'
            . $where
            . " ORDER BY l.created_at DESC, l.id DESC LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        $leads = $st->fetchAll();

        $byLead = self::studentsForLeads(array_column($leads, 'id'));
        foreach ($leads as &$lead) {
            $lead['students'] = $byLead[(int)$lead['id']] ?? [];
        }
        return $leads;
    }

    /** How many leads the same filters match — the pager's denominator. */
    public static function countLeads(array $filters = []): int {
        $params = [];
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM leads l' . self::leadFilterSql($filters, $params));
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    /** Per-status counts for the filter tabs, zero-filled. $filters may narrow by source. */
    public static function statusCounts(array $filters = []): array {
        $counts = array_fill_keys(self::STATUSES, 0);
        $params = [];
        // Only the source narrows these — a status filter would make each tab
        // count itself.
        $where = self::leadFilterSql(['source' => $filters['source'] ?? null], $params);
        $st = self::pdo()->prepare('SELECT l.status, COUNT(*) AS n FROM leads l' . $where . ' GROUP BY l.status');
        $st->execute($params);
        foreach ($st->fetchAll() as $row) {
            $counts[(string)$row['status']] = (int)$row['n'];
        }
        return $counts;
    }

    // The shared WHERE clause behind listLeads/countLeads/statusCounts.
    // Unknown statuses and sources are dropped rather than passed through, so
    // a hand-edited query string can only ever widen the view, never error.
    private static function leadFilterSql(array $filters, array &$params): string {
        $where = [];

        $statuses = array_values(array_filter(
            (array)($filters['statuses'] ?? []),
            fn($s) => in_array($s, self::STATUSES, true)
        ));
        if ($statuses) {
            $where[] = 'l.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }

        $source = (string)($filters['source'] ?? '');
        if (in_array($source, self::SOURCES, true)) {
            $where[] = 'l.source = ?';
            $params[] = $source;
        }

        return $where ? ' WHERE ' . implode(' AND ', $where) : '';
    }

    // ===== Admin updates =====

    public static function updateStatus(?UserContext $ctx, int $leadId, string $status): void {
        self::assertAdmin($ctx);
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown lead status: ' . $status);
        }
        self::pdo()->prepare('UPDATE leads SET status = ? WHERE id = ?')->execute([$status, $leadId]);
        self::log($ctx, 'lead.status_changed', ['lead_id' => $leadId, 'status' => $status]);
    }

    // ===== Internal notes (append-only history) =====

    /**
     * Append a note to a lead's history, optionally moving its status in the
     * same save. Notes are never updated or deleted, so two admins working the
     * same lead cannot clobber each other's record of what was said.
     *
     * The note and the status change commit together, so the history can never
     * disagree with the lead. An empty body is allowed only when the status
     * actually changes — the row then stands as the record of that transition.
     * Returns the new lead_notes id.
     */
    public static function addLeadNote(?UserContext $ctx, int $leadId, string $body, ?string $newStatus = null): int {
        self::assertAdmin($ctx);
        $lead = self::findLead($leadId);
        if (!$lead) {
            throw new InvalidArgumentException('Lead not found.');
        }
        if ($newStatus !== null && $newStatus !== '' && !in_array($newStatus, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown lead status: ' . $newStatus);
        }

        $body = trim($body);
        $statusChanged = $newStatus !== null && $newStatus !== '' && $newStatus !== (string)$lead['status'];
        if ($body === '' && !$statusChanged) {
            throw new InvalidArgumentException('Write a note, or change the status.');
        }

        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            if ($statusChanged) {
                $pdo->prepare('UPDATE leads SET status = ? WHERE id = ?')->execute([$newStatus, $leadId]);
            }
            $pdo->prepare(
                'INSERT INTO lead_notes (lead_id, created_by_user_id, body, status_after) VALUES (?,?,?,?)'
            )->execute([$leadId, $ctx->id, $body, $statusChanged ? $newStatus : null]);
            $noteId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::log($ctx, 'lead.note_added', [
            'lead_id' => $leadId, 'lead_note_id' => $noteId, 'status_changed' => $statusChanged,
        ]);
        if ($statusChanged) {
            // Emitted separately so this action keeps the same meaning in the
            // activity log whichever screen made the change.
            self::log($ctx, 'lead.status_changed', ['lead_id' => $leadId, 'status' => $newStatus]);
        }
        return $noteId;
    }

    /**
     * A lead's notes, oldest first, with author names joined. A NULL author is
     * a note carried over from the old single admin_notes column, which never
     * recorded who wrote it.
     */
    public static function notesForLead(int $leadId): array {
        $st = self::pdo()->prepare(
            'SELECT n.*, u.first_name AS author_first_name, u.last_name AS author_last_name
             FROM lead_notes n
             LEFT JOIN users u ON u.id = n.created_by_user_id
             WHERE n.lead_id = ?
             ORDER BY n.created_at, n.id'
        );
        $st->execute([$leadId]);
        return $st->fetchAll();
    }

    // ===== Stripe =====

    public static function attachCheckoutSession(?UserContext $ctx, int $leadId, string $sessionId): void {
        self::pdo()->prepare('UPDATE leads SET stripe_checkout_session_id = ? WHERE id = ?')
            ->execute([$sessionId, $leadId]);
        self::log($ctx, 'lead.checkout_session_attached', ['lead_id' => $leadId]);
    }

    // The PaymentIntent behind the registration form's embedded card fields.
    // Recorded up front so a payment can be matched back to its lead whether
    // the news arrives by webhook or by the browser returning.
    public static function attachPaymentIntent(?UserContext $ctx, int $leadId, string $paymentIntentId): void {
        self::pdo()->prepare('UPDATE leads SET stripe_payment_intent_id = ? WHERE id = ?')
            ->execute([$paymentIntentId, $leadId]);
        self::log($ctx, 'lead.payment_intent_attached', ['lead_id' => $leadId]);
    }

    // The reference a payment is keyed by: the PaymentIntent for the
    // embedded card form, or a Checkout Session for anything still in flight
    // from the hosted-checkout era.
    public static function paymentReference(array $lead): string {
        $intent = trim((string)($lead['stripe_payment_intent_id'] ?? ''));
        return $intent !== '' ? $intent : trim((string)($lead['stripe_checkout_session_id'] ?? ''));
    }

    /**
     * Record a completed Stripe payment on the lead. Idempotent: the guarded
     * UPDATE only fires while the session matches and nothing has been
     * recorded yet, so webhook + return-page races are harmless. Returns
     * whether this call recorded the payment.
     */
    public static function recordLeadPayment(int $leadId, int $amountCents, string $reference, ?string $paymentIntentId = null): bool {
        if ($amountCents <= 0 || trim($reference) === '') {
            return false;
        }
        // $reference is whichever Stripe object the payment came through —
        // the PaymentIntent (embedded card form) or a Checkout Session — and
        // has to already be on the lead, so a stray webhook cannot mark an
        // unrelated registration paid.
        $st = self::pdo()->prepare(
            'UPDATE leads
             SET amount_paid_cents = ?,
                 stripe_payment_intent_id = COALESCE(?, stripe_payment_intent_id),
                 paid_at = NOW()
             WHERE id = ? AND amount_paid_cents = 0
               AND (stripe_payment_intent_id = ? OR stripe_checkout_session_id = ?)'
        );
        $st->execute([$amountCents, $paymentIntentId, $leadId, $reference, $reference]);
        $recorded = $st->rowCount() > 0;
        if ($recorded) {
            self::log(null, 'lead.payment_recorded', [
                'lead_id' => $leadId,
                'amount_cents' => $amountCents,
                'reference' => $reference,
            ]);
        }
        return $recorded;
    }

    public static function findLeadByCheckoutSession(string $sessionId): ?array {
        if (trim($sessionId) === '') {
            return null;
        }
        $st = self::pdo()->prepare('SELECT * FROM leads WHERE stripe_checkout_session_id = ? LIMIT 1');
        $st->execute([$sessionId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // ===== Information-request leads =====

    /**
     * Create the lead behind a completed page 3 of the information request:
     * one leads row (source 'inquiry', no semester, nothing quoted) plus
     * exactly one lead_students row. Called from
     * InquiryManagement::promoteToLead() inside its transaction, so this
     * method deliberately opens none of its own.
     *
     * $parent is the uncompleted-form row being promoted; $student is
     * ['first_name','last_name','age','enrollment_status',
     *  'instruments_of_interest' => string[], 'instruments_other'].
     */
    public static function createInquiryLead(?UserContext $ctx, array $parent, array $student): int {
        $first = trim((string)($parent['first_name'] ?? ''));
        $last = trim((string)($parent['last_name'] ?? ''));
        $email = strtolower(trim((string)($parent['email'] ?? '')));
        if ($first === '' || $last === '') {
            throw new InvalidArgumentException('Please give the parent or guardian first and last name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('That email address does not look right.');
        }

        $pdo = self::pdo();
        $pdo->prepare(
            'INSERT INTO leads
               (status, source, parent_first_name, parent_last_name, email, phone,
                sms_consent, newsletter_opt_in,
                address_country, address_street_1, address_street_2, address_city, address_state, address_zip)
             VALUES (\'new\',\'inquiry\',?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $first,
            $last,
            $email,
            trim((string)($parent['phone'] ?? '')),
            !empty($parent['sms_consent']) ? 1 : 0,
            !empty($parent['newsletter_opt_in']) ? 1 : 0,
            self::orNull($parent['address_country'] ?? null),
            self::orNull($parent['address_street_1'] ?? null),
            self::orNull($parent['address_street_2'] ?? null),
            self::orNull($parent['address_city'] ?? null),
            self::orNull($parent['address_state'] ?? null),
            self::orNull($parent['address_zip'] ?? null),
        ]);
        $leadId = (int)$pdo->lastInsertId();
        self::writeInquiryStudent($leadId, $student);

        self::log($ctx, 'lead.created', ['lead_id' => $leadId, 'source' => 'inquiry', 'student_count' => 1]);
        return $leadId;
    }

    /**
     * Re-save page 1 against a lead that already exists. Once page 3 has
     * promoted the uncompleted form, the lead IS the record — a visitor who
     * goes back and fixes their email must fix it there, or the confirmation
     * would go to the wrong address.
     */
    public static function updateInquiryContact(?UserContext $ctx, int $leadId, array $contact): void {
        $email = strtolower(trim((string)($contact['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('That email address does not look right.');
        }
        self::pdo()->prepare(
            'UPDATE leads SET
               parent_first_name = ?, parent_last_name = ?, email = ?, phone = ?,
               sms_consent = ?, newsletter_opt_in = ?
             WHERE id = ?'
        )->execute([
            trim((string)($contact['first_name'] ?? '')),
            trim((string)($contact['last_name'] ?? '')),
            $email,
            trim((string)($contact['phone'] ?? '')),
            !empty($contact['sms_consent']) ? 1 : 0,
            !empty($contact['newsletter_opt_in']) ? 1 : 0,
            $leadId,
        ]);
        self::log($ctx, 'lead.inquiry_contact_saved', ['lead_id' => $leadId]);
    }

    /** Re-save page 2 against an already-promoted lead. See updateInquiryContact(). */
    public static function updateInquiryAddress(?UserContext $ctx, int $leadId, array $address): void {
        self::pdo()->prepare(
            'UPDATE leads SET
               address_country = ?, address_street_1 = ?, address_street_2 = ?,
               address_city = ?, address_state = ?, address_zip = ?
             WHERE id = ?'
        )->execute([
            self::orNull($address['address_country'] ?? null),
            self::orNull($address['address_street_1'] ?? null),
            self::orNull($address['address_street_2'] ?? null),
            self::orNull($address['address_city'] ?? null),
            self::orNull($address['address_state'] ?? null),
            self::orNull($address['address_zip'] ?? null),
            $leadId,
        ]);
        self::log($ctx, 'lead.inquiry_address_saved', ['lead_id' => $leadId]);
    }

    /**
     * Re-save the single student on an inquiry lead. Backs the Back-then-
     * Continue path on page 3: the visitor edits their answers rather than
     * forking a second lead.
     */
    public static function replaceInquiryStudent(?UserContext $ctx, int $leadId, array $student): void {
        $lead = self::findLead($leadId);
        if (!$lead || (string)$lead['source'] !== 'inquiry') {
            throw new InvalidArgumentException('That is not an information-request lead.');
        }
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            // Only unconverted rows; a converted student is live data now.
            $pdo->prepare('DELETE FROM lead_students WHERE lead_id = ? AND converted_student_user_id IS NULL')
                ->execute([$leadId]);
            self::writeInquiryStudent($leadId, $student);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::log($ctx, 'lead.inquiry_student_saved', ['lead_id' => $leadId]);
    }

    private static function writeInquiryStudent(int $leadId, array $student): void {
        $first = trim((string)($student['first_name'] ?? ''));
        $last = trim((string)($student['last_name'] ?? ''));
        if ($first === '' || $last === '') {
            throw new InvalidArgumentException('Please give the student first and last name.');
        }
        $interests = array_values(array_intersect(
            (array)($student['instruments_of_interest'] ?? []),
            self::INQUIRY_INSTRUMENT_INTERESTS
        ));
        $age = (int)($student['age'] ?? 0);
        $enrollment = (string)($student['enrollment_status'] ?? '');

        self::pdo()->prepare(
            'INSERT INTO lead_students
               (lead_id, first_name, last_name, age, enrollment_status, instruments_of_interest, instruments_other)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $leadId,
            $first,
            $last,
            $age > 0 ? $age : null,
            isset(self::ENROLLMENT_STATUSES[$enrollment]) ? $enrollment : null,
            json_encode($interests),
            self::orNull($student['instruments_other'] ?? null),
        ]);
    }

    /**
     * Page 4 of the information request: the family-level answers that are
     * asked once, whatever the student. Safe to call more than once — the
     * visitor can go back and resubmit.
     */
    public static function updateInquiryDetails(?UserContext $ctx, int $leadId, array $details): void {
        $lead = self::findLead($leadId);
        if (!$lead) {
            throw new InvalidArgumentException('Lead not found.');
        }
        $owned = array_values(array_intersect(
            (array)($details['owned_instruments'] ?? []),
            self::OWNED_INSTRUMENT_CHOICES
        ));
        $theoryInterest = (string)($details['theory_program_interest'] ?? '');
        $theoryKnowledge = (string)($details['theory_knowledge'] ?? '');

        self::pdo()->prepare(
            'UPDATE leads SET
               semester_label = ?, owned_instruments = ?, owned_instruments_other = ?,
               music_background = ?, theory_program_interest = ?, theory_knowledge = ?,
               referral_source = ?, inquiry_comments = ?
             WHERE id = ?'
        )->execute([
            self::orNull($details['semester_label'] ?? null),
            json_encode($owned),
            self::orNull($details['owned_instruments_other'] ?? null),
            self::orNull($details['music_background'] ?? null),
            isset(self::THEORY_INTEREST_LABELS[$theoryInterest]) ? $theoryInterest : null,
            isset(self::THEORY_KNOWLEDGE_LABELS[$theoryKnowledge]) ? $theoryKnowledge : null,
            self::orNull($details['referral_source'] ?? null),
            self::orNull($details['comments'] ?? null),
            $leadId,
        ]);

        self::log($ctx, 'lead.inquiry_details_saved', ['lead_id' => $leadId]);
    }

    // ===== Convert (admin) =====

    // The default instruments-table match for a wizard choice. "Cello/Bass"
    // defaults to Cello; the convert form lets the admin pick Double Bass.
    public static function instrumentIdForChoice(string $choice): ?int {
        $choice = trim($choice);
        if ($choice === '') {
            return null; // An inquiry lead student has no instrument decided yet.
        }
        $name = $choice === 'Cello/Bass' ? 'Cello' : $choice;
        $row = InstrumentCatalog::findByName($name);
        return $row ? (int)$row['id'] : null;
    }

    // The instruments-table match for an information-request interest. The
    // inquiry vocabulary is the family's words; the catalog is ours.
    public static function instrumentIdForInterest(string $interest): ?int {
        $map = ['Bass' => 'Double Bass', 'Guitar Ensemble' => 'Guitar', 'Other' => ''];
        $name = $map[trim($interest)] ?? trim($interest);
        if ($name === '') {
            return null;
        }
        $row = InstrumentCatalog::findByName($name);
        return $row ? (int)$row['id'] : null;
    }

    /**
     * What the convert form should preselect for a lead student: the
     * registration wizard's single choice when there is one, otherwise the
     * first information-request interest that maps to a real instrument.
     */
    public static function defaultInstrumentIdForLeadStudent(array $leadStudent): ?int {
        $chosen = self::instrumentIdForChoice((string)($leadStudent['instrument'] ?? ''));
        if ($chosen) {
            return $chosen;
        }
        foreach (json_decode((string)($leadStudent['instruments_of_interest'] ?? '[]'), true) ?: [] as $interest) {
            $id = self::instrumentIdForInterest((string)$interest);
            if ($id) {
                return $id;
            }
        }
        return null;
    }

    /**
     * Turn a lead into live data: parent user (created or adopted by email),
     * child users with student profiles + instruments + parenthood links,
     * optional reservation placements, and the lead's Stripe payment moved to
     * a chosen student's ledger.
     *
     * $options:
     *   'students' => [lead_student_id => [
     *       'instrument_id' => int,          // admin's Cello/Bass resolution
     *       'date_of_birth' => ?string,
     *       'reservation' => ?['teacher_user_id','location_id','day_of_week','start_time','duration_minutes'],
     *   ]],
     *   'payment_target_lead_student_id' => ?int,
     *
     * Idempotent/safe to re-enter: already-converted lead students (their
     * converted_student_user_id is set) are skipped, the parent adoption is
     * an upsert, and the ledger payment insert is UNIQUE-guarded (plus a
     * cross-student session check so one session never credits two students).
     * Throws on reservation conflicts (nothing further is attempted; already
     * created people stay recorded on the lead so re-running just continues).
     */
    public static function convertLead(UserContext $ctx, int $leadId, array $options = []): array {
        self::assertAdmin($ctx);
        $lead = self::findLead($leadId);
        if (!$lead) {
            throw new InvalidArgumentException('Lead not found.');
        }
        $leadStudents = self::studentsForLead($leadId);
        if (!$leadStudents) {
            throw new InvalidArgumentException('This lead has no students.');
        }
        $studentOptions = (array)($options['students'] ?? []);

        // 1. Parent: adopt by email or create, then fill in contact details
        //    non-destructively (only set what the lead knows).
        $adopted = UserManagement::adoptOrCreatePerson($ctx, [
            'first_name' => (string)$lead['parent_first_name'],
            'last_name' => (string)$lead['parent_last_name'],
            'email' => (string)$lead['email'],
            'cell_phone' => (string)$lead['phone'],
        ]);
        $parentUserId = (int)$adopted['id'];
        $profileFields = [];
        foreach ([
            'address_street_1' => 'address_street_1',
            'address_street_2' => 'address_street_2',
            'address_city' => 'address_city',
            'address_state' => 'address_state',
            'address_zip' => 'address_zip',
        ] as $leadKey => $userKey) {
            if (trim((string)($lead[$leadKey] ?? '')) !== '') {
                $profileFields[$userKey] = (string)$lead[$leadKey];
            }
        }
        if ($profileFields) {
            UserManagement::updateProfile($ctx, $parentUserId, $profileFields);
        }

        // 2. Students.
        $studentUserIds = [];
        foreach ($leadStudents as $leadStudent) {
            $leadStudentId = (int)$leadStudent['id'];
            if (!empty($leadStudent['converted_student_user_id'])) {
                $studentUserIds[$leadStudentId] = (int)$leadStudent['converted_student_user_id'];
                continue;
            }
            $opts = (array)($studentOptions[$leadStudentId] ?? []);

            $studentUserId = UserManagement::createUser($ctx, [
                'first_name' => (string)$leadStudent['first_name'],
                'last_name' => (string)$leadStudent['last_name'],
                'email' => '',
                'no_login' => true,
            ]);
            StudentTeacherManagement::ensureStudentProfile($ctx, $studentUserId, [
                'date_of_birth' => $opts['date_of_birth'] ?? null,
                'class_of' => $leadStudent['class_of'] ?? null,
            ]);
            if (trim((string)($leadStudent['shirt_size'] ?? '')) !== '') {
                UserManagement::updateProfile($ctx, $studentUserId, [
                    'shirt_size' => (string)$leadStudent['shirt_size'],
                ]);
            }
            $instrumentId = (int)($opts['instrument_id'] ?? 0)
                ?: self::defaultInstrumentIdForLeadStudent($leadStudent);
            if ($instrumentId) {
                InstrumentCatalog::addStudentInstruments($ctx, $studentUserId, [$instrumentId]);
            }
            StudentTeacherManagement::linkParentChild($ctx, $parentUserId, $studentUserId);

            self::pdo()->prepare('UPDATE lead_students SET converted_student_user_id = ? WHERE id = ?')
                ->execute([$studentUserId, $leadStudentId]);
            $studentUserIds[$leadStudentId] = $studentUserId;
        }

        // 3. Optional reservation placements (pending_reach_out, no charges —
        //    charges post when the reservation is later confirmed).
        require_once __DIR__ . '/ReservationManagement.php';
        $reservationIds = [];
        $semesterId = !empty($lead['semester_id']) ? (int)$lead['semester_id'] : null;
        foreach ($leadStudents as $leadStudent) {
            $leadStudentId = (int)$leadStudent['id'];
            $reservation = $studentOptions[$leadStudentId]['reservation'] ?? null;
            if (!$reservation || empty($reservation['teacher_user_id'])) {
                continue;
            }
            // Fall through zero as well as missing: an inquiry lead student
            // has no lesson length at all, and a posted empty duration field
            // arrives as "0", which would book a zero-length lesson.
            $duration = (int)($reservation['duration_minutes'] ?? 0)
                ?: (int)($leadStudent['lesson_length_minutes'] ?? 0)
                ?: 30;
            $reservationIds[] = ReservationManagement::createReservation($ctx, [
                'semester_id' => $reservation['semester_id'] ?? $semesterId,
                'teacher_user_id' => (int)$reservation['teacher_user_id'],
                'location_id' => (int)($reservation['location_id'] ?? 0),
                'student_user_id' => $studentUserIds[$leadStudentId],
                'day_of_week' => (int)($reservation['day_of_week'] ?? -1),
                'start_time' => (string)($reservation['start_time'] ?? ''),
                'duration_minutes' => $duration,
                'status' => 'pending_reach_out',
            ], ['post_charges' => false]);
        }

        // 4. Move the Stripe payment onto a student's ledger. Skipped (with a
        //    notice) when the session is already on ANY ledger, so one session
        //    can never credit two students.
        $paymentRecorded = false;
        $paymentNotice = null;
        $paidCents = (int)$lead['amount_paid_cents'];
        // Whichever Stripe object the money came through — it doubles as the
        // ledger's idempotency key, so a payment can never be credited twice.
        $sessionId = self::paymentReference($lead);
        $targetLeadStudentId = (int)($options['payment_target_lead_student_id'] ?? 0);
        if ($paidCents > 0 && $sessionId !== '' && $targetLeadStudentId && isset($studentUserIds[$targetLeadStudentId])) {
            $st = self::pdo()->prepare('SELECT COUNT(*) FROM ledger_entries WHERE stripe_checkout_session_id = ?');
            $st->execute([$sessionId]);
            if ((int)$st->fetchColumn() > 0) {
                $paymentNotice = 'This payment is already on a ledger — not recorded again.';
            } else {
                require_once __DIR__ . '/Billing.php';
                $paymentRecorded = Billing::recordStripePayment(
                    $studentUserIds[$targetLeadStudentId],
                    $paidCents,
                    $sessionId,
                    self::orNull($lead['stripe_payment_intent_id'] ?? null),
                    $semesterId
                );
            }
        }

        // 5. Mark the lead converted.
        self::pdo()->prepare(
            "UPDATE leads SET status = 'converted', converted_parent_user_id = ?, converted_at = COALESCE(converted_at, NOW()) WHERE id = ?"
        )->execute([$parentUserId, $leadId]);

        self::log($ctx, 'lead.converted', [
            'lead_id' => $leadId,
            'parent_user_id' => $parentUserId,
            'student_user_ids' => array_values($studentUserIds),
            'reservation_ids' => $reservationIds,
            'payment_recorded' => $paymentRecorded,
        ]);

        return [
            'parent_user_id' => $parentUserId,
            'parent_existed' => (bool)$adopted['existed'],
            'student_user_ids' => $studentUserIds,
            'reservation_ids' => $reservationIds,
            'payment_recorded' => $paymentRecorded,
            'payment_notice' => $paymentNotice,
        ];
    }

    // ===== Internals =====

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
    }

    private static function orNull($v): ?string {
        if ($v === null) return null;
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    // "Class of" is optional, but a value that is given has to be a real
    // graduation year — a stray age or a mistyped digit is worth catching
    // while the family is still on the form.
    private static function normalizeClassOf($value): ?int {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}$/', $value) || (int)$value < 1900 || (int)$value > 2200) {
            throw new InvalidArgumentException(
                'Class of should be a four-digit graduation year, for example ' . (date('Y') + 6) . '.'
            );
        }
        return (int)$value;
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
