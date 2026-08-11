<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib CL-01 and CL-02 (P1e Decision B). A clinic is an owning unit, a name, a weekday, an
 * AM/PM session, an optional location and note, and an active flag — the one piece of the
 * department's week the master rota does not describe.
 *
 * ---------------------------------------------------------------------------------------------
 * 1. `weekday` IS ISO-8601: MONDAY = 1 … SUNDAY = 7. Not Carbon's `dayOfWeek`.
 * ---------------------------------------------------------------------------------------------
 * `Calendar::weekendDays()` already returns ISO weekday numbers, `Calendar::weekStartIsoDay()`
 * returns one, and `Calendar::isWeekend()` compares `isoWeekday()` against that list. This column
 * must be comparable to all three with no translation step, so it uses their numbering and no
 * other. Carbon's `dayOfWeek` (Sunday = 0) is a SECOND numbering scheme, and admitting it anywhere
 * near this column would make a third — the "two definitions of one fact" failure this codebase
 * keeps paying for. `Calendar::weekdayLabel()` is the only thing that names a weekday and it throws
 * on 0 precisely so `dayOfWeek` cannot enter through a label call.
 *
 * The integer is ABSOLUTE and nothing stored depends on where the department's week starts. There
 * is no `institutions.week_start` column and there never was: `Calendar::weekStartIsoDay()` derives
 * the start from `weekend_days`, so a department that changes its weekend re-orders every clinic
 * map in the instance immediately, with no stored value changing and no data migration.
 *
 * ---------------------------------------------------------------------------------------------
 * 2. `clinic_attendees` HOLDS A RULE, NEVER A RESOLVED ROSTER (Decision B).
 * ---------------------------------------------------------------------------------------------
 * CL-02 — "rotators on a unit attach to its clinics by default; per-clinic refinement by level or
 * named people" — is a QUERY, not a list. A clinic is a weekly recurrence with no date of its own,
 * so a stored roster would be a snapshot as of *what day*? There is no honest answer and every
 * wrong one is invisible. `clinics.attendee_mode` selects which rule applies:
 *
 *   rotators (default) — everyone the master rota has on the owning unit that day. NO rows here.
 *   levels             — those rotators whose level ON THAT DATE is in the attached set.
 *   named              — exactly the attached people, without consulting the rota at all (the
 *                        external consultant who attends a clinic and rotates nowhere).
 *
 * `App\Support\Clinics\ClinicRoster::forDate()` resolves it at READ time. Consequently THIS TABLE
 * NEVER NEEDS MAINTAINING WHEN THE ROTA MOVES: no hook in `RotaAssignment`, `RotaFill` or
 * `RotaImport`, and no second copy of the rota to drift. It is the same call `vacations` already
 * makes by carrying no `period_id` — "which period(s) it touches is a range intersection computed
 * at read time, never a foreign key".
 *
 * ---------------------------------------------------------------------------------------------
 * 3. THE EXACTLY-ONE-OF AND NO-DUPLICATE CONSTRAINTS LIVE IN `ClinicWriter`, NOT HERE.
 * ---------------------------------------------------------------------------------------------
 * A `clinic_attendees` row must name exactly one of `level_id`/`person_id`, and must not repeat one
 * within a clinic. NEITHER CONSTRAINT IS ADDED AT THE DATABASE LEVEL, because neither engine can
 * express it: a UNIQUE index over nullable columns enforces nothing on SQLite or MySQL 8.4 — NULLs
 * compare distinct, so `(clinic_id, level_id)` happily admits five rows with a null `level_id`, and
 * a row naming both columns or neither satisfies it perfectly.
 *
 * This is the identical situation `2026_08_14_120002` records for `person_levels`' overlap rule:
 * "NO overlap constraint is added at the database level. SQLite cannot express it, and a partial
 * unique index on MySQL 8.4 would not either. The guarantee lives in App\Support\LevelAssignment,
 * which `PersonLevelsHaveOneWriterTest` proves is the only writer." Here the guarantee lives in
 * `App\Support\Clinics\ClinicWriter` and `ClinicWritersAreSingularTest` is the proof — which makes
 * that guard the only thing behind the constraint rather than defence in depth over one.
 *
 * There is likewise NO unique index on `(unit_id, weekday, session)`. Two clinics may share all
 * three — two rooms, one session — and `ClinicWriterTest::test_two_clinics_may_share_a_unit_
 * weekday_and_session` pins the absence so nobody adds one later on the reasonable-sounding grounds
 * that a clinic is identified by when it runs. It is not.
 *
 * ---------------------------------------------------------------------------------------------
 * 4. NO SOFT DELETES ON EITHER TABLE, and no destroy path.
 * ---------------------------------------------------------------------------------------------
 * A clinic is schedule structure, not a clinical row — exactly like `master_rota_assignments` and
 * `vacations`, and for the reason P1d Decision E gives: the hash-chained `audit_log` is the
 * history, and tombstoning would put a `whereNull('deleted_at')` on every read path for history
 * that is already unedited and unerasable elsewhere. A clinic that stopped running is DEACTIVATED
 * (`active = false`, UN-04's "hides forward, never deletes history"), which is why the controller
 * has no `destroy()` at all, following `UnitController`/`LevelController`/`HolidayController`.
 *
 * ---------------------------------------------------------------------------------------------
 * 5. `institution_id` IS PROVENANCE (D11), AND NO INDEX LEADS WITH IT.
 * ---------------------------------------------------------------------------------------------
 * One database per customer: `institution_id` is provenance and in-instance grouping, never a query
 * filter and never part of a key. An index led by it is a recurring mistake — proposed twice by
 * plan text (P1a Task 4's `periods` unique index, Task 7's `holidays` index) and caught twice
 * empirically by `InstitutionProvenanceTest`, whose `index([...])`/`unique([...])` pattern has no
 * allow-list at all. Both indexes below lead with columns something actually filters or orders on.
 *
 * `clinic_attendees` carries NO `institution_id` — a pure child table does not repeat its parent's
 * provenance, the `person_levels` / `unit_field_definitions` precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

            // restrictOnDelete: units are RETIRED (`active = false`), never deleted (UN-04). The
            // owning unit must additionally carry `clinic_owner = true` — a column, never a code
            // list and never a `match` on 'WARD'. Owner Decision B (P1b) makes WARD the clinic
            // owner at QCH; a fifth department that ticks the box gets clinics with no code change.
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();

            // PLAIN TEXT, all three. `name`, `location` and `note` are NEVER purified server-side —
            // the same contract as `handovers.extra_fields`, not the four `SanitizedHtml` clinical
            // narrative fields. Every consumer escapes on render (`{{ }}` / `:value`, never
            // `v-html`), because a clinic legitimately called "ENT <> Audiology" must survive.
            $table->string('name');

            // ISO-8601, Monday = 1 … Sunday = 7. See section 1 above.
            $table->unsignedTinyInteger('weekday');

            // 'AM' | 'PM', offered AND validated from `Clinic::SESSIONS` — one list, so the picker
            // and the write-side rule cannot drift (the D9 / `SignoffPickers` discipline).
            $table->string('session', 2);

            $table->string('location')->nullable();
            $table->text('note')->nullable();

            // 'rotators' | 'levels' | 'named', from `Clinic::ATTENDEE_MODES`. A MODE ON THE PARENT,
            // rather than a bag of typed rules in the child, is what makes the child homogeneous
            // per clinic and lets one writer enforce it — and it removes a precedence question
            // nobody has answered (with include- and exclude-shaped rules in one table, "named
            // person X" plus "exclude person X" is a legal state with two defensible readings, and
            // a wrong reading fails silently). There is no exclude rule.
            $table->string('attendee_mode', 20)->default('rotators');

            $table->boolean('active')->default(true);
            $table->timestamps();

            // The map reads `where('active', true)` and groups/orders by unit, then weekday, then
            // session — so the composite leads with `unit_id` and the boolean stands alone. NOT led
            // by `institution_id`, which nothing filters on (D11).
            $table->index(['unit_id', 'weekday', 'session']);
            $table->index('active');
        });

        Schema::create('clinic_attendees', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete: the child has no meaning without its clinic, and `clinics` never
            // soft-deletes, so this is the whole cleanup story. The one path that hard-deletes a
            // `clinics` row will be `DemoDepartment::remove()` (P1e-2), which is ledger-scoped.
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete: a level is never deleted once it has history — retiring one sets
            // `active = false` — which is exactly what `person_levels` does with the same column.
            $table->foreignId('level_id')->nullable()->constrained()->restrictOnDelete();

            // cascadeOnDelete matches `person_levels` and `master_rota_assignments`: people are
            // SOFT-deleted (owner ruling), so this FK never fires in practice. It exists so a hard
            // delete in a future data-repair script cannot leave an attendee rule pointing at
            // nobody.
            $table->foreignId('person_id')->nullable()->constrained()->cascadeOnDelete();

            $table->timestamps();

            // The only access pattern: "the rule set for this clinic". Led by the column that IS
            // the filter. (MySQL indexes an FK column automatically; this is stated explicitly for
            // the same reason `master_rota_assignments` states its own `index('unit_id')` — the
            // read path should not depend on an engine's incidental behaviour.)
            $table->index('clinic_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_attendees');
        Schema::dropIfExists('clinics');
    }
};
