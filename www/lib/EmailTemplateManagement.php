<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/LeadManagement.php';
require_once __DIR__ . '/MarkdownToHtml.php';

/**
 * Transactional email wording that admins can change without a deploy
 * (Admin > Email Templates).
 *
 * The split of ownership is deliberate: the code owns template_key, name and
 * available_variables — they describe what the calling code actually passes —
 * while the admin owns the subject and the body. Bodies are written in markdown
 * and converted to HTML at send time. Rendering escapes every substituted value,
 * so no wording an admin types and no value a family submits can inject markup
 * into an email.
 */
class EmailTemplateManagement {

    public const KEY_INQUIRY_FAMILY = 'inquiry_family_confirmation';
    public const KEY_INQUIRY_STAFF = 'inquiry_staff_notification';

    private static function pdo(): PDO {
        return pdo();
    }

    // ===== Reads =====

    public static function listTemplates(): array {
        return self::pdo()->query(
            'SELECT t.*, u.first_name AS updated_by_first_name, u.last_name AS updated_by_last_name
             FROM email_templates t
             LEFT JOIN users u ON u.id = t.updated_by_user_id
             ORDER BY t.name'
        )->fetchAll();
    }

    public static function find(string $templateKey): ?array {
        $st = self::pdo()->prepare(
            'SELECT t.*, u.first_name AS updated_by_first_name, u.last_name AS updated_by_last_name
             FROM email_templates t
             LEFT JOIN users u ON u.id = t.updated_by_user_id
             WHERE t.template_key = ? LIMIT 1'
        );
        $st->execute([$templateKey]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** The {{variable}} names the calling code supplies for this template. */
    public static function availableVariables(string $templateKey): array {
        $template = self::find($templateKey);
        if (!$template) {
            return [];
        }
        $decoded = json_decode((string)($template['available_variables'] ?? '[]'), true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    // ===== Admin write =====

    public static function update(?UserContext $ctx, string $templateKey, string $subject, string $bodyMarkdown): void {
        self::assertAdmin($ctx);
        if (!self::find($templateKey)) {
            throw new InvalidArgumentException('Unknown email template: ' . $templateKey);
        }
        $subject = trim($subject);
        if ($subject === '') {
            throw new InvalidArgumentException('An email needs a subject line.');
        }
        if (trim($bodyMarkdown) === '') {
            throw new InvalidArgumentException('An email needs a body.');
        }

        self::pdo()->prepare(
            'UPDATE email_templates SET subject = ?, body_markdown = ?, updated_by_user_id = ? WHERE template_key = ?'
        )->execute([$subject, $bodyMarkdown, $ctx->id, $templateKey]);

        self::log($ctx, 'email_template.updated', ['template_key' => $templateKey]);
    }

    // ===== Render =====

    /**
     * Substitute {{name}} placeholders and return ['subject', 'body_html'], or
     * null when the template row is missing — callers then skip the send
     * rather than mailing something half-built.
     *
     * Markdown body is converted to HTML at render time. Every value is escaped
     * on the way in. A placeholder with no supplied value renders as an empty
     * string, so a typo'd {{varible}} shows as a gap rather than leaking
     * template syntax to a family.
     */
    public static function render(string $templateKey, array $variables): ?array {
        $template = self::find($templateKey);
        if (!$template) {
            return null;
        }
        $markdown = self::substitute((string)$template['body_markdown'], $variables, true, isMarkdown: true);
        return [
            'subject' => self::substitute((string)$template['subject'], $variables, false),
            'body_html' => MarkdownToHtml::convert($markdown),
        ];
    }

    /** Placeholders used in this wording that the code does not supply. */
    public static function unknownPlaceholders(string $templateKey, string $subject, string $bodyHtml): array {
        $known = self::availableVariables($templateKey);
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $subject . ' ' . $bodyHtml, $matches);
        $used = array_values(array_unique($matches[1] ?? []));
        return array_values(array_diff($used, $known));
    }

    /** Plausible values for every variable — used by the preview and test send. */
    public static function sampleVariables(string $templateKey): array {
        $samples = [
            'parent_first_name' => 'Maria',
            'parent_last_name' => 'Delgado',
            'parent_email' => 'maria.delgado@example.com',
            'parent_phone' => '(718) 555-0142',
            'student_first_name' => 'Luis',
            'student_last_name' => 'Delgado',
            'student_age' => '9',
            'enrollment_status' => 'New student',
            'instruments_of_interest' => 'Piano, Violin',
            'semester_label' => 'Fall 2026',
            'owned_instruments' => 'Piano',
            'music_background' => 'Two years of school recorder, no formal lessons yet.',
            'theory_program_interest' => 'Need more information',
            'theory_knowledge' => 'Beginner (Note reading, Clefs, Basic Rhythm)',
            'referral_source' => 'Word of Mouth',
            'comments' => 'Are Saturday mornings available?',
            'mailing_address' => '1234 Grand Concourse, Apt 5B, Bronx, NY 10456, United States',
            'newsletter_opt_in' => 'Yes',
            'sms_consent' => 'Yes',
            'contact_phone' => Settings::contactPhone(),
            'site_title' => Settings::siteTitle(),
            'lead_admin_url' => rtrim(Settings::get('site_base_url', ''), '/') . '/admin/lead.php?id=1',
        ];
        // Anything the template declares but this list has not thought of
        // still previews as something rather than a gap.
        foreach (self::availableVariables($templateKey) as $name) {
            if (!isset($samples[$name])) {
                $samples[$name] = strtoupper($name);
            }
        }
        return $samples;
    }

    /**
     * The variable map for the two information-request templates, built from a
     * lead row and its single lead_students row. Lives here rather than in the
     * mailer so the preview and the real send can never drift apart.
     */
    public static function inquiryVariables(array $lead, array $leadStudent): array {
        $yesNo = fn($v) => !empty($v) ? 'Yes' : 'No';
        $listOf = function ($json, ?string $other) {
            $items = json_decode((string)($json ?? '[]'), true);
            $items = is_array($items) ? array_map('strval', $items) : [];
            if ($other !== null && trim($other) !== '') {
                $items = array_map(fn($i) => $i === 'Other' ? 'Other: ' . trim($other) : $i, $items);
            }
            return $items ? implode(', ', $items) : '';
        };

        $addressParts = array_filter([
            trim((string)($lead['address_street_1'] ?? '')),
            trim((string)($lead['address_street_2'] ?? '')),
            trim((string)($lead['address_city'] ?? '')),
            trim((string)($lead['address_state'] ?? '')),
            trim((string)($lead['address_zip'] ?? '')),
            trim((string)($lead['address_country'] ?? '')),
        ], fn($p) => $p !== '');

        $enrollment = (string)($leadStudent['enrollment_status'] ?? '');
        $theoryInterest = (string)($lead['theory_program_interest'] ?? '');
        $theoryKnowledge = (string)($lead['theory_knowledge'] ?? '');

        return [
            'parent_first_name' => (string)($lead['parent_first_name'] ?? ''),
            'parent_last_name' => (string)($lead['parent_last_name'] ?? ''),
            'parent_email' => (string)($lead['email'] ?? ''),
            'parent_phone' => (string)($lead['phone'] ?? ''),
            'student_first_name' => (string)($leadStudent['first_name'] ?? ''),
            'student_last_name' => (string)($leadStudent['last_name'] ?? ''),
            'student_age' => (string)($leadStudent['age'] ?? ''),
            'enrollment_status' => LeadManagement::ENROLLMENT_STATUSES[$enrollment] ?? '',
            'instruments_of_interest' => $listOf(
                $leadStudent['instruments_of_interest'] ?? null,
                $leadStudent['instruments_other'] ?? null
            ),
            'semester_label' => (string)($lead['semester_label'] ?? ''),
            'owned_instruments' => $listOf(
                $lead['owned_instruments'] ?? null,
                $lead['owned_instruments_other'] ?? null
            ),
            'music_background' => (string)($lead['music_background'] ?? ''),
            'theory_program_interest' => LeadManagement::THEORY_INTEREST_LABELS[$theoryInterest] ?? '',
            'theory_knowledge' => LeadManagement::THEORY_KNOWLEDGE_LABELS[$theoryKnowledge] ?? '',
            'referral_source' => (string)($lead['referral_source'] ?? ''),
            'comments' => (string)($lead['inquiry_comments'] ?? ''),
            'mailing_address' => implode(', ', $addressParts),
            'newsletter_opt_in' => $yesNo($lead['newsletter_opt_in'] ?? 0),
            'sms_consent' => $yesNo($lead['sms_consent'] ?? 0),
            'contact_phone' => Settings::contactPhone(),
            'site_title' => Settings::siteTitle(),
            'lead_admin_url' => rtrim(Settings::get('site_base_url', ''), '/')
                . '/admin/lead.php?id=' . (int)($lead['id'] ?? 0),
        ];
    }

    // ===== Internals =====

    /**
     * In the body, a value is HTML-escaped. For HTML bodies, newlines become <br>;
     * for markdown bodies, newlines are preserved for markdown processing.
     * Neither an admin's wording nor a family's answer can inject markup.
     *
     * The subject is a plain-text header, so it is NOT HTML-escaped — that
     * would put a literal "&amp;" in front of a family. What it does get is
     * every CR/LF collapsed to a space: a header cannot span lines, and
     * letting one would be header injection.
     */
    private static function substitute(string $template, array $variables, bool $isHtmlBody, bool $isMarkdown = false): string {
        return (string)preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            function (array $m) use ($variables, $isHtmlBody, $isMarkdown): string {
                $value = (string)($variables[$m[1]] ?? '');
                if (!$isHtmlBody) {
                    return trim((string)preg_replace('/[\r\n]+/', ' ', $value));
                }
                $escaped = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                return $isMarkdown ? $escaped : nl2br($escaped);
            },
            $template
        );
    }

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) {
            throw new RuntimeException('Admins only');
        }
    }

    private static function log(?UserContext $ctx, string $action, array $meta): void {
        try {
            ActivityLog::log($ctx, $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }
}
