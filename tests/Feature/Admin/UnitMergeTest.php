<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Unit;
use App\Models\UnitFieldDefinition;
use App\Models\User;
use App\Support\UnitMerge;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Munawib UN-01's merge — the highest-risk task in P1b. It touches SIGNED clinical records.
 *
 * THE COLLISION: `handover_signoffs` carries UNIQUE(unit_id, handover_date)
 * (2026_07_24_130002:77). If both the source and the target already have a signed day on the
 * same date, re-pointing the source's row onto the target violates that index. The merge must
 * surface every such date in a PREVIEW the administrator confirms, and must never discover the
 * collision as a 23000 mid-insert.
 *
 * WHAT A MERGE NEVER DOES: delete a clinical row, delete a sign-off, or delete the source unit.
 * The source is retired (`active = false`) and stays in the database by id.
 */
class UnitMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
        $this->admin = User::factory()->create(['position' => 0]);
    }

    protected function tearDown(): void
    {
        // A couple of tests register a temporary HandoverSignoff::saving() listener to force a
        // mid-transaction failure; this class has no listeners of its own (grepped before
        // writing this file), so flushing after every test is a complete, safe cleanup rather
        // than a partial one.
        HandoverSignoff::flushEventListeners();
        parent::tearDown();
    }

    public function test_a_dry_run_preview_reports_counts_per_table_and_colliding_dates_with_no_phi(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');

        Handover::create([
            'unit_id' => $source->id, 'handover_date' => '2026-08-08',
            'mrn' => 'M-999888', 'patient_name' => 'Zzuniquechildname',
        ]);
        Handover::create(['unit_id' => $source->id, 'handover_date' => '2026-08-09', 'mrn' => 'M-2']);
        HandoverSignoff::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08']);
        HandoverSignoff::create(['unit_id' => $target->id, 'handover_date' => '2026-08-08']);
        HandoverSignoff::create(['unit_id' => $source->id, 'handover_date' => '2026-08-09']);

        $response = $this->actingAs($this->admin)
            ->get("/admin/structure/units/merge?source={$source->id}&target={$target->id}")
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/UnitMerge')
            ->where('plan.handovers', 2)
            ->where('plan.field_definitions', 0)
            ->where('plan.preferred_unit_users', 0)
            ->where('plan.collisions', ['2026-08-08']));

        // Counts and dates only — never patient content, encrypted or not.
        $this->assertStringNotContainsString('Zzuniquechildname', $response->getContent());
        $this->assertStringNotContainsString('M-999888', $response->getContent());
    }

    public function test_a_merge_with_no_collisions_repoints_everything_and_retires_the_source(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');

        Handover::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08', 'mrn' => 'M-1']);
        Handover::create(['unit_id' => $source->id, 'handover_date' => '2026-08-09', 'mrn' => 'M-2']);
        $signoff = HandoverSignoff::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08']);
        $definition = UnitFieldDefinition::create([
            'unit_id' => $source->id, 'key' => 'weight', 'label' => 'Weight',
        ]);
        $user = User::factory()->create(['preferred_unit_id' => $source->id]);

        $this->actingAs($this->admin)
            ->post('/admin/structure/units/merge', [
                'source_unit_id' => $source->id,
                'target_unit_id' => $target->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Handover::where('unit_id', $target->id)->count());
        $this->assertSame(0, Handover::where('unit_id', $source->id)->count());
        $this->assertSame($target->id, $signoff->fresh()->unit_id);
        $this->assertSame($target->id, $definition->fresh()->unit_id);
        $this->assertSame($target->id, $user->fresh()->preferred_unit_id);

        // The source unit ROW is never deleted — only retired.
        $this->assertNotNull(Unit::find($source->id));
        $this->assertFalse($source->fresh()->active);
    }

    /** A refusal — not a 23000 — the moment both units are signed for the same date. */
    public function test_a_signoff_collision_is_refused_without_a_named_resolution(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');

        HandoverSignoff::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08']);
        HandoverSignoff::create(['unit_id' => $target->id, 'handover_date' => '2026-08-08']);

        $this->actingAs($this->admin)
            ->post('/admin/structure/units/merge', [
                'source_unit_id' => $source->id,
                'target_unit_id' => $target->id,
            ])
            ->assertSessionHasErrors('resolution');

        // Nothing moved and nothing was retired — a refusal, not a partial write.
        $this->assertSame($source->id, HandoverSignoff::whereDate('handover_date', '2026-08-08')
            ->where('unit_id', $source->id)->first()?->unit_id);
        $this->assertTrue($source->fresh()->active);
    }

    /**
     * `keep_target`: the handover ROWS for the colliding date still move — the source's signoff
     * HEADER for that date never does, and is never deleted. A non-colliding date moves
     * normally. The target's own pre-existing signoff for the colliding date is untouched.
     */
    public function test_keep_target_moves_handovers_but_leaves_the_colliding_signoff_header_on_the_source(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');

        Handover::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08', 'mrn' => 'M-1']);
        $sourceCollidingSignoff = HandoverSignoff::create([
            'unit_id' => $source->id, 'handover_date' => '2026-08-08', 'endorsement_time' => '7:30 Am',
        ]);
        $targetSignoff = HandoverSignoff::create([
            'unit_id' => $target->id, 'handover_date' => '2026-08-08', 'endorsement_time' => '15:30',
        ]);
        // A second, NON-colliding date: PICU has no signoff on 08-09, so this one moves normally.
        $sourceCleanSignoff = HandoverSignoff::create(['unit_id' => $source->id, 'handover_date' => '2026-08-09']);

        $this->actingAs($this->admin)
            ->post('/admin/structure/units/merge', [
                'source_unit_id' => $source->id,
                'target_unit_id' => $target->id,
                'resolution' => UnitMerge::KEEP_TARGET,
                'accepted_collisions' => ['2026-08-08'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The handover row for the colliding date moved to the target...
        $this->assertSame($target->id, Handover::whereDate('handover_date', '2026-08-08')->first()->unit_id);

        // ...but the SOURCE's signoff header for that date stayed exactly where it was, and was
        // never soft- or hard-deleted.
        $sourceCollidingSignoff->refresh();
        $this->assertSame($source->id, $sourceCollidingSignoff->unit_id);
        $this->assertNull($sourceCollidingSignoff->deleted_at);
        $this->assertNotNull(HandoverSignoff::withTrashed()->find($sourceCollidingSignoff->id));

        // The target's own pre-existing signoff for that date is completely untouched.
        $targetSignoff->refresh();
        $this->assertSame($target->id, $targetSignoff->unit_id);
        $this->assertSame('15:30', $targetSignoff->endorsement_time);

        // The non-colliding date's signoff DID move — only the colliding one stayed behind.
        $this->assertSame($target->id, $sourceCleanSignoff->fresh()->unit_id);
    }

    /** The confirm list must match the actual collisions exactly, or the merge refuses. */
    public function test_accepted_collisions_must_match_the_real_collisions_exactly(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');

        HandoverSignoff::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08']);
        HandoverSignoff::create(['unit_id' => $target->id, 'handover_date' => '2026-08-08']);

        $this->actingAs($this->admin)
            ->post('/admin/structure/units/merge', [
                'source_unit_id' => $source->id,
                'target_unit_id' => $target->id,
                'resolution' => UnitMerge::KEEP_TARGET,
                'accepted_collisions' => ['2099-01-01'],
            ])
            ->assertSessionHasErrors('accepted_collisions');

        $this->assertTrue($source->fresh()->active);
    }

    /** `abort` is a named, explicit refusal — distinct from simply not submitting. */
    public function test_abort_resolution_makes_no_changes(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');

        HandoverSignoff::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08']);
        HandoverSignoff::create(['unit_id' => $target->id, 'handover_date' => '2026-08-08']);

        $this->actingAs($this->admin)
            ->post('/admin/structure/units/merge', [
                'source_unit_id' => $source->id,
                'target_unit_id' => $target->id,
                'resolution' => UnitMerge::ABORT,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($source->fresh()->active);
        $this->assertSame($source->id, HandoverSignoff::whereDate('handover_date', '2026-08-08')
            ->where('unit_id', $source->id)->first()?->unit_id);
        $this->assertDatabaseMissing('audit_log', ['action' => 'unit_merge']);
    }

    public function test_merging_a_unit_into_itself_is_refused(): void
    {
        $unit = Unit::findByCode('PICU');

        $this->actingAs($this->admin)
            ->post('/admin/structure/units/merge', [
                'source_unit_id' => $unit->id,
                'target_unit_id' => $unit->id,
            ])
            ->assertSessionHasErrors('target_unit_id');
    }

    public function test_merging_into_an_inactive_target_is_refused(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');
        $target->update(['active' => false]);

        $this->actingAs($this->admin)
            ->post('/admin/structure/units/merge', [
                'source_unit_id' => $source->id,
                'target_unit_id' => $target->id,
            ])
            ->assertSessionHasErrors('target_unit_id');

        $this->assertTrue($source->fresh()->active);
    }

    /**
     * Two units each defining a custom field under the same key would collide on
     * `unit_field_definitions`' own UNIQUE(unit_id, key) exactly like a signoff date does — the
     * plan's own text names only the signoff collision, but the schema carries a second one.
     * Refused before any write, matching finding 14's discipline for the signoff case.
     */
    public function test_a_conflicting_custom_field_key_is_refused_before_any_write(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');

        UnitFieldDefinition::create(['unit_id' => $source->id, 'key' => 'weight', 'label' => 'Weight (source)']);
        UnitFieldDefinition::create(['unit_id' => $target->id, 'key' => 'weight', 'label' => 'Weight (target)']);
        Handover::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08', 'mrn' => 'M-1']);

        $this->actingAs($this->admin)
            ->post('/admin/structure/units/merge', [
                'source_unit_id' => $source->id,
                'target_unit_id' => $target->id,
            ])
            ->assertSessionHasErrors('target_unit_id');

        // Nothing moved — the refusal happened before any write, not mid-transaction.
        $this->assertSame(1, Handover::where('unit_id', $source->id)->count());
        $this->assertTrue($source->fresh()->active);
    }

    /**
     * The whole merge is ONE transaction. A failure forced partway through (here, on the
     * SECOND signoff row saved) must leave every table exactly as it was — including the
     * handovers already re-pointed and the first signoff already saved in the same call.
     */
    public function test_a_forced_failure_mid_merge_leaves_every_table_exactly_as_it_was(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');

        Handover::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08', 'mrn' => 'M-1']);
        HandoverSignoff::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08']);
        HandoverSignoff::create(['unit_id' => $source->id, 'handover_date' => '2026-08-09']);

        HandoverSignoff::saving(function (HandoverSignoff $model): void {
            if ($model->handover_date?->format('Y-m-d') === '2026-08-09') {
                throw new \RuntimeException('Simulated mid-merge failure.');
            }
        });

        try {
            UnitMerge::commit($source, $target, [], $this->admin->id, '127.0.0.1');
            $this->fail('Expected the forced failure to propagate out of commit().');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated mid-merge failure.', $e->getMessage());
        }

        // The handover re-pointed in step 1 of the SAME transaction is back on the source.
        $this->assertSame(1, Handover::where('unit_id', $source->id)->count());
        $this->assertSame(0, Handover::where('unit_id', $target->id)->count());
        // The FIRST signoff, already saved before the forced failure, is rolled back too.
        $this->assertSame(2, HandoverSignoff::where('unit_id', $source->id)->count());
        $this->assertSame(0, HandoverSignoff::where('unit_id', $target->id)->count());
        $this->assertTrue($source->fresh()->active);
    }

    public function test_a_summary_audit_row_and_one_row_per_resolved_collision_are_recorded(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');

        Handover::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08', 'mrn' => 'M-1']);
        HandoverSignoff::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08']);
        HandoverSignoff::create(['unit_id' => $target->id, 'handover_date' => '2026-08-08']);

        $this->actingAs($this->admin)
            ->post('/admin/structure/units/merge', [
                'source_unit_id' => $source->id,
                'target_unit_id' => $target->id,
                'resolution' => UnitMerge::KEEP_TARGET,
                'accepted_collisions' => ['2026-08-08'],
            ])
            ->assertRedirect();

        $summary = AuditLog::where('action', 'unit_merge')->latest('id')->first();
        $this->assertNotNull($summary);
        $this->assertStringContainsString('source='.$source->id, $summary->detail);
        $this->assertStringContainsString('target='.$target->id, $summary->detail);
        $this->assertStringContainsString('handovers=1', $summary->detail);
        $this->assertStringNotContainsString('M-1', $summary->detail);

        $collisionRow = AuditLog::where('action', 'unit_merge_collision_kept')->latest('id')->first();
        $this->assertNotNull($collisionRow);
        $this->assertStringContainsString('date=2026-08-08', $collisionRow->detail);
    }

    public function test_a_resident_is_forbidden(): void
    {
        $resident = User::factory()->create(['position' => 4]);
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');

        $this->actingAs($resident)
            ->post('/admin/structure/units/merge', [
                'source_unit_id' => $source->id,
                'target_unit_id' => $target->id,
            ])
            ->assertForbidden();

        $this->actingAs($resident)
            ->get("/admin/structure/units/merge?source={$source->id}&target={$target->id}")
            ->assertForbidden();
    }

    public function test_after_the_merge_the_source_404s_and_the_target_shows_the_merged_day(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');
        Handover::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08', 'mrn' => 'M-1']);

        $this->actingAs($this->admin)->post('/admin/structure/units/merge', [
            'source_unit_id' => $source->id,
            'target_unit_id' => $target->id,
        ])->assertRedirect();

        $this->actingAs($this->admin)->get('/endorsement/nicu')->assertNotFound();
        $this->actingAs($this->admin)->get('/endorsement/picu/2026-08-08')->assertOk();
    }
}
