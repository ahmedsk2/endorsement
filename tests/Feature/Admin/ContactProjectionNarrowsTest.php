<?php

namespace Tests\Feature\Admin;

use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Support\PersonPresenter;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1d finding 1 / OWNER DECISIONS #7. `PersonPresenter::one()` used to emit `email` to EVERY
 * viewer — safe only because every caller then sat behind `cap:people.manage`, which is also
 * what `PersonPolicy::viewContact()`'s first branch grants. `rota.view`, seeded to every
 * authenticated member (owner decision 2), is the first consumer with a narrower capability, so
 * the no-op gate this class's docblock predicted is now real.
 *
 * These call `PersonPresenter::one()` DIRECTLY rather than through a route, following P1c Task 2's
 * amendment: every existing caller sits behind `cap:people.manage`, so the refusing branch is
 * unreachable through any current endpoint and the presenter itself is precisely what is under
 * test.
 */
class ContactProjectionNarrowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_email_is_absent_not_null_when_the_policy_refuses(): void
    {
        // A resident: no people.manage, and the department has not opted members into contacts.
        $viewer = User::factory()->create(['position' => 4]);
        $person = Person::factory()->create(['email' => 'someone@example.test']);

        $projected = PersonPresenter::one($person, $viewer);

        $this->assertArrayNotHasKey('email', $projected);
        $this->assertArrayNotHasKey('phone', $projected);
        $this->assertSame($person->full_name, $projected['full_name']);
    }

    public function test_a_roster_manager_still_sees_the_email(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $person = Person::factory()->create(['email' => 'someone@example.test']);

        $this->assertSame('someone@example.test', PersonPresenter::one($person, $admin)['email']);
    }

    public function test_the_members_setting_reveals_the_email_alongside_the_phone(): void
    {
        // PE-02's department setting: `email` follows `phone` exactly, which is the whole point —
        // two contact fields governed by one decision, not one governed and one forgotten.
        Institution::current()->update(['contact_visibility' => Institution::CONTACT_MEMBERS]);

        $member = User::factory()->create(['position' => 4]);
        $person = Person::factory()->create([
            'email' => 'someone@example.test',
            'phone' => '0500000000',
        ]);

        $projected = PersonPresenter::one($person, $member);

        $this->assertSame('someone@example.test', $projected['email']);
        $this->assertSame('0500000000', $projected['phone']);
    }

    public function test_a_null_viewer_gets_no_contact_field_at_all(): void
    {
        $this->assertArrayNotHasKey('email', PersonPresenter::one(Person::factory()->create(), null));
    }
}
