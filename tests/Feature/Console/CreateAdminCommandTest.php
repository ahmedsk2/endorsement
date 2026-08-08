<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bootstrap problem: a clean production database has no accounts, and every route
 * that could create one is behind auth + a capability. Registration only ever produces a
 * pending Resident, and approving that pending Resident requires an administrator who
 * does not exist yet. This command is the only door in, so it has to be right.
 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_verified_administrator(): void
    {
        $this->artisan('user:create-admin')
            ->expectsQuestion('Username (used to sign in)', 'ahmed')
            ->expectsQuestion('Full name (shown on printed handovers)', 'Dr Ahmed Administrator')
            ->expectsQuestion('Email address', 'admin@example.org')
            ->expectsQuestion('Password', 'Str0ng-pass!x')
            ->expectsQuestion('Confirm password', 'Str0ng-pass!x')
            ->assertExitCode(0);

        $user = User::where('member_name', 'ahmed')->firstOrFail();

        $this->assertSame(0, $user->position, 'position 0 is Admin');
        $this->assertTrue((bool) $user->active);
        $this->assertNotNull(
            $user->email_verified_at,
            'the bootstrap admin must not be gated behind an email they cannot yet receive: SMTP is configured in-app, after login',
        );
    }

    public function test_the_password_is_hashed_never_stored_readable(): void
    {
        $this->artisan('user:create-admin')
            ->expectsQuestion('Username (used to sign in)', 'ahmed')
            ->expectsQuestion('Full name (shown on printed handovers)', 'Dr Ahmed Administrator')
            ->expectsQuestion('Email address', 'admin@example.org')
            ->expectsQuestion('Password', 'Str0ng-pass!x')
            ->expectsQuestion('Confirm password', 'Str0ng-pass!x')
            ->assertExitCode(0);

        $stored = User::where('member_name', 'ahmed')->value('password');

        $this->assertNotSame('Str0ng-pass!x', $stored);
        $this->assertTrue(password_verify('Str0ng-pass!x', $stored));
    }

    public function test_it_refuses_a_password_that_fails_the_shared_policy(): void
    {
        // Same PasswordPolicy the registration form uses — the bootstrap door must not be
        // the weakest one.
        $this->artisan('user:create-admin')
            ->expectsQuestion('Username (used to sign in)', 'ahmed')
            ->expectsQuestion('Full name (shown on printed handovers)', 'Dr Ahmed Administrator')
            ->expectsQuestion('Email address', 'admin@example.org')
            ->expectsQuestion('Password', 'password')
            ->expectsQuestion('Confirm password', 'password')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_when_the_confirmation_does_not_match(): void
    {
        $this->artisan('user:create-admin')
            ->expectsQuestion('Username (used to sign in)', 'ahmed')
            ->expectsQuestion('Full name (shown on printed handovers)', 'Dr Ahmed Administrator')
            ->expectsQuestion('Email address', 'admin@example.org')
            ->expectsQuestion('Password', 'Str0ng-pass!x')
            ->expectsQuestion('Confirm password', 'Str0ng-pass!y')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_refuses_to_overwrite_an_existing_username(): void
    {
        // Silently updating would let a re-run reset a real administrator's password.
        User::factory()->create(['member_name' => 'ahmed', 'position' => 4]);

        $this->artisan('user:create-admin')
            ->expectsQuestion('Username (used to sign in)', 'ahmed')
            ->expectsQuestion('Full name (shown on printed handovers)', 'Dr Ahmed Administrator')
            ->expectsQuestion('Email address', 'admin@example.org')
            ->expectsQuestion('Password', 'Str0ng-pass!x')
            ->expectsQuestion('Confirm password', 'Str0ng-pass!x')
            ->assertExitCode(1);

        // Not ->value('position'): Eloquent's value() does first([$column]), a narrow SELECT
        // that omits person_id, and the read-through accessor (P0c) needs it to resolve the
        // person. A full row fetch loads person_id, so the accessor works as it does in the app.
        $this->assertSame(4, User::where('member_name', 'ahmed')->firstOrFail()->position);
    }

    public function test_it_writes_an_audit_entry_without_the_password(): void
    {
        $this->artisan('user:create-admin')
            ->expectsQuestion('Username (used to sign in)', 'ahmed')
            ->expectsQuestion('Full name (shown on printed handovers)', 'Dr Ahmed Administrator')
            ->expectsQuestion('Email address', 'admin@example.org')
            ->expectsQuestion('Password', 'Str0ng-pass!x')
            ->expectsQuestion('Confirm password', 'Str0ng-pass!x')
            ->assertExitCode(0);

        // Creating the first administrator out-of-band is exactly the event an auditor
        // asks about after an incident.
        $entry = \DB::table('audit_log')->where('action', 'admin_bootstrapped')->first();

        $this->assertNotNull($entry);
        // The column is `detail`, singular. Reading `->details` here made this assertion
        // vacuous — it compared against null-coalesced '' and would have passed even if the
        // password had been written straight into the trail.
        $this->assertNotNull($entry->detail, 'the entry should record which user was created');
        $this->assertStringNotContainsString('Str0ng-pass!x', $entry->detail);
        $this->assertStringContainsString('user_id=', $entry->detail);
    }
}
