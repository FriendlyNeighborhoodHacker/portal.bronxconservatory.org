<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

// Announcements: holiday schedules, recital information, general updates.
class AnnouncementManagement {

    public const AUDIENCES = ['all', 'parents', 'students', 'teachers'];

    private static function pdo(): PDO {
        return pdo();
    }

    public static function create(?UserContext $ctx, string $title, string $body, string $audience = 'all'): int {
        self::assertAdmin($ctx);
        self::assertAudience($audience);
        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('Title and text are both required.');
        }
        self::pdo()->prepare(
            'INSERT INTO announcements (title, body, audience, created_by_user_id) VALUES (?,?,?,?)'
        )->execute([$title, $body, $audience, $ctx->id]);
        $id = (int)self::pdo()->lastInsertId();
        self::log($ctx, 'announcement.created', ['announcement_id' => $id]);
        return $id;
    }

    public static function update(?UserContext $ctx, int $id, string $title, string $body, string $audience): void {
        self::assertAdmin($ctx);
        self::assertAudience($audience);
        self::pdo()->prepare('UPDATE announcements SET title=?, body=?, audience=? WHERE id=?')
            ->execute([trim($title), trim($body), $audience, $id]);
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
        return self::pdo()->query('SELECT * FROM announcements ORDER BY published_at DESC, id DESC')->fetchAll();
    }

    // Announcements one of the user's roles should see. $roles from
    // Application::rolesForUser (admins see everything).
    public static function listForRoles(array $roles, int $limit = 20): array {
        if (in_array('admin', $roles, true)) {
            return self::listAll();
        }
        $audiences = ['all'];
        foreach (['parent' => 'parents', 'student' => 'students', 'teacher' => 'teachers'] as $role => $audience) {
            if (in_array($role, $roles, true)) {
                $audiences[] = $audience;
            }
        }
        $placeholders = implode(',', array_fill(0, count($audiences), '?'));
        $st = self::pdo()->prepare(
            "SELECT * FROM announcements WHERE audience IN ($placeholders)
             ORDER BY published_at DESC, id DESC LIMIT " . (int)$limit
        );
        $st->execute($audiences);
        return $st->fetchAll();
    }

    private static function assertAudience(string $audience): void {
        if (!in_array($audience, self::AUDIENCES, true)) {
            throw new InvalidArgumentException('Unknown audience: ' . $audience);
        }
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
