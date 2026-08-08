<?php

namespace App\Models;

use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            'constraints' => 'array',
            'notes' => \App\Casts\EncryptedString::class,
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
}
