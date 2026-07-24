<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE `handovers` table discriminated by `unit_id`, collapsing the four drifted legacy
 * tables (`patintsendorcement`, `nicu_patintsendorcement`, `scbu_patintsendorcement`,
 * `ward_patintsendorcement` — the misspelling is real). Per-unit legacy field differences
 * are preserved as nullable columns: NICU/SCBU `dob`, WARD `age` + `ward_unit`. Rich-text
 * fields are sanitized on write by the model (SanitizedHtml cast).
 *
 * Differences from the reference app's schema (spec §4, rulings 7-8):
 *   - NO `draft` column — the day-level sign-off (`handover_signoffs.signed_off_at`) is
 *     the only workflow state; a half-built per-row draft flag would sit dead.
 *   - `legacy_source_table` + `legacy_id` — lossless import provenance. The legacy natural
 *     key (date, mrn, bed) collapses ~2.5k real rows; provenance keys import 1:1 and make
 *     `legacy:import` idempotent without dedupe loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            // The unit discriminator that collapses the 4 legacy tables into one.
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->date('handover_date')->nullable();      // legacy *_patintsendorcement.STAYDATE
            $table->string('bed')->nullable();              // legacy BED (WARD screens label it "Room")

            // Patient snapshot (legacy stored name/MRN inline, not FK'd — a point-in-time sheet).
            $table->string('mrn')->nullable();              // legacy MRN
            $table->string('patient_name')->nullable();     // legacy PNAME

            // Per-unit legacy fields preserved.
            $table->dateTime('dob')->nullable();            // legacy NICU/SCBU dob
            $table->string('age')->nullable();              // legacy WARD age (free text)
            $table->string('ward_unit')->nullable();        // legacy WARD unit (free text sub-unit)

            // Rich-text handover fields — sanitized on write (HTMLPurifier allow-list).
            $table->text('disease')->nullable();            // legacy DISEASE  ("Problem List")
            $table->text('details')->nullable();            // legacy DETAILS  ("Clinical Condition")
            $table->text('plan')->nullable();               // legacy PLAN     ("Plan of Care")
            $table->text('nevent')->nullable();             // legacy nevent   ("To be followed")

            // Last author attribution — legacy had no per-row author FK.
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Lossless legacy-import provenance (null on rows born in this system).
            $table->string('legacy_source_table')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['unit_id', 'handover_date']);
            $table->unique(['legacy_source_table', 'legacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handovers');
    }
};
