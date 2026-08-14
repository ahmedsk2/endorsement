<?php

namespace Tests\Feature\Build;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A `hasColumn` GUARD MUST COVER ONE STATEMENT, NOT ONE BLOCK.
 *
 * MySQL has no transactional DDL: a migration stops between two of the statements it emits and
 * leaves the earlier ones applied, with nothing recorded in `migrations`. Six of the twenty-two
 * P1 migrations are written to survive that — every column wrapped in `Schema::hasColumn`, so a
 * retry picks up where the previous attempt stopped. `docs/DEPLOY-P1-2026-08-12.md` §9.0 now tells
 * operators those six are safe to re-run.
 *
 * FIVE OF THE SIX ARE. The sixth was not, and the hole is invisible because the retry SUCCEEDS.
 * `2026_08_14_120002_add_provenance_to_person_levels` emitted five statements from three blocks —
 * `->index()` is its own `alter table … add index`, `->constrained()` its own `add constraint` —
 * and the guard was one `hasColumn` per block. A failure landing between the column and its index
 * left the column present, so the retry skipped the block, recorded the migration as Ran, and the
 * index was **permanently missing with no error anywhere**. Measured on MySQL 8.4 on 2026-08-12
 * (`docs/REHEARSAL-MYSQL-2026-08-12.md` §5.6): `migrate` reported the migration DONE, restored
 * `reason`, `created_by` and its foreign key, and left `person_levels_promotion_batch_id_index`
 * absent.
 *
 * This test reproduces exactly that residue — the state a failure between statements 1 and 2
 * leaves — and asserts the retry restores ALL of it. It is written over the real migration rather
 * than a synthetic one so it keeps guarding the file it is about, and it is engine-independent:
 * the residue is built with the schema builder, not with raw MySQL DDL.
 *
 * Watched failing against the pre-fix migration (the index stayed absent while every other
 * assertion passed) before the guard was split.
 */
class GuardedMigrationsRetryCompletelyTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_08_14_120002_add_provenance_to_person_levels';

    private const INDEX = 'person_levels_promotion_batch_id_index';

    public function test_a_migration_that_stopped_between_a_column_and_its_index_restores_the_index(): void
    {
        $this->assertTrue(Schema::hasIndex('person_levels', self::INDEX), 'precondition');

        // The residue of a run that emitted statement 1 and died before statement 2: the column
        // is there, its index is not, and `migrations` never recorded the migration.
        Schema::table('person_levels', function ($table) {
            $table->dropIndex(self::INDEX);
        });
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        $this->assertFalse(Schema::hasIndex('person_levels', self::INDEX));
        $this->assertTrue(Schema::hasColumn('person_levels', 'promotion_batch_id'));

        $exit = Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(0, $exit, "migrate exited non-zero:\n".Artisan::output());
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());

        // The point of the test. Before the fix this was false, the migration was recorded as Ran,
        // and nothing would ever add the index again.
        $this->assertTrue(
            Schema::hasIndex('person_levels', self::INDEX),
            'the retry recorded the migration as Ran and left its index permanently missing'
        );
    }

    public function test_a_migration_that_stopped_between_a_column_and_its_foreign_key_restores_the_key(): void
    {
        // Same shape one statement later: `->constrained()` is a separate `add constraint`.
        Schema::table('person_levels', function ($table) {
            $table->dropForeign(['created_by']);
        });
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        $this->assertFalse($this->hasCreatedByForeignKey());

        $exit = Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(0, $exit, "migrate exited non-zero:\n".Artisan::output());
        $this->assertTrue(
            $this->hasCreatedByForeignKey(),
            'the retry left `person_levels.created_by` with no foreign key'
        );
    }

    public function test_re_running_the_migration_against_a_complete_table_changes_nothing(): void
    {
        // The ordinary case the guards exist for: every check false, no statement emitted, and
        // nothing lost. Guards that only work on residue would be worse than none.
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        $exit = Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(0, $exit, "migrate exited non-zero:\n".Artisan::output());
        $this->assertTrue(Schema::hasIndex('person_levels', self::INDEX));
        $this->assertTrue(Schema::hasColumn('person_levels', 'reason'));
        $this->assertTrue(Schema::hasColumn('person_levels', 'created_by'));
        $this->assertTrue($this->hasCreatedByForeignKey());
    }

    private function hasCreatedByForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('person_levels'))
            ->contains(fn (array $key) => in_array('created_by', $key['columns'], true));
    }
}
