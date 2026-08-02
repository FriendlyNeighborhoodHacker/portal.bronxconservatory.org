<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/LeadManagement.php';

/**
 * The public "Request More Information" flow (/inquiry/).
 *
 * The point of the flow is that it saves early: page 1 writes an uncompleted
 * form so staff can reach out to a family who never finishes, and page 3
 * promotes that form into a real lead and deletes it. So a row in
 * incomplete_inquiries always means exactly one thing — "this family never
 * told us about a student."
 *
 * This class owns all incomplete_inquiries SQL; the leads/lead_students writes
 * live in LeadManagement, which owns those tables.
 */
class InquiryManagement {

    /**
     * The countries offered on the address page. The United States comes
     * first because nearly every family is local; the rest are alphabetical.
     * Not exhaustive by design — "Other" catches the long tail, and staff
     * confirm unusual addresses by phone anyway.
     */
    public const COUNTRIES = [
        'United States',
        'Argentina', 'Australia', 'Brazil', 'Canada', 'China', 'Colombia', 'Dominican Republic',
        'Ecuador', 'France', 'Germany', 'Ghana', 'India', 'Ireland', 'Israel', 'Italy', 'Jamaica',
        'Japan', 'Mexico', 'Nigeria', 'Philippines', 'Poland', 'Portugal', 'Puerto Rico',
        'South Korea', 'Spain', 'Trinidad and Tobago', 'Ukraine', 'United Kingdom', 'Other',
    ];

    /** USPS codes: the 50 states, DC, and the inhabited territories. */
    public const US_STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
        'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
        'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
        'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
        'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
        'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
        'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
        'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
        'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
        'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        'AS' => 'American Samoa', 'GU' => 'Guam', 'MP' => 'Northern Mariana Islands',
        'PR' => 'Puerto Rico', 'VI' => 'U.S. Virgin Islands',
    ];

    public const REFERRAL_SOURCES = [
        'Web Search', 'Word of Mouth', 'Family and Friends', 'Email Marketing',
        'Bronx Council on the Arts', 'Community Outreach', 'Other',
    ];

    public const DEFAULT_COUNTRY = 'United States';

    private static function pdo(): PDO {
        return pdo();
    }

    // ===== Public flow ($ctx is null — nobody is signed in) =====

    /**
     * Page 1: record the contact details so staff can follow up even if the
     * visitor never comes back. Returns the new uncompleted-form id, which the
     * flow keeps in the session (never in a form field, so one visitor can
     * never address another's row).
     */
    public static function startInquiry(?UserContext $ctx, array $contact): int {
        $error = self::validateContact($contact);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
        self::pdo()->prepare(
            'INSERT INTO incomplete_inquiries
               (first_name, last_name, email, phone, newsletter_opt_in, sms_consent, last_step_completed)
             VALUES (?,?,?,?,?,?,1)'
        )->execute(self::contactParams($contact));
        $id = (int)self::pdo()->lastInsertId();

        self::log($ctx, 'inquiry.draft_started', ['incomplete_inquiry_id' => $id]);
        return $id;
    }

    /** Page 1 again, after the visitor went Back: update in place, never fork. */
    public static function updateContact(?UserContext $ctx, int $id, array $contact): void {
        $error = self::validateContact($contact);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
        $params = self::contactParams($contact);
        $params[] = $id;
        self::pdo()->prepare(
            'UPDATE incomplete_inquiries
             SET first_name = ?, last_name = ?, email = ?, phone = ?, newsletter_opt_in = ?, sms_consent = ?
             WHERE id = ?'
        )->execute($params);

        self::log($ctx, 'inquiry.draft_contact_updated', ['incomplete_inquiry_id' => $id]);
    }

    /** Page 2: attach the mailing address. */
    public static function saveAddress(?UserContext $ctx, int $id, array $address): void {
        $error = self::validateAddress($address);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
        self::pdo()->prepare(
            'UPDATE incomplete_inquiries SET
               address_country = ?, address_street_1 = ?, address_street_2 = ?,
               address_city = ?, address_state = ?, address_zip = ?,
               last_step_completed = GREATEST(last_step_completed, 2)
             WHERE id = ?'
        )->execute(array_merge(array_values(self::normalizeAddress($address)), [$id]));

        self::log($ctx, 'inquiry.draft_address_saved', ['incomplete_inquiry_id' => $id]);
    }

    /**
     * Page 3: the family has told us about a student, so this is a real lead
     * now. Creating the lead and deleting the uncompleted form happen in one
     * transaction — a crash mid-promotion leaves either a draft or a lead,
     * never both and never neither. Returns the new lead id.
     */
    public static function promoteToLead(?UserContext $ctx, int $draftId, array $student): int {
        $error = self::validateStudent($student);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
        $draft = self::find($draftId);
        if (!$draft) {
            throw new InvalidArgumentException('That form has already been submitted.');
        }

        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $leadId = LeadManagement::createInquiryLead($ctx, $draft, $student);
            $pdo->prepare('DELETE FROM incomplete_inquiries WHERE id = ?')->execute([$draftId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::log($ctx, 'inquiry.draft_promoted', [
            'incomplete_inquiry_id' => $draftId, 'lead_id' => $leadId,
        ]);
        return $leadId;
    }

    // ===== Validation =====
    // Pure functions: no database, no session, one message at a time — the
    // flow shows the first problem and sends the visitor back to a form that
    // still holds everything they typed.

    public static function validateContact(array $contact): ?string {
        if (trim((string)($contact['first_name'] ?? '')) === ''
            || trim((string)($contact['last_name'] ?? '')) === '') {
            return 'Please give your first and last name.';
        }
        $email = trim((string)($contact['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'That email address does not look right.';
        }
        if (strcasecmp($email, trim((string)($contact['confirm_email'] ?? ''))) !== 0) {
            return 'The two email addresses do not match.';
        }
        if (strlen(preg_replace('/\D/', '', (string)($contact['phone'] ?? '')) ?? '') < 10) {
            return 'Please give a phone number we can reach you on.';
        }
        return null;
    }

    /**
     * US addresses get real validation (a state we recognize, a ZIP shaped
     * like a ZIP) because that is where every family actually is. Elsewhere we
     * ask for street, city and postal code and leave the province free text —
     * guessing at the world's address formats would only reject real people.
     */
    public static function validateAddress(array $address): ?string {
        $country = trim((string)($address['address_country'] ?? ''));
        if ($country === '') {
            return 'Please choose a country.';
        }
        if (trim((string)($address['address_street_1'] ?? '')) === '') {
            return 'Please give a street address.';
        }
        if (trim((string)($address['address_city'] ?? '')) === '') {
            return 'Please give a city.';
        }
        $zip = trim((string)($address['address_zip'] ?? ''));
        if ($zip === '') {
            return 'Please give a postal code.';
        }

        if ($country === self::DEFAULT_COUNTRY) {
            $state = trim((string)($address['address_state'] ?? ''));
            if ($state === '') {
                return 'Please choose a state.';
            }
            if (!isset(self::US_STATES[strtoupper($state)])) {
                return 'Please choose a state from the list.';
            }
            if (!preg_match('/^\d{5}(-\d{4})?$/', $zip)) {
                return 'Please give a five-digit ZIP code.';
            }
        }
        return null;
    }

    public static function validateStudent(array $student): ?string {
        if (trim((string)($student['first_name'] ?? '')) === ''
            || trim((string)($student['last_name'] ?? '')) === '') {
            return 'Please give the student first and last name.';
        }
        $age = (int)($student['age'] ?? 0);
        if ($age < 1 || $age > 120) {
            return 'Please give the student age.';
        }
        $enrollment = (string)($student['enrollment_status'] ?? '');
        if (!isset(LeadManagement::ENROLLMENT_STATUSES[$enrollment])) {
            return 'Please say whether this is a new or continuing student.';
        }
        $interests = array_values(array_intersect(
            (array)($student['instruments_of_interest'] ?? []),
            LeadManagement::INQUIRY_INSTRUMENT_INTERESTS
        ));
        if (!$interests) {
            return 'Please choose at least one instrument of interest.';
        }
        if (in_array('Other', $interests, true)
            && trim((string)($student['instruments_other'] ?? '')) === '') {
            return 'Please tell us which other instrument you are interested in.';
        }
        return null;
    }

    /**
     * Page 4. Only the choice fields are checked — every free-text answer on
     * that page is genuinely optional, and refusing a submission over a blank
     * "any questions?" box would cost us a family for nothing.
     *
     * $semesterOptions is the admin-configured list (Settings), passed in so
     * this stays a pure function.
     */
    public static function validateDetails(array $details, array $semesterOptions): ?string {
        $semester = (string)($details['semester_label'] ?? '');
        if ($semester === '' || !in_array($semester, $semesterOptions, true)) {
            return 'Please choose a term.';
        }
        $owned = array_values(array_intersect(
            (array)($details['owned_instruments'] ?? []),
            LeadManagement::OWNED_INSTRUMENT_CHOICES
        ));
        if (in_array('Other', $owned, true)
            && trim((string)($details['owned_instruments_other'] ?? '')) === '') {
            return 'Please tell us which other instrument you own.';
        }
        $theoryInterest = (string)($details['theory_program_interest'] ?? '');
        if ($theoryInterest !== '' && !isset(LeadManagement::THEORY_INTEREST_LABELS[$theoryInterest])) {
            return 'Please choose one of the music theory program answers.';
        }
        $theoryKnowledge = (string)($details['theory_knowledge'] ?? '');
        if ($theoryKnowledge !== '' && !isset(LeadManagement::THEORY_KNOWLEDGE_LABELS[$theoryKnowledge])) {
            return 'Please choose one of the music theory knowledge levels.';
        }
        $referral = (string)($details['referral_source'] ?? '');
        if ($referral !== '' && !in_array($referral, self::REFERRAL_SOURCES, true)) {
            return 'Please choose how you heard about us from the list.';
        }
        return null;
    }

    /**
     * The posted address reduced to the six columns that are stored, in that
     * order. The address page submits both a US state dropdown and a free-text
     * province so it works without JavaScript; the chosen country decides
     * which of the two is the answer.
     */
    public static function normalizeAddress(array $address): array {
        $country = trim((string)($address['address_country'] ?? self::DEFAULT_COUNTRY));
        if ($country === self::DEFAULT_COUNTRY) {
            $state = strtoupper(trim((string)($address['address_state'] ?? '')));
        } else {
            $province = trim((string)($address['address_province'] ?? ''));
            $state = $province !== '' ? $province : trim((string)($address['address_state'] ?? ''));
        }
        return [
            'address_country' => $country,
            'address_street_1' => trim((string)($address['address_street_1'] ?? '')),
            'address_street_2' => self::orNull($address['address_street_2'] ?? null),
            'address_city' => trim((string)($address['address_city'] ?? '')),
            'address_state' => self::orNull($state),
            'address_zip' => trim((string)($address['address_zip'] ?? '')),
        ];
    }

    // ===== Reads =====

    public static function find(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM incomplete_inquiries WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** One page of uncompleted forms, newest first. */
    public static function listIncomplete(int $limit = 25, int $offset = 0): array {
        // As in LeadManagement::listLeads — LIMIT/OFFSET cannot be bound here.
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        return self::pdo()->query(
            "SELECT * FROM incomplete_inquiries ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset"
        )->fetchAll();
    }

    public static function countIncomplete(): int {
        return (int)self::pdo()->query('SELECT COUNT(*) FROM incomplete_inquiries')->fetchColumn();
    }

    // ===== Admin =====

    public static function saveAdminNotes(?UserContext $ctx, int $id, string $notes): void {
        self::assertAdmin($ctx);
        self::pdo()->prepare('UPDATE incomplete_inquiries SET admin_notes = ? WHERE id = ?')
            ->execute([trim($notes), $id]);
        self::log($ctx, 'inquiry.draft_notes_saved', ['incomplete_inquiry_id' => $id]);
    }

    public static function delete(?UserContext $ctx, int $id): void {
        self::assertAdmin($ctx);
        self::pdo()->prepare('DELETE FROM incomplete_inquiries WHERE id = ?')->execute([$id]);
        self::log($ctx, 'inquiry.draft_deleted', ['incomplete_inquiry_id' => $id]);
    }

    // ===== Internals =====

    private static function contactParams(array $contact): array {
        return [
            trim((string)($contact['first_name'] ?? '')),
            trim((string)($contact['last_name'] ?? '')),
            strtolower(trim((string)($contact['email'] ?? ''))),
            trim((string)($contact['phone'] ?? '')),
            !empty($contact['newsletter_opt_in']) ? 1 : 0,
            !empty($contact['sms_consent']) ? 1 : 0,
        ];
    }

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

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
