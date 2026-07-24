<?php

namespace App\Support;

/**
 * Data-quality validation for the admission/patient entry forms — a 1:1 port of the legacy
 * repo-root `validate.php` (and its `tests/validate_test.php`). The legacy forms accept
 * garbage a registry must reject: ht/wt of 0 or 11111, a zoo of missing-value tokens
 * ("-", "nill", "NaN", "> max", "NULL", "") that should all collapse to null, and a
 * discharge date preceding admission (negative length-of-stay).
 *
 * SCOPE: demographics/vitals plausibility only. It deliberately does NOT touch the
 * PIM-3/PRISM-3 score inputs or unit-explicit labs (those are the deferred Scores step).
 *
 * Pure functions, no I/O — unit-tested in tests/Unit/PlausibilityTest.php.
 */
class Plausibility
{
    /**
     * Inclusive plausibility ranges for the non-score demographic/vitals fields, sourced from
     * the data dictionary. An out-of-range value is data-entry garbage (coerced to null),
     * never stored verbatim.
     *
     *   ht 30-250 cm   wt 0.3-150 kg   temp 25-43 C   HR 0-300 bpm   SBP 20-300 mmHg
     *
     * @var array<string, array{0: float, 1: float}>
     */
    public const RANGES = [
        'ht' => [30.0, 250.0],
        'wt' => [0.3, 150.0],
        'temp' => [25.0, 43.0],
        'hr' => [0.0, 300.0],
        'sbp' => [20.0, 300.0],
    ];

    /**
     * Maps the normalized `admissions` columns to their legacy range key. Only these two
     * demographic columns live on `admissions`; temp/HR/SBP belong to the deferred score
     * inputs, so they are range-checked elsewhere (their ranges are kept here for parity).
     *
     * @var array<string, string>
     */
    public const FIELD_RANGE_KEYS = [
        'height_cm' => 'ht',
        'weight_kg' => 'wt',
    ];

    /**
     * Recognised missing-value tokens (lower-cased) that collapse to null.
     *
     * @var list<string>
     */
    private const MISSING_TOKENS = ['', '-', 'nill', 'nil', 'nan', '> max', 'null'];

    /**
     * Trim a raw form value and map any recognised missing-value token to null; a genuine
     * value is returned trimmed.
     */
    public static function cleanMissing(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return in_array(strtolower($trimmed), self::MISSING_TOKENS, true) ? null : $trimmed;
    }

    /**
     * True if $value is numeric and falls within the inclusive [$min, $max] range.
     * Non-numeric input (including a null that survived cleanMissing) is rejected.
     */
    public static function validRange(mixed $value, float $min, float $max): bool
    {
        if (! is_numeric($value)) {
            return false;
        }

        $number = (float) $value;

        return $number >= $min && $number <= $max;
    }

    /**
     * True if the discharge date is empty OR on/after the admission date. Either date being
     * empty is "nothing to compare" and passes; guards against negative length-of-stay.
     */
    public static function plausibleDates(?string $admit, ?string $discharge): bool
    {
        if (empty($discharge) || empty($admit)) {
            return true;
        }

        return strtotime($discharge) >= strtotime($admit);
    }

    /**
     * The inclusive range tuple for a known `admissions` column, or null if the column is not
     * range-checked here.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function rangeForColumn(string $column): ?array
    {
        $key = self::FIELD_RANGE_KEYS[$column] ?? null;

        return $key === null ? null : self::RANGES[$key];
    }
}
