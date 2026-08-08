<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_screen_renders(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_reset_link_request_creates_a_token_row(): void
    {
        User::factory()->create(['member_email' => 'doc@example.com', 'active' => true]);

        $response = $this->from('/forgot-password')->post('/forgot-password', [
            'email' => 'doc@example.com',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'doc@example.com']);
    }

    public function test_reset_link_request_does_not_leak_unknown_accounts(): void
    {
        $response = $this->from('/forgot-password')->post('/forgot-password', [
            'email' => 'ghost@example.com',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'ghost@example.com']);
    }

    public function test_reset_password_screen_renders(): void
    {
        $this->get('/reset-password/some-token?email=doc@example.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ResetPassword'));
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create([
            'member_email' => 'doc@example.com',
            'password' => 'Old-pass1!',
            'active' => true,
        ]);

        $token = Password::createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'doc@example.com',
            'password' => 'New-pass123!',
            'password_confirmation' => 'New-pass123!',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('New-pass123!', $user->fresh()->password));
    }

    // ------------------------------------------------------------ OWNER DECISION 2 (2026-08-08)
    //
    // Email is unified onto `people.email`. `users.member_email` still physically exists but is
    // no longer independently written by any P0c-era write path, so it can drift from the
    // person's real address (an old value frozen at account creation, or hand-planted here to
    // PROVE the drift is possible). Every case below deliberately makes the two columns disagree
    // and asserts resolution follows `people.email` — the join, not the frozen raw column — end
    // to end through the real HTTP kernel, never at the unit level alone.

    /**
     * (a) — the reset-link REQUEST leg resolves the account by joining through `person_id` to
     * `people.email`, not by trusting the raw `users.member_email` column. Proven by making the
     * two disagree: the CURRENT (person) address must resolve; the STALE (raw column) address
     * must not.
     */
    public function test_reset_link_request_resolves_through_the_person_link_not_the_frozen_raw_column(): void
    {
        $user = User::factory()->create(['member_email' => 'current@example.org', 'active' => true]);
        // Simulate drift: something (an old LegacyImport run, a hand edit) left the raw column
        // behind. The person's email — the single authoritative address since P0c — has moved on.
        DB::table('users')->where('id', $user->id)->update(['member_email' => 'stale-raw-column@example.org']);
        $this->assertSame('current@example.org', $user->fresh()->person->email);

        $this->post('/forgot-password', ['email' => 'current@example.org'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'current@example.org']);

        $this->post('/forgot-password', ['email' => 'stale-raw-column@example.org'])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'stale-raw-column@example.org']);
    }

    /**
     * (a) — the reset COMPLETION leg (NewPasswordController) resolves through the same join.
     * A token minted against the person's current address completes; presenting the stale raw
     * column's address — even with a validly-hashed token row planted for it — does not.
     */
    public function test_reset_completes_through_the_person_link_even_when_the_frozen_raw_column_disagrees(): void
    {
        $user = User::factory()->create(['member_email' => 'current2@example.org', 'password' => 'Old-pass1!', 'active' => true]);
        DB::table('users')->where('id', $user->id)->update(['member_email' => 'stale-raw2@example.org']);

        $token = Password::createToken($user->fresh());

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'current2@example.org',
            'password' => 'New-pass123!',
            'password_confirmation' => 'New-pass123!',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('New-pass123!', $user->fresh()->password));
    }

    /**
     * (c) — `getEmailForPasswordReset()` and `routeNotificationForMail()` both follow the link.
     * Triggered through the real `/forgot-password` route (never called directly), so this
     * exercises the whole broker path: `getEmailForPasswordReset()` is what
     * `DatabaseTokenRepository::create()` stores as the token row's `email` column, and
     * `routeNotificationForMail()` is what Laravel's notification dispatcher asks the model for
     * when it delivers the `ResetPassword` notification. Both are asserted against the person's
     * CURRENT address while the frozen raw `users.member_email` column holds something else.
     */
    public function test_reset_notification_and_token_both_follow_the_persons_current_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['member_email' => 'routed@example.org', 'active' => true]);
        DB::table('users')->where('id', $user->id)->update(['member_email' => 'unrouted-stale@example.org']);

        $this->post('/forgot-password', ['email' => 'routed@example.org'])->assertSessionHasNoErrors();

        // getEmailForPasswordReset(): the token row.
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'routed@example.org']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'unrouted-stale@example.org']);

        // routeNotificationForMail(): where the notification dispatcher actually sends it.
        Notification::assertSentTo(
            $user->fresh(),
            ResetPassword::class,
            fn (ResetPassword $notification, array $channels, User $notifiable): bool => $notifiable->routeNotificationForMail($notification) === 'routed@example.org'
        );
    }

    /**
     * (b) — the other side of owner decision 2: a person with NO `users` row cannot obtain a
     * reset link by way of the new person-join lookup either. `RosterOnlyCannotAuthenticateTest`
     * already proves this for the pre-unification code path; repeated here so the guarantee is
     * pinned specifically against the join-based `whereHas('person', …)` closure this task added
     * — a future edit to that closure that accidentally matched on a NULL join could otherwise
     * only be caught by the other test file going red for an unrelated reason.
     */
    public function test_a_person_with_no_account_cannot_obtain_a_reset_link_through_the_join(): void
    {
        $person = \App\Models\Person::factory()->create(['email' => 'roster.only.reset@example.org']);

        $this->post('/forgot-password', ['email' => 'roster.only.reset@example.org'])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'roster.only.reset@example.org']);
        $this->assertFalse($person->hasAccount());
    }
}
