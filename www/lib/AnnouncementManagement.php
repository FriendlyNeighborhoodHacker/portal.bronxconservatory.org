<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

// Announcements: holiday schedules, recital information, general updates.
// An announcement is shown on dashboards while valid_until has not passed.
class AnnouncementManagement {

    private static function pdo(): PDO {
        return pdo();
    }

    public static function create(?UserContext $ctx, string $title, string $body, string $validUntil): int {
        self::assertAdmin($ctx);
        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('Title and text are both required.');
        }
        $validUntil = self::normalizeDate($validUntil);
        self::pdo()->prepare(
            'INSERT INTO announcements (title, body, valid_until, created_by_user_id) VALUES (?,?,?,?)'
        )->execute([$title, $body, $validUntil, $ctx->id]);
        $id = (int)self::pdo()->lastInsertId();
        self::log($ctx, 'announcement.created', ['announcement_id' => $id]);
        return $id;
    }

    public static function update(?UserContext $ctx, int $id, string $title, string $body, string $validUntil): void {
        self::assertAdmin($ctx);
        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('Title and text are both required.');
        }
        $validUntil = self::normalizeDate($validUntil);
        self::pdo()->prepare('UPDATE announcements SET title=?, body=?, valid_until=? WHERE id=?')
            ->execute([$title, $body, $validUntil, $id]);
        self::log($ctx, 'announcement.updated', ['announcement_id' => $id]);
    }

    public static function delete(?UserContext $ctx, int $id): void {
        self::assertAdmin($ctx);
        self::pdo()->prepare('DELETE FROM announcements WHERE id=?')->execute([$id]);
        self::log($ctx, 'announcement.deleted', ['announcement_id' => $id]);
    }

    public static function find(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM announcements WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function listAll(): array {
        return self::pdo()->query('SELECT * FROM announcements ORDER BY created_at DESC, id DESC')->fetchAll();
    }

    /** Recent active announcements (valid_until today or later), newest first. */
    public static function listActive(int $limit = 20): array {
        $limit = max(1, min(100, $limit));
        return self::pdo()->query(
            "SELECT * FROM announcements WHERE valid_until >= CURDATE()
             ORDER BY created_at DESC, id DESC LIMIT $limit"
        )->fetchAll();
    }

    private static function normalizeDate(string $date): string {
        $ts = strtotime(trim($date));
        if (trim($date) === '' || $ts === false) {
            throw new InvalidArgumentException('"Valid until" must be a date.');
        }
        return date('Y-m-d', $ts);
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
