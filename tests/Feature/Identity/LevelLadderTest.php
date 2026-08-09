<?php

namespace Tests\Feature\Identity;

use App\Models\Level;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Munawib LV-01's `external` flag on the level ladder.
 *
 * OWNER DECISION A (2026-08-09, plan `2026-08-09-p1b-structure-admin.md`) overrode the P1
 * plan's original Task 6 text, which also asked for a `terminal`/graduating marker plus
 * `Level::nextAfter()` as "the one definition of advance one level". The owner rejected the
 * inference entirely: a wrong terminal marker fails silently in two directions — an unmarked
 * top level lets a cohort advance into a level that does not exist, and a wrongly-marked
 * middle level graduates a cohort a year early. Neither is built here. P1c's annual promotion
 * stays a single-action, previewed, audited operation, but the OPERATOR chooses the target
 * level explicitly.
 */
class LevelLadderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_level_defaults_external_false(): void
    {
        $level = Level::factory()->create(['code' => 'R1']);

        $this->assertFalse($level->fresh()->external);
    }

    public function test_external_casts_to_a_boolean_not_a_string(): void
    {
        $level = Level::factory()->create(['code' => 'R2', 'external' => 1]);

        $this->assertIsBool($level->fresh()->external);
    }

    public function test_scope_internal_excludes_external_levels(): void
    {
        Level::factory()->create(['code' => 'R1', 'external' => false, 'display_order' => 10]);
        Level::factory()->create(['code' => 'R2', 'external' => false, 'display_order' => 20]);
        Level::factory()->create(['code' => 'EXT', 'external' => true, 'display_order' => 90]);

        $this->assertSame(
            ['R1', 'R2'],
            Level::query()->internal()->ordered()->pluck('code')->all()
        );
    }

    public function test_scope_ordered_still_orders_by_display_order_then_id(): void
    {
        $b = Level::factory()->create(['code' => 'B', 'display_order' => 10]);
        $a = Level::factory()->create(['code' => 'A', 'display_order' => 10]);
        $c = Level::factory()->create(['code' => 'C', 'display_order' => 20]);

        $this->assertSame(
            [$b->id, $a->id, $c->id],
            Level::query()->ordered()->pluck('id')->all()
        );
    }

    /**
     * Pins Owner Decision A so it cannot silently regress: no `terminal` column, and no
     * `Level::nextAfter()` inference method. Whatever P1c's promotion screen needs, it takes
     * the target level as explicit input rather than reading it off the level ladder.
     *
     * WIDENED in P1c Task 10 to scan `app/` as a whole, not just `Level.php` — P1c's promotion
     * screen is the first plan with a live reason to WANT the inference, so this is the first
     * time the guard has anything real to catch. A plain substring scan, deliberately coarse
     * (same species as `PersonLevelsHaveOneWriterTest`/`ContactFieldsAreProjectedOnceTest`): the
     * point is a stop-sign a future PR trips over, not perfect precision.
     */
    public function test_there_is_no_terminal_column_and_no_next_after_inference(): void
    {
        $this->assertFalse(Schema::hasColumn('levels', 'terminal'));
        $this->assertFalse(method_exists(Level::class, 'nextAfter'));

        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) File::get($file->getPathname());

            foreach (['nextAfter', "'terminal'", '->terminal'] as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())).' contains '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders,
            "P1b Owner Decision A: no terminal marker and no next-level inference anywhere in app/.\n"
            .implode("\n", $offenders));
    }
}
