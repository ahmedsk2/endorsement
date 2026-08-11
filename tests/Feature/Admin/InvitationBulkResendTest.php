<?php

namespace Tests\Feature\Admin;

use App\Mail\OpsAlertMail;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\Invitations\BulkResend;
use App\Support\Invitations\InvitationIssue;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * LV-02's bulk resend (P1c-2 Task 4, Decision D) — the first operation in this codebase with a
 * side effect the database cannot roll back.
 *
 * THE PROPERTY THAT MATTERS IS THE ORDERING: the rows are committed, THEN the mail goes out, THEN
 * the trail is written. A transaction that rolled back after the sends would leave recipients
 * holding live links to invitations that do not exist and an operator whose screen says the whole
 * thing was refused — a state with no recovery, and one no test inside this process could observe,
 * because the failure is in somebody else's inbox. Committing first means the worst case is the
 * reverse: rows exist and some mail did not go, which is visible, reportable per person, and fixed
 * by resending those people.
 *
 * `test_mail_is_sent_after_the_transaction_commits_not_inside_it` is the one that proves it, and it
 * was watched failing against an implementation that mailed inside the transaction before it was
 * trusted.
 */
class InvitationBulkResendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);

        // Decision D property 1: the endpoint refuses outright when mail is not configured, so
        // every case that is NOT about that refusal has to configure it first. `smtp` is the one
        // transport this codebase treats as "mail works" (`AppSettings::applyOverrides()` selects
        // it, `OpsAlert` and `SettingsController` both ask the same question).
        config(['mail.default' => 'smtp']);
    }

    private function admin(): User
    {
        return User::factory()->create(['position' => 0]);
    }

    private function chief(): User
    {
        return User::factory()->create(['position' => 5]);
    }

    /**
     * A roster person holding one OPEN invitation — the ordinary subject of a bulk resend.
     *
     * Minted through `InvitationIssue`, the one writer, rather than a factory: there is no
     * `Invitation` factory (finding 3) and a fixture that inserted rows directly would be a
     * second writer of the shape this slice spent a task removing.
     */
    private function invited(User $actor, int $position = 4, ?string $email = null): Person
    {
        $person = Person::factory()->create([
            'position' => $position,
            'email' => $email ?? fake()->unique()->safeEmail(),
        ]);

        InvitationIssue::issue($this->requestAs($actor), $person, $position);

        return $person;
    }

    /** The `Request` `InvitationIssue` authorizes and attributes against. */
    private function requestAs(User $actor): Request
    {
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn (): User => $actor);

        return $request;
    }

    /** Age a person's latest invitation out, the way the clock would. */
    private function expire(Person $person): void
    {
        Invitation::query()
            ->where('person_id', $person->getKey())
            ->orderByDesc('id')
            ->limit(1)
            ->update(['expires_at' => now()->subDay()]);
    }

    /** @param  list<Person>  $people */
    private function preview(User $actor, array $people): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($actor)->post('/admin/invitations/bulk-resend/preview', [
            'person_ids' => array_map(fn (Person $p): int => (int) $p->getKey(), $people),
        ]);
    }

    /** @param  list<Person>  $people */
    private function confirm(User $actor, array $people, string $digest): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($actor)->post('/admin/invitations/bulk-resend', [
            'person_ids' => array_map(fn (Person $p): int => (int) $p->getKey(), $people),
            'digest' => $digest,
        ]);
    }

    /** @param  list<Person>  $people */
    private function digestFor(User $actor, array $people): string
    {
        $this->preview($actor, $people)->assertRedirect();

        return (string) (session('invitation_bulk_preview')['digest'] ?? '');
    }

    // ------------------------------------------------------------------ the honest refusal

    public function test_bulk_resend_is_refused_outright_when_mail_is_not_configured(): void
    {
        $admin = $this->admin();
        $person = $this->invited($admin);

        // The single-invite path may legitimately proceed with mail off — it flashes the one-time
        // link on screen as the delivery. A bulk path has nowhere to surface N bearer credentials,
        // so refusing is the only honest branch.
        config(['mail.default' => 'log']);

        $before = Invitation::count();

        $response = $this->actingAs($admin)->post('/admin/invitations/bulk-resend', [
            'person_ids' => [$person->getKey()],
            'digest' => str_repeat('0', 64),
        ]);

        $response->assertSessionHasErrors('person_ids');
        $this->assertStringContainsString(
            'Settings',
            (string) session('errors')->first('person_ids'),
            'The refusal must name the fix, not merely decline.',
        );
        $this->assertSame($before, Invitation::count(), 'A refused bulk resend writes nothing.');
    }

    public function test_the_preview_is_refused_when_mail_is_not_configured_too(): void
    {
        // Otherwise the operator previews forty-seven sends and is refused only at the confirm,
        // which is the same information arriving one click too late.
        $admin = $this->admin();
        $person = $this->invited($admin);

        config(['mail.default' => 'log']);

        $this->preview($admin, [$person])->assertSessionHasErrors('person_ids');
        $this->assertNull(session('invitation_bulk_preview'));
    }

    // ------------------------------------------------------------------ preview

    public function test_the_preview_names_the_count_and_the_number_of_emails(): void
    {
        $admin = $this->admin();
        $a = $this->invited($admin);
        $b = $this->invited($admin);

        $before = $this->invitationSnapshot();

        $this->assertNotSame([], $before, 'The snapshot has to have something in it to compare.');

        $this->preview($admin, [$a, $b])->assertRedirect()->assertSessionHasNoErrors();

        $preview = session('invitation_bulk_preview');

        $this->assertSame(2, $preview['summary']['selected']);
        $this->assertSame(2, $preview['summary']['will_send']);
        $this->assertSame(0, $preview['summary']['skipped']);
        $this->assertSame(BulkResend::CAP, $preview['cap']);
        $this->assertCount(2, $preview['rows']);
        $this->assertSame(64, strlen((string) $preview['digest']));

        // A preview writes NOTHING — not a row, not a revocation, not an audit entry.
        $this->assertSame($before, $this->invitationSnapshot());
        $this->assertSame(0, AuditLog::where('action', 'invitation_bulk_resend')->count());
        $this->assertSame(0, AuditLog::where('action', 'invitation_resent')->count());
    }

    public function test_the_preview_never_carries_a_link_or_an_address(): void
    {
        // The preview reaches an Inertia prop. A bulk path has nowhere to surface N one-time bearer
        // credentials and must not try — ids and counts only, and the screen resolves names from
        // the roster props it already holds.
        $admin = $this->admin();
        $person = $this->invited($admin, 4, 'preview.subject@example.org');

        $this->preview($admin, [$person])->assertRedirect();

        $encoded = (string) json_encode(session('invitation_bulk_preview'));

        $this->assertStringNotContainsString('@', $encoded);
        $this->assertStringNotContainsString('/invitation/', $encoded);
    }

    public function test_the_preview_and_the_report_reach_the_screen_as_shared_flash_props(): void
    {
        // `back()->with(...)` flashes to the SESSION. A key no `HandleInertiaRequests::share()`
        // names is invisible to every page in the app — the feature exists only in a feature test,
        // all four suites stay green, and the screen shows nothing. That has happened three times
        // in this programme already, which is why this asserts through a real second request
        // rather than reading `session()` the way every other case here does.
        Mail::fake();

        $admin = $this->admin();
        $person = $this->invited($admin);

        $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->post('/admin/invitations/bulk-resend/preview', ['person_ids' => [$person->getKey()]])
            ->assertRedirect();

        // `withHeaders()` sets the CASE's default headers, not one request's, so an un-flushed
        // `X-Inertia: true` makes the next GET a 409 version mismatch rather than a page.
        $this->flushHeaders();

        $page = $this->actingAs($admin)->get('/admin/people');
        $page->assertOk();

        $props = $page->viewData('page')['props'];
        $this->assertNotNull($props['flash']['invitation_bulk_preview'] ?? null);

        $digest = (string) $props['flash']['invitation_bulk_preview']['digest'];

        $this->confirm($admin, [$person], $digest)->assertRedirect();

        $props = $this->actingAs($admin)->get('/admin/people')->viewData('page')['props'];
        $this->assertNotNull($props['flash']['invitation_bulk_report'] ?? null);
        $this->assertSame(1, $props['flash']['invitation_bulk_report']['summary']['sent']);
    }

    // ------------------------------------------------------------------ the pin

    public function test_a_confirm_against_a_different_set_than_was_previewed_is_refused(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $a = $this->invited($admin);
        $b = $this->invited($admin);

        $digest = $this->digestFor($admin, [$a]);
        $before = $this->invitationSnapshot();

        $this->confirm($admin, [$a, $b], $digest)->assertSessionHasErrors('digest');

        $this->assertSame($before, $this->invitationSnapshot(), 'A stale confirm writes nothing at all.');
        Mail::assertNothingSent();
    }

    public function test_the_pin_covers_the_state_not_merely_the_id_set(): void
    {
        // `StatePin` exists because re-deriving the analysis inside the transaction is necessary
        // and NOT sufficient: it makes the commit compute a FRESH answer, so a world that moved
        // between the preview and the confirm is acted on as whatever it now says rather than as
        // what the operator approved. Here that is somebody claiming their account, or an
        // administrator revoking their link, in the seconds between the two clicks.
        //
        // An id-set-only pin — the shape Decision D's own wording suggests — passes the id check
        // here and sends anyway. Watched failing against exactly that before this was trusted.
        Mail::fake();

        $admin = $this->admin();
        $a = $this->invited($admin);
        $b = $this->invited($admin);

        $digest = $this->digestFor($admin, [$a, $b]);

        // The same TWO ids, but one of them has since claimed.
        User::factory()->create(['person_id' => $b->getKey()]);

        $before = $this->invitationSnapshot();

        $this->confirm($admin, [$a, $b], $digest)->assertSessionHasErrors('digest');

        $this->assertSame($before, $this->invitationSnapshot());
        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------------ the cap

    public function test_more_than_fifty_ids_is_refused_by_validation(): void
    {
        $admin = $this->admin();
        $ids = Person::factory()->count(BulkResend::CAP + 1)->create()
            ->map(fn (Person $p): int => (int) $p->getKey())->all();

        $response = $this->actingAs($admin)->post('/admin/invitations/bulk-resend/preview', [
            'person_ids' => $ids,
        ]);

        $response->assertSessionHasErrors('person_ids');

        // A refusal that NAMES the cap, never a silent truncation: an operator whose fifty-first
        // person quietly did not get an email has no way to discover it.
        $this->assertStringContainsString(
            (string) BulkResend::CAP,
            (string) session('errors')->first('person_ids'),
        );
        $this->assertNull(session('invitation_bulk_preview'));
    }

    // ------------------------------------------------------------------ authorize the WHOLE set

    public function test_the_whole_selection_is_authorized_before_any_write(): void
    {
        // P1c-1 finding 12's shape: nine residents and one consultant is a 403 for the whole
        // submission, not nine sent and one refused. `ManagerScope::assertMayTarget()` audits its
        // refusal and then aborts, so the pass runs BEFORE the transaction — inside one, the audit
        // row would unwind with the abort and the attempt would vanish from the trail.
        $chief = $this->chief();
        $admin = $this->admin();

        $residents = [];
        for ($i = 0; $i < 9; $i++) {
            $residents[] = $this->invited($admin, 4);
        }
        $consultant = $this->invited($admin, 3);

        $before = Invitation::count();
        $digest = str_repeat('a', 64);

        $this->confirm($chief, [...$residents, $consultant], $digest)->assertForbidden();

        $this->assertSame($before, Invitation::count(), 'Not one invitation is minted.');
        $this->assertSame(1, AuditLog::where('action', 'user_scope_denied')->count());
    }

    public function test_a_chief_resident_may_bulk_resend_to_residents(): void
    {
        Mail::fake();

        $chief = $this->chief();
        $admin = $this->admin();
        $a = $this->invited($admin, 4);
        $b = $this->invited($admin, 4);

        $digest = $this->digestFor($chief, [$a, $b]);
        $this->confirm($chief, [$a, $b], $digest)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, session('invitation_bulk_report')['summary']['sent']);
    }

    // ------------------------------------------------------------------ skipped, not refused

    public function test_a_person_with_an_account_is_skipped_not_refused(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $a = $this->invited($admin);
        $claimed = $this->invited($admin);
        User::factory()->create(['person_id' => $claimed->getKey()]);

        $digest = $this->digestFor($admin, [$a, $claimed]);
        $this->confirm($admin, [$a, $claimed], $digest)->assertRedirect()->assertSessionHasNoErrors();

        $report = session('invitation_bulk_report');

        $this->assertSame(
            BulkResend::SKIPPED_HAS_ACCOUNT,
            $this->outcomeFor($report, $claimed),
        );
        $this->assertSame(BulkResend::SENT, $this->outcomeFor($report, $a));
        $this->assertSame(1, $report['summary']['sent']);
    }

    public function test_a_person_with_no_email_is_skipped(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $a = $this->invited($admin);
        $addressless = $this->invited($admin);
        $addressless->forceFill(['email' => null])->save();

        $digest = $this->digestFor($admin, [$a, $addressless]);
        $this->confirm($admin, [$a, $addressless], $digest)->assertRedirect()->assertSessionHasNoErrors();

        $report = session('invitation_bulk_report');

        $this->assertSame(BulkResend::SKIPPED_NO_EMAIL, $this->outcomeFor($report, $addressless));
        $this->assertSame(1, $report['summary']['sent']);
    }

    public function test_a_person_with_no_invitation_is_skipped_rather_than_invited(): void
    {
        // A bulk RESEND is not a bulk INVITE. There is no superseded row to take a position from,
        // and `people.position` is not it: positions 0 and 5 are absent from
        // `InvitationController::OFFERABLE` on purpose, so minting from the roster row would make
        // this the wider door onto a bearer credential that the whole slice exists to prevent.
        Mail::fake();

        $admin = $this->admin();
        $a = $this->invited($admin);
        $never = Person::factory()->create(['position' => 4]);

        $digest = $this->digestFor($admin, [$a, $never]);
        $this->confirm($admin, [$a, $never], $digest)->assertRedirect()->assertSessionHasNoErrors();

        $report = session('invitation_bulk_report');

        $this->assertSame(BulkResend::SKIPPED_NO_INVITATION, $this->outcomeFor($report, $never));
        $this->assertSame(0, Invitation::where('person_id', $never->getKey())->count());
    }

    public function test_a_revoked_invitation_is_skipped_the_way_the_single_path_refuses_it(): void
    {
        // `InvitationController::resend()` refuses a revoked row outright, because revoking is a
        // deliberate act meaning "this link must not work" and resending from that row would undo
        // it through a shorter path. The bulk path must not be the shorter path.
        Mail::fake();

        $admin = $this->admin();
        $a = $this->invited($admin);
        $killed = $this->invited($admin);

        $invitation = Invitation::where('person_id', $killed->getKey())->orderByDesc('id')->firstOrFail();
        $this->actingAs($admin)->delete("/admin/invitations/{$invitation->id}")->assertRedirect();

        $digest = $this->digestFor($admin, [$a, $killed]);
        $this->confirm($admin, [$a, $killed], $digest)->assertRedirect()->assertSessionHasNoErrors();

        $report = session('invitation_bulk_report');

        $this->assertSame(BulkResend::SKIPPED_REVOKED, $this->outcomeFor($report, $killed));
        $this->assertSame(1, Invitation::where('person_id', $killed->getKey())->count());
    }

    public function test_an_expired_invitation_is_resent(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $aged = $this->invited($admin);
        $this->expire($aged);

        $digest = $this->digestFor($admin, [$aged]);
        $this->confirm($admin, [$aged], $digest)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(BulkResend::SENT, $this->outcomeFor(session('invitation_bulk_report'), $aged));
        $this->assertSame(2, Invitation::where('person_id', $aged->getKey())->count());
    }

    // ------------------------------------------------------------------ THE ordering

    public function test_mail_is_sent_after_the_transaction_commits_not_inside_it(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $a = $this->invited($admin);
        $b = $this->invited($admin);
        $c = $this->invited($admin);

        $digest = $this->digestFor($admin, [$a, $b, $c]);

        $before = Invitation::count();

        // Fail the SECOND mint, inside the transaction. If any mail had already gone out by then,
        // its recipient would hold a live link to an invitation the rollback has just erased.
        $minted = 0;
        Invitation::creating(function () use (&$minted): void {
            if (++$minted === 2) {
                throw new \RuntimeException('the second mint fails');
            }
        });

        $this->confirm($admin, [$a, $b, $c], $digest)->assertStatus(500);

        Mail::assertNothingSent();
        $this->assertSame($before, Invitation::count(), 'The whole transaction rolled back.');
        $this->assertSame(
            0,
            Invitation::whereNotNull('revoked_at')->count(),
            'Not even the supersede survived.',
        );
        $this->assertSame(0, AuditLog::where('action', 'invitation_bulk_resend')->count());
    }

    public function test_the_success_case_writes_the_rows_and_then_sends_one_mail_each(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $people = [$this->invited($admin), $this->invited($admin), $this->invited($admin)];

        $digest = $this->digestFor($admin, $people);
        $this->confirm($admin, $people, $digest)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(6, Invitation::count(), 'Three superseded rows kept, three fresh ones.');
        $this->assertSame(3, Invitation::whereNotNull('revoked_at')->count());
        Mail::assertSent(\App\Mail\InvitationMail::class, 3);

        $report = session('invitation_bulk_report');
        $this->assertSame(3, $report['summary']['sent']);
        $this->assertSame(0, $report['summary']['failed']);
    }

    public function test_the_report_never_carries_a_link_or_an_address(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $person = $this->invited($admin, 4, 'report.subject@example.org');

        $digest = $this->digestFor($admin, [$person]);
        $this->confirm($admin, [$person], $digest)->assertRedirect();

        $encoded = (string) json_encode(session('invitation_bulk_report'));

        $this->assertStringNotContainsString('@', $encoded);
        $this->assertStringNotContainsString('/invitation/', $encoded);
    }

    // ------------------------------------------------------------------ partial delivery

    public function test_a_single_failed_send_is_reported_per_person_and_does_not_abort_the_rest(): void
    {
        $admin = $this->admin();
        $ok = $this->invited($admin, 4, 'reachable@example.org');
        $bad = $this->invited($admin, 4, 'unreachable@example.org');

        $digest = $this->digestFor($admin, [$ok, $bad]);

        Log::spy();

        $pending = \Mockery::mock();
        $pending->shouldReceive('send')->andReturnNull();

        // SMTP transport errors routinely quote the envelope recipient back, which is exactly the
        // string that must never reach a log line or an audit detail.
        Mail::shouldReceive('to')->with('unreachable@example.org')
            ->andThrow(new \RuntimeException('550 5.1.1 <unreachable@example.org> user unknown'));
        Mail::shouldReceive('to')->andReturn($pending);

        $this->confirm($admin, [$ok, $bad], $digest)->assertRedirect()->assertSessionHasNoErrors();

        $report = session('invitation_bulk_report');

        $this->assertSame(1, $report['summary']['sent']);
        $this->assertSame(1, $report['summary']['failed']);
        $this->assertSame(BulkResend::SENT, $this->outcomeFor($report, $ok));
        $this->assertSame(BulkResend::MAIL_FAILED, $this->outcomeFor($report, $bad));

        // A partial send is the EXPECTED outcome, not an error state: both rows exist, so the
        // operator can resend just the one that failed.
        $this->assertSame(4, Invitation::count());

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return ! str_contains($message.' '.(string) json_encode($context), '@')
                && array_key_exists('person', $context)
                && array_key_exists('invitation', $context)
                && $context['exception'] === \RuntimeException::class;
        });
    }

    // ------------------------------------------------------------------ the trail

    public function test_one_summary_audit_row_plus_one_row_per_person(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $people = [$this->invited($admin), $this->invited($admin)];
        $skipped = $this->invited($admin);
        User::factory()->create(['person_id' => $skipped->getKey()]);

        $digest = $this->digestFor($admin, [...$people, $skipped]);
        $this->confirm($admin, [...$people, $skipped], $digest)->assertRedirect();

        $summaries = AuditLog::where('action', 'invitation_bulk_resend')->get();
        $this->assertCount(1, $summaries, 'ONE summary row for the operation.');
        $this->assertStringContainsString('selected=3', (string) $summaries[0]->detail);
        $this->assertStringContainsString('n=2', (string) $summaries[0]->detail);
        $this->assertStringContainsString('sent=2', (string) $summaries[0]->detail);
        $this->assertStringContainsString('failed=0', (string) $summaries[0]->detail);

        // One row per person ACTED ON — never the single path's PAIR (an issue plus a revoke),
        // which on a cohort of fifty would be a hundred chain appends for one operator act.
        $this->assertSame(2, AuditLog::where('action', 'invitation_resent')->count());
        $this->assertSame(0, AuditLog::where('action', 'invitation_issued')->count());
        $this->assertSame(0, AuditLog::where('action', 'invitation_revoked')->count());

        $detail = (string) AuditLog::where('action', 'invitation_resent')->orderByDesc('id')->value('detail');
        $this->assertMatchesRegularExpression('/person=\d+/', $detail);
        $this->assertMatchesRegularExpression('/invitation=\d+/', $detail);
        $this->assertMatchesRegularExpression('/superseded=\d+/', $detail);

        // Ids, field names and counts only — never a name, never an address.
        $everything = AuditLog::query()->pluck('detail')->implode(' ');
        $this->assertStringNotContainsString('@', $everything);
    }

    public function test_neither_new_action_is_on_the_anomaly_watch_list(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $people = [$this->invited($admin), $this->invited($admin)];

        $digest = $this->digestFor($admin, $people);
        $this->confirm($admin, $people, $digest)->assertRedirect();

        // The alert path must be genuinely LIVE, or "no mail was sent" proves only that alerting
        // was switched off: `OpsAlert::critical()` needs both a configured address and the smtp
        // transport before it sends anything at all.
        AppSettings::set('alert_email', 'ops@example.org');

        $this->artisan('audit:anomalies', ['--hours' => 24])->assertSuccessful();

        Mail::assertNotSent(OpsAlertMail::class);
    }

    // ------------------------------------------------------------------ rotation, in bulk

    public function test_every_resent_token_is_unique_and_every_old_one_is_dead(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $people = [];
        for ($i = 0; $i < 5; $i++) {
            $people[] = $this->invited($admin);
        }

        $originals = Invitation::query()->pluck('token_hash')->all();

        $digest = $this->digestFor($admin, $people);
        $this->confirm($admin, $people, $digest)->assertRedirect()->assertSessionHasNoErrors();

        $fresh = Invitation::whereNull('revoked_at')->pluck('token_hash')->all();

        $this->assertCount(5, $fresh);
        $this->assertCount(5, array_unique($fresh), 'Five resends, five distinct secrets.');
        $this->assertSame([], array_intersect($originals, $fresh), 'No token is ever re-mailed.');

        // Every superseded row is KEPT and marked revoked — the history AC-03 preserves, and the
        // only evidence available if a link is later found somewhere it should not be.
        $this->assertSame(10, Invitation::count());
        $this->assertSame(5, Invitation::whereNotNull('revoked_at')->count());
        foreach ($originals as $hash) {
            $this->assertNotNull(
                Invitation::where('token_hash', $hash)->value('revoked_at'),
                'An old link that still redeems is the whole failure a resend exists to prevent.',
            );
        }
    }

    /**
     * Every invitation row as plain comparable scalars — the whole table, not a count.
     *
     * A count would go on passing if a preview quietly stamped `revoked_at` on somebody's live
     * link, which is the write a preview is most plausibly one line away from making.
     *
     * @return list<array<string, string|int|null>>
     */
    private function invitationSnapshot(): array
    {
        return Invitation::query()->orderBy('id')->get()->map(fn (Invitation $i): array => [
            'id' => (int) $i->getKey(),
            'token_hash' => (string) $i->token_hash,
            'revoked_at' => $i->revoked_at?->toIso8601String(),
            'accepted_at' => $i->accepted_at?->toIso8601String(),
            'expires_at' => $i->expires_at?->toIso8601String(),
        ])->all();
    }

    /** @param  array<string, mixed>  $report */
    private function outcomeFor(array $report, Person $person): string
    {
        foreach ($report['rows'] as $row) {
            if ((int) $row['person_id'] === (int) $person->getKey()) {
                return (string) $row['outcome'];
            }
        }

        $this->fail('Person '.$person->getKey().' is missing from the report entirely.');
    }
}
