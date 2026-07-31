<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';

// Limited-authentication tokens for acting on a family's enrollment from an
// email link (the "Great news — here's your schedule" email).
//
// A token authenticates one user for exactly one family, and only within the
// /f/ pages (review schedule, confirm enrollment). The raw 64-hex-char token
// exists only inside the email; the database stores its sha256. Tokens expire
// (see the family_token_expiry_days setting) but are not revoked on
// confirmation, so a family can re-open their schedule from the same email.
class FamilyAccessTokens {

    public const DEFAULT_TTL_DAYS = 30;

    private static function pdo(): PDO {
        return pdo();
    }

    private static function ttlDays(): int {
        $days = (int)Settings::get('family_token_expiry_days', (string)self::DEFAULT_TTL_DAYS);
        return $days > 0 ? $days : self::DEFAULT_TTL_DAYS;
    }

    // Returns the RAW token (the only time it exists in plaintext). A per-run
    // cache keeps one link per (family, user) within a single send.
    private static array $issuedThisRun = [];

    public static function issueForFamilyRecipient(int $familyId, int $userId, ?int $ttlDays = null): string {
        $cacheKey = $familyId . ':' . $userId;
        if (isset(self::$issuedThisRun[$cacheKey])) {
            return self::$issuedThisRun[$cacheKey];
        }

        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $days = $ttlDays ?? self::ttlDays();
        $expiresAt = date('Y-m-d H:i:s', time() + $days * 86400);

        $st = self::pdo()->prepare(
            'INSERT INTO family_access_tokens (family_id, user_id, token_hash, expires_at) VALUES (?,?,?,?)'
        );
        $st->execute([$familyId, $userId, $hash, $expiresAt]);

        self::$issuedThisRun[$cacheKey] = $raw;
        return $raw;
    }

    // Verifies a raw token. Returns ['token_id','family_id','user_id'] or
    // null if the token is unknown, expired, or revoked. Bumps last_used_at.
    public static function verify(string $rawToken): ?array {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || !preg_match('/^[0-9a-f]{64}$/', $rawToken)) {
            return null;
        }

        $hash = hash('sha256', $rawToken);
        $st = self::pdo()->prepare(
            'SELECT id, family_id, user_id FROM family_access_tokens
             WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $st->execute([$hash]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }

        self::pdo()->prepare('UPDATE family_access_tokens SET last_used_at = NOW() WHERE id = ?')
            ->execute([(int)$row['id']]);

        return [
            'token_id' => (int)$row['id'],
            'family_id' => (int)$row['family_id'],
            'user_id' => (int)$row['user_id'],
        ];
    }

    public static function revokeForFamily(int $familyId): void {
        self::pdo()->prepare('UPDATE family_access_tokens SET revoked_at = NOW() WHERE family_id = ? AND revoked_at IS NULL')
            ->execute([$familyId]);
    }

    // Absolute URL for the token-authenticated schedule review page, used in
    // emails.
    public static function scheduleReviewUrl(string $rawToken): string {
        $base = rtrim(Settings::get('site_base_url', ''), '/');
        return $base . '/f/schedule.php?token=' . $rawToken;
    }
}
