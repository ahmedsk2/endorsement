<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'institution_id',
        'person_id',
        'preferred_unit_id',
        'member_name',
        'password',
        'active',
        'pass_exp_date',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_method',
        'email_verified_at',
        'signature_path',
        'signature_updated_at',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'pass_exp_date' => 'date',
            // MFA (B4): the TOTP secret and recovery codes are encrypted at rest so a DB
            // read alone never yields a usable second factor.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'signature_updated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The name of record, read through the person (P0c).
     *
     * `users.full_name` is dropped by 2026_08_10_120003; this accessor exists so that the ~40
     * PHP reads of `$user->full_name` — the signed-off-by snapshot, the print header, the Inertia
     * props, the OTP mail — need no change at all. Only the handful of SQL-level reads
     * (`orderBy('full_name')`) had to move, and they are listed in the P0c plan.
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->person?->full_name);
    }

    /**
     * The job role, read through the person (P0c). Capability resolution
     * (App\Support\AccessControl::resolve) keys off this, and so does the two-factor privilege
     * classifier — so there must be exactly one copy of it, and it is the roster's.
     */
    protected function position(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => $this->person === null ? null : (int) $this->person->position,
        );
    }

    /**
     * The account's address, read through the person (P0c/D9, owner decision 2026-08-08).
     *
     * There is ONE email column now: `people.email`. `users.member_email` is a legacy artifact —
     * it still physically exists (dropping it is its own migration, not done here) but is no
     * longer independently written by any write path this task touches, so nothing may trust its
     * raw value. Every normal read (`$user->member_email`) goes through this accessor instead,
     * which is exactly what makes `getEmailForPasswordReset()` and `routeNotificationForMail()`
     * below correct without any change to their bodies: they already read `$this->member_email`,
     * and that now always resolves through the `person_id` link.
     */
    protected function memberEmail(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->person?->email);
    }

    /** The account's e-mail address is confirmed (a link sent to it was opened). */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** A handwritten signature is on file (drawn or uploaded from the profile page). */
    public function hasSignature(): bool
    {
        return $this->signature_path !== null && $this->signature_path !== '';
    }

    /**
     * The second factor actually in force: 'totp' only counts once enrolment is CONFIRMED,
     * 'email' needs a verified address to send the code to. Anything else is "none", which
     * is what the setup checklist nags about.
     */
    public function activeTwoFactorMethod(): ?string
    {
        if ($this->two_factor_method === 'totp' && $this->hasTwoFactorEnabled()) {
            return 'totp';
        }

        if ($this->two_factor_method === 'email' && $this->hasVerifiedEmail() && $this->member_email !== null) {
            return 'email';
        }

        return null;
    }

    /**
     * True once the user has enrolled AND confirmed a TOTP second factor — the login flow
     * only challenges confirmed users (a pending, unconfirmed secret does not gate login).
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * The address the password-reset broker builds the reset link for. `$this->member_email` is
     * the read-through accessor above — this method needed no code change to "follow the link"
     * (owner decision 2026-08-08, requirement c): it already reads that attribute, and the
     * attribute now always resolves via `person_id` -> `people.email` rather than the frozen raw
     * column. The LOOKUP side of the broker is a separate concern, handled in
     * `PasswordResetLinkController`/`NewPasswordController` (the raw column can no longer be
     * trusted as a query filter either).
     */
    public function getEmailForPasswordReset(): ?string
    {
        return $this->member_email;
    }

    /**
     * Route mail notifications (e.g. the password-reset link, the login email code) to the
     * account's address. Same reasoning as `getEmailForPasswordReset()` above.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->member_email;
    }

    /** This device-level web-push subscriptions for the user (spec §10.2).
     * @return HasMany<PushSubscription, $this> */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /** The units this user wants handover reminders for (spec §10.2 opt-in).
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<Unit, $this> */
    public function reminderUnits(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'reminder_preferences')->withTimestamps();
    }

    /**
     * Legacy parity: a password is expired once its set date (`pass_exp_date`) is more than
     * 3 months old. A NULL date means "no expiry set" and never forces a change.
     */
    public function passwordExpired(): bool
    {
        if ($this->pass_exp_date === null) {
            return false;
        }

        return $this->pass_exp_date->copy()->addMonths(3)->lessThan(today());
    }

    /**
     * The institution this user's account belongs to — provenance and in-instance grouping
     * (D11), NOT a security boundary. The isolation boundary is the database, not this column;
     * never use it to scope a clinical query. See
     * docs/superpowers/plans/2026-08-08-p0d-tenancy-provisioning.md.
     *
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * The person this account belongs to — the name of record.
     *
     * Since P0c (D3 reversed) `users` is the AUTHENTICATION record and nothing else. Who this
     * account belongs to, what their job role is and what they are called all live on `people`.
     *
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Per-user capability overrides (grant/deny).
     *
     * @return HasMany<UserCapability, $this>
     */
    public function userCapabilities(): HasMany
    {
        return $this->hasMany(UserCapability::class);
    }

    /**
     * Audit-log entries attributed to this user.
     *
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
