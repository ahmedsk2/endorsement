<?php

namespace Tests\Feature\Build;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * EVERY `down()` IN THE CHAIN, RUN — IN ORDER, AGAINST ROWS.
 *
 * Nothing else in this suite ever calls a `down()`. `RefreshDatabase` and `migrate:fresh` only
 * go forward, so a `down()` can be wrong for months and every one of the other tests stays
 * green. The 2026-08-12 upgrade-path rehearsal (docs/REHEARSAL-UPGRADE-2026-08-12.md) found
 * THREE broken ones this way and they all failed identically:
 *
 *   error in index <name> after drop column: no such column: "<column>"
 *
 * SQLite refuses to drop a column while any index still names it. `dropConstrainedForeignId()`
 * emits dropForeign + dropColumn and nothing that removes a SEPARATE index on the same column,
 * so a `down()` that leans on it to clean up an explicit `->index()`/`->unique()` fails. It fails
 * MID-BATCH: `migrate:rollback` has by then already reversed every later migration and dropped
 * their tables, and exits 1 with the chain half-unwound.
 *
 * The test reverses the WHOLE chain rather than the P1 slice, so it does not need a hard-coded
 * count that a future migration would silently invalidate, and it then re-applies it — a `down()`
 * that leaves residue an `up()` then trips over is the same defect one step later.
 */
class MigrationChainRollsBackTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_down_runs_in_order_and_the_chain_re_applies(): void
    {
        $unit = Unit::query()->firstOrCreate(['code' => 'PICU'], ['name' => 'Pediatric Intensive Care Unit']);
        $user = User::factory()->create();
        $personId = $user->person_id;

        $this->assertNotNull($personId, 'the fixture must exercise the identity migrations');

        $total = DB::table('migrations')->count();
        $this->assertGreaterThan(40, $total, 'the whole chain should be recorded, not a subset');

        $rollback = Artisan::call('migrate:rollback', ['--step' => $total, '--force' => true]);

        $this->assertSame(0, $rollback, "migrate:rollback exited non-zero:\n".Artisan::output());
        $this->assertSame(0, DB::table('migrations')->count(), 'the chain did not fully reverse');
        $this->assertFalse(Schema::hasTable('people'));
        $this->assertFalse(Schema::hasTable('users'));

        $forward = Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(0, $forward, "migrate exited non-zero after a rollback:\n".Artisan::output());
        $this->assertSame($total, DB::table('migrations')->count());
        $this->assertTrue(Schema::hasColumn('users', 'person_id'));
        $this->assertTrue(Schema::hasColumn('person_levels', 'promotion_batch_id'));
        $this->assertTrue(Schema::hasColumn('invitations', 'person_id'));

        // And the columns 2026_08_10_120003 moved off `users` are still gone, not resurrected by
        // a `down()` that ran when it should not have.
        $this->assertFalse(Schema::hasColumn('users', 'full_name'));
        $this->assertFalse(Schema::hasColumn('users', 'position'));
    }
}
