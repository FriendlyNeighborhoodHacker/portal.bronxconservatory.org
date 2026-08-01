<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UserManagementTest extends TestCase
{
    private UserContext $ctx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
    }

    public function testSoftDeleteHidesUserEverywhere(): void
    {
        $id = fx_user('Del', 'Eted', ['email' => 'del@example.org', 'password_hash' => 'h']);
        pdo()->exec("INSERT INTO student_profiles (user_id) VALUES ($id)");

        $this->assertNotNull(UserManagement::findAuthByEmail('del@example.org'));
        $this->assertSame(['student'], Application::rolesForUser($id));

        UserManagement::deleteUser($this->ctx, $id);

        // Cannot authenticate, hidden from lists, holds no roles.
        $this->assertNull(UserManagement::findAuthByEmail('del@example.org'));
        $this->assertNotContains($id, array_map('intval', array_column(UserManagement::listUsers(), 'id')));
        $this->assertContains($id, array_map('intval', array_column(UserManagement::listUsers('', true), 'id')));
        Application::clearRolesCacheForTesting();
        $this->assertSame([], Application::rolesForUser($id));

        // The row itself remains (soft delete).
        $this->assertNotNull(UserManagement::findById($id));

        UserManagement::restoreUser($this->ctx, $id);
        $this->assertNotNull(UserManagement::findAuthByEmail('del@example.org'));
    }

    public function testCannotDeleteYourself(): void
    {
        $this->expectException(RuntimeException::class);
        UserManagement::deleteUser($this->ctx, $this->ctx->id);
    }
}
