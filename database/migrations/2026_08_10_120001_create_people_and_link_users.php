<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D3 (REVERSED 2026-08-08) — the roster becomes its own table.
 *
 * `people` is who someone IS: the name of record on a handover sheet, the row a rota assignment
 * points at, the person a duty-hours report counts. `users` stays what it has always been: an
 * authentication record. The link is one-to-at-most-one (`users.person_id` UNIQUE).
 *
 * The whole security argument rests on one property of this schema: THERE IS NO CREDENTIAL ON
 * `people`. No password, no login handle, no remember token, no second factor. A person who has
 * never claimed an account has no row in `users`, so `AuthenticatedSessionController`'s lookup by
 * `member_name`, the password broker's lookup by `member_email`, and
 * `EloquentUserProvider::retrieveById/retrieveByToken` all find nothing — with no new gate
 * anywhere and all six existing `active` defences untouched. Do not add a credential column here.
 *
 * BACKFILL: every existing `users` row is, by definition, a claimed account, so each gets exactly
 * one person carrying its name, position, address and institution. Soft-deleted accounts are
 * INCLUDED — a trashed account's person is still the name of record on every sheet they signed.
 *
 * REVERSIBLE: `down()` drops the `person_id` FK/unique index off `users` and drops `people`
 * outright. Nothing is copied back onto `users` here (there is nothing to copy — `full_name`
 * and `position` still live on `users` at this point in the migration sequence; they only move
 * in 2026_08_10_120003). Rolling back this migration after 120003 has already dropped those
 * columns would lose the name/position data entirely, so the mandated order is: roll back
 * 120003 first (which restores `users.full_name`/`users.position` by copying back through
 * `person_id`), THEN roll back this migration. See docs/RUNBOOK-DEPLOY.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

            // The name of record. NOT NULL: a person with no name cannot be named on a sheet.
            $table->string('full_name');

            // Munawib `shortName` — the ROTA handle, distinct from `users.member_name`, the LOGIN
            // handle. Unique outright, not per institution: `institution_id` is nullable and a
            // UNIQUE index treats NULLs as distinct on both MySQL/InnoDB and SQLite, so
            // UNIQUE(institution_id, short_name) would be toothless for exactly the bootstrap and
            // fixture rows. D11 makes one database one customer, so plain UNIQUE is both honest
            // and enforceable.
            $table->string('short_name', 50)->nullable()->unique();

            // Job role. Orthogonal to training level (design §5.1): a person is a Resident AND a
            // PGY-2. This is the ONLY copy — `users.position` is dropped in 2026_08_10_120003.
            $table->unsignedTinyInteger('position')->index();

            // The roster/contact address and the roster-import matching key. `users.member_email`
            // survives separately because Laravel's password broker resolves accounts with
            // `User::where('member_email', …)`; see the plan's finding 6.
            $table->string('email')->nullable()->unique();

            // PE-01 staff personal data. PDPL: `phone` and `notes` must never reach audit_log
            // details, exception messages, URLs or push payloads — the same rule as PHI.
            $table->string('phone', 32)->nullable();
            $table->date('joined_at')->nullable();
            // Deliberately PLAINTEXT (owner decision 3, 2026-08-08 — OVERRIDES this migration's
            // original comment, which said this would be encrypted like `reopen_reason` before
            // the owner decided). `$hidden` on the Person model keeps it out of any serialised
            // response; docs/COMPLIANCE.md records that it is therefore legible in a raw DB read
            // and in backups.
            $table->text('notes')->nullable();

            // PE-01 structured scheduling constraints, read by the solver. Deliberately NOT
            // encrypted: Rota holds no PHI (design §9.2), the engine and solver must read these,
            // and `text` + `encrypted:array` would forfeit any SQL-side querying and force a
            // retype later — which the project rules forbid on a column holding real data.
            $table->json('constraints')->nullable();

            // PE-03 ad-hoc external rotator. NOT nullable: a three-valued "is this external" is a
            // bug generator.
            $table->boolean('external')->default(false);

            // Governs whether this person may be NAMED. `users.active` separately governs whether
            // they may AUTHENTICATE. Keeping those two questions apart is the point of D3's
            // reversal; never express one as the other.
            $table->boolean('active')->default(true)->index();

            $table->timestamps();
            $table->softDeletes();   // people are deactivated, never deleted (owner ruling)
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->after('id')
                ->constrained('people')->nullOnDelete();
            // At most one account per person.
            $table->unique('person_id');
        });

        $now = now()->toDateTimeString();

        DB::table('users')
            ->select('id', 'full_name', 'member_name', 'member_email', 'position', 'active', 'institution_id', 'created_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($now) {
                foreach ($rows as $u) {
                    $personId = DB::table('people')->insertGetId([
                        'institution_id' => $u->institution_id,
                        // `users.full_name` is nullable; `member_name` is not. Fall back rather
                        // than insert a NULL into a NOT NULL column and abort the migration.
                        'full_name' => (string) ($u->full_name ?? $u->member_name),
                        'position' => (int) $u->position,
                        'email' => $u->member_email,
                        'external' => false,
                        'active' => (bool) $u->active,
                        'created_at' => $u->created_at ?? $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('users')->where('id', $u->id)->update(['person_id' => $personId]);
                }
            });
    }

    public function down(): void
    {
        // THREE STATEMENTS, IN THIS ORDER, BECAUSE THE TWO ENGINES CONSTRAIN OPPOSITE ENDS OF IT.
        //
        // InnoDB refuses `dropUnique(['person_id'])` while the foreign key still exists —
        // `SQLSTATE[HY000]: 1553 Cannot drop index 'users_person_id_unique': needed in a foreign
        // key constraint`, because an FK column must keep an index and this unique one was the
        // only candidate. That is what the 2026-08-09 MySQL rehearsal found.
        //
        // SQLite refuses the opposite: it will not drop a column while any index still names it —
        // `error in index users_person_id_unique after drop column: no such column: "person_id"`.
        // The fix for MySQL was `dropConstrainedForeignId('person_id')` alone, on the belief that
        // it "drops the unique index that lived on that same column" too. It does not: it emits
        // dropForeign + dropColumn and nothing that removes the separate unique index, so on
        // SQLite the column drop fails and `migrate:rollback` stops mid-batch. Measured
        // 2026-08-12 in the upgrade-path rehearsal (docs/REHEARSAL-UPGRADE-2026-08-12.md).
        //
        // Dropping the constraint FIRST, then the index, then the column satisfies both: once the
        // FK is gone InnoDB has no reason to hold the index, and once the index is gone SQLite has
        // no reason to hold the column.
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
            $table->dropUnique(['person_id']);
            $table->dropColumn('person_id');
        });

        Schema::dropIfExists('people');
    }
};
