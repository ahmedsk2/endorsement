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
        'extra_fields',
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
            // DIRECT IDENTIFIERS — encrypted at rest (docs/COMPLIANCE.md layer 4). A
            // stolen dump or a compromised DB account yields ciphertext, not a child's
            // name and hospital number. Consequence: these columns cannot be searched or
            // sorted in SQL. Nothing in this system does either.
            'mrn' => \App\Casts\EncryptedString::class,
            'patient_name' => \App\Casts\EncryptedString::class,
            'dob' => \App\Casts\EncryptedDateTime::class,
            // Rich-text handover fields: sanitized on write (allow-list; scripts/handlers stripped).
            'disease' => SanitizedHtml::class,
            'details' => SanitizedHtml::class,
            'plan' => SanitizedHtml::class,
            'nevent' => SanitizedHtml::class,
            // P0b bounded custom fields (design §6.2) — a per-unit {key: value} map driven by
            // unit_field_definitions, encrypted at rest (docs/COMPLIANCE.md layer 4) for the
            // same reason as the identity columns above: whatever a department chooses to
            // collect here (weight, allergy, whatever else) is clinical text about a named
            // child the moment it sits next to mrn/patient_name on the same row. A stolen
            // dump or compromised DB account must yield ciphertext, not a readable map.
            'extra_fields' => \App\Casts\EncryptedJson::class,
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
