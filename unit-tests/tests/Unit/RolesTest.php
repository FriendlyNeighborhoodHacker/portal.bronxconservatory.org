<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    protected function setUp(): void
    {
        test_reset_all();
    }

    private function makeUser(bool $admin = false): int
    {
        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash, is_admin) VALUES ('U', 'Ser', 'h', " . ($admin ? 1 : 0) . ')');
        return (int)pdo()->lastInsertId();
    }

    public function testRolesDeriveFromProfileRows(): void
    {
        $plain = $this->makeUser();
        $this->assertSame([], Application::rolesForUser($plain));

        $admin = $this->makeUser(true);
        $this->assertSame(['admin'], Application::rolesForUser($admin));

        $teacher = $this->makeUser();
        pdo()->exec('INSERT INTO teacher_profiles (user_id) VALUES (' . $teacher . ')');
        $this->assertSame(['teacher'], Application::rolesForUser($teacher));

        $student = $this->makeUser();
        pdo()->exec('INSERT INTO student_profiles (user_id) VALUES (' . $student . ')');
        $this->assertSame(['student'], Application::rolesForUser($student));

        $parent = $this->makeUser();
        pdo()->exec('INSERT INTO parenthood (parent_user_id, child_user_id) VALUES (' . $parent . ',' . $student . ')');
        $this->assertSame(['parent'], Application::rolesForUser($parent));
    }

    public function testAdultStudentParentHoldsBothRoles(): void
    {
        $child = $this->makeUser();
        pdo()->exec('INSERT INTO student_profiles (user_id) VALUES (' . $child . ')');

        // A parent who also takes lessons themselves (the "I am the student"
        // registration checkbox) is both parent and student, in routing
        // priority order.
        $adult = $this->makeUser();
        pdo()->exec('INSERT INTO parenthood (parent_user_id, child_user_id) VALUES (' . $adult . ',' . $child . ')');
        pdo()->exec('INSERT INTO student_profiles (user_id) VALUES (' . $adult . ')');
        $this->assertSame(['parent', 'student'], Application::rolesForUser($adult));
    }
}
