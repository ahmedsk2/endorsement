<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single-use, expiring, address-bound invitation to create one account.
 *
 * The plaintext token exists for exactly one moment — the response to the inviter — and is
 * never stored, logged or audited. Everything persisted here is either non-secret or a hash.
 */
class Invitation extends Model
{
    /** How long an invitation stays redeemable. Long enough for a night shift to see it. */
    public const LIFETIME_DAYS = 7;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'institution_id',
        'person_id',
        'member_email',
        'position',
        'token_hash',
        'invited_by_user_id',
        'expires_at',
        'accepted_at',
        'accepted_user_id',
        'revoked_at',
        'revoked_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Mint one. Returns the model and the plaintext token — the ONLY time it exists.
     *
     * `$person` is the roster row this invitation is issued TO (P0c/Task 8) — matched-or-created
     * by the caller before this runs. Nullable so direct callers that predate the person link
     * (and the redemption path's own "create at redemption time" fallback for those rows) keep
     * working unchanged.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $email, int $position, ?User $invitedBy, ?Person $person = null): array
    {
        // 64 hex characters of CSPRNG. Guessing is not a threat model anyone needs to think
        // about again at this size, which is the point.
        $token = bin2hex(random_bytes(32));

        $invitation = static::create([
            'institution_id' => $invitedBy?->institution_id,
            'person_id' => $person?->getKey(),
            'member_email' => Str::lower(trim($email)),
            'position' => $position,
            'token_hash' => hash('sha256', $token),
            'invited_by_user_id' => $invitedBy?->getKey(),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return [$invitation, $token];
    }

    /**
     * Find a REDEEMABLE invitation for this token, or null.
     *
     * Every condition is applied here, in one place, so no caller can accidentally redeem
     * an expired, revoked or already-used invitation by checking only some of them.
     */
    public static function redeemable(string $token): ?self
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return static::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** The roster row this invitation is issued to (P0c/Task 8). Null for pre-P0c rows. */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
