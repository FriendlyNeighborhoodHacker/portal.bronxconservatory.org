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

    // The conservatory's phone number, shown in the footer of every page and
    // prominently on the login/registration pages (warm, not institutional).
    public static function contactPhone(): string {
        return self::get('contact_phone', '(718) 841-7415');
    }

    // ── Semester pricing ────────────────────────────────────────────────
    // Stored as dollar strings ("300.00"), exposed in integer cents (exact
    // arithmetic; matches Stripe's unit). Charged to a student's ledger when
    // their semester reservation is confirmed.

    public static function registrationCostCents(): int {
        return self::dollarsToCents(self::get('registration_cost', '0'));
    }

    public static function semesterLessonCostCents(): int {
        return self::dollarsToCents(self::get('semester_lesson_cost', '0'));
    }

    public static function recitalFeeCents(): int {
        return self::dollarsToCents(self::get('recital_fee', '0'));
    }

    // ── Public registration wizard pricing ──────────────────────────────
    // These price the quote on the public registration form (leads).
    // registrationCostCents (once per family) and recitalFeeCents (per
    // lesson block) are shared with the semester-confirmation charges above.

    public static function tuition30Cents(): int {
        return self::dollarsToCents(self::get('tuition_30', '0'));
    }

    public static function tuition60Cents(): int {
        return self::dollarsToCents(self::get('tuition_60', '0'));
    }

    public static function tuitionEnsembleCents(): int {
        return self::dollarsToCents(self::get('tuition_ensemble', '0'));
    }

    public static function installmentFeeCents(): int {
        return self::dollarsToCents(self::get('installment_fee', '0'));
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

    private static function dollarsToCents(string $dollars): int {
        $dollars = trim(str_replace(['$', ','], '', $dollars));
        if ($dollars === '' || !is_numeric($dollars)) {
            return 0;
        }
        return (int)round((float)$dollars * 100);
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
