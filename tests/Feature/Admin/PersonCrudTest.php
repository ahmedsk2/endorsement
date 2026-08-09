<?php

namespace Tests\Feature\Admin;

use App\Models\Person;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PE-01's full field set. Nothing here writes `users` — the invitation flow remains the only
 * path from a roster entry to a credential (design §5.1, Decision I).
 */
class PersonCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
        $this->admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Bilal Rotator',
            'short_name' => 'bilal',
            'position' => 3,
            'email' => 'bilal@example.test',
            'phone' => '0500000001',
            'joined_at' => '2026-01-01',
            'notes' => 'Prefers weekday clinics',
            'constraints' => ['no_nights' => true],
            'external' => false,
            'active' => true,
        ], $overrides);
    }

    public function test_an_administrator_creates_a_roster_only_person_with_every_field(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/people', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('status');

        $person = Person::where('short_name', 'bilal')->firstOrFail();

        $this->assertSame('Bilal Rotator', $person->full_name);
        $this->assertSame('bilal', $person->short_name);
        $this->assertSame(3, $person->position);
        $this->assertSame('bilal@example.test', $person->email);
        $this->assertSame('0500000001', $person->phone);
        $this->assertSame('2026-01-01', $person->joined_at->format('Y-m-d'));
        $this->assertSame('Prefers weekday clinics', $person->notes);
        $this->assertSame(['no_nights' => true], $person->constraints);
        $this->assertFalse($person->external);
        $this->assertTrue($person->active);
        $this->assertFalse($person->hasAccount());
    }

    public function test_short_name_uniqueness_is_a_422_not_a_23000(): void
    {
        Person::factory()->create(['short_name' => 'taken']);

        $this->actingAs($this->admin)
            ->post('/admin/people', $this->payload(['short_name' => 'taken']))
            ->assertSessionHasErrors('short_name');
    }

    public function test_short_name_uniqueness_is_ignored_on_self_edit(): void
    {
        $person = Person::factory()->create(['short_name' => 'mine']);

        $this->actingAs($this->admin)
            ->patch('/admin/people/'.$person->id, $this->payload(['short_name' => 'mine']))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
    }

    /**
     * `people.email` carries a genuine UNIQUE column, and this screen does a plain create with
     * no claim-or-merge step (unlike invitation acceptance / registration approval) — so a
     * collision with an EXISTING roster-only person's email must still be a 422, not a raw
     * DB-constraint 500. `Person::accountEmailRule()` alone would pass this and let the request
     * reach `Person::create()`, which then hits the unique index directly.
     */
    public function test_email_matching_an_existing_roster_only_person_is_a_422_not_a_500(): void
    {
        Person::factory()->create(['email' => 'shared@example.test']);

        $this->actingAs($this->admin)
            ->post('/admin/people', $this->payload(['email' => 'shared@example.test', 'short_name' => null]))
            ->assertSessionHasErrors('email');
    }

    /** Self-edit: keeping your own current email must not collide with yourself. */
    public function test_email_uniqueness_is_ignored_on_self_edit(): void
    {
        $person = Person::factory()->create(['email' => 'mine@example.test']);

        $this->actingAs($this->admin)
            ->patch('/admin/people/'.$person->id, $this->payload(['email' => 'mine@example.test', 'short_name' => null]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
    }

    public function test_email_matching_a_claimed_account_is_a_failure(): void
    {
        $claimed = User::factory()->create();

        $this->actingAs($this->admin)
            ->post('/admin/people', $this->payload(['email' => $claimed->person->email, 'short_name' => null]))
            ->assertSessionHasErrors('email');
    }

    public function test_a_blank_email_stores_null_not_empty_string(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/people', $this->payload(['email' => '', 'short_name' => null]));

        $person = Person::where('full_name', 'Bilal Rotator')->firstOrFail();

        $this->assertNull($person->email);
    }

    public function test_constraints_round_trip_as_an_array(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/people', $this->payload(['constraints' => ['no_nights' => true, 'max_weekly_hours' => 40]]));

        $person = Person::where('short_name', 'bilal')->firstOrFail();

        $this->assertSame(['no_nights' => true, 'max_weekly_hours' => 40], $person->constraints);
    }

    public function test_notes_and_phone_are_writable_and_never_echoed_into_the_audit_detail(): void
    {
        $this->actingAs($this->admin)->post('/admin/people', $this->payload());

        $person = Person::where('short_name', 'bilal')->firstOrFail();

        $this->assertSame('0500000001', $person->phone);
        $this->assertSame('Prefers weekday clinics', $person->notes);

        $row = \App\Models\AuditLog::where('action', 'person_create')->firstOrFail();

        $this->assertStringNotContainsString('0500000001', (string) $row->detail);
        $this->assertStringNotContainsString('Prefers weekday clinics', (string) $row->detail);
        $this->assertStringNotContainsString('Bilal Rotator', (string) $row->detail);
        $this->assertStringNotContainsString('bilal@example.test', (string) $row->detail);
    }

    public function test_creating_a_person_never_creates_an_account(): void
    {
        $before = User::count();

        $this->actingAs($this->admin)->post('/admin/people', $this->payload());

        $this->assertSame($before, User::count());

        $person = Person::where('short_name', 'bilal')->firstOrFail();
        $this->assertFalse($person->hasAccount());
    }

    public function test_there_is_no_delete_endpoint(): void
    {
        $person = Person::factory()->create();

        $this->actingAs($this->admin)
            ->delete('/admin/people/'.$person->id)
            ->assertStatus(405);
    }

    public function test_a_non_manager_cannot_create_or_update_a_person(): void
    {
        $resident = User::factory()->create(['position' => 4]);
        $person = Person::factory()->create();

        $this->actingAs($resident)->post('/admin/people', $this->payload())->assertForbidden();
        $this->actingAs($resident)->patch('/admin/people/'.$person->id, $this->payload())->assertForbidden();
    }
}
