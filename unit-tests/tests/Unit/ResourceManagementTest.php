<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ResourceManagementTest extends TestCase
{
    private UserContext $ctx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
    }

    private function makeLesson(): array
    {
        $teacher = fx_teacher();
        $student = fx_student();
        $setup = fx_semester_with_dates($this->ctx, $teacher, '2030-09-07', 2);
        [$semesterId, $locationId, , $dayOfWeek] = $setup;
        ReservationManagement::createReservation($this->ctx, [
            'semester_id' => $semesterId,
            'teacher_user_id' => $teacher,
            'location_id' => $locationId,
            'student_user_id' => $student,
            'day_of_week' => $dayOfWeek,
            'start_time' => '10:00',
            'status' => 'confirmed',
        ]);
        $lessonIds = array_map('intval', array_column(
            pdo()->query('SELECT id FROM lessons ORDER BY start_datetime')->fetchAll(), 'id'
        ));
        return [$teacher, $student, $semesterId, $lessonIds];
    }

    public function testFileAndLinkResourcesWithPermissions(): void
    {
        [$teacher, $student, $semesterId, $lessonIds] = $this->makeLesson();
        $teacherCtx = new UserContext($teacher, false);

        $fileId = ResourceManagement::addFileResource($teacherCtx, $lessonIds[0], 'Scales PDF', fx_uploaded_pdf());
        $linkId = ResourceManagement::addLinkResource($teacherCtx, $lessonIds[1], 'Practice video', 'https://example.org/v');

        $parent = fx_parent_of($student);
        $stranger = fx_user('Randy', 'Random');
        foreach ([$fileId, $linkId] as $resourceId) {
            $this->assertTrue(ResourceManagement::canUserDownload($student, $resourceId));
            $this->assertTrue(ResourceManagement::canUserDownload($parent, $resourceId));
            $this->assertTrue(ResourceManagement::canUserDownload($teacher, $resourceId));
            $this->assertFalse(ResourceManagement::canUserDownload($stranger, $resourceId));
        }

        // My Materials: chronological by lesson date.
        $materials = ResourceManagement::resourcesForStudentInSemester($student, $semesterId);
        $this->assertSame(['Scales PDF', 'Practice video'], array_column($materials, 'title'));
        $this->assertSame('file', $materials[0]['resource_type']);
        $this->assertSame('link', $materials[1]['resource_type']);
    }

    public function testOnlyTheLessonsTeacherMayAdd(): void
    {
        [, , , $lessonIds] = $this->makeLesson();
        $other = fx_teacher('Olga', 'Other');
        $this->expectException(RuntimeException::class);
        ResourceManagement::addLinkResource(new UserContext($other, false), $lessonIds[0], 'X', 'https://example.org');
    }

    public function testLinkMustBeHttp(): void
    {
        [$teacher, , , $lessonIds] = $this->makeLesson();
        $this->expectException(InvalidArgumentException::class);
        ResourceManagement::addLinkResource(new UserContext($teacher, false), $lessonIds[0], 'X', 'javascript:alert(1)');
    }

    /**
     * canUserDelete() is what the Edit resources modal asks before drawing a
     * Remove tickbox, so it has to agree with deleteResource() exactly —
     * otherwise the UI offers a control that fails on save.
     */
    public function testCanUserDeleteMatchesWhoMayActuallyDelete(): void
    {
        [$teacher, , , $lessonIds] = $this->makeLesson();
        $teacherCtx = new UserContext($teacher, false);
        $resourceId = ResourceManagement::addLinkResource($teacherCtx, $lessonIds[0], 'X', 'https://example.org');
        $resource = ResourceManagement::find($resourceId);

        $other = fx_teacher('Olga', 'Other');
        $this->assertTrue(ResourceManagement::canUserDelete($teacherCtx, $resource), 'its uploader may');
        $this->assertTrue(ResourceManagement::canUserDelete($this->ctx, $resource), 'an admin may');
        $this->assertFalse(ResourceManagement::canUserDelete(new UserContext($other, false), $resource));
        $this->assertFalse(ResourceManagement::canUserDelete(null, $resource), 'logged out may not');

        // Whoever canUserDelete() refuses, deleteResource() must refuse too.
        $this->expectException(RuntimeException::class);
        ResourceManagement::deleteResource(new UserContext($other, false), $resourceId);
    }

    /** The edit rows carry the uploader's name, so the query must join it. */
    public function testResourcesForLessonIncludesUploaderName(): void
    {
        [$teacher, , , $lessonIds] = $this->makeLesson();
        ResourceManagement::addLinkResource(new UserContext($teacher, false), $lessonIds[0], 'X', 'https://example.org');

        $rows = ResourceManagement::resourcesForLesson($lessonIds[0]);
        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('uploader_first_name', $rows[0]);
        $this->assertNotSame('', trim((string)$rows[0]['uploader_first_name'] . (string)$rows[0]['uploader_last_name']));
    }

    public function testDeleteResourceRules(): void
    {
        [$teacher, , , $lessonIds] = $this->makeLesson();
        $teacherCtx = new UserContext($teacher, false);
        $resourceId = ResourceManagement::addLinkResource($teacherCtx, $lessonIds[0], 'X', 'https://example.org');

        $other = fx_teacher('Olga', 'Other');
        try {
            ResourceManagement::deleteResource(new UserContext($other, false), $resourceId);
            $this->fail('Expected denial.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('added a material', $e->getMessage());
        }

        ResourceManagement::deleteResource($teacherCtx, $resourceId);
        $this->assertNull(ResourceManagement::find($resourceId));
    }
}
