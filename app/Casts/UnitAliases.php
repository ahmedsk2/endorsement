<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Munawib UN-03: alias names normalise imports (typo tolerance) **while preserving source
 * data**. Both halves of that sentence are load-bearing here.
 *
 *  - PRESERVED: the stored value is exactly what the administrator typed, minus surrounding
 *    whitespace. "Paeds ICU" comes back "Paeds ICU", not "PAEDS ICU" — the alias list is read
 *    by humans on the units screen and a normalised store would make it unreadable.
 *  - NORMALISED: matching is case- and whitespace-insensitive, done at COMPARE time by
 *    Unit::findByCodeOrAlias(), never by mangling the stored string.
 *
 * De-duplication is therefore case-insensitive with FIRST-SPELLING-WINS: "Ward Four" and
 * "ward four" are one alias, and the one kept is the one typed first, on purpose.
 *
 * Unlike App\Casts\ExtraRowFields there is no key allow-list, and there must not be one: an
 * alias is free text an administrator authors, not a field name that reaches a mass-assignment
 * boundary. Unlike App\Casts\EncryptedJson there is nothing secret here — aliases are
 * configuration, never PHI.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
class UnitAliases implements CastsAttributes
{
    /** A generous ceiling, so a paste accident cannot store a novel. */
    private const MAX_ALIASES = 50;

    private const MAX_LENGTH = 100;

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? self::normalize($decoded) : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $list = is_array($value) ? self::normalize($value) : [];

        return [$key => json_encode($list)];
    }

    /**
     * Case-folded key for MATCHING only. Collapses internal whitespace runs too, so
     * "Ward   Four" and "Ward Four" are the same alias.
     */
    public static function fold(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function normalize(array $values): array
    {
        $out = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_LENGTH) {
                continue;
            }

            $folded = self::fold($trimmed);

            if (isset($seen[$folded])) {
                continue;
            }

            $seen[$folded] = true;
            $out[] = $trimmed;

            if (count($out) >= self::MAX_ALIASES) {
                break;
            }
        }

        return $out;
    }
}
