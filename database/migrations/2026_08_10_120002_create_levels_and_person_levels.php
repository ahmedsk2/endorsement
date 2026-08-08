<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib LV-01 (levels) / LV-04 (effective-dated history).
 *
 * Deliberately no `people.level_id`. The design doc's §5.1 puts a current level pointer on the
 * person AND a history table — two definitions of "current level" that will drift the day one is
 * updated and the other is not. This plan stores history ONLY, in `person_levels`, and
 * `Person::levelAt($date)` (added to `app/Models/Person.php`) is the sole resolver. There is no
 * denormalized "current" column anywhere to fall out of step with it.
 *
 * Self-contained: nothing else reads either table yet, and no level is seeded — the QCH level set
 * (R1/R2/PGY-2/etc.) is departmental data the owner supplies; inventing one here would be a
 * clinical guess this plan has no standing to make.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

            // The rota-facing identity, e.g. R1 / PGY-2. Unique outright, not per institution:
            // `institution_id` is nullable and a UNIQUE index treats NULLs as distinct on both
            // MySQL/InnoDB and SQLite, so a composite unique would be toothless for exactly the
            // bootstrap and fixture rows. D11 makes one database one customer, so plain UNIQUE is
            // both honest and enforceable — the same reasoning as `people.short_name`.
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('display_order')->default(1000);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('person_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            // restrictOnDelete, NOT nullOnDelete: a history row that has forgotten which level it
            // records is worse than no history at all. Retire a level with active = false instead
            // of deleting it once it has any history.
            $table->foreignId('level_id')->constrained()->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();   // null = still current
            $table->timestamps();

            $table->unique(['person_id', 'effective_from']);
            $table->index(['person_id', 'effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_levels');
        Schema::dropIfExists('levels');
    }
};
