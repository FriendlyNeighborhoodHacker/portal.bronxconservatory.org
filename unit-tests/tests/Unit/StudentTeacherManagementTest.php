<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The keyword/teacher/instrument filters behind the admin Students and
 * Teachers lists. These run against real prepared statements (the test PDO,
 * like production, has PDO::ATTR_EMULATE_PREPARES off), so they also cover
 * how the search clauses bind their placeholders.
 */
final class StudentTeacherManagementTest extends TestCase
{
    protected function setUp(): void
    {
        test_reset_all();
    }

    /** @return int[] ids of the matched students */
    private static function studentIds(string $keyword, ?int $teacherId = null, ?int $instrumentId = null, ?int $semesterId = null): array
    {
        $rows = StudentTeacherManagement::listStudentsFiltered($keyword, $teacherId, $instrumentId, $semesterId);
        return array_map(fn($r) => (int)$r['id'], $rows);
    }

    public function testStudentKeywordMatchesNamePrefixes(): void
    {
        $marco = fx_student('Marco', 'Reyes');
        $other = fx_student('Nina', 'Alvarez');

        $this->assertSame([$marco], self::studentIds('marc'));
        $this->assertSame([$other], self::studentIds('alva'));
        $this->assertSame([], self::studentIds('zzz'));

        // No keyword: everyone, each row carrying its parents and instruments.
        $all = StudentTeacherManagement::listStudentsFiltered('');
        $this->assertCount(2, $all);
        $this->assertSame([], $all[0]['instruments']);
        $this->assertSame([], $all[0]['parents']);
    }

    public function testStudentKeywordMatchesParentAndPhoneAndAddress(): void
    {
        $child = fx_student('Kid', 'Suzuki');
        fx_parent_of($child, 'Marco', 'Suzuki');
        fx_student('Nina', 'Alvarez');

        $this->assertSame([$child], self::studentIds('marco'));

        // Tokens match as prefixes, so the phone and the street both search
        // from their first character.
        pdo()->exec("UPDATE users SET cell_phone = '9175550123', address_street_1 = '12 Grand Concourse' WHERE id = $child");
        $this->assertSame([$child], self::studentIds('917555'));
        $this->assertSame([$child], self::studentIds('12'));
        $this->assertSame([], self::studentIds('grand'));
    }

    public function testStudentKeywordTokensAllMustMatch(): void
    {
        $marco = fx_student('Marco', 'Reyes');
        fx_student('Marco', 'Alvarez');

        $this->assertSame([$marco], self::studentIds('marco rey'));
        $this->assertSame([], self::studentIds('marco nobody'));
    }

    public function testStudentFiltersByInstrumentAndTeacher(): void
    {
        $ctx = fx_admin_ctx();
        $violin = (int)InstrumentCatalog::findByName('Violin')['id'];
        $cello = (int)InstrumentCatalog::findByName('Cello')['id'];

        $marco = fx_student('Marco', 'Reyes');
        $nina = fx_student('Nina', 'Alvarez');
        InstrumentCatalog::setStudentInstruments($ctx, $marco, [$violin]);
        InstrumentCatalog::setStudentInstruments($ctx, $nina, [$cello]);

        $this->assertSame([$marco], self::studentIds('', null, $violin));
        $this->assertSame([$marco], self::studentIds('marco', null, $violin));
        $this->assertSame([], self::studentIds('marco', null, $cello));

        $teacher = fx_teacher('Tess', 'Teacher');
        $semesterId = fx_semester($ctx);
        $locationId = fx_location_id();
        pdo()->exec(
            "INSERT INTO semester_lesson_reservations
               (semester_id, student_user_id, teacher_user_id, location_id, status, day_of_week, start_time)
             VALUES ($semesterId, $marco, $teacher, $locationId, 'confirmed', 1, '15:00:00')"
        );

        $this->assertSame([$marco], self::studentIds('', $teacher, null, $semesterId));
        $this->assertSame([$marco], self::studentIds('marco', $teacher, $violin, $semesterId));
        $this->assertSame([], self::studentIds('nina', $teacher, null, $semesterId));
        // A different semester has no reservation with that teacher.
        $this->assertSame([], self::studentIds('', $teacher, null, $semesterId + 1000));
    }

    public function testStudentFilterSkipsDeletedUsers(): void
    {
        $marco = fx_student('Marco', 'Reyes');
        pdo()->exec("UPDATE users SET is_deleted = 1 WHERE id = $marco");
        $this->assertSame([], self::studentIds('marco'));
    }

    public function testTypeaheadsFindPeopleByTheirWholeName(): void
    {
        $devon = fx_student('Devon', 'Brown');
        fx_student('Devon', 'Okafor');
        $marisol = fx_teacher('Marisol', 'Vega');
        $denise = fx_parent_of($devon, 'Denise', 'Brown');

        $ids = fn(array $rows) => array_map(fn($r) => (int)$r['id'], $rows);

        // Typing the whole name used to find nobody: no column holds both words.
        $this->assertSame([$devon], $ids(StudentTeacherManagement::searchStudentsByNamePrefix('Devon Brown')));
        $this->assertSame([$marisol], $ids(StudentTeacherManagement::searchTeachersByNamePrefix('Marisol Vega')));
        $this->assertSame([$denise], $ids(StudentTeacherManagement::searchPeopleForParentLink('Denise Brown')));

        // A first name alone still finds everyone who has it.
        $this->assertCount(2, StudentTeacherManagement::searchStudentsByNamePrefix('Devon'));
        // Words are prefixes here — you are typing a name from its start.
        $this->assertSame([], StudentTeacherManagement::searchStudentsByNamePrefix('evon'));
        $this->assertSame([], StudentTeacherManagement::searchStudentsByNamePrefix('Devon Nobody'));
    }

    public function testAParentStaysFindableAfterTheirLastChildIsUnlinked(): void
    {
        $ctx = fx_admin_ctx();
        $child = fx_student('Devon', 'Brown');
        $parent = fx_parent_of($child, 'Denise', 'Brown');

        $ids = fn(string $q, ?int $forChild = null) => array_map(
            fn($r) => (int)$r['id'],
            StudentTeacherManagement::searchPeopleForParentLink($q, $forChild)
        );

        // Unlinking the only child is what used to make a parent unfindable:
        // being a parent is a parenthood row, and they no longer had one.
        StudentTeacherManagement::unlinkParentChild($ctx, $parent, $child);
        $this->assertSame([], StudentTeacherManagement::parentsOfStudent($child));
        $this->assertSame([$parent], $ids('deni'));

        // ...so they can be linked straight back.
        StudentTeacherManagement::linkParentChild($ctx, $parent, $child, 'mother');
        $this->assertSame([$parent], array_map(fn($r) => (int)$r['id'],
            StudentTeacherManagement::parentsOfStudent($child)));
    }

    public function testParentNamesComeBackPerStudentInOneLookup(): void
    {
        $withTwo = fx_student('Kid', 'Reyes');
        fx_parent_of($withTwo, 'Marco', 'Reyes');
        fx_parent_of($withTwo, 'Ana', 'Reyes');
        $withOne = fx_student('Nina', 'Alvarez');
        fx_parent_of($withOne, 'Pat', 'Alvarez');
        $orphan = fx_student('Solo', 'Adult');

        $names = StudentTeacherManagement::parentNamesByStudent([$withTwo, $withOne, $orphan, $withTwo]);

        $this->assertSame(['Ana Reyes', 'Marco Reyes'], $names[$withTwo]);
        $this->assertSame(['Pat Alvarez'], $names[$withOne]);
        // A student with nobody on file is simply absent from the map.
        $this->assertArrayNotHasKey($orphan, $names);
        $this->assertSame([], StudentTeacherManagement::parentNamesByStudent([]));
    }

    public function testParentLinkSearchOffersAnyoneExceptWhoIsAlreadyLinked(): void
    {
        $child = fx_student('Devon', 'Devlin');
        $linked = fx_parent_of($child, 'Denise', 'Devlin');
        $teacher = fx_teacher('Dora', 'Devlin');
        $stranger = fx_user('Dana', 'Devlin');

        $ids = fn(string $q, ?int $forChild = null) => array_map(
            fn($r) => (int)$r['id'],
            StudentTeacherManagement::searchPeopleForParentLink($q, $forChild)
        );

        // Without a child in hand: everyone matching, parents first.
        $this->assertSame($linked, $ids('dev')[0]);
        $this->assertContains($teacher, $ids('dev'));   // a teacher can be a parent too
        $this->assertContains($stranger, $ids('dev'));
        // Devon is somebody's child, so he is never offered as a parent —
        // not even to a different family.
        $this->assertNotContains($child, $ids('dev'));

        // For this child: the child themselves and their current parents drop out.
        $offered = $ids('dev', $child);
        $this->assertNotContains($linked, $offered);
        $this->assertNotContains($child, $offered);
        $this->assertSame([$stranger, $teacher], $offered);

        // Roles come back so the admin can tell two Devlins apart.
        $rows = StudentTeacherManagement::searchPeopleForParentLink('deni');
        $this->assertSame(1, (int)$rows[0]['is_parent']);
        $this->assertSame(0, (int)$rows[0]['is_teacher']);
    }

    public function testTeacherKeywordAndInstrumentFilters(): void
    {
        $ctx = fx_admin_ctx();
        $violin = (int)InstrumentCatalog::findByName('Violin')['id'];

        $marco = fx_teacher('Marco', 'Reyes');
        fx_teacher('Nina', 'Alvarez');
        InstrumentCatalog::setTeacherInstruments($ctx, $marco, [$violin]);
        pdo()->exec("UPDATE users SET email = 'marco@example.com' WHERE id = $marco");

        $ids = fn(...$a) => array_map(fn($r) => (int)$r['id'], StudentTeacherManagement::listTeachersFiltered(...$a));

        $this->assertSame([$marco], $ids('marco'));
        $this->assertSame([$marco], $ids('marco@ex'));
        $this->assertSame([$marco], $ids('marco rey', $violin));
        $this->assertSame([$marco], $ids('', $violin));
        $this->assertCount(2, $ids(''));
        $this->assertSame([], $ids('nina', $violin));
    }
}
