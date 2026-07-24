<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shift-handover SIGN-OFF (attestation).
 *
 * Legacy shape (`validate-endorsement.php`, table `{unit}_endorsement`): the attestation is a
 * PER-DAY HEADER row — `Dates`, `endorsedby`, `endorsedto`, `consultantby`, `consultantto`
 * (WARD: a single `consultantoncall`), `time` — one row per handover date, NOT one per patient
 * row. That modelling is kept: this table is keyed (unit_id, handover_date) and is a sibling of
 * `handovers`. WARD's `consultantoncall` maps into `consultant_by_*` with a per-unit screen
 * label (spec ruling 5); no dedicated column exists for it.
 *
 * Deviations from legacy, deliberate:
 *  - legacy stored `endorsedby`/`endorsedto` as a bare `members.member_id` STRING and the
 *    consultant fields as FREE TEXT. Here all four are real user FKs, each paired with a
 *    `*_name` SNAPSHOT captured at write time. A medico-legal record must not change meaning
 *    when a user is later renamed or soft-deleted; the FK is nullOnDelete, the name survives it.
 *  - `signed_off_at` / `signed_off_by_user_id` make "signed" an explicit, timestamped state
 *    (legacy had no signed state at all — nothing ever locked).
 *  - sign-off is REVERSIBLE only through an audited correction path: `reopened_at`,
 *    `reopened_by_user_id` and a mandatory `reopen_reason`. Nothing is erased on reopen.
 *  - UNIQUE (unit_id, handover_date) — legacy had NO unique index on Dates; duplicate day
 *    headers were possible by race. Fixed at the schema level here.
 *  - `endorsement_time_minutes` (0-1439) is the machine-readable derivation of the verbatim
 *    `endorsement_time` display label (folded in from the reference's 140000 migration).
 *  - `legacy_source_table` + `legacy_id` — lossless import provenance (spec ruling 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handover_signoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->date('handover_date')->nullable();   // legacy {unit}_endorsement.Dates

            // Endorsing / receiving RESIDENT (legacy endorsedby / endorsedto — a members picker).
            $table->foreignId('endorsed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('endorsed_by_name')->nullable();
            $table->foreignId('endorsed_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('endorsed_to_name')->nullable();

            // Covering / receiving CONSULTANT (WARD: the single "Consultant Oncall" lives in *_by_*).
            $table->foreignId('consultant_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('consultant_by_name')->nullable();
            $table->foreignId('consultant_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('consultant_to_name')->nullable();

            // Legacy `time` — a shift-change label ("7:30 Am" / "13:30"), stored VERBATIM so
            // imported legacy values and new ones read identically on the sheet — plus the
            // machine-readable minutes-past-midnight derivation.
            $table->string('endorsement_time')->nullable();
            $table->unsignedSmallInteger('endorsement_time_minutes')->nullable();

            // The attestation stamp itself.
            $table->timestamp('signed_off_at')->nullable();
            $table->foreignId('signed_off_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // The audited correction path — never a delete.
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reopen_reason')->nullable();

            // Lossless legacy-import provenance (null on rows born in this system).
            $table->string('legacy_source_table')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One attestation per unit-day (the legacy day-header cardinality, now enforced).
            $table->unique(['unit_id', 'handover_date']);
            $table->unique(['legacy_source_table', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handover_signoffs');
    }
};
