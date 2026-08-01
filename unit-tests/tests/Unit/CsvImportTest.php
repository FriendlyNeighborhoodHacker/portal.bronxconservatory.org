<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CsvImportTest extends TestCase
{
    public function testParseCsvWithQuotesAndBlankLines(): void
    {
        $parsed = CsvImport::parseCsv("Name,Email\n\n\"Lopez, Ana\",ana@example.org\nBo,bo@example.org\n");
        $this->assertSame(['Name', 'Email'], $parsed['headers']);
        $this->assertSame([['Lopez, Ana', 'ana@example.org'], ['Bo', 'bo@example.org']], $parsed['rows']);
    }

    public function testParseTabDelimited(): void
    {
        $parsed = CsvImport::parseCsv("Name\tEmail\nAna\tana@example.org", "\t");
        $this->assertSame([['Ana', 'ana@example.org']], $parsed['rows']);
    }

    public function testShortAndLongRowsArePadded(): void
    {
        $parsed = CsvImport::parseCsv("A,B,C\n1,2\n1,2,3,4");
        $this->assertSame([['1', '2', ''], ['1', '2', '3']], $parsed['rows']);
    }

    public function testEmptyInputRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CsvImport::parseCsv("  \n ");
    }

    public function testSuggestMappingMatchesLabelsAndFieldNames(): void
    {
        $fields = TeacherCsvImport::targetFields();
        $mapping = CsvImport::suggestColumnMapping(
            ['First Name', 'LAST_NAME', 'E-mail', 'Cell Phone Number', 'Mystery Column'],
            $fields
        );
        // Punctuation-insensitive: "E-mail" still maps to email.
        $this->assertSame(['first_name', 'last_name', 'email', 'cell_phone', ''], $mapping);
    }

    public function testSuggestMappingNeverReusesAField(): void
    {
        $mapping = CsvImport::suggestColumnMapping(['Email', 'email'], TeacherCsvImport::targetFields());
        $this->assertSame(['email', ''], $mapping);
    }

    public function testApplyMappingDropsIgnoredColumns(): void
    {
        $rows = [['Ana', 'x', 'ana@example.org']];
        $out = CsvImport::applyMapping($rows, ['first_name', '', 'email']);
        $this->assertSame([['first_name' => 'Ana', 'email' => 'ana@example.org']], $out);
    }
}
