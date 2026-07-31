<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';

/**
 * The portal's email bodies, built in code (single-tenant — no per-tenant
 * template storage). Account-lifecycle emails (verification, password reset)
 * live in mailer.php; these are the BCM-specific messages. Each builder
 * returns ['subject' => string, 'html' => string] for send_email().
 *
 * Tone note (docs/app_spec.md): warm, not institutional. Every email signs
 * off with a real phone number.
 */
class EmailTemplates {

    private static function e($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    private static function wrap(string $inner): string {
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#0D1B2A;">'
            . $inner
            . '<p>Questions? Call us any time at ' . self::e(Settings::contactPhone()) . '.</p>'
            . '<p>— The Bronx Conservatory of Music</p>'
            . '</div>';
    }

    /**
     * The "Great news" email sent when an admin assigns a family's schedule.
     * $scheduleUrl is a family-access-token link to /f/schedule.php;
     * $lessonLines are pre-formatted plain-text schedule lines.
     */
    public static function scheduleAssigned(string $parentFirstName, array $lessonLines, string $scheduleUrl): array {
        $name = trim($parentFirstName) !== '' ? self::e($parentFirstName) : 'there';
        $lines = '';
        foreach ($lessonLines as $line) {
            $lines .= '<li>' . self::e($line) . '</li>';
        }
        $html = '<p>Hello ' . $name . ',</p>'
            . '<p><strong>Great news!</strong> We have a spot for your family at the Bronx Conservatory of Music. Here is your schedule:</p>'
            . '<ul>' . $lines . '</ul>'
            . '<p><a href="' . self::e($scheduleUrl) . '">Review your schedule and complete enrollment</a></p>';
        return [
            'subject' => 'Great news — we have a spot for your family at BCM!',
            'html' => self::wrap($html),
        ];
    }

    /**
     * Confirmation shown-and-sent after a registration form submission.
     * $path is 'complete_enrollment' or 'talk_first'; the copy differs.
     */
    public static function registrationReceived(string $parentFirstName, string $path, array $studentFirstNames): array {
        $name = trim($parentFirstName) !== '' ? self::e($parentFirstName) : 'there';
        $students = $studentFirstNames ? self::e(implode(', ', $studentFirstNames)) : 'your family';
        if ($path === 'talk_first') {
            $body = '<p>Thank you for registering ' . $students . ' with the Bronx Conservatory of Music!</p>'
                . '<p>You asked to talk with us first — someone from BCM will call you within two business days to answer your questions and find the right schedule for your family.</p>';
        } else {
            $body = '<p>Thank you for registering ' . $students . ' with the Bronx Conservatory of Music!</p>'
                . '<p>We are matching your family with a teacher and time that fit your preferences. You will hear from us with your schedule shortly.</p>';
        }
        $html = '<p>Hello ' . $name . ',</p>' . $body
            . '<p>In the meantime, check your email for a link to set up your BCM Family Portal account.</p>';
        return [
            'subject' => 'Welcome to the Bronx Conservatory of Music!',
            'html' => self::wrap($html),
        ];
    }
}
