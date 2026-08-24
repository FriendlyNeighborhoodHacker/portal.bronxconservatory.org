<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ImpersonationTest extends TestCase
{
    protected function setUp(): void
    {
        test_reset_all();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function makeDeveloper(): int
    {
        $id = fx_user('Dev', 'Eloper', ['is_admin' => true]);
        pdo()->exec('UPDATE users SET is_developer = 1 WHERE id = ' . $id);
        return $id;
    }

    private function startImpersonation(int $devId, int $targetId): void
    {
        $_SESSION['impersonator_uid'] = $devId;
        $_SESSION['impersonator_name'] = 'Dev Eloper';
        $_SESSION['uid'] = $targetId;
        $_SESSION['is_admin'] = 0;
    }

    public function testRestoreReturnsToImpersonator(): void
    {
        $dev = $this->makeDeveloper();
        $student = fx_student();
        $this->startImpersonation($dev, $student);

        $this->assertTrue(end_impersonation_and_restore());
        $this->assertSame($dev, (int)$_SESSION['uid']);
        $this->assertSame(1, $_SESSION['is_admin']);
        $this->assertArrayNotHasKey('impersonator_uid', $_SESSION);
        $this->assertArrayNotHasKey('impersonator_name', $_SESSION);

        $rows = ActivityLog::list(['action_type' => 'user.impersonate_end']);
        $this->assertCount(1, $rows);
        $this->assertSame($dev, (int)$rows[0]['user_id']);
        $this->assertSame($dev, (int)$rows[0]['metadata']['impersonator_user_id']);
        $this->assertSame($student, (int)$rows[0]['metadata']['target_user_id']);
    }

    public function testRestoreFailsClosedWhenImpersonatorDeleted(): void
    {
        $dev = $this->makeDeveloper();
        $student = fx_student();
        $this->startImpersonation($dev, $student);
        pdo()->exec('UPDATE users SET is_deleted = 1 WHERE id = ' . $dev);

        $this->assertFalse(end_impersonation_and_restore());
        $this->assertSame([], $_SESSION);
    }

    public function testRestoreFailsClosedWhenImpersonatorNoLongerDeveloper(): void
    {
        $dev = $this->makeDeveloper();
        $student = fx_student();
        $this->startImpersonation($dev, $student);
        pdo()->exec('UPDATE users SET is_developer = 0 WHERE id = ' . $dev);

        $this->assertFalse(end_impersonation_and_restore());
        $this->assertSame([], $_SESSION);
    }

    public function testRestoreWithoutImpersonationIsNoop(): void
    {
        $_SESSION['uid'] = fx_student();
        $this->assertFalse(end_impersonation_and_restore());
        $this->assertArrayHasKey('uid', $_SESSION);
    }

    public function testActivityLogStampsImpersonatorFromContext(): void
    {
        $dev = $this->makeDeveloper();
        $student = fx_student();
        $ctx = new UserContext($student, false, false, $dev);

        ActivityLog::log($ctx, 'user.test_action', ['foo' => 'bar']);

        $rows = ActivityLog::list(['action_type' => 'user.test_action']);
        $this->assertCount(1, $rows);
        $this->assertSame($student, (int)$rows[0]['user_id']);
        $this->assertSame($dev, (int)$rows[0]['metadata']['impersonator_user_id']);
        $this->assertSame('bar', $rows[0]['metadata']['foo']);
    }
}
