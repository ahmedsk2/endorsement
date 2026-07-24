<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One device's web-push subscription. Infrastructure only — no clinical data.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'endpoint_hash',
        'p256dh',
        'auth',
    ];

    protected static function booted(): void
    {
        // Uniqueness rides on the hash (endpoints exceed index-safe lengths); derive it
        // on save so no caller can forget it.
        static::saving(function (PushSubscription $subscription): void {
            $subscription->endpoint_hash = hash('sha256', (string) $subscription->endpoint);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
