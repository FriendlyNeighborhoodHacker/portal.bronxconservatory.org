<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

// BCM teaching locations (Access Bronx Charter School, Bronx Community
// College, ... — seeded by schema.sql, managed by admins).
class LocationManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    public static function all(bool $activeOnly = false): array {
        $sql = 'SELECT * FROM locations';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        return self::pdo()->query($sql . ' ORDER BY name')->fetchAll();
    }

    public static function find(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM locations WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function create(?UserContext $ctx, string $name, ?string $address = null): int {
        self::assertAdmin($ctx);
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Location name is required.');
        }
        self::pdo()->prepare('INSERT INTO locations (name, address) VALUES (?,?)')
            ->execute([$name, self::orNull($address)]);
        $id = (int)self::pdo()->lastInsertId();
        self::log($ctx, 'location.created', ['location_id' => $id, 'name' => $name]);
        return $id;
    }

    public static function update(?UserContext $ctx, int $id, string $name, ?string $address, bool $isActive): void {
        self::assertAdmin($ctx);
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Location name is required.');
        }
        self::pdo()->prepare('UPDATE locations SET name=?, address=?, is_active=? WHERE id=?')
            ->execute([$name, self::orNull($address), $isActive ? 1 : 0, $id]);
        self::log($ctx, 'location.updated', ['location_id' => $id]);
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
