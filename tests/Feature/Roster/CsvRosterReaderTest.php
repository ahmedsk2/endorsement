<?php

namespace Tests\Feature\Roster;

use App\Support\Roster\CsvRosterReader;
use App\Support\Roster\RosterFormatException;
use Tests\TestCase;

/**
 * P1c Task 11 (ST-04, Decision E). `CsvRosterReader` is the only adapter behind
 * `App\Support\Roster\RosterReader` — built on PHP core, no spreadsheet package. The reader never
 * guesses what a column means (`App\Support\Roster\RosterImport`, Task 12, does the mapping); it
 * only answers two questions honestly: what are the headers, and what is in each row.
 */
class CsvRosterReaderTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../fixtures/roster';

    private function reader(string $name): CsvRosterReader
    {
        return new CsvRosterReader(self::FIXTURES.'/'.$name);
    }

    /** @return array<int, array<string, string>> */
    private function allRows(CsvRosterReader $reader): array
    {
        $out = [];
        foreach ($reader->rows() as $line => $row) {
            $out[$line] = $row;
        }

        return $out;
    }

    public function test_headers_are_returned_trimmed_in_file_order(): void
    {
        $headers = $this->reader('clean.csv')->headers();

        $this->assertSame(
            ['Full Name', 'Short Name', 'Email', 'Phone', 'Position', 'Level', 'Joined'],
            $headers,
        );
    }

    /**
     * The BOM attaching itself to the first header is the single most common CSV-import bug —
     * it makes "Full Name" fail to match anything with no visible difference on screen.
     */
    public function test_a_utf8_bom_is_stripped_from_the_first_header_only(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'roster');
        file_put_contents($path, "\xEF\xBB\xBFFull Name,Email\nJane Doe,jane@example.test\n");

        $reader = new CsvRosterReader($path);
        @unlink($path);

        $this->assertSame(['Full Name', 'Email'], $reader->headers());
    }

    public function test_clean_csv_yields_eight_rows_keyed_by_header_text(): void
    {
        $rows = $this->allRows($this->reader('clean.csv'));

        $this->assertCount(8, $rows);

        $first = array_values($rows)[0];
        $this->assertSame('Ahmed Al-Otaibi', $first['Full Name']);
        $this->assertSame('A. Otaibi', $first['Short Name']);
        $this->assertSame('ahmed.otaibi@example.test', $first['Email']);
        $this->assertSame('R1', $first['Level']);
    }

    public function test_messy_headers_csv_yields_the_same_data_as_clean_csv(): void
    {
        $messy = $this->reader('messy-headers.csv');

        $this->assertSame(
            ['full name', 'Short_Name', 'EMAIL', 'phone', 'Position', 'level', 'joined at'],
            $messy->headers(),
            'Headers are trimmed but otherwise returned verbatim — mapping is Task 12\'s job, not the reader\'s.',
        );

        $cleanRows = array_values($this->allRows($this->reader('clean.csv')));
        $messyRows = array_values($this->allRows($messy));

        $this->assertCount(count($cleanRows), $messyRows);

        foreach ($cleanRows as $i => $cleanRow) {
            $messyRow = $messyRows[$i];
            $this->assertSame(array_values($cleanRow), array_values($messyRow));
        }
    }

    public function test_tab_delimited_input_is_detected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'roster');
        file_put_contents($path, "Full Name\tEmail\nJane Doe\tjane@example.test\n");

        $reader = new CsvRosterReader($path);
        $rows = $this->allRows($reader);
        @unlink($path);

        $this->assertSame(['Full Name', 'Email'], $reader->headers());
        $this->assertSame('Jane Doe', array_values($rows)[0]['Full Name']);
        $this->assertSame('jane@example.test', array_values($rows)[0]['Email']);
    }

    public function test_crlf_line_endings_leave_no_trailing_carriage_return(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'roster');
        file_put_contents($path, "Full Name,Email\r\nJane Doe,jane@example.test\r\n");

        $reader = new CsvRosterReader($path);
        $row = array_values($this->allRows($reader))[0];
        @unlink($path);

        $this->assertSame('jane@example.test', $row['Email']);
        $this->assertStringEndsNotWith("\r", $row['Email']);
    }

    public function test_arabic_content_is_byte_identical_to_the_fixture(): void
    {
        $rows = array_values($this->allRows($this->reader('clean.csv')));

        $this->assertSame('نورة الحربي', $rows[2]['Full Name']);
        $this->assertSame('عبدالله القحطاني', $rows[3]['Full Name']);
    }

    /**
     * Excel's plain "CSV" export uses the system codepage, and mojibake imports *successfully*
     * and is then wrong forever. Refusing beats importing garbage (Decision E).
     */
    public function test_a_non_utf8_file_is_refused_with_a_message_naming_the_fix(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'roster');
        // ISO-8859-1: an accented name that is NOT valid UTF-8 once written raw.
        file_put_contents($path, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', "Full Name,Email\nRené Doe,rene@example.test\n"));

        try {
            new CsvRosterReader($path);
            $this->fail('Expected a RosterFormatException for a non-UTF-8 file.');
        } catch (RosterFormatException $e) {
            $this->assertStringContainsString('UTF-8', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    /** The pairing contract `App\Support\Csv::neutralise()` sets: exactly one apostrophe undone. */
    public function test_a_neutralised_cell_is_unneutralised_on_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'roster');
        file_put_contents($path, "Full Name,Notes\nJane Doe,'=SUM(A1)\n");

        $reader = new CsvRosterReader($path);
        $row = array_values($this->allRows($reader))[0];
        @unlink($path);

        $this->assertSame('=SUM(A1)', $row['Notes']);
    }

    /** Over-un-neutralising is its own bug: a real transliteration must survive untouched. */
    public function test_an_apostrophe_not_followed_by_a_dangerous_character_is_untouched(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'roster');
        file_put_contents($path, "Full Name,Notes\n'Abd Doe,'Abd note\n");

        $reader = new CsvRosterReader($path);
        $row = array_values($this->allRows($reader))[0];
        @unlink($path);

        $this->assertSame("'Abd Doe", $row['Full Name']);
        $this->assertSame("'Abd note", $row['Notes']);
    }

    public function test_a_file_over_the_row_cap_throws_rather_than_streaming_forever(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'roster');
        $handle = fopen($path, 'w');
        fwrite($handle, "Full Name,Email\n");
        for ($i = 0; $i <= CsvRosterReader::MAX_ROWS; $i++) {
            fwrite($handle, "Person {$i},person{$i}@example.test\n");
        }
        fclose($handle);

        $reader = new CsvRosterReader($path);

        $this->expectException(RosterFormatException::class);

        try {
            foreach ($reader->rows() as $row) {
                // Drain until the cap throws.
            }
        } finally {
            @unlink($path);
        }
    }
}
