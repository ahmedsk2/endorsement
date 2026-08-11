<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Clinic;
use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\UnitFieldDefinition;
use App\Models\User;
use App\Support\Clinics\ClinicRoster;
use App\Support\Clinics\ClinicWriter;
use App\Support\Push\FakePushSender;
use App\Support\Push\PushSender;
use App\Support\Rota\RotaAssignment;
use App\Support\UnitMerge;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A period to hang rota spans off. `PeriodGenerator` is not involved — these tests are about
     * what a merge moves, not about how a year is cut into blocks.
     */
    private function period(): Period
    {
        return Period::create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-28',
        ]);
    }

    /**
     * `clinic_owner` is opt-in per unit and `ReferenceSeeder` ticks it for WARD only, so the two
     * units these merge tests use have to be told they own clinics before either can hold one.
     */
    private function makeBothClinicOwners(Unit $source, Unit $target): void
    {
        $source->update(['clinic_owner' => true]);
        $target->update(['clinic_owner' => true]);
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

    // --- The three tables a merge used to strand (design §14 item 23) ---------------------------

    /**
     * THE ONE THAT ACTUALLY HURT. `reminder_preferences` is written only from a member's own
     * notification settings and nothing lists or re-points another person's rows, so a stranded
     * opt-in is not a cosmetic leftover: `SendHandoverReminders` iterates ACTIVE units only, the
     * source is retired by the merge, and that account's handover reminders stop for good with no
     * screen anywhere to repair it.
     *
     * The assertion is therefore behavioural, not a column read: the command actually pushes.
     */
    public function test_a_merge_re_points_a_reminder_opt_in_and_the_reminder_still_arrives(): void
    {
        $sender = new FakePushSender;
        $this->app->instance(PushSender::class, $sender);

        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');

        $resident = User::factory()->create(['position' => 4]);
        $resident->reminderUnits()->attach($source->id);
        $resident->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/merge-reminder',
            'p256dh' => 'k-p256dh',
            'auth' => 'k-auth',
        ]);

        $this->actingAs($this->admin)->post('/admin/structure/units/merge', [
            'source_unit_id' => $source->id,
            'target_unit_id' => $target->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([$target->id], $resident->fresh()->reminderUnits()->pluck('units.id')->all());

        // …and the push really lands, on the surviving unit, after a handover window has opened.
        Carbon::setTestNow(Carbon::parse('2026-07-24 13:45:00'));
        $this->artisan('endorsement:remind')->assertExitCode(0);

        $titles = implode(' ', array_map(fn (array $sent): string => $sent[1]['title'], $sender->sent));
        $this->assertStringContainsString($target->code, $titles);
        $this->assertStringNotContainsString($source->code, $titles);
    }

    /**
     * THE ONLY REAL COLLISION OF THE THREE: `reminder_preferences` carries UNIQUE(user_id,
     * unit_id), so an account opted in to BOTH units cannot hold two rows after the merge.
     *
     * The source row is DROPPED rather than kept, and that loses nothing — the surviving target row
     * states the identical fact ("this account wants this unit's reminders") about the unit that
     * survives. It gets no per-row confirmation like a signoff date does, because there is no
     * second reading: this table is pure infrastructure (its own migration says so), holds no
     * clinical or medico-legal content, and a kept-behind row would point at a retired unit the
     * reminder command never visits.
     */
    public function test_an_opt_in_the_account_already_holds_on_the_target_is_dropped_not_duplicated(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');

        $both = User::factory()->create(['position' => 4]);
        $both->reminderUnits()->attach([$source->id, $target->id]);

        $sourceOnly = User::factory()->create(['position' => 4]);
        $sourceOnly->reminderUnits()->attach($source->id);

        $plan = UnitMerge::plan($source, $target);
        $this->assertSame(1, $plan['reminders'], 'One opt-in re-points; the duplicate is counted separately.');
        $this->assertSame(1, $plan['reminders_dropped']);

        $this->actingAs($this->admin)->post('/admin/structure/units/merge', [
            'source_unit_id' => $source->id,
            'target_unit_id' => $target->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([$target->id], $both->fresh()->reminderUnits()->pluck('units.id')->all());
        $this->assertSame([$target->id], $sourceOnly->fresh()->reminderUnits()->pluck('units.id')->all());
        $this->assertSame(0, DB::table('reminder_preferences')->where('unit_id', $source->id)->count());
    }

    /**
     * NO COLLISION IS POSSIBLE HERE, and the case that looks most like one proves it: a person
     * split across BOTH units inside one period.
     *
     * `MasterRotaAssignment::booted()` refuses overlapping spans for one (person, period) pair and
     * is UNIT-BLIND — it never looks at `unit_id` — so re-pointing that column cannot create an
     * overlap the data did not already have. What it does create is two adjacent spans on the same
     * unit, which is a legitimate shape the grid already renders and which is deliberately NOT
     * coalesced: merging them would be a second definition of what a span is, and would erase from
     * the row itself the fact that these were once two rotations.
     */
    public function test_a_merge_re_points_every_rota_span_including_a_split_across_both_units(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');
        $period = $this->period();

        $person = Person::factory()->create();
        RotaAssignment::split($person, $period, [
            ['unit_id' => $source->id, 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-14'],
            ['unit_id' => $target->id, 'starts_on' => '2026-08-15', 'ends_on' => '2026-08-28'],
        ]);

        $plan = UnitMerge::plan($source, $target);
        $this->assertSame(1, $plan['rota_assignments']);

        $this->actingAs($this->admin)->post('/admin/structure/units/merge', [
            'source_unit_id' => $source->id,
            'target_unit_id' => $target->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, MasterRotaAssignment::where('unit_id', $source->id)->count());
        $this->assertSame(2, MasterRotaAssignment::where('unit_id', $target->id)->count());

        // Both spans kept their own dates — nothing was coalesced, nothing was dropped.
        $this->assertSame(
            [['2026-08-01', '2026-08-14'], ['2026-08-15', '2026-08-28']],
            MasterRotaAssignment::where('unit_id', $target->id)->orderBy('starts_on')->get()
                ->map(fn (MasterRotaAssignment $span): array => [
                    $span->starts_on->format('Y-m-d'), $span->ends_on->format('Y-m-d'),
                ])->all()
        );
    }

    /**
     * THE LOAD-BEARING ONE. `clinics` has no unique key a merge can violate — the migration
     * deliberately omits one on `(unit_id, weekday, session)` because two rooms may run one
     * session — so every clinic simply moves. What a clinic-shaped fix ALONE would have broken is
     * this: `ClinicRoster` answers "who is rotating on this unit" from `master_rota_assignments` at
     * read time, so a clinic moved onto the target while its rota spans stayed on the source is a
     * clinic that resolves to NOBODY, with no error anywhere.
     */
    public function test_a_merged_clinic_still_resolves_the_people_whose_rota_moved_with_it(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');
        $this->makeBothClinicOwners($source, $target);
        $period = $this->period();

        $clinic = ClinicWriter::create($source, [
            'name' => 'Follow-up', 'weekday' => 2, 'session' => 'AM',
        ]);
        $person = Person::factory()->create();
        RotaAssignment::set($person, $period, $source);

        // Before the merge the clinic resolves this person on its own unit.
        $this->assertCount(1, ClinicRoster::forDate($clinic, '2026-08-11'));

        $plan = UnitMerge::plan($source, $target);
        $this->assertSame(1, $plan['clinics']);

        $this->actingAs($this->admin)->post('/admin/structure/units/merge', [
            'source_unit_id' => $source->id,
            'target_unit_id' => $target->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $clinic->refresh();
        $this->assertSame($target->id, $clinic->unit_id);

        // The whole point: the clinic and the rota arrived together, so it still has attendees.
        $roster = ClinicRoster::forDate($clinic, '2026-08-11');
        $this->assertCount(1, $roster);
        $this->assertSame((int) $person->getKey(), $roster[0]['id']);

        // And it can still be deactivated and revived — `ClinicWriter::assertOwns()` sees a live,
        // clinic-owning unit, which is precisely what a stranded clinic could not offer it.
        ClinicWriter::setActive($clinic, false);
        ClinicWriter::setActive($clinic, true);
        $this->assertTrue($clinic->fresh()->active);
    }

    /**
     * A clinic cannot land on a unit that does not own clinics — `ClinicWriter` refuses it, and
     * discovering that halfway through the transaction would be exactly the mid-write 23000 the
     * signoff collision handling exists to prevent. Refused up front, naming the control that fixes
     * it, with nothing moved.
     */
    public function test_a_merge_is_refused_when_the_target_does_not_own_the_source_s_clinics(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');
        $source->update(['clinic_owner' => true]);   // …and the target deliberately does not.

        ClinicWriter::create($source, ['name' => 'Follow-up', 'weekday' => 2, 'session' => 'AM']);
        Handover::create(['unit_id' => $source->id, 'handover_date' => '2026-08-08', 'mrn' => 'M-1']);

        $this->assertSame(1, UnitMerge::clinicsTheTargetCannotOwn($source, $target));

        $this->actingAs($this->admin)->post('/admin/structure/units/merge', [
            'source_unit_id' => $source->id,
            'target_unit_id' => $target->id,
        ])->assertSessionHasErrors('target_unit_id');

        // Refused BEFORE any write — not a partial merge.
        $this->assertSame(1, Handover::where('unit_id', $source->id)->count());
        $this->assertSame(1, Clinic::where('unit_id', $source->id)->count());
        $this->assertTrue($source->fresh()->active);
    }

    /** The preview an operator confirms must count what will move, per table, before it moves. */
    public function test_the_preview_counts_the_rota_clinic_and_reminder_rows_the_merge_will_move(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');
        $this->makeBothClinicOwners($source, $target);
        $period = $this->period();

        ClinicWriter::create($source, ['name' => 'Follow-up', 'weekday' => 2, 'session' => 'AM']);
        ClinicWriter::create($source, ['name' => 'Second room', 'weekday' => 2, 'session' => 'AM']);
        RotaAssignment::set(Person::factory()->create(), $period, $source);
        User::factory()->create(['position' => 4])->reminderUnits()->attach($source->id);

        $this->actingAs($this->admin)
            ->get("/admin/structure/units/merge?source={$source->id}&target={$target->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/UnitMerge')
                ->where('plan.rota_assignments', 1)
                ->where('plan.clinics', 2)
                ->where('plan.reminders', 1)
                ->where('plan.reminders_dropped', 0)
                ->where('clinics_the_target_cannot_own', 0));
    }

    /**
     * One transaction over all seven tables — and the failure is forced at the LAST step, after
     * every one of them has already been written, or this proves nothing about the three tables the
     * fix added. (Written first with the sibling test's `HandoverSignoff::saving()` throw, it passed
     * on a tree where the merge did not touch those tables at all: the throw fires at step 2, so the
     * later steps never ran and the assertions below were true for the wrong reason.)
     *
     * THE FAILURE IS A REAL PRODUCTION GUARD, not a test-only listener: `Unit::booted()` refuses a
     * reserved code, and the source's in-memory code is set to one so that step 8's retirement —
     * the last write inside the transaction — throws. A `Unit::saving()` listener would have needed
     * `Unit::flushEventListeners()` in tearDown, and unlike `HandoverSignoff`, `Unit` HAS a booted
     * listener of its own; flushing would silently disable that guard for the rest of the process.
     */
    public function test_a_failure_at_the_last_step_takes_the_rota_clinics_and_reminders_back(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');
        $this->makeBothClinicOwners($source, $target);
        $period = $this->period();

        $clinic = ClinicWriter::create($source, ['name' => 'Follow-up', 'weekday' => 2, 'session' => 'AM']);
        RotaAssignment::set(Person::factory()->create(), $period, $source);
        User::factory()->create(['position' => 4])->reminderUnits()->attach($source->id);
        Handover::create(['unit_id' => $source->id, 'handover_date' => '2026-08-09', 'mrn' => 'M-1']);

        // In memory only — never saved, and never true of the row in the database.
        $source->code = Unit::RESERVED_CODES[0];

        try {
            UnitMerge::commit($source, $target, [], $this->admin->id, '127.0.0.1');
            $this->fail('Expected the reserved-code guard to fire on the final retirement write.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('reserved', $e->getMessage());
        }

        $source = Unit::findByCode('NICU');
        $this->assertSame($source->id, $clinic->fresh()->unit_id);
        $this->assertSame(1, MasterRotaAssignment::where('unit_id', $source->id)->count());
        $this->assertSame(1, DB::table('reminder_preferences')->where('unit_id', $source->id)->count());
        $this->assertSame(0, DB::table('reminder_preferences')->where('unit_id', $target->id)->count());
        $this->assertSame(1, Handover::where('unit_id', $source->id)->count());
        $this->assertTrue($source->active);
        $this->assertDatabaseMissing('audit_log', ['action' => 'unit_merge']);
    }

    /** Ids and counts only — no unit name, no clinic name, no person's name. */
    public function test_the_summary_audit_row_counts_the_three_new_tables_and_names_nobody(): void
    {
        $source = Unit::findByCode('NICU');
        $target = Unit::findByCode('PICU');
        $this->makeBothClinicOwners($source, $target);
        $period = $this->period();

        ClinicWriter::create($source, ['name' => 'Zzuniqueclinicname', 'weekday' => 2, 'session' => 'AM']);
        RotaAssignment::set(Person::factory()->create(['full_name' => 'Zzuniquepersonname']), $period, $source);
        $both = User::factory()->create(['position' => 4]);
        $both->reminderUnits()->attach([$source->id, $target->id]);

        $this->actingAs($this->admin)->post('/admin/structure/units/merge', [
            'source_unit_id' => $source->id,
            'target_unit_id' => $target->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $summary = AuditLog::where('action', 'unit_merge')->latest('id')->first();
        $this->assertNotNull($summary);
        $this->assertStringContainsString('rota=1', $summary->detail);
        $this->assertStringContainsString('clinics=1', $summary->detail);
        $this->assertStringContainsString('reminders=0', $summary->detail);
        $this->assertStringContainsString('reminders_dropped=1', $summary->detail);
        $this->assertStringNotContainsString('Zzuniqueclinicname', $summary->detail);
        $this->assertStringNotContainsString('Zzuniquepersonname', $summary->detail);
    }
}
