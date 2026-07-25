<?php

namespace Tests\Feature;

use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Unit;
use App\Models\User;
use App\Support\Push\FakePushSender;
use App\Support\Push\PushSender;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Spec §10.2 — handover-time reminders. A scheduled command checks each unit shortly
 * after each handover time: no signed endorsement today -> push to that unit's opted-in
 * users. Payloads carry unit + date + status ONLY — never patient data.
 */
class ReminderTest extends TestCase
{
    use RefreshDatabase;

    private FakePushSender $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);

        $this->sender = new FakePushSender();
        $this->app->instance(PushSender::class, $this->sender);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function unit(string $code): Unit
    {
        return Unit::where('code', $code)->firstOrFail();
    }

    private function optedInResident(string ...$codes): User
    {
        $user = User::factory()->create(['position' => 4]);

        foreach ($codes as $code) {
            $user->reminderUnits()->attach($this->unit($code)->id);
        }

        $user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.fake()->uuid(),
            'p256dh' => 'k-p256dh',
            'auth' => 'k-auth',
        ]);

        return $user;
    }

    // ----------------------------------------------------------- subscriptions

    public function test_a_user_can_register_and_remove_a_push_subscription(): void
    {
        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)->post('/push/subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'keys' => ['p256dh' => 'pk', 'auth' => 'ak'],
        ])->assertRedirect();

        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $user->id]);

        $this->actingAs($user)->delete('/push/subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
        ])->assertRedirect();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_subscriptions_require_authentication(): void
    {
        $this->post('/push/subscriptions', ['endpoint' => 'x'])->assertRedirect('/login');
    }

    public function test_reminder_units_can_be_chosen_on_the_profile(): void
    {
        $user = User::factory()->create(['position' => 4]);

        $this->actingAs($user)->patch('/profile/reminders', [
            'unit_ids' => [$this->unit('PICU')->id, $this->unit('WARD')->id],
        ])->assertRedirect();

        $this->assertSame(2, $user->fresh()->reminderUnits()->count());

        // Re-submitting replaces the set (unchecking works).
        $this->actingAs($user)->patch('/profile/reminders', [
            'unit_ids' => [$this->unit('PICU')->id],
        ])->assertRedirect();

        $this->assertSame(1, $user->fresh()->reminderUnits()->count());
    }

    // ----------------------------------------------------------- the command

    public function test_it_reminds_opted_in_users_of_units_with_no_signed_endorsement(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 13:45:00'));

        $user = $this->optedInResident('PICU');
        $this->optedInResident('NICU');

        // NICU is signed today; PICU is not (no sheet at all).
        Handover::create(['unit_id' => $this->unit('NICU')->id, 'handover_date' => '2026-07-24', 'patient_name' => 'Secret Child']);
        HandoverSignoff::create(['unit_id' => $this->unit('NICU')->id, 'handover_date' => '2026-07-24', 'signed_off_at' => now()]);

        $this->artisan('endorsement:remind')->assertExitCode(0);

        $this->assertCount(1, $this->sender->sent);
        [$subscription, $payload] = $this->sender->sent[0];

        $this->assertSame($user->id, $subscription->user_id);
        $this->assertStringContainsString('PICU', $payload['title'].$payload['body']);
        $this->assertStringContainsString('2026-07-24', $payload['body']);
    }

    public function test_payloads_never_carry_patient_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 07:55:00'));

        $this->optedInResident('WARD');
        Handover::create([
            'unit_id' => $this->unit('WARD')->id,
            'handover_date' => '2026-07-24',
            'patient_name' => 'Secret Child',
            'mrn' => 'SECRET-9',
        ]); // sheet exists but unsigned -> still reminded

        $this->artisan('endorsement:remind')->assertExitCode(0);

        $this->assertNotEmpty($this->sender->sent);
        $json = json_encode(array_column($this->sender->sent, 1));
        $this->assertStringNotContainsString('Secret Child', $json);
        $this->assertStringNotContainsString('SECRET-9', $json);
    }

    public function test_the_same_slot_is_never_reminded_twice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 13:45:00'));
        $this->optedInResident('PICU');

        $this->artisan('endorsement:remind')->assertExitCode(0);
        $this->artisan('endorsement:remind')->assertExitCode(0);

        // Four units unsigned, one opted-in user for PICU -> exactly one push total.
        $this->assertCount(1, $this->sender->sent);
    }

    public function test_outside_any_reminder_window_nothing_is_sent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 06:00:00'));
        $this->optedInResident('PICU');

        $this->artisan('endorsement:remind')->assertExitCode(0);

        $this->assertCount(0, $this->sender->sent);
    }

    public function test_users_without_opt_in_are_never_pushed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-24 13:45:00'));

        // Subscribed but NOT opted into any unit.
        $user = User::factory()->create(['position' => 4]);
        $user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/no-optin',
            'p256dh' => 'k',
            'auth' => 'k',
        ]);

        $this->artisan('endorsement:remind')->assertExitCode(0);

        $this->assertCount(0, $this->sender->sent);
    }
}
