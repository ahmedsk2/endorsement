<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE REFINEMENT RULE on a clinic (Munawib CL-02) — never a resolved attendee, and never a snapshot
 * of who the rota happened to have on the unit at some unstated moment. The table name says
 * "attendees" because the spec does; what it stores is the RULE by which attendees are found.
 *
 * Exactly one of `level_id` / `person_id` is set, and which one is determined by the parent's
 * `clinics.attendee_mode`:
 *
 *   `levels` → `level_id` set, `person_id` null. "Those rotators whose level ON THAT DATE is R2."
 *   `named`  → `person_id` set, `level_id` null. "Dr X, whatever the rota says."
 *   `rotators` → NO ROWS AT ALL. The default needs no refinement to express.
 *
 * NEITHER CONSTRAINT IS ENFORCED BY THE DATABASE, and that is not an oversight — a UNIQUE index
 * over nullable columns enforces nothing on SQLite or MySQL 8.4 (NULLs compare distinct), so
 * `(clinic_id, level_id)` would happily admit five rows with a null `level_id`, and a row naming
 * both columns or neither would satisfy it perfectly. `App\Support\Clinics\ClinicWriter` is the one
 * writer and the whole guarantee; `ClinicWritersAreSingularTest` is the proof, which makes that
 * guard the only thing behind the constraint rather than defence in depth over one. The precedent
 * is `person_levels`' overlap rule living in `App\Support\LevelAssignment` for the identical
 * engine-capability reason (`2026_08_14_120002`'s docblock).
 *
 * There is deliberately NO polymorphic `subject_type` column to buy a unique index back. It would
 * add a discriminator whose only job is to satisfy an index this codebase has already decided it
 * does not need, and the writer would still have to enforce the mode-homogeneity rule that the
 * index could not express either.
 *
 * NO SOFT DELETE: schedule structure. Replacing a clinic's rule set deletes and re-inserts, which
 * is what makes a re-run of a form or a seeder converge instead of duplicating.
 */
class ClinicAttendee extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'clinic_id',
        'level_id',
        'person_id',
    ];

    /**
     * @return BelongsTo<Clinic, $this>
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * @return BelongsTo<Level, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
