<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The data half of P0b's bounded custom fields (design §6.2, "Ceiling 2") — a department
 * describes its own handover-sheet fields as rows here, rather than a code change. This table
 * only defines the SHAPE of a custom field; the values themselves live in
 * `handovers.extra_fields`, added in a later migration, behind an `EncryptedJson` cast.
 *
 * Coexists with `units.extra_row_fields` rather than replacing it: that column keeps driving
 * the existing named, individually-encrypted identity columns (`dob`, `age`, `ward_unit`).
 * This table is strictly additive on top. The four paediatric units get zero rows here, so
 * nothing about them changes.
 *
 * No `institution_id` — the unit already carries it via `foreignId('unit_id')`, and a field
 * definition is meaningless outside the unit that owns it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_field_definitions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();

            // The map key inside handovers.extra_fields. Immutable once values exist:
            // renaming it orphans every stored value under the old key, so the (not yet
            // built) admin path must forbid renaming a key that has ever been used.
            $table->string('key', 64);
            $table->string('label');
            $table->string('type', 16)->default('text'); // text | date | select
            $table->json('options')->nullable(); // select only: list<string>
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('display_order')->default(1000);

            // Opt-IN for units.active (a half-configured unit must be inert) but opt-OUT
            // here: a definition someone bothered to create is meant to be used, and it is
            // inert anyway until a unit actually renders it. Retiring one flips this to
            // false rather than deleting the row, so historical `extra_fields` values under
            // its key are never orphaned — they simply stop rendering.
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique(['unit_id', 'key']);
            $table->index(['unit_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_field_definitions');
    }
};
