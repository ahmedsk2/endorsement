<?php

namespace Tests\Feature\Auth;

use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'New Doctor',
            'member_name' => 'new_doc',
            'member_email' => 'new@example.com',
            'position' => 4,
            'password' => 'secret-pass1',
            'password_confirmation' => 'secret-pass1',
        ], $overrides);
    }

    public function test_register_screen_renders(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Register'));
    }

    public function test_registration_rejects_an_email_already_in_the_pending_queue(): void
    {
        PendingRegistration::create([
            'full_name' => 'First Doctor',
            'member_name' => 'first_doc',
            'member_email' => 'dup@example.com',
            'position' => 3,
            'password' => 'secret-pass1',
            'requested_at' => now(),
        ]);

        $response = $this->from('/register')->post('/register', $this->payload([
            'member_name' => 'second_doc',
            'member_email' => 'dup@example.com',
        ]));

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('member_email');
        $this->assertSame(1, PendingRegistration::where('member_email', 'dup@example.com')->count());
    }

    public function test_registration_creates_a_pending_row_not_a_user_and_does_not_authenticate(): void
    {
        $response = $this->post('/register', $this->payload());

        $response->assertRedirect(route('login'));

        $this->assertDatabaseHas('pending_registrations', [
            'full_name' => 'New Doctor',
            'member_name' => 'new_doc',
            'member_email' => 'new@example.com',
            'position' => 4,
        ]);
        $this->assertDatabaseMissing('users', ['member_name' => 'new_doc']);
        $this->assertGuest();

        $pending = PendingRegistration::where('member_name', 'new_doc')->firstOrFail();
        $this->assertNotSame('secret-pass1', $pending->password);
        $this->assertTrue(Hash::check('secret-pass1', $pending->password));
    }

    public function test_registration_may_not_create_an_administrator(): void
    {
        $response = $this->from('/register')->post('/register', $this->payload(['position' => 0]));

        $response->assertSessionHasErrors('position');
        $this->assertDatabaseMissing('pending_registrations', ['member_name' => 'new_doc']);
    }

    public function test_member_name_must_be_unique_across_users(): void
    {
        User::factory()->create(['member_name' => 'taken']);

        $response = $this->from('/register')->post('/register', $this->payload(['member_name' => 'taken']));

        $response->assertSessionHasErrors('member_name');
        $this->assertDatabaseMissing('pending_registrations', ['member_name' => 'taken']);
    }

    public function test_member_name_must_be_unique_across_pending_registrations(): void
    {
        PendingRegistration::create([
            'full_name' => 'Someone',
            'member_name' => 'pending_dude',
            'member_email' => 'pending@example.com',
            'position' => 1,
            'password' => 'hashed-placeholder',
        ]);

        $response = $this->from('/register')->post('/register', $this->payload(['member_name' => 'pending_dude']));

        $response->assertSessionHasErrors('member_name');
    }

    public function test_email_must_be_valid(): void
    {
        $response = $this->from('/register')->post('/register', $this->payload(['member_email' => 'not-an-email']));

        $response->assertSessionHasErrors('member_email');
        $this->assertDatabaseMissing('pending_registrations', ['member_name' => 'new_doc']);
    }

    public function test_password_must_be_confirmed(): void
    {
        $response = $this->from('/register')->post('/register', $this->payload([
            'password' => 'secret-pass1',
            'password_confirmation' => 'different-pass1',
        ]));

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertDatabaseMissing('pending_registrations', ['member_name' => 'new_doc']);
    }
}
