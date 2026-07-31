<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ResourceManagementTest extends TestCase
{
    private UserContext $adminCtx;
    private int $teacherId;
    private int $studentId;
    private int $parentId;
    private int $strangerId;

    protected function setUp(): void
    {
        test_reset_all();

        pdo()->exec("INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at) VALUES ('Admin', 'A', 'admin@example.com', 'h', 1, NOW())");
        $this->adminCtx = new UserContext((int)pdo()->lastInsertId(), true);
        UserContext::set($this->adminCtx);

        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Tina', 'Teacher', 'h')");
        $this->teacherId = (int)pdo()->lastInsertId();
        pdo()->exec('INSERT INTO teacher_profiles (user_id) VALUES (' . $this->teacherId . ')');

        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Sofia', 'Student', '')");
        $this->studentId = (int)pdo()->lastInsertId();
        pdo()->exec('INSERT INTO student_profiles (user_id) VALUES (' . $this->studentId . ')');

        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Maria', 'Parent', 'h')");
        $this->parentId = (int)pdo()->lastInsertId();
        pdo()->exec('INSERT INTO parenthood (parent_user_id, child_user_id) VALUES (' . $this->parentId . ',' . $this->studentId . ')');

        pdo()->exec("INSERT INTO users (first_name, last_name, password_hash) VALUES ('Stranger', 'S', 'h')");
        $this->strangerId = (int)pdo()->lastInsertId();
    }

    // Insert a resource row directly (the multipart upload path is exercised
    // manually; storeUploadedPrivateFile needs a real $_FILES upload).
    private function insertResource(?int $lessonId, ?int $studentUserId, int $uploadedBy): int
    {
        $fileId = Files::insertPrivateFile('fake-mp3-bytes', 'audio/mpeg', 'week3.mp3', $uploadedBy);
        pdo()->prepare('INSERT INTO lesson_resources (lesson_id, student_user_id, title, private_file_id, uploaded_by_user_id) VALUES (?,?,?,?,?)')
            ->execute([$lessonId, $studentUserId, 'Week 3 recording', $fileId, $uploadedBy]);
        return (int)pdo()->lastInsertId();
    }

    public function testDownloadAuthorizationMatrix(): void
    {
        $resourceId = $this->insertResource(null, $this->studentId, $this->teacherId);

        $this->assertTrue(ResourceManagement::canUserDownload($this->adminCtx->id, $resourceId), 'admin');
        $this->assertTrue(ResourceManagement::canUserDownload($this->teacherId, $resourceId), 'uploader');
        $this->assertTrue(ResourceManagement::canUserDownload($this->studentId, $resourceId), 'student');
        $this->assertTrue(ResourceManagement::canUserDownload($this->parentId, $resourceId), 'parent');
        $this->assertFalse(ResourceManagement::canUserDownload($this->strangerId, $resourceId), 'stranger');
    }

    public function testLessonAttachedResourceFollowsLessonVisibility(): void
    {
        $lessonId = LessonManagement::createLesson($this->adminCtx, [
            'lesson_type' => 'individual',
            'teacher_user_id' => $this->teacherId,
            'student_user_id' => $this->studentId,
            'start_datetime' => '2026-08-01 09:00:00',
        ]);
        $resourceId = $this->insertResource($lessonId, null, $this->teacherId);

        $this->assertTrue(ResourceManagement::canUserDownload($this->parentId, $resourceId), 'parent via lesson');
        $this->assertFalse(ResourceManagement::canUserDownload($this->strangerId, $resourceId), 'stranger via lesson');
    }

    public function testResourcesForStudentIncludesDirectAndGroupLesson(): void
    {
        // Direct attachment.
        $this->insertResource(null, $this->studentId, $this->teacherId);

        // Group-lesson attachment where the student is on the roster.
        $groupLessonId = LessonManagement::createLesson($this->adminCtx, [
            'lesson_type' => 'group',
            'name' => 'Ensemble',
            'teacher_user_id' => $this->teacherId,
            'start_datetime' => '2026-08-01 10:00:00',
            'student_user_ids' => [$this->studentId],
        ]);
        $this->insertResource($groupLessonId, null, $this->teacherId);

        $this->assertCount(2, ResourceManagement::resourcesForStudent($this->studentId));
        $this->assertCount(0, ResourceManagement::resourcesForStudent($this->strangerId));
    }

    public function testMissingResourceDeniesDownload(): void
    {
        $this->assertFalse(ResourceManagement::canUserDownload($this->adminCtx->id, 99999));
    }
}
