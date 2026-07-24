<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCapability extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'capability_id',
        'effect',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Capability, $this>
     */
    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }
}
