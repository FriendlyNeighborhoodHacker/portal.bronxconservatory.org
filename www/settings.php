<?php
// Settings management for the BCM Family Portal
require_once __DIR__ . '/config.php';

class Settings {
    private static function pdo(): PDO {
        return pdo();
    }

    public static function get(string $key, string $default = ''): string {
        $st = self::pdo()->prepare('SELECT value FROM settings WHERE key_name = ? LIMIT 1');
        $st->execute([$key]);
        $row = $st->fetch();
        return $row ? (string)$row['value'] : $default;
    }

    public static function set(string $key, string $value): bool {
        $st = self::pdo()->prepare(
            'INSERT INTO settings (key_name, value) VALUES (?, ?) 
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        return $st->execute([$key, $value]);
    }

    public static function siteTitle(): string {
        return self::get('site_title', APP_NAME);
    }

    public static function announcement(): string {
        return self::get('announcement', '');
    }

    public static function timezone(): string {
        return self::get('timezone', date_default_timezone_get());
    }

    /**
     * A full https://host/path URL for somewhere in the portal — what Stripe
     * needs to send a family back after paying. Falls back to the current
     * request's host, which is what makes this work in local development
     * before site_base_url has been set.
     */
    public static function absoluteUrl(string $path): string {
        $base = rtrim(self::get('site_base_url', ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return $base . $path;
    }

    // The conservatory's phone number, shown in the footer of every page and
    // prominently on the login/registration pages (warm, not institutional).
    public static function contactPhone(): string {
        return self::get('contact_phone', '(718) 841-7415');
    }

    // The semester the public registration wizard is open for; null means
    // registration is closed (the wizard shows a friendly closed page).
    public static function registrationSemesterId(): ?int {
        $value = self::get('registration_semester_id', '');
        if ($value === '' || !is_numeric($value) || (int)$value <= 0) {
            return null;
        }
        return (int)$value;
    }

    /**
     * The Semester choices on the public information-request form. Stored as a
     * JSON array of labels, but a hand-edited newline-separated list is
     * accepted too so a stray edit in the database cannot empty the form.
     * Never returns an empty list — a radio group with no options would make
     * page 4 unsubmittable.
     */
    public static function inquirySemesterOptions(): array {
        $raw = trim(self::get('inquiry_semester_options', ''));
        $options = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $candidates = is_array($decoded) ? $decoded : preg_split('/\R/', $raw);
            foreach ($candidates as $option) {
                $option = trim((string)$option);
                if ($option !== '') {
                    $options[] = $option;
                }
            }
        }
        return $options ?: ['Upcoming Semester'];
    }

    // Where the information-request staff notification goes. An empty value
    // means "do not send" rather than an error.
    public static function inquiryNotificationEmail(): string {
        return trim(self::get('inquiry_notification_email', ''));
    }


    public static function loginImageUrl(): string {
        $fileId = self::get('login_image_file_id', '');
        if ($fileId === '' || !is_numeric($fileId)) {
            return '';
        }
        
        require_once __DIR__ . '/lib/Files.php';
        return Files::publicFileImageUrl((int)$fileId, 200);
    }
}
