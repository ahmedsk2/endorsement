<?php

namespace App\Models;

use App\Support\Calendar;
use Carbon\Carbon;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person on the departmental roster — the name of record.
 *
 * A person may or may not have an account. `hasAccount()` (a `users` row exists) is what D9 calls
 * "claimed", and it is a JOIN, not a column: there is no lifecycle enum to keep in step with
 * reality. Naming is governed here (`active`); authenticating is governed on `users` (`active`).
 */
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'institution_id',
        'full_name',
        'short_name',
        'position',
        'email',
        'phone',
        'joined_at',
        'notes',
        'constraints',
        'external',
        'active',
    ];

    /**
     * Staff personal data (PDPL). `User` is serialised into Inertia props in several places and
     * this model will be too; neither of these may travel by accident.
     *
     * @var list<string>
     */
    protected $hidden = ['phone', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'active' => 'boolean',
            'external' => 'boolean',
            'joined_at' => 'date',
            // Deliberately PLAINTEXT (owner decision 3, 2026-08-08 — OVERRIDES the plan's
            // original draft, which cast this through EncryptedString before the owner decided).
            // Both `notes` and `constraints` stay unencrypted; see docs/COMPLIANCE.md for the
            // stated reasoning (free-text staff notes are visible in a raw DB read and therefore
            // in backups — an auditable choice, not an oversight).
            'constraints' => 'array',
        ];
    }

    /**
     * The account, if this person has ever claimed one. At most one (`users.person_id` UNIQUE).
     *
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** "Claimed", in D9's sense: this person can authenticate, so they can also sign. */
    public function hasAccount(): bool
    {
        return $this->user()->exists();
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position');
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * The person's training-level history (Munawib LV-04). There is deliberately no `level_id`
     * column on this table: a denormalized "current" pointer beside a history table is two
     * definitions of one fact, and they drift. `levelAt()` is the only resolver.
     *
     * @return HasMany<PersonLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(PersonLevel::class);
    }

    /**
     * The level in force on a given date. Both bounds are INCLUSIVE: a level that runs to 30 June
     * is still in force on 30 June, and its successor starts on 1 July.
     */
    public function levelAt(Carbon|string|null $date = null): ?Level
    {
        $on = $date === null ? Calendar::todayYmd() : Calendar::ymd($date);

        return $this->levels()
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('effective_from')
            ->with('level')
            ->first()?->level;
    }

    public function currentLevel(): ?Level
    {
        return $this->levelAt();
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('people.active', true);
    }

    /**
     * The ONE normalization for a roster address. Case and whitespace differ between a hospital
     * spreadsheet, an invitation form and a self-registration; matching must not.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        $email = $email === null ? null : mb_strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    /**
     * Find the person an imported or invited address already belongs to. Soft-deleted people are
     * INCLUDED: they still occupy the unique index, and re-inviting someone who left is a
     * reactivation, never a second human. Returns null for a null/blank address — a missing
     * address never matches, it does not match everyone.
     */
    public static function matchByEmail(?string $email): ?self
    {
        $normalized = self::normalizeEmail($email);

        if ($normalized === null) {
            return null;
        }

        return static::withTrashed()->where('email', $normalized)->first();
    }

    /**
     * Validation rule: this address must not already belong to a CLAIMED person — one who
     * already has an account. One definition (the 2026-07-26 audit's "offer/validate from one
     * predicate" discipline, applied here too), used everywhere an email is checked before an
     * account gets created: `CreateAdmin`, invitations, admin approval of a pending
     * registration. A match onto a ROSTER-ONLY person (no account) is deliberately NOT a
     * failure — that is the normal, desired case (design §5.2.4): the person already exists,
     * the invite/approval claims them rather than duplicating them.
     *
     * `people.email` is P0c/D9's single authoritative address (owner decision, 2026-08-08) — the
     * `users.member_email` column is a legacy artifact no longer independently written, so a
     * raw `Rule::unique('users', 'member_email')` check would silently stop catching real
     * collisions. This closure is the replacement: it always resolves through the current link,
     * never the raw column.
     *
     * @param  int|null  $ignorePersonId  exclude this person (editing your OWN current address)
     * @return \Closure(string, mixed, \Closure): void
     */
    public static function accountEmailRule(?int $ignorePersonId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignorePersonId): void {
            $person = self::matchByEmail(is_string($value) ? $value : null);

            if ($person === null || $person->getKey() === $ignorePersonId) {
                return;
            }

            if ($person->hasAccount()) {
                $fail('An account with this email already exists.');
            }
        };
    }
}
