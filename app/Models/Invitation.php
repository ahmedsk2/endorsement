<?php

namespace App\Models;

use App\Support\AppSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, expiring, address-bound invitation to create one account.
 *
 * The plaintext token exists for exactly one moment — the response to the inviter — and is
 * never stored, logged or audited. Everything persisted here is either non-secret or a hash.
 *
 * `App\Support\Invitations\InvitationIssue` IS THE ONLY WRITER of this table (P1c-2 Decision C),
 * apart from the redemption stamp and the explicit revoke endpoint. `issue()` below is the mint it
 * calls; it is not an entry point for anybody else.
 *
 * `member_email` IS NOT A DUPLICATE OF `people.email` (Decision G). It is the address a credential
 * was actually mailed to, FROZEN AT SEND TIME. The roster address can be corrected afterwards — a
 * typo fixed, a hospital account migrated — and when it is, this column must keep saying where the
 * link went, because that is the only record of who could have received it. Do not "tidy" the two
 * into one column, and do not backfill this one from the roster.
 *
 * `position` IS AN AUTHORIZATION SUBJECT, NOT A ROLE ASSIGNMENT (Decision G). It records what
 * `App\Support\ManagerScope` was asked to approve when the link was minted, and it is what a resend
 * is re-authorized against. The claim path takes it only on the branch that CREATES a person; for
 * an invitation bound to somebody the roster already knows it is never written onto `people`, so a
 * stale link cannot demote them. `InvitationTest::test_claiming_an_invitation_never_changes_an
 * _existing_roster_persons_position` is what keeps that true.
 */
class Invitation extends Model
{
    /**
     * The DEFAULT lifetime, in days. Long enough for a night shift to see it.
     *
     * SEVEN IS A DELIBERATE OVERRIDE of Munawib AC-02's stated "default 14 days" (P1 owner
     * decision 5, round 2, 2026-08-08), recorded here rather than applied silently: a
     * redeemed invitation reaches a child's clinical records, so a link that was forwarded,
     * left in a shared inbox or printed stays live for half as long.
     */
    public const LIFETIME_DAYS = 7;

    /** Bounds for the configured lifetime. A day is the shortest useful window... */
    public const LIFETIME_MIN = 1;

    /** ...and a month the longest anyone has argued for, so the knob cannot reach "never". */
    public const LIFETIME_MAX = 30;

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
            // ONE definition of normalising an address (`Person::normalizeEmail()`), not a
            // second inline copy of it — case and whitespace differ between a hospital
            // spreadsheet, an invitation form and a self-registration, and matching must
            // not. The `?? ''` is the column contract, not a guess: `member_email` is NOT
            // NULL, and the normaliser returns null for a blank input. Every caller already
            // refuses a blank address; the column must not depend on their doing so.
            'member_email' => Person::normalizeEmail($email) ?? '',
            'position' => $position,
            'token_hash' => hash('sha256', $token),
            'invited_by_user_id' => $invitedBy?->getKey(),
            'expires_at' => now()->addDays(self::lifetimeDays()),
        ]);

        return [$invitation, $token];
    }

    /**
     * How long an invitation stays redeemable, in days.
     *
     * `LIFETIME_DAYS` is the DEFAULT, not the value — an unset or unparseable setting falls
     * back to it, so a department that never opens the settings screen behaves exactly as it
     * did before this method existed. The clamp is not belt-and-braces over the FormRequest:
     * `app_settings` is a plain key/value table an operator can also reach with a database
     * console, and an invitation is a bearer credential whose lifetime must not be settable
     * to "effectively never" by a route this method cannot see.
     *
     * This is the ONE definition. Nothing else computes an expiry.
     */
    public static function lifetimeDays(): int
    {
        $configured = (int) (AppSettings::get('invitation_lifetime_days') ?? 0);

        return $configured >= self::LIFETIME_MIN && $configured <= self::LIFETIME_MAX
            ? $configured
            : self::LIFETIME_DAYS;
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
