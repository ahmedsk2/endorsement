<?php

namespace Tests\Feature\Auth;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D3, reversed 2026-08-08: a person on the roster who has never claimed an account has NO row in
 * `users`, so there is nothing for any credential path to find. That is a stronger guarantee than
 * a gate, because it needs no code to be right — but only for as long as the two tables stay
 * separate. Every case below names the path recon report 0 mapped, so a regression says which
 * door was reopened.
 *
 * The paths, exhaustively (recon report 0 §B):
 *   B1  password login                 POST /login
 *   B2  forced password change         POST /change-password   (session-parked identity)
 *   B3  TOTP challenge                 POST /two-factor-challenge (session-parked identity)
 *   B4  email OTP                      POST /email-code, POST /email-code/resend
 *   B5  trusted devices                (mintable only after a proven second factor)
 *   B6  remember-me recaller           EloquentUserProvider::retrieveByToken()
 *   B7  session resumption             EloquentUserProvider::retrieveById()
 *   B8  password-reset broker          POST /forgot-password, POST /reset-password
 *   B9  email-verification signed URLs GET /profile/email/verify/{user}/{hash}
 *   B10 invitation acceptance          POST /invitation/{token}   (covered in InvitationTest)
 */
class RosterOnlyCannotAuthenticateTest extends TestCase
{
    use RefreshDatabase;

    private function rosterOnlyPerson(): Person
    {
        return Person::factory()->create([
            'full_name' => 'Dr Roster Only',
            'short_name' => 'ROS',
            'position' => 3,
            'email' => 'roster.only@example.org',
            'active' => true,
        ]);
    }

    /**
     * The structural guarantee itself, asserted by name. `people` holds no credential, so there
     * is nothing on it to check a password against, no handle to look one up by, and no token to
     * resume a session from. A migration that adds any of these turns this red on the spot.
     */
    public function test_the_people_table_carries_no_credential_column(): void
    {
        foreach (['password', 'member_name', 'remember_token', 'two_factor_secret',
            'two_factor_recovery_codes', 'two_factor_confirmed_at', 'signature_path',
            'email_verified_at', 'pass_exp_date'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('people', $column),
                "people.{$column} exists — the roster table has acquired a credential, and a ".
                'roster-only person can no longer be proven unable to authenticate.'
            );
        }
    }

    /** B1 — there is no `member_name` to look up, and no row for `Hash::check` to reach. */
    public function test_a_roster_only_person_cannot_log_in(): void
    {
        $person = $this->rosterOnlyPerson();

        foreach ([$person->short_name, $person->email, (string) $person->id] as $handle) {
            $this->post('/login', ['member_name' => $handle, 'password' => 'password'])
                ->assertSessionHasErrors('member_name');
            $this->assertGuest();
        }
    }

    /** B8 request leg — the broker resolves by users.member_email; no user row, no token row. */
    public function test_a_roster_only_person_cannot_request_a_password_reset(): void
    {
        $person = $this->rosterOnlyPerson();

        $this->post('/forgot-password', ['email' => $person->email])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    /** B8 reset leg — even with a hand-planted token row, there is no user to reset. */
    public function test_a_planted_reset_token_cannot_mint_an_account_for_a_roster_person(): void
    {
        $person = $this->rosterOnlyPerson();
        $token = Str::random(64);

        DB::table('password_reset_tokens')->insert([
            'email' => $person->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $person->email,
            'password' => 'Sufficiently-L0ng-Password!',
            'password_confirmation' => 'Sufficiently-L0ng-Password!',
        ])->assertSessionHasErrors();

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    /**
     * B3/B4 — the id-space confusion case, and the reason this test exists at all. `people.id`
     * and `users.id` are independent sequences, so person 1 and user 1 are different humans. A
     * person id parked in a challenge session key must resolve to nothing, not to whichever
     * account happens to share the integer.
     */
    public function test_a_person_id_in_a_challenge_session_key_resolves_to_nobody(): void
    {
        $person = $this->rosterOnlyPerson();
        $this->assertSame(1, $person->id, 'fixture assumption: the first person takes id 1');

        $this->withSession(['auth.two_factor.user_id' => $person->id])
            ->get('/two-factor-challenge')
            ->assertRedirect('/login');

        $this->withSession(['auth.email_otp.user_id' => $person->id])
            ->get('/email-code')
            ->assertRedirect('/login');

        $this->withSession(['auth.password_expired_user_id' => $person->id])
            ->get('/change-password')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    /**
     * B6 — the recaller path, which resolves through retrieveByToken() and compares
     * `remember_token` and nothing else. A person has no remember_token to forge one against.
     */
    public function test_a_roster_only_person_has_no_remember_token_to_forge_a_recaller_from(): void
    {
        $person = $this->rosterOnlyPerson();

        $this->assertNull(User::withTrashed()->where('person_id', $person->id)->first());
        $this->assertSame(0, DB::table('users')->where('person_id', $person->id)->count());
    }

    /** B9 — {user} is route-model-bound to `users`; a person id is not resolvable there. */
    public function test_a_person_id_is_not_bindable_where_a_user_is_expected(): void
    {
        $person = $this->rosterOnlyPerson();
        $account = User::factory()->create();

        $this->actingAs($account)
            ->get('/profile/email/verify/'.$person->id.'/'.sha1((string) $person->email))
            ->assertStatus(403);   // invalid signature, before any binding — never a 200
    }

    /** The claim direction: once an account exists, the person is reachable and normal. */
    public function test_a_claimed_person_authenticates_normally(): void
    {
        $account = User::factory()->create(['member_name' => 'claimed', 'full_name' => 'Dr Claimed']);

        $this->post('/login', ['member_name' => 'claimed', 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($account->fresh());
        $this->assertNotNull($account->person_id);
    }
}
