<?php

namespace App\Models;

use Database\Factories\PersonLevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One effective-dated span of a person's training level (Munawib LV-04). `effective_to === null`
 * means "still current". Both bounds are inclusive — see `Person::levelAt()`, the sole resolver.
 *
 * `promotion_batch_id`/`reason`/`created_by` are provenance (P1c Decision G): who wrote this span
 * and why, and — for `promotion_batch_id` — which single act it belongs to, so a bulk promotion's
 * per-person rows can be grouped back into one event. `App\Support\LevelAssignment` is the ONLY
 * writer of this table (`tests/Feature/Build/PersonLevelsHaveOneWriterTest.php`).
 *
 * `created_by` is `users`, not `people`: it records the ACTOR. `people.id` and `users.id` are
 * independent sequences — never compare or copy them positionally.
 */
class PersonLevel extends Model
{
    /** @use HasFactory<PersonLevelFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'person_id',
        'level_id',
        'effective_from',
        'effective_to',
        'promotion_batch_id',
        'reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'created_by' => 'integer',
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

    /**
     * The ACTOR who wrote this span — `users`, never `people`. Task 7's history panel resolves
     * `$user->full_name` through this relation, which is a read-through accessor onto the linked
     * `Person` (P0c): a narrowed eager load MUST carry `person_id` or the accessor silently
     * returns null (`with('createdBy:id,person_id')`, never `with('createdBy:id,full_name')` —
     * the same trap that broke four live sites with zero test coverage before P0c's audit).
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
