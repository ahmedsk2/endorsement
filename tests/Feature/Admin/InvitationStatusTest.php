<?php

namespace Tests\Feature\Admin;

use App\Models\Capability;
use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use App\Models\UserCapability;
use App\Support\AccessControl;
use App\Support\Calendar;
use App\Support\Invitations\InvitationStatus;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AC-02's *"claim status visible"* — P1c-2 Decision B.
 *
 * Five states, DERIVED from the three columns that already exist (`accepted_at`, `revoked_at`,
 * `expires_at`) and never stored. A stored one would be the `person_status` lifecycle enum D3
 * removed, wearing a different name: "claimed" is a join, "invited" is a row, and neither is a
 * column. There is no migration in this plan and there must not be one here.
 *
 * Two properties this file exists to pin, both of which a plausible implementation gets wrong:
 *
 *  - **ONE query for a whole page.** The People screen lists the entire roster. A per-person
 *    "latest invitation" lookup is the N+1 P1c-1's finding 5 exists to prevent, and it is
 *    invisible on a two-person fixture — hence the thirty-person case below with an explicit
 *    bound.
 *  - **The projection scopes ITSELF.** `InvitationController::openInvitations()` declared a
 *    `?User $viewer` and never used it, leaving the scoping in one caller's body
 *    (`UserManagementController::index()`), where a second caller could not see it. A Chief
 *    Resident must not learn a Consultant's claim state through the newer of two doors.
 */
class InvitationStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0]);
    }

    /** A scoped manager: `users.manage_residents` and nothing wider (the Chief Resident tier). */
    private function scopedManager(): User
    {
        $user = User::factory()->create(['position' => 5]);

        UserCapability::create([
            'user_id' => $user->getKey(),
            'capability_id' => Capability::where('key', 'users.manage_residents')->firstOrFail()->getKey(),
            'effect' => 'grant',
        ]);

        AccessControl::flush($user->getKey());

        return $user;
    }

    private function person(int $position = 4): Person
    {
        return Person::factory()->create(['position' => $position]);
    }

    /** @return array<int, array<string, mixed>> */
    private function statusFor(Person $person, ?User $viewer = null): array
    {
        return InvitationStatus::forPeople([$person], $viewer ?? $this->admin());
    }

    // ------------------------------------------------------------------ the five states

    public function test_a_person_with_no_invitation_is_none(): void
    {
        $person = $this->person();

        $status = $this->statusFor($person)[$person->getKey()];

        $this->assertSame('none', $status['state']);
        $this->assertNull($status['at']);
        $this->assertNull($status['id']);
    }

    public function test_an_open_invitation_reports_its_expiry(): void
    {
        $person = $this->person();
        [$invitation] = Invitation::issue('open@example.org', 4, $this->admin(), $person);

        $status = $this->statusFor($person)[$person->getKey()];

        $this->assertSame('open', $status['state']);
        $this->assertSame((int) $invitation->getKey(), $status['id']);
        $this->assertSame(Calendar::ymd($invitation->expires_at), $status['at']['date']);
    }

    public function test_an_expired_invitation_is_expired_not_open(): void
    {
        $person = $this->person();
        [$invitation] = Invitation::issue('expired@example.org', 4, $this->admin(), $person);
        $invitation->forceFill(['expires_at' => now()->subDay()])->save();

        $status = $this->statusFor($person)[$person->getKey()];

        $this->assertSame('expired', $status['state']);
        $this->assertSame(Calendar::ymd($invitation->fresh()->expires_at), $status['at']['date']);
    }

    /**
     * Through the REAL claim flow, never a hand-written `accepted_at`: the projection has to be
     * proved against what the redemption path actually writes, not against a fixture that agrees
     * with it by construction.
     */
    public function test_a_claimed_invitation_is_claimed_with_its_accepted_at(): void
    {
        $person = $this->person();
        [$invitation, $token] = Invitation::issue('claimer@example.org', 4, $this->admin(), $person);

        $this->post("/invitation/{$token}", [
            'full_name' => 'Dr Claimed',
            'member_name' => 'drclaimed',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertRedirect('/login');

        $accepted = $invitation->fresh();
        $this->assertNotNull($accepted->accepted_at, 'the claim flow did not record an acceptance');

        $status = $this->statusFor($person->fresh())[$person->getKey()];

        $this->assertSame('claimed', $status['state']);
        $this->assertSame(Calendar::ymd($accepted->accepted_at), $status['at']['date']);
    }

    public function test_a_revoked_invitation_is_revoked(): void
    {
        $person = $this->person();
        $admin = $this->admin();
        [$invitation] = Invitation::issue('revoked@example.org', 4, $admin, $person);

        $this->actingAs($admin)->delete('/admin/invitations/'.$invitation->getKey())->assertRedirect();

        $status = $this->statusFor($person, $admin)[$person->getKey()];

        $this->assertSame('revoked', $status['state']);
        $this->assertSame(Calendar::ymd($invitation->fresh()->revoked_at), $status['at']['date']);
    }

    /**
     * The case a resend produces EVERY TIME (Decision C rotates the token and supersedes the old
     * row), so precedence by row id descending is not a tie-break nicety — it is the normal path.
     */
    public function test_the_latest_invitation_wins(): void
    {
        $person = $this->person();
        $admin = $this->admin();

        [$revoked] = Invitation::issue('latest@example.org', 4, $admin, $person);
        $revoked->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $admin->getKey()])->save();

        [$expired] = Invitation::issue('latest@example.org', 4, $admin, $person);
        $expired->forceFill(['expires_at' => now()->subDay()])->save();

        [$open] = Invitation::issue('latest@example.org', 4, $admin, $person);

        $status = $this->statusFor($person, $admin)[$person->getKey()];

        $this->assertSame('open', $status['state']);
        $this->assertSame((int) $open->getKey(), $status['id']);
    }

    // ------------------------------------------------------------------ scoping

    public function test_a_chief_resident_sees_a_state_only_for_residents(): void
    {
        $admin = $this->admin();
        $resident = $this->person(4);
        $consultant = $this->person(3);

        Invitation::issue('res@example.org', 4, $admin, $resident);
        Invitation::issue('cons@example.org', 3, $admin, $consultant);

        // ONE call: the projection scopes itself, per person, and the caller applies nothing.
        $statuses = InvitationStatus::forPeople([$resident, $consultant], $this->scopedManager());

        $this->assertSame('open', $statuses[$resident->getKey()]['state']);
        $this->assertSame('hidden', $statuses[$consultant->getKey()]['state']);

        // Hidden means hidden: no expiry, no invitation id, nothing to correlate.
        $this->assertNull($statuses[$consultant->getKey()]['at']);
        $this->assertNull($statuses[$consultant->getKey()]['id']);
    }

    public function test_a_viewer_with_no_management_capability_sees_nothing_at_all(): void
    {
        $resident = $this->person(4);
        Invitation::issue('res@example.org', 4, $this->admin(), $resident);

        $statuses = InvitationStatus::forPeople([$resident], User::factory()->create(['position' => 4]));

        $this->assertSame('hidden', $statuses[$resident->getKey()]['state']);
    }

    // ------------------------------------------------------------------ the budget

    /**
     * ONE query for the whole page, not one per person (Decision B). Thirty people, twenty of
     * them carrying an invitation: an implementation that opens a lookup per person costs thirty
     * and this bound catches it, where a two-person fixture would not. MEASURED, not computed —
     * the first run of this case reported 34 against a bound of 1, and the extra thirty were a
     * per-person `hasAccount()` EXISTS the implementation has since stopped asking.
     *
     * TWO WARMUPS, deliberate and visible, both of them things a real request has already paid for
     * long before it reaches the roster: `Calendar` memoises the institution row and the holiday
     * list in process statics, and `AccessControl` caches the viewer's capability set (the
     * `cap:people.manage` middleware resolves it before the controller runs at all). Counting them
     * here would measure request ORDER, and would move this bound depending on which test ran
     * first.
     *
     * The second assertion is the one that survives a future warmup change: the cost of thirty
     * people must equal the cost of three. An N+1 cannot satisfy it however the log is primed.
     */
    public function test_the_whole_page_costs_one_query(): void
    {
        $admin = $this->admin();

        $people = [];

        for ($i = 0; $i < 30; $i++) {
            $person = $this->person();
            $people[] = $person;

            if ($i < 20) {
                Invitation::issue("bulk{$i}@example.org", 4, $admin, $person);
            }
        }

        InvitationStatus::forPeople(array_slice($people, 0, 3), $admin);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $statuses = InvitationStatus::forPeople($people, $admin);
        $wide = count(DB::getQueryLog());

        DB::flushQueryLog();
        InvitationStatus::forPeople(array_slice($people, 0, 3), $admin);
        $narrow = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertCount(30, $statuses, 'the projection did not answer for every person asked about');
        $this->assertSame(1, $wide,
            "the claim-status projection ran {$wide} queries for 30 people — it must be one for the page");
        $this->assertSame($narrow, $wide,
            "thirty people cost {$wide} queries and three cost {$narrow} — the cost moves with the roster");
    }

    /**
     * The same budget one level up, through the real screen — because a projection that costs one
     * query is worth nothing if the controller that calls it opens thirty of its own around it.
     * Two roster sizes, one request each, and the counts must match: the People screen's cost may
     * not move with the size of the department.
     *
     * `assertSame` between the two runs rather than an absolute bound, for the reason
     * `RotaReadViewTest`'s own budget records: a first request in a process pays session and
     * capability warmup a second one does not, so an exact figure would pin request ORDER. Both
     * requests here are made by the same already-warm actor.
     *
     * MEASURED: **7 queries for the whole screen** at both roster sizes, read off a deliberately
     * unreachable bound — one of the seven is this projection. With a per-person account lookup
     * reintroduced on purpose the same two runs cost 12 and 37, which is what proves this case can
     * fail rather than merely passing.
     */
    public function test_the_people_screen_carries_the_claim_state_without_paying_per_person(): void
    {
        $admin = $this->admin();

        $invited = null;

        for ($i = 0; $i < 5; $i++) {
            $person = $this->person();
            $invited ??= $person;
            Invitation::issue("small{$i}@example.org", 4, $admin, $person);
        }

        $this->actingAs($admin)->get('/admin/people')->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($admin)->get('/admin/people')->assertOk();
        $small = count(DB::getQueryLog());

        for ($i = 0; $i < 25; $i++) {
            Invitation::issue("large{$i}@example.org", 4, $admin, $this->person());
        }

        DB::flushQueryLog();
        $response = $this->actingAs($admin)->get('/admin/people')->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($small, $large,
            "the People screen cost {$small} queries for 5 people and {$large} for 30 — the claim "
            .'status is being looked up per person');

        $captured = null;
        $response->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use (&$captured): void {
            $captured = $page->toArray()['props']['people'];
        });

        $this->assertNotEmpty($captured);

        // The row for a person we KNOW was invited — never `$captured[0]`, which is whoever sorts
        // first by `full_name` and is just as likely to be the ADMIN's own person, who has no
        // invitation and correctly reads `none`. That is a faker-name lottery: it passed for three
        // runs and then failed in P1c-2 Task 4 purely because twenty-two new tests moved the
        // sequence of names drawn before this one. A case that can go red for a reason it does not
        // name is worse than no case.
        $row = null;

        foreach ($captured as $person) {
            if ((int) $person['id'] === (int) $invited->getKey()) {
                $row = $person;
            }
        }

        $this->assertNotNull($row, 'the invited person is missing from the People screen entirely');
        $this->assertSame('open', $row['invitation']['state'],
            'the People screen did not receive the claim state at all');
    }

    // ------------------------------------------------------------------ dates

    /**
     * ST-06. Every timestamp is formatted SERVER-SIDE and arrives as a string carrying the
     * department's Hijri label, exactly like `PersonPresenter::history()`'s spans. A raw ISO
     * string would push the formatting into the browser's own locale and timezone, which is the
     * class of bug `CalendarIsTheOnlyConverterTest` exists to make impossible.
     */
    public function test_every_timestamp_is_a_preformatted_string_carrying_a_hijri_label(): void
    {
        $person = $this->person();
        Invitation::issue('shaped@example.org', 4, $this->admin(), $person);

        $at = $this->statusFor($person)[$person->getKey()]['at'];

        foreach (['date', 'hijri', 'time'] as $key) {
            $this->assertArrayHasKey($key, $at);
            $this->assertIsString($at[$key], "at.{$key} is not a preformatted string");
        }

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $at['date']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $at['time']);
        $this->assertNotSame('', $at['hijri']);
    }

    /** The tag text is composed server-side too — the component interpolates and formats nothing. */
    public function test_each_state_carries_a_server_composed_label(): void
    {
        $person = $this->person();
        $none = $this->statusFor($person)[$person->getKey()];

        Invitation::issue('labelled@example.org', 4, $this->admin(), $person);
        $open = $this->statusFor($person)[$person->getKey()];

        $this->assertIsString($none['label']);
        $this->assertNotSame('', $none['label']);
        $this->assertStringContainsString($open['at']['date'], $open['label'],
            'the open-state label does not carry the expiry the screen is meant to show');
    }
}
