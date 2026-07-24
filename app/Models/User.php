<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
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
        'position',
        'preferred_unit_id',
        'full_name',
        'member_name',
        'member_email',
        'password',
        'active',
        'pass_exp_date',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
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
            'position' => 'integer',
            'pass_exp_date' => 'date',
            // MFA (B4): the TOTP secret and recovery codes are encrypted at rest so a DB
            // read alone never yields a usable second factor.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
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
     * The login field for the password-reset broker and mail routing is `member_email`
     * (this app has no `email` column). Overriding these keeps Laravel's password broker
     * and notification routing pointed at the right attribute.
     */
    public function getEmailForPasswordReset(): ?string
    {
        return $this->member_email;
    }

    /**
     * Route mail notifications (e.g. the password-reset link) to `member_email`.
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
     * The institution (tenant) this user belongs to.
     *
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * The role catalog row matching this user's `position`.
     *
     * @return BelongsTo<Position, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position');
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
