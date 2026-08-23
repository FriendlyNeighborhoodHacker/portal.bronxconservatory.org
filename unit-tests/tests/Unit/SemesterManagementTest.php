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

    public function testSemesterWideDateListGroupsAndSplits(): void
    {
        $semesterId = fx_semester($this->ctx, 'fall', 2030, '2030-09-01', '2030-12-20');
        $a = fx_location_id();
        $b = fx_second_location_id();
        SemesterManagement::setActiveLocations($this->ctx, $semesterId, [$a, $b]);
        $nameA = (string)pdo()->query("SELECT name FROM locations WHERE id=$a")->fetchColumn();
        $nameB = (string)pdo()->query("SELECT name FROM locations WHERE id=$b")->fetchColumn();

        // Sep 14: same status and title at both locations -> one entry.
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $a, '2030-09-14', '09:00:00', '17:00:00', 'active', 'Day 1');
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $b, '2030-09-14', '09:00:00', '17:00:00', 'active', 'Day 1');
        // Sep 21: same title, different status -> two entries.
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $a, '2030-09-21', '09:00:00', '17:00:00', 'active', 'Day 2');
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $b, '2030-09-21', '09:00:00', '17:00:00', 'inactive', 'Day 2');
        // Sep 28: same status, different title -> two entries.
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $a, '2030-09-28', '09:00:00', '17:00:00', 'active', 'Day 3');
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $b, '2030-09-28', '09:00:00', '17:00:00', 'active', 'Recital');
        // Oct 5: grouped, but the locations keep different hours.
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $a, '2030-10-05', '09:00:00', '17:00:00', 'active', 'Day 4');
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $b, '2030-10-05', '10:00:00', '16:00:00', 'active', 'Day 4');

        $entries = SemesterManagement::locationDatesGroupedForSemester($semesterId);

        // Chronological, with the active listing before the inactive one.
        $this->assertSame(
            ['2030-09-14', '2030-09-21', '2030-09-21', '2030-09-28', '2030-09-28', '2030-10-05'],
            array_column($entries, 'date')
        );

        // Sep 14: consolidated across both locations.
        $this->assertSame('Day 1', $entries[0]['title']);
        $this->assertSame('active', $entries[0]['status']);
        $this->assertSame([$nameA, $nameB], array_column($entries[0]['locations'], 'name'));
        $this->assertTrue($entries[0]['uniform_time']);

        // Sep 21: split by status, one location each.
        $this->assertSame(['active', 'inactive'], [$entries[1]['status'], $entries[2]['status']]);
        $this->assertCount(1, $entries[1]['locations']);
        $this->assertCount(1, $entries[2]['locations']);

        // Sep 28: split by title.
        $this->assertSame(['Day 3', 'Recital'], [$entries[3]['title'], $entries[4]['title']]);

        // Oct 5: grouped, but the differing hours are flagged for the view.
        $this->assertCount(2, $entries[5]['locations']);
        $this->assertFalse($entries[5]['uniform_time']);
    }

    public function testSemesterWideDateListIsEmptyWithoutDates(): void
    {
        $semesterId = fx_semester($this->ctx);
        $this->assertSame([], SemesterManagement::locationDatesGroupedForSemester($semesterId));
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

    public function testLocationWeekdaysReplaceAndCleanup(): void
    {
        $semesterId = fx_semester($this->ctx);
        $locA = fx_location_id();
        $locB = fx_second_location_id();
        SemesterManagement::setActiveLocations($this->ctx, $semesterId, [$locA, $locB], [
            $locA => [[6, '09:00', '17:00']],
            $locB => [[6, '09:00', '17:00'], [2, '15:30', '20:00']],
        ]);

        $this->assertSame([6], array_map('intval',
            array_column(SemesterManagement::locationWeekdays($semesterId, $locA), 'day_of_week')));
        $this->assertSame([2, 6], array_map('intval',
            array_column(SemesterManagement::locationWeekdays($semesterId, $locB), 'day_of_week')));

        // Replace-set: Tuesday goes away when not re-declared.
        SemesterManagement::setLocationWeekdays($this->ctx, $semesterId, $locB, [[6, '10:00', '16:00']]);
        $rows = SemesterManagement::locationWeekdays($semesterId, $locB);
        $this->assertCount(1, $rows);
        $this->assertSame('10:00:00', $rows[0]['start_time']);

        // Removing a location drops its declarations too.
        SemesterManagement::setActiveLocations($this->ctx, $semesterId, [$locA]);
        $this->assertSame([], SemesterManagement::locationWeekdays($semesterId, $locB));

        // Bad input throws.
        $this->expectException(InvalidArgumentException::class);
        SemesterManagement::setLocationWeekdays($this->ctx, $semesterId, $locA, [[6, '17:00', '09:00']]);
    }

    public function testWeekdaysForLocationIsTheUnionOfDeclaredAndDates(): void
    {
        $semesterId = fx_semester($this->ctx);
        $locationId = fx_location_id();
        SemesterManagement::setActiveLocations($this->ctx, $semesterId, [$locationId]);

        // Nothing known yet.
        $this->assertSame([], SemesterManagement::weekdaysForLocation($semesterId, $locationId));

        // Declared Tuesday counts before any dates exist…
        SemesterManagement::setLocationWeekdays($this->ctx, $semesterId, $locationId, [[2, '15:30', '20:00']]);
        $this->assertSame([2], SemesterManagement::weekdaysForLocation($semesterId, $locationId));

        // …and a real date on an undeclared weekday still counts.
        SemesterManagement::upsertLocationDate(
            $this->ctx, $semesterId, $locationId, '2030-09-07', '09:00:00', '17:00:00', 'active', 'Saturday one-off'
        );
        $this->assertSame([2, 6], SemesterManagement::weekdaysForLocation($semesterId, $locationId));
    }

    public function testDayHoursPreferDeclarationsAndUnionAcrossLocations(): void
    {
        $semesterId = fx_semester($this->ctx);
        $locA = fx_location_id();
        $locB = fx_second_location_id();
        SemesterManagement::setActiveLocations($this->ctx, $semesterId, [$locA, $locB]);

        // A declares Saturday 10-4; B has only dates, one of them wider (9-5).
        SemesterManagement::setLocationWeekdays($this->ctx, $semesterId, $locA, [[6, '10:00', '16:00']]);
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locA, '2030-09-07', '08:00:00', '18:00:00', 'active', null);
        SemesterManagement::upsertLocationDate($this->ctx, $semesterId, $locB, '2030-09-07', '09:00:00', '17:00:00', 'active', null);

        $hours = SemesterManagement::dayHoursForSemester($semesterId);
        // A's declaration overrides its own wider date; B's date-derived hours
        // still widen the day for the semester.
        $this->assertSame([9 * 60, 17 * 60], $hours[6]);
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

    public function testColumnsWidenForPairsWithNoAssignment(): void
    {
        $semesterId = fx_semester($this->ctx);
        $loc = fx_location_id();
        $other = fx_second_location_id();
        $assigned = fx_teacher('Alice', 'Alpha');
        $sub = fx_teacher('Sue', 'Substitute');
        SemesterManagement::setLocationTeachers($this->ctx, $semesterId, [[$loc, $assigned]]);
        $columns = SemesterManagement::locationTeachers($semesterId);

        // Nothing missing: the spine is returned untouched.
        $this->assertSame(
            $columns,
            SemesterManagement::locationTeachersIncluding($columns, [
                ['location_id' => $loc, 'teacher_user_id' => $assigned],
            ])
        );

        // A substitute covering at the assigned location, and one covering at
        // a location this semester doesn't use at all.
        $widened = SemesterManagement::locationTeachersIncluding($columns, [
            ['location_id' => $loc, 'teacher_user_id' => $sub],
            ['location_id' => $loc, 'teacher_user_id' => $sub],   // deduped
            ['location_id' => $other, 'teacher_user_id' => $sub],
        ]);
        $this->assertCount(3, $widened);
        $this->assertSame([$assigned, $sub, $sub], array_map('intval', array_column($widened, 'teacher_user_id')));
        // Locations stay contiguous — the grid's header spans depend on it.
        $this->assertSame([$loc, $loc, $other], array_map('intval', array_column($widened, 'location_id')));
        $this->assertSame('Sue', $widened[1]['teacher_first_name']);
        $this->assertTrue($widened[1]['is_extra']);
        $this->assertArrayNotHasKey('is_extra', $widened[0]);
    }
}
