<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Munawib AR-05/MR-03. A vacation is an OVERLAY on a rota assignment, not a rotation itself: a
 * person on leave still belongs to whatever unit the master rota has them on that period, and the
 * leave subtracts from availability (MR-07) rather than replacing the assignment. Storing it as a
 * unit value would destroy the assignment it modifies.
 *
 * P1d Decision C — NO `period_id`, deliberately, for three reasons:
 *
 *  1. It crosses period boundaries. A fortnight straddling Block 11 and Block 12 is ONE fact; keyed
 *     on a period it becomes two rows that must be edited, cancelled and reported on together
 *     forever.
 *  2. Periods are regenerable and hard-deletable structure (`PeriodController::destroy()`, hardened
 *     against `master_rota_assignments` in the prior migration but NOT checked against this table —
 *     a vacation has nothing to orphan). A vacation must survive a department switching from months
 *     to week-blocks, the same hazard `periods.kind` is stored per row to defend against.
 *  3. Which period(s) a vacation touches is a RANGE INTERSECTION computed at read time, not a
 *     foreign key — MR-07's per-period summary derives it from `starts_on`/`ends_on` against
 *     whichever periods happen to exist when the summary renders.
 *
 * `granularity` (`'week'`|`'date'`) records HOW the leave was entered and is edited — the stored
 * dates are always real dates, one canonical representation. A `week` booking is snapped by
 * `App\Support\Rota\VacationBooking` to the department's own week boundaries
 * (`App\Support\Calendar::weekOf()`, Task 2) before it ever reaches this table; this column never
 * drives a query, only an edit form's initial control state.
 *
 * `source` (`'manual'`|`'import'`) records how the row was created. AR-05's third source — an
 * approved RQ-01 leave request becoming a vacation — is a P3 hook, named and not built.
 *
 * NO SOFT DELETE (Decision E) — the same reasoning `master_rota_assignments` carries: schedule
 * structure, not a clinical row, and the hash-chained `audit_log` is the history.
 *
 * NO `notes` COLUMN — none of Munawib's MR-* requirements ask for one here, and adding one would
 * buy a `ContactFieldsAreProjectedOnceTest` guard exception for a column no requirement wants.
 *
 * `institution_id` is provenance and in-instance grouping only (D11) — never a query filter, never
 * part of a key. `InstitutionProvenanceTest`'s index/unique pattern has no allow-list at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

            // cascadeOnDelete matches `master_rota_assignments`: people are SOFT-deleted (owner
            // ruling), so this FK never fires in practice; it exists so a hard delete in a future
            // data-repair script cannot leave a vacation pointing at nobody.
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();

            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('granularity', 10);
            $table->string('source', 20);
            $table->timestamps();

            $table->index('person_id');
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacations');
    }
};
