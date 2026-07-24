<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marker: "AccessControlSeeder has already applied the role default for this
 * (position, capability) pair". Its presence turns a re-run into a genuine no-op, so an
 * administrator's deliberate revocation is never re-imposed by a routine deploy.
 *
 * Append-only in practice — rows are written once, on first sight of a (position, capability).
 */
class AppliedRoleDefault extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'position',
        'capability_id',
        'applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Capability, $this>
     */
    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }
}
