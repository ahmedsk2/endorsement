<?php

namespace App\Models;

use App\Casts\SanitizedHtml;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The unified shift-handover row (collapses the four legacy `*_patintsendorcement` tables
 * into one, discriminated by `unit_id`). The four rich-text fields are sanitized on write
 * via the HTMLPurifier allow-list (stored-XSS defense).
 */
class Handover extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'institution_id',
        'unit_id',
        'handover_date',
        'bed',
        'mrn',
        'patient_name',
        'dob',
        'age',
        'ward_unit',
        'disease',
        'details',
        'plan',
        'nevent',
        'author_user_id',
        'legacy_source_table',
        'legacy_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'handover_date' => 'date',
            'dob' => 'datetime',
            // Rich-text handover fields: sanitized on write (allow-list; scripts/handlers stripped).
            'disease' => SanitizedHtml::class,
            'details' => SanitizedHtml::class,
            'plan' => SanitizedHtml::class,
            'nevent' => SanitizedHtml::class,
        ];
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
