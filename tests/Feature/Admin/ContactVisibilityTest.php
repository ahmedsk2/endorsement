<?php

namespace Tests\Feature\Admin;

use App\Models\Capability;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Policies\PersonPolicy;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Munawib PE-02 — "contact visibility per policy toggles, logged-in members only".
 *
 * Finding 2: `Person::$hidden` does NOT keep `phone`/`notes` out of an Inertia prop — every
 * admin screen in this codebase builds its props with an explicit map that bypasses it. The
 * enforcement is `App\Support\PersonPresenter` (the one projection) plus `App\Policies\
 * PersonPolicy` (the first policy in this codebase), gated by `institutions.contact_visibility`
 * — a two-valued department setting, never a per-field matrix, and `notes` is on neither side.
 */
class ContactVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_the_default_is_admins_only(): void
    {
        $this->assertSame(Institution::CONTACT_ADMINS, Institution::current()->contact_visibility);
    }

    public function test_a_people_manager_sees_phone_and_notes(): void
    {
        $admin = User::factory()->create(['position' => 0, 'full_name' => 'AAA Admin']);
        Person::factory()->create(['full_name' => 'Has Contact', 'phone' => '0500000000', 'notes' => 'Prefers night shifts']);

        $this->actingAs($admin)->get('/admin/people')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('people.1.phone', '0500000000')
                ->where('people.1.notes', 'Prefers night shifts')
            );
    }

    /** Task 1's gate, restated so the contact tests cannot be read as the only protection. */
    public function test_a_non_manager_cannot_open_the_screen_at_all(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/people')->assertForbidden();
    }

    /**
     * Absent, not null: a null phone and a withheld phone must not look the same on the wire, or
     * a future consumer renders one as the other.
     */
    public function test_phone_is_ABSENT_not_null_when_the_policy_refuses(): void
    {
        Institution::current()->update(['contact_visibility' => Institution::CONTACT_ADMINS]);

        $viewer = User::factory()->create(['position' => 4, 'full_name' => 'AAA Viewer']);
        $this->grant($viewer, 'people.manage', 'deny');
        $this->grant($viewer, 'endorsement.view', 'grant');

        Person::factory()->create(['full_name' => 'Has Contact', 'phone' => '0500000000']);

        $this->actingAs($viewer)->get('/admin/people');

        // The GET above is 403 (the viewer has no people.manage), so exercise the presenter
        // directly — this is what the projection returns for a viewer the policy refuses.
        $person = Person::where('full_name', 'Has Contact')->first();
        $props = \App\Support\PersonPresenter::one($person, $viewer);

        $this->assertArrayNotHasKey('phone', $props);
    }

    public function test_setting_members_exposes_phone_to_any_account_holder_but_never_notes(): void
    {
        Institution::current()->update(['contact_visibility' => Institution::CONTACT_MEMBERS]);

        $member = User::factory()->create(['position' => 4]);
        $person = Person::factory()->create(['full_name' => 'Has Contact', 'phone' => '0500000000', 'notes' => 'Secret note']);

        $props = \App\Support\PersonPresenter::one($person, $member);

        $this->assertSame('0500000000', $props['phone']);
        $this->assertArrayNotHasKey('notes', $props);
    }

    /**
     * Pre-merge finding 2. P1d Task 7 put `email` behind the same gate as `phone` —
     * `PersonPresenter` releases the pair in one branch, because the rota grid gave
     * `viewContact` its first holder of a capability narrower than `people.manage`. Nothing
     * pinned that, and ruling 21 still described the setting as governing `phone` alone. The
     * predicate is named for what it actually decides (`membersMaySeeContact`), and both fields
     * are asserted on both sides of the toggle.
     */
    public function test_the_members_setting_releases_email_as_well_as_phone(): void
    {
        Institution::current()->update(['contact_visibility' => Institution::CONTACT_MEMBERS]);

        $this->assertTrue(\App\Support\ContactVisibility::membersMaySeeContact());

        $member = User::factory()->create(['position' => 4]);
        $this->grant($member, 'people.manage', 'deny');

        $person = Person::factory()->create([
            'full_name' => 'Has Contact',
            'phone' => '0500000000',
            'email' => 'has.contact@example.test',
        ]);

        $props = \App\Support\PersonPresenter::one($person, $member);

        $this->assertSame('0500000000', $props['phone']);
        $this->assertSame('has.contact@example.test', $props['email']);
    }

    public function test_the_admins_setting_withholds_email_as_well_as_phone(): void
    {
        Institution::current()->update(['contact_visibility' => Institution::CONTACT_ADMINS]);

        $this->assertFalse(\App\Support\ContactVisibility::membersMaySeeContact());

        $member = User::factory()->create(['position' => 4]);
        $this->grant($member, 'people.manage', 'deny');

        $person = Person::factory()->create([
            'full_name' => 'Has Contact',
            'phone' => '0500000000',
            'email' => 'has.contact@example.test',
        ]);

        $props = \App\Support\PersonPresenter::one($person, $member);

        // Absent, not null — both of them, for the same reason.
        $this->assertArrayNotHasKey('phone', $props);
        $this->assertArrayNotHasKey('email', $props);
    }

    public function test_notes_is_never_exposed_by_the_setting(): void
    {
        $this->assertArrayNotHasKey('members', array_filter(
            Institution::CONTACT_VISIBILITIES,
            fn (string $label, string $key): bool => str_contains(strtolower($label), 'notes'),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public function test_the_setting_is_validated_against_an_allow_list(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)
            ->patch('/admin/people/visibility', ['contact_visibility' => 'all'])
            ->assertSessionHasErrors('contact_visibility');
    }

    public function test_the_setting_write_is_audited_by_key_never_by_value(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)->patch('/admin/people/visibility', ['contact_visibility' => Institution::CONTACT_MEMBERS]);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'contact_visibility_update',
            'detail' => 'key=contact_visibility',
        ]);
    }

    /**
     * `Gate::before` (AppServiceProvider.php:56) returns TRUE for any ability string that is a
     * capability key the user holds, and only returns null on a miss — which is what lets
     * ordinary policies run at all. A policy ability named like a capability key would therefore
     * be short-circuited to `true` for every holder and this class would never run — a silent
     * authorization bypass with no error and no failing test anywhere else.
     */
    public function test_policy_abilities_do_not_collide_with_capability_keys(): void
    {
        $abilities = array_map(
            fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(PersonPolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        $keys = Capability::pluck('key')->all();

        foreach ($abilities as $ability) {
            $this->assertNotContains($ability, $keys, "Policy ability '{$ability}' collides with a capability key.");
            $this->assertStringNotContainsString('.', $ability, "Policy ability '{$ability}' looks like a capability key.");
        }
    }

    private function grant(User $user, string $key, string $effect): void
    {
        \App\Models\UserCapability::create([
            'user_id' => $user->getKey(),
            'capability_id' => (int) Capability::where('key', $key)->value('id'),
            'effect' => $effect,
        ]);

        \App\Support\AccessControl::flush((int) $user->getKey());
    }
}
