<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;

/**
 * One previous value of one clinical field. Append-only: written, never updated or deleted.
 *
 * The old value is ENCRYPTED like the live column. It is the same patient data — a
 * superseded problem list is still a child's diagnosis — and history is exactly what an
 * attacker would read if the live columns were hardened and this table was not.
 */
class HandoverRevision extends Model
{
    /** Append-only: only created_at is maintained. */
    public const UPDATED_AT = null;

    /** The fields whose history is worth keeping: identifiers + the four narratives. */
    public const TRACKED = [
        'mrn', 'patient_name', 'dob',
        'disease', 'details', 'plan', 'nevent',
    ];

    protected $fillable = [
        'handover_id',
        'field',
        'old_value',
        'changed_by_user_id',
        'day_was_signed',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_value' => EncryptedString::class,
            'day_was_signed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function handover()
    {
        return $this->belongsTo(Handover::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
