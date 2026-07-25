<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One live e-mail one-time code for a user. The code itself is stored HASHED — a database
 * read must never yield a usable second factor (the same rule the encrypted TOTP secret
 * follows). One row per user: re-requesting replaces it, using it deletes it.
 */
class LoginOtp extends Model
{
    protected $fillable = ['user_id', 'code_hash', 'attempts', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
