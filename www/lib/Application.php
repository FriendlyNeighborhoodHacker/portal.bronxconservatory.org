<?php
declare(strict_types=1);

class Application {
    private static bool $initialized = false;
    
    public static function init(): void {
        if (self::$initialized) {
            return;
        }
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // All "today" logic (due pills, email schedules) runs in the app's
        // timezone, not the server's (the CLI runner does the same).
        try {
            require_once __DIR__ . '/../settings.php';
            date_default_timezone_set(Settings::timezone());
        } catch (\Throwable $e) {
            // Settings table unavailable (e.g. mid-install): keep server default.
        }

        self::$initialized = true;
    }

    /**
     * The roles a user holds, derived from row existence rather than a role
     * column: 'admin' (users.is_admin), 'teacher' (teacher_profiles row),
     * 'parent' (parenthood row as parent), 'student' (student_profiles row).
     * Order reflects dashboard routing priority.
     */
    private static array $rolesCache = [];

    // Tests truncate tables between cases, so cached roles go stale there.
    public static function clearRolesCacheForTesting(): void {
        self::$rolesCache = [];
    }

    public static function rolesForUser(int $userId): array {
        if (isset(self::$rolesCache[$userId])) {
            return self::$rolesCache[$userId];
        }

        $roles = [];
        $st = pdo()->prepare('SELECT is_admin FROM users WHERE id = ?');
        $st->execute([$userId]);
        $user = $st->fetch();
        if ($user && !empty($user['is_admin'])) {
            $roles[] = 'admin';
        }

        $st = pdo()->prepare('SELECT 1 FROM teacher_profiles WHERE user_id = ?');
        $st->execute([$userId]);
        if ($st->fetchColumn()) {
            $roles[] = 'teacher';
        }

        $st = pdo()->prepare('SELECT 1 FROM parenthood WHERE parent_user_id = ? LIMIT 1');
        $st->execute([$userId]);
        if ($st->fetchColumn()) {
            $roles[] = 'parent';
        }

        $st = pdo()->prepare('SELECT 1 FROM student_profiles WHERE user_id = ?');
        $st->execute([$userId]);
        if ($st->fetchColumn()) {
            $roles[] = 'student';
        }

        return self::$rolesCache[$userId] = $roles;
    }
}
