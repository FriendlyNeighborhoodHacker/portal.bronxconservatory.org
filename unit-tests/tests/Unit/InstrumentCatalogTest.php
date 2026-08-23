<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class InstrumentCatalogTest extends TestCase
{
    private UserContext $ctx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->ctx = fx_admin_ctx();
    }

    private function idsByName(array $names): array
    {
        $byName = array_column(InstrumentCatalog::all(), 'id', 'name');
        return array_map(fn(string $name) => (int)$byName[$name], $names);
    }

    public function testLikelyLessonInstrumentsPrefersTheOverlap(): void
    {
        $teacher = fx_teacher();
        $student = fx_student();
        InstrumentCatalog::setTeacherInstruments($this->ctx, $teacher, $this->idsByName(['Piano', 'Violin']));
        InstrumentCatalog::setStudentInstruments($this->ctx, $student, $this->idsByName(['Violin', 'Cello']));

        $this->assertSame(['Violin'], InstrumentCatalog::likelyLessonInstruments($teacher, $student));
    }

    public function testLikelyLessonInstrumentsFallsBackTeacherThenStudent(): void
    {
        $teacher = fx_teacher();
        $student = fx_student();

        // No overlap: the teacher's own list wins.
        InstrumentCatalog::setTeacherInstruments($this->ctx, $teacher, $this->idsByName(['Piano']));
        InstrumentCatalog::setStudentInstruments($this->ctx, $student, $this->idsByName(['Cello']));
        $this->assertSame(['Piano'], InstrumentCatalog::likelyLessonInstruments($teacher, $student));

        // Teacher has none recorded: the student's list.
        InstrumentCatalog::setTeacherInstruments($this->ctx, $teacher, []);
        $this->assertSame(['Cello'], InstrumentCatalog::likelyLessonInstruments($teacher, $student));

        // Neither has any: empty.
        InstrumentCatalog::setStudentInstruments($this->ctx, $student, []);
        $this->assertSame([], InstrumentCatalog::likelyLessonInstruments($teacher, $student));
    }
}
