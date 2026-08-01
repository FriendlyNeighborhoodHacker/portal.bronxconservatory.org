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

    public function testAdoptOrCreatePersonReusesExistingEmail(): void
    {
        $existing = fx_user('Brian', 'R', ['email' => 'brian@example.org', 'password_hash' => 'h']);

        // Same email typed into an Add form: the account is adopted, the
        // typed fields refresh it, nothing throws.
        $person = UserManagement::adoptOrCreatePerson($this->ctx, [
            'first_name' => 'Brian',
            'last_name' => 'Rosenthal',
            'email' => 'BRIAN@example.org',
            'cell_phone' => '914-555-0000',
        ]);
        $this->assertTrue($person['existed']);
        $this->assertSame($existing, (int)$person['id']);
        $user = UserManagement::findById($existing);
        $this->assertSame('Rosenthal', $user['last_name']);
        $this->assertSame('914-555-0000', $user['cell_phone']);

        // A fresh email creates a lightweight person.
        $created = UserManagement::adoptOrCreatePerson($this->ctx, [
            'first_name' => 'Nell', 'last_name' => 'New', 'email' => 'nell@example.org',
        ]);
        $this->assertFalse($created['existed']);
        $this->assertNotSame($existing, (int)$created['id']);
    }

    public function testAdoptOrCreatePersonRestoresSoftDeletedAccount(): void
    {
        $deleted = fx_user('Gone', 'Girl', ['email' => 'gone@example.org', 'is_deleted' => true]);

        $person = UserManagement::adoptOrCreatePerson($this->ctx, [
            'first_name' => 'Gone', 'last_name' => 'Girl', 'email' => 'gone@example.org',
        ]);
        $this->assertTrue($person['existed']);
        $this->assertSame($deleted, (int)$person['id']);
        $this->assertSame(0, (int)UserManagement::findById($deleted)['is_deleted']);
    }

    public function testUpdateProfileSavesTheWholePersonFieldSet(): void
    {
        $id = fx_user('Pam', 'Parent', ['email' => 'pam@example.org', 'password_hash' => 'h']);

        UserManagement::updateProfile(new UserContext($id, false), $id, [
            'first_name' => 'Pam', 'last_name' => 'Parent', 'preferred_name' => 'Pammy',
            'suffix' => 'Jr', 'email' => 'pam@example.org', 'secondary_email' => 'pam2@example.org',
            'cell_phone' => '718-555-0100', 'home_phone' => '718-555-0101',
            'address_street_1' => '1 Grand Concourse', 'address_street_2' => 'Apt 2',
            'address_city' => 'Bronx', 'address_state' => 'NY', 'address_zip' => '10451',
            'emergency_contact_name' => 'Ed Emergency', 'emergency_contact_phone' => '718-555-0102',
            'shirt_size' => 'L',
        ]);

        $user = UserManagement::findById($id);
        $this->assertSame('Pammy', $user['preferred_name']);
        $this->assertSame('pam2@example.org', $user['secondary_email']);
        $this->assertSame('1 Grand Concourse', $user['address_street_1']);
        $this->assertSame('10451', $user['address_zip']);
        $this->assertSame('Ed Emergency', $user['emergency_contact_name']);
        $this->assertSame('L', $user['shirt_size']);

        // Cleared fields come back as NULL rather than empty strings.
        UserManagement::updateProfile(new UserContext($id, false), $id, ['suffix' => '']);
        $this->assertNull(UserManagement::findById($id)['suffix']);
    }

    public function testParentMayUpdateTheirOwnChildsProfile(): void
    {
        $childId = fx_student('Kid', 'One');
        $parentId = fx_parent_of($childId);

        UserManagement::updateProfile(new UserContext($parentId, false), $childId, [
            'cell_phone' => '718-555-0200', 'shirt_size' => 'YM',
        ]);

        $child = UserManagement::findById($childId);
        $this->assertSame('718-555-0200', $child['cell_phone']);
        $this->assertSame('YM', $child['shirt_size']);
    }

    public function testUnrelatedUserMayNotUpdateSomeoneElsesProfile(): void
    {
        $childId = fx_student('Kid', 'Two');
        fx_parent_of($childId);
        $stranger = fx_user('Stan', 'Stranger');

        $this->expectException(RuntimeException::class);
        UserManagement::updateProfile(new UserContext($stranger, false), $childId, [
            'cell_phone' => '718-555-0300',
        ]);
    }
}
