<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One effective-dated span of a person's training level (Munawib LV-04). `effective_to === null`
 * means "still current". Both bounds are inclusive — see `Person::levelAt()`, the sole resolver.
 */
class PersonLevel extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'person_id',
        'level_id',
        'effective_from',
        'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }
}
