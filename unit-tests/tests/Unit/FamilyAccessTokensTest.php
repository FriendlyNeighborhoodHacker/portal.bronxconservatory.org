<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FamilyAccessTokensTest extends TestCase
{
    private int $familyId;
    private int $parentId;

    protected function setUp(): void
    {
        test_reset_all();

        // Clear the per-run token reuse cache between tests
        $ref = new ReflectionProperty(FamilyAccessTokens::class, 'issuedThisRun');
        $ref->setValue(null, []);

        pdo()->exec("INSERT INTO users (first_name, last_name, email, password_hash) VALUES ('Maria', 'Martinez', 'maria@example.com', '')");
        $this->parentId = (int)pdo()->lastInsertId();
        pdo()->exec("INSERT INTO families (family_name, status, primary_parent_user_id) VALUES ('Martinez', 'schedule_assigned', {$this->parentId})");
        $this->familyId = (int)pdo()->lastInsertId();
    }

    public function testIssueAndVerifyRoundtrip(): void
    {
        $raw = FamilyAccessTokens::issueForFamilyRecipient($this->familyId, $this->parentId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $raw);

        $auth = FamilyAccessTokens::verify($raw);
        $this->assertNotNull($auth);
        $this->assertSame($this->familyId, $auth['family_id']);
        $this->assertSame($this->parentId, $auth['user_id']);

        // Verification bumps last_used_at.
        $lastUsed = pdo()->query('SELECT last_used_at FROM family_access_tokens')->fetchColumn();
        $this->assertNotNull($lastUsed);
    }

    public function testRawTokenIsNotStored(): void
    {
        $raw = FamilyAccessTokens::issueForFamilyRecipient($this->familyId, $this->parentId);
        $rows = pdo()->query('SELECT token_hash FROM family_access_tokens')->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame(hash('sha256', $raw), $rows[0]['token_hash']);
    }

    public function testVerifyRejectsGarbageAndUnknown(): void
    {
        $this->assertNull(FamilyAccessTokens::verify(''));
        $this->assertNull(FamilyAccessTokens::verify('not-a-token'));
        $this->assertNull(FamilyAccessTokens::verify(str_repeat('a', 64)));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $raw = FamilyAccessTokens::issueForFamilyRecipient($this->familyId, $this->parentId);
        pdo()->exec("UPDATE family_access_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $this->assertNull(FamilyAccessTokens::verify($raw));
    }

    public function testRevokedTokenIsRejected(): void
    {
        $raw = FamilyAccessTokens::issueForFamilyRecipient($this->familyId, $this->parentId);
        FamilyAccessTokens::revokeForFamily($this->familyId);
        $this->assertNull(FamilyAccessTokens::verify($raw));
    }

    public function testSameRunReusesToken(): void
    {
        $a = FamilyAccessTokens::issueForFamilyRecipient($this->familyId, $this->parentId);
        $b = FamilyAccessTokens::issueForFamilyRecipient($this->familyId, $this->parentId);
        $this->assertSame($a, $b);
        $this->assertSame(1, (int)pdo()->query('SELECT COUNT(*) FROM family_access_tokens')->fetchColumn());
    }
}
