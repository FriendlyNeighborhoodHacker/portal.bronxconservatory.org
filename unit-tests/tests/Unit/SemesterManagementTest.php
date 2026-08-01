<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SemesterManagementTest extends TestCase
{
    private UserContext $ctx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
    }

    public function testCreateAndLabel(): void
    {
        $id = SemesterManagement::createSemester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        $semester = SemesterManagement::find($id);
        $this->assertSame('fall', $semester['season']);
        $this->assertSame(2030, (int)$semester['year']);
        $this->assertSame('Fall 2030', SemesterManagement::label($semester));
        $this->assertTrue(SemesterManagement::hasAnySemester());
    }

    public function testSeasonYearMustBeUnique(): void
    {
        SemesterManagement::createSemester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        $this->expectException(InvalidArgumentException::class);
        SemesterManagement::createSemester($this->ctx, 'fall', 2030, '2030-09-02', '2030-12-21');
    }

    public function testEndBeforeStartRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SemesterManagement::createSemester($this->ctx, 'fall', 2030, '2030-12-20', '2030-09-01');
    }

    public function testNonAdminCannotCreate(): void
    {
        $this->expectException(RuntimeException::class);
        SemesterManagement::createSemester(new UserContext(fx_user('N', 'A'), false), 'fall', 2030, '2030-09-01', '2030-12-20');
    }

    public function testUpdateChangesSeasonYearAndDates(): void
    {
        $id = SemesterManagement::createSemester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        SemesterManagement::updateSemester($this->ctx, $id, 'spring', 2031, '2031-01-15', '2031-05-30');

        $semester = SemesterManagement::find($id);
        $this->assertSame('Spring 2031', SemesterManagement::label($semester));
        $this->assertSame('2031-01-15', $semester['start_date']);
        $this->assertSame('2031-05-30', $semester['end_date']);
    }

    public function testUpdateRejectsDuplicateSeasonYear(): void
    {
        SemesterManagement::createSemester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        $spring = SemesterManagement::createSemester($this->ctx, 'spring', 2031, '2031-01-15', '2031-05-30');

        $this->expectException(InvalidArgumentException::class);
        SemesterManagement::updateSemester($this->ctx, $spring, 'fall', 2030, '2031-01-15', '2031-05-30');
    }

    public function testUpdateRejectsEndBeforeStartAndUnknownSemester(): void
    {
        $id = SemesterManagement::createSemester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        try {
            SemesterManagement::updateSemester($this->ctx, $id, 'fall', 2030, '2030-12-20', '2030-09-01');
            $this->fail('Expected end-before-start to be rejected.');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->expectException(InvalidArgumentException::class);
        SemesterManagement::updateSemester($this->ctx, $id + 999, 'fall', 2031, '2031-09-01', '2031-12-20');
    }

    public function testUpdateDoesNotTouchClassDates(): void
    {
        $setup = fx_semester_with_dates($this->ctx, fx_teacher(), '2030-09-07', 4);
        [$semesterId] = $setup;
        $this->assertCount(4, SemesterManagement::locationDates($semesterId));

        // A range that excludes every class date changes nothing but the row.
        SemesterManagement::updateSemester($this->ctx, $semesterId, 'fall', 2030, '2030-01-01', '2030-01-31');
        $this->assertCount(4, SemesterManagement::locationDates($semesterId));
        $this->assertSame(4, SemesterManagement::countLocationDatesOutsideRange($semesterId, '2030-01-01', '2030-01-31'));
        $this->assertSame(0, SemesterManagement::countLocationDatesOutsideRange($semesterId, '2030-09-01', '2030-12-31'));
    }

    public function testNonAdminCannotUpdate(): void
    {
        $id = SemesterManagement::createSemester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        $this->expectException(RuntimeException::class);
        SemesterManagement::updateSemester(new UserContext(fx_user('N', 'A'), false), $id, 'fall', 2031, '2031-09-01', '2031-12-20');
    }

    public function testDefaultSemesterPrefersCurrentThenFutureThenPast(): void
    {
        $past = SemesterManagement::createSemester($this->ctx, 'fall', 2024, '2024-09-01', '2024-12-20');
        $this->assertSame($past, (int)SemesterManagement::resolveDefaultSemester('2026-01-15')['id']);

        $future = SemesterManagement::createSemester($this->ctx, 'fall', 2026, '2026-09-01', '2026-12-20');
        $this->assertSame($future, (int)SemesterManagement::resolveDefaultSemester('2026-01-15')['id']);

        $current = SemesterManagement::createSemester($this->ctx, 'spring', 2026, '2026-01-05', '2026-05-20');
        $this->assertSame($current, (int)SemesterManagement::resolveDefaultSemester('2026-01-15')['id']);
    }

    public function testTestSeasonIgnoredUnlessNothingElseExists(): void
    {
        $test = SemesterManagement::createSemester($this->ctx, 'test', 2026, '2026-01-01', '2026-12-31');
        // Only a test semester exists: fall back to it rather than nothing.
        $this->assertSame($test, (int)SemesterManagement::resolveDefaultSemester('2026-01-15')['id']);

        // A real (even past) semester beats a current test semester.
        $real = SemesterManagement::createSemester($this->ctx, 'fall', 2024, '2024-09-01', '2024-12-20');
        $this->assertSame($real, (int)SemesterManagement::resolveDefaultSemester('2026-01-15')['id']);
    }

    public function testActiveLocationsDiffApply(): void
    {
        $semesterId = fx_semester($this->ctx);
        $locA = fx_location_id();
        $locB = fx_second_location_id();

        SemesterManagement::setActiveLocations($this->ctx, $semesterId, [$locA, $locB]);
        $this->assertCount(2, SemesterManagement::activeLocations($semesterId));

        SemesterManagement::setActiveLocations($this->ctx, $semesterId, [$locB]);
        $active = SemesterManagement::activeLocations($semesterId);
        $this->assertCount(1, $active);
        $this->assertSame($locB, (int)$active[0]['id']);
    }

    public function testLocationDateUpsertAndWeekdayQueries(): void
    {
        $semesterId = fx_semester($this->ctx);
        $loc = fx_location_id();
        // 2030-09-07 is a Saturday.
        $this->assertSame('6', date('w', strtotime('2030-09-07')));

        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $loc, '2030-09-07', '09:00:00', '17:00:00', 'active', 'Day 1');
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $loc, '2030-09-14', '09:00:00', '17:00:00', 'inactive', 'Holiday Week');
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $loc, '2030-09-08', '10:00:00', '12:00:00', 'active', null); // a Sunday

        $saturdayActive = SemesterManagement::activeDatesForLocationWeekday($semesterId, $loc, 6);
        $this->assertCount(1, $saturdayActive);
        $this->assertSame('2030-09-07', $saturdayActive[0]['date']);

        $saturdayInactive = SemesterManagement::inactiveDatesForLocationWeekday($semesterId, $loc, 6);
        $this->assertCount(1, $saturdayInactive);
        $this->assertSame('Holiday Week', $saturdayInactive[0]['title']);

        // Upsert on the same date updates rather than duplicating.
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $loc, '2030-09-07', '09:30:00', '16:30:00', 'active', 'Day 1b');
        $all = SemesterManagement::locationDates($semesterId, $loc);
        $this->assertCount(3, $all);
        $this->assertSame('09:30:00', $all[0]['start_time']);
    }

    public function testLocationTeachersOrderAndMembership(): void
    {
        $semesterId = fx_semester($this->ctx);
        $loc = fx_location_id();
        $t1 = fx_teacher('Alice', 'Alpha');
        $t2 = fx_teacher('Bob', 'Beta');

        SemesterManagement::setLocationTeachers($this->ctx, $semesterId, [[$loc, $t2], [$loc, $t1]]);
        $columns = SemesterManagement::locationTeachers($semesterId);
        $this->assertCount(2, $columns);
        // Column order follows the order pairs were given.
        $this->assertSame($t2, (int)$columns[0]['teacher_user_id']);
        $this->assertSame($t1, (int)$columns[1]['teacher_user_id']);

        $this->assertTrue(SemesterManagement::isTeacherAtLocation($semesterId, $loc, $t1));
        $this->assertFalse(SemesterManagement::isTeacherAtLocation($semesterId, fx_second_location_id(), $t1));
    }
}
