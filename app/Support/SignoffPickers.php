<?php

namespace App\Support;

use App\Models\Person;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * WHO may be named on a handover sign-off — one definition per field, used for BOTH the offered
 * list and the write-side rule.
 *
 * `staffPickers()` used to build both lists from one shared closure, so any predicate added
 * inside it applied to consultants too — the exact opposite of D9. And `Rule::exists` runs on the
 * raw query builder, which never sees Eloquent's SoftDeletes global scope, so a predicate written
 * once as Eloquent and once as raw SQL is two predicates that drift. Both problems are solved the
 * same way: the predicate is a closure over a QUERY BUILDER, applied to the validation rule
 * directly and to the Eloquent offer query through `getQuery()`.
 *
 * D9 (design §5.3): endorsers must have a claimed, live account, because their SIGNATURE is the
 * evidence; consultants need only be an active person, because the covering consultant is a name
 * of record and frequently never logs in.
 */
final class SignoffPickers
{
    /**
     * RULING 6 — residents and chief residents. A Chief Resident (5) is a resident clinically;
     * promotion must not remove them from the handover.
     *
     * @var list<int>
     */
    public const ENDORSER_POSITIONS = [4, 5];

    /**
     * The COVERING / RECEIVING consultant — a different question from who personally handed
     * over, so this list stays position 3 alone.
     *
     * @var list<int>
     */
    public const CONSULTANT_POSITIONS = [3];

    /** @return \Closure(QueryBuilder): void */
    public static function endorserPredicate(): \Closure
    {
        return function (QueryBuilder $query): void {
            self::rosteredIn($query, self::ENDORSER_POSITIONS);

            // …and holds a live account. This is D9's "claimed", expressed as a join rather than
            // as a status column, so it cannot disagree with reality.
            $query->whereExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.person_id', 'people.id')
                    ->where('users.active', true)
                    ->whereNull('users.deleted_at');
            });
        };
    }

    /** @return \Closure(QueryBuilder): void */
    public static function consultantPredicate(): \Closure
    {
        return fn (QueryBuilder $query) => self::rosteredIn($query, self::CONSULTANT_POSITIONS);
    }

    /**
     * `whereNull('people.deleted_at')` is written EXPLICITLY: Rule::exists bypasses the
     * SoftDeletes global scope, and this same closure is used on both sides.
     *
     * @param  list<int>  $positions
     */
    private static function rosteredIn(QueryBuilder $query, array $positions): void
    {
        $query->whereIn('people.position', $positions)
            ->where('people.active', true)
            ->whereNull('people.deleted_at');
    }

    /** The write-side rule. @param  \Closure(QueryBuilder): void  $predicate */
    public static function rule(\Closure $predicate): Exists
    {
        return Rule::exists('people', 'id')->where($predicate);
    }

    /**
     * The offered list, from the SAME predicate.
     *
     * `$keep` is the id currently stored on the sheet. A stored id absent from the list renders
     * as a `<select>` with no matching `<option>`, and Sheet.vue's next submit then sends null —
     * silently clearing a recorded endorser on an unsigned day. It is appended flagged `retired`
     * and rendered disabled, so the value is visible and cannot be lost by accident. It is NOT
     * accepted by the rule: parity is per offered-and-selectable option.
     *
     * @param  \Closure(QueryBuilder): void  $predicate
     * @return list<array{id: int, name: string, retired?: bool}>
     */
    public static function offer(\Closure $predicate, ?int $keep = null): array
    {
        $query = Person::query()->orderBy('people.full_name');
        $predicate($query->getQuery());

        $list = $query->get(['people.id', 'people.full_name'])
            ->map(fn (Person $p): array => ['id' => (int) $p->id, 'name' => (string) $p->full_name])
            ->all();

        if ($keep !== null && ! in_array($keep, array_column($list, 'id'), true)) {
            $person = Person::withTrashed()->find($keep);

            if ($person !== null) {
                $list[] = ['id' => (int) $person->id, 'name' => (string) $person->full_name, 'retired' => true];
            }
        }

        return $list;
    }
}
