<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib UN-02 (three independent capability flags), UN-03 (import aliases), UN-05 (optional
 * secondary display name). Design §6.1 asserted P0a shipped these; P0a's
 * 2026_08_08_120001_add_configuration_to_units.php added nine PRESENTATION columns and nothing
 * else (P1b finding 5). Design §6.1 has already been corrected by P1a Task 9; this migration is
 * what makes it true.
 *
 * Additive and defaulted, per the project rule. Per-column Schema::hasColumn guards follow
 * P0a's own hardening (its amendment 7): the Blueprint emits one ALTER TABLE per column, so
 * guarding only the first leaves a partial failure unrecoverable.
 *
 * The three flags default FALSE, matching P0a's `active` decision (amendment 2): a
 * half-configured department must be INERT. A flag defaulting true would enrol a freshly
 * created unit into the training rotation and the on-call roster before anyone confirmed it.
 *
 * `aliases` carries NO index. It is read by `Unit::findByCodeOrAlias()`, which loads the (very
 * small) unit set and matches in PHP — a JSON containment index would be MySQL-only and this
 * schema runs on SQLite under test. Units number in the tens, not the thousands.
 */
return new class extends Migration
{
    /** The four QCH units, backfilled so an existing production database needs no seeder run. */
    private const SEEDED_CODES = ['PICU', 'NICU', 'SCBU', 'WARD'];

    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            // UN-02. Three INDEPENDENT flags — never collapsed into one enum: a subspecialty
            // that owns clinics but is neither a rotation nor an on-call target is a real shape.
            if (! Schema::hasColumn('units', 'training_rotation')) {
                $table->boolean('training_rotation')->default(false)->after('active');
            }
        });

        Schema::table('units', function (Blueprint $table) {
            if (! Schema::hasColumn('units', 'call_target')) {
                $table->boolean('call_target')->default(false)->after('training_rotation');
            }
        });

        Schema::table('units', function (Blueprint $table) {
            if (! Schema::hasColumn('units', 'clinic_owner')) {
                $table->boolean('clinic_owner')->default(false)->after('call_target');
            }
        });

        Schema::table('units', function (Blueprint $table) {
            // UN-03. Nullable rather than defaulted: MySQL cannot carry a literal DEFAULT on a
            // JSON column. The App\Casts\UnitAliases cast resolves null to [] on read, so no
            // caller ever sees one.
            if (! Schema::hasColumn('units', 'aliases')) {
                $table->json('aliases')->nullable()->after('clinic_owner');
            }
        });

        Schema::table('units', function (Blueprint $table) {
            // UN-05. Stored for future translations; the spec itself says "unused at launch",
            // and UnitProfile deliberately does not carry it to the client.
            if (! Schema::hasColumn('units', 'name2')) {
                $table->string('name2')->nullable()->after('name');
            }
        });

        // Backfill the four paediatric units so an EXISTING production database is correct
        // without waiting for `db:seed --force`. They are where residents rotate and where
        // on-call is counted.
        // DB::table, not Eloquent: a migration must not depend on a model's current shape.
        //
        // NOTE (correcting this docblock, not the statement below): an earlier version of
        // this comment said "no clinics exist anywhere until P1e, so clinic_owner stays
        // false" for all four units. That was already wrong the day this migration was
        // written — Owner Decision B (2026-08-09, predates this file) makes WARD the sole
        // clinic owner, and `ReferenceSeeder` has always reflected that on a COLD START. This
        // migration's own backfill disagreed for an UPGRADE (an existing `units` row that
        // predates the decision, or that was created before the seeder picked it up): it left
        // `clinic_owner` false here for every seeded code including WARD, and because unit
        // profile columns are written on CREATE only (by design — a re-seed must never
        // silently revert an administrator's configuration), `db:seed --force` could never
        // correct that afterwards. Deliberately NOT fixed by editing the statement below —
        // this migration has already shipped and may have already run with the old, wrong
        // behaviour on some database (including the MySQL rehearsal stack that found this).
        // See `2026_08_15_120002_correct_ward_clinic_owner`, which corrects the data
        // directly and unconditionally, and is what actually fixes this for every database
        // regardless of whether it hit this bug or not.
        DB::table('units')
            ->whereIn('code', self::SEEDED_CODES)
            ->update(['training_rotation' => true, 'call_target' => true]);
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn(['training_rotation', 'call_target', 'clinic_owner', 'aliases', 'name2']);
        });
    }
};
