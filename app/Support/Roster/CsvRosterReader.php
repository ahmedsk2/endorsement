<?php

namespace App\Support\Roster;

use SplFileObject;

/**
 * ST-04's CSV/TSV adapter (P1c Decision E), built on PHP core — `SplFileObject`, nothing else.
 * There is no spreadsheet package in composer.lock and adding one to a system holding children's
 * PHI is the owner's supply-chain decision, not a developer's (P1c finding 8). xlsx becomes one
 * class implementing {@see RosterReader} plus one composer line the day the owner says so.
 *
 * ENCODING IS REFUSED, NOT MANGLED. Excel's plain "CSV" export uses the system codepage and
 * turns Arabic names into mojibake that imports *successfully* and is then wrong forever — the
 * whole file is checked against UTF-8 before a single row is parsed, and a non-UTF-8 file is
 * refused with a message naming the fix rather than imported as garbage.
 *
 * PAIRED WITH `App\Support\Csv` (Decision F): a cell beginning with one of `Csv`'s six dangerous
 * prefixes gets a single leading apostrophe on export, and THIS reader strips exactly that one
 * apostrophe back off, so export -> re-import is lossless (`CsvInjectionTest`'s pairing case).
 * A cell that begins with an apostrophe NOT followed by a dangerous character is left alone —
 * `'Abd` is a real transliteration, not a neutralised formula.
 *
 * Headers are trimmed and returned VERBATIM — this reader never guesses what a column means.
 * `App\Support\Roster\RosterImport` (Task 12) is what maps header text onto a destination field.
 */
final class CsvRosterReader implements RosterReader
{
    /** A department roster is tens of staff; two thousand is a paste accident or a wrong file. */
    public const MAX_ROWS = 2000;

    private const DELIMITER_CANDIDATES = [',', "\t", ';'];

    /** Mirrors `App\Support\Csv::DANGEROUS_PREFIXES` — the pairing this reader undoes. */
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    private const BOM = "\xEF\xBB\xBF";

    /** @var list<string> */
    private readonly array $headers;

    private readonly string $delimiter;

    public function __construct(private readonly string $path)
    {
        $contents = (string) file_get_contents($path);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new RosterFormatException(
                'This file is not UTF-8. In Excel use File → Save As → CSV UTF-8; '
                .'a plain CSV export writes Arabic names in a way this system cannot read correctly.'
            );
        }

        $firstLine = strtok($contents, "\r\n");
        $headerLine = $firstLine === false ? '' : $firstLine;

        if (str_starts_with($headerLine, self::BOM)) {
            $headerLine = substr($headerLine, strlen(self::BOM));
        }

        $this->delimiter = self::sniffDelimiter($headerLine);
        // `escape: ''` (review minor 11, paired with `App\Support\Csv`'s own fix): PHP 8.4
        // deprecates the implicit `\` escape default, and that default actively corrupts a cell
        // ending in a backslash by reading past the field's closing quote. RFC 4180 has no escape
        // character at all — only doubled enclosures — which this reader relies on regardless.
        $this->headers = array_map(
            fn (string $h): string => self::unNeutralise(trim($h)),
            str_getcsv($headerLine, $this->delimiter, escape: ''),
        );
    }

    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Rows are yielded in file order with plain sequential keys (0, 1, 2, ...) —
     * `App\Support\Roster\RosterImport` (Task 12) is what turns a row's position into a
     * human-facing "line N" for a validation report; the reader itself makes no claim about
     * line numbers, only about order.
     */
    public function rows(): iterable
    {
        $file = new SplFileObject($this->path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        // `escape: ''` — see the constructor's own comment on this same fix (review minor 11).
        $file->setCsvControl($this->delimiter, escape: '');

        $lineNumber = 0;
        $dataRows = 0;

        foreach ($file as $cells) {
            $lineNumber++;

            if ($lineNumber === 1 || $cells === false || $cells === null || $cells === [null]) {
                // Line 1 is the header, already parsed in the constructor. `false`/`[null]` is
                // SplFileObject's own end-of-file phantom read past the last real line — a
                // trailing newline at EOF is normal, not an extra blank row.
                continue;
            }

            $dataRows++;

            if ($dataRows > self::MAX_ROWS) {
                throw new RosterFormatException(
                    'This file has more than '.self::MAX_ROWS.' rows. That is a paste accident or '
                    .'the wrong file — a department roster is at most a few hundred names.'
                );
            }

            $row = [];
            foreach ($this->headers as $i => $header) {
                $value = $cells[$i] ?? '';
                $row[$header] = self::unNeutralise(rtrim((string) $value, "\r"));
            }

            yield $row;
        }
    }

    /** Whichever of `,` `\t` `;` yields the most fields on the header line. */
    private static function sniffDelimiter(string $headerLine): string
    {
        $best = ',';
        $bestCount = -1;

        foreach (self::DELIMITER_CANDIDATES as $candidate) {
            $count = count(str_getcsv($headerLine, $candidate, escape: ''));

            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Decision F's pairing: strip exactly one leading apostrophe ahead of a dangerous prefix.
     *
     * NOT a true inverse of `App\Support\Csv::neutralise()` (review minor 13): a value that was
     * GENUINELY typed starting with an apostrophe then a dangerous character (`'=90kg`) is
     * indistinguishable here from one this system's own export added, so it is stripped either
     * way. Not an execution risk — see `Csv`'s own docblock for the full reasoning and
     * `CsvInjectionTest` for the pinned case — but a re-import is not perfectly lossless for
     * this one genuine shape.
     */
    private static function unNeutralise(string $value): string
    {
        if ($value === '' || $value[0] !== "'") {
            return $value;
        }

        $next = $value[1] ?? '';

        return in_array($next, self::DANGEROUS_PREFIXES, true) ? substr($value, 1) : $value;
    }
}
