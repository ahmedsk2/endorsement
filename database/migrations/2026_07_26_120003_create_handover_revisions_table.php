<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each clinical field said BEFORE it was changed.
 *
 * This system's purpose is a medico-legal handover record, and its core integrity property
 * is "what was written and attested at handover time". Nothing preserved that. `updateRow`
 * overwrote in place and audited `row=<id>` — proving THAT an edit happened while retaining
 * nothing about WHAT was originally there. So a reopen, a rewrite and a re-sign after an
 * adverse event left an audit trail that looks orderly and a record whose earlier content
 * existed only in a nightly backup, pruned after fourteen days.
 *
 * Append-only by intent: rows are written, never updated or deleted. The previous VALUE is
 * stored, encrypted with the same casts as the live column — this is patient data and gets
 * the same protection, not less because it is historical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handover_revisions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('handover_id')->constrained('handovers')->cascadeOnDelete();

            // Which field changed, and what it held before. `old_value` is nullable because
            // a field can go from empty to filled.
            $table->string('field', 32);
            $table->text('old_value')->nullable();

            // Who changed it. Nullable so a console or import-time change is still recorded
            // rather than being refused.
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Whether the day was SIGNED at the moment of the change. An edit to a signed
            // day is the case that matters: it means the attestation no longer describes
            // what was attested.
            $table->boolean('day_was_signed')->default(false);

            $table->timestamp('created_at')->nullable();

            $table->index(['handover_id', 'created_at'], 'handover_revisions_row_time_index');
            $table->index('created_at', 'handover_revisions_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handover_revisions');
    }
};
