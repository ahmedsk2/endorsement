<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AC-02's *"resendable singly"* (P1c-2 Task 3, Decision C).
 *
 * THE PROPERTY THAT MATTERS IS NOT "a new row exists" — it is that **the old link is dead**. An
 * invitation is a bearer credential: whoever holds the URL can create the account it names. A
 * resend happens precisely because the first link went missing, aged out, or was forwarded into a
 * mailbox somebody else now reads, so re-mailing the same token would extend the life of a
 * credential that may already be in the wrong hands and would make revoking the first link
 * meaningless — the "revoked" one and the "new" one would be the same secret.
 *
 * THE SUPERSEDED ROW IS KEPT. `invitations` records revocation rather than deleting, and who was
 * invited, by whom, and what became of it is the history AC-03 exists to preserve — as well as the
 * only evidence available if a link is later found somewhere it should not be.
 */
class InvitationResendTest extends TestCase
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

    private function chief(): User
    {
        return User::factory()->create(['position' => 5]);
    }

    /**
     * Invite through the ENDPOINT, so the plaintext token comes back the one way a real inviter
     * ever sees it — flashed, once, never stored.
     *
     * @return array{0: Invitation, 1: string} the row and its plaintext token
     */
    private function inviteVia(User $actor, string $email = 'new.doctor@example.org', int $position = 4): array
    {
        $response = $this->actingAs($actor)
            ->post('/admin/invitations', ['member_email' => $email, 'position' => $position]);

        $response->assertRedirect();

        return [
            Invitation::query()->orderByDesc('id')->firstOrFail(),
            $this->tokenFrom((string) session('invitation_link')),
        ];
    }

    /** The link is `/invitation/{token}`; the token is the last segment. */
    private function tokenFrom(string $link): string
    {
        return (string) basename(parse_url($link, PHP_URL_PATH) ?: '');
    }

    // ------------------------------------------------------------------ rotation

    public function test_a_resend_rotates_the_token(): void
    {
        $admin = $this->admin();
        [$first, $oldToken] = $this->inviteVia($admin);

        // Sanity: the link we captured really did redeem before the resend.
        $this->assertNotNull(Invitation::redeemable($oldToken));

        $this->actingAs($admin)
            ->post("/admin/invitations/{$first->id}/resend")
            ->assertRedirect();

        $newToken = $this->tokenFrom((string) session('invitation_link'));
        $latest = Invitation::query()->orderByDesc('id')->firstOrFail();

        $this->assertNotSame($first->id, $latest->id, 'A resend mints a new row.');
        $this->assertNotSame($first->token_hash, $latest->token_hash);

        // The assertion that carries the security property: asserting the hash changed only
        // proves a new secret exists. The OLD one must no longer open the door.
        $this->assertNull(Invitation::redeemable($oldToken), 'The superseded link must be dead.');
        $this->assertNotNull(Invitation::redeemable($newToken));
    }

    public function test_the_superseded_row_is_kept_and_marked_revoked(): void
    {
        $admin = $this->admin();
        [$first] = $this->inviteVia($admin);

        $this->actingAs($admin)->post("/admin/invitations/{$first->id}/resend")->assertRedirect();

        $first->refresh();

        $this->assertNotNull($first->revoked_at, 'The superseded row is revoked...');
        $this->assertSame($admin->getKey(), (int) $first->revoked_by_user_id);
        // ...and KEPT. Two rows, one of them live.
        $this->assertSame(2, Invitation::count());
        $this->assertSame(1, Invitation::whereNull('revoked_at')->whereNull('accepted_at')->count());
    }

    public function test_a_resend_does_not_change_the_position(): void
    {
        $admin = $this->admin();
        [$first] = $this->inviteVia($admin, 'charge.nurse@example.org', 2);

        // The request carries no position, and a supplied one is ignored: the role travels with
        // the INVITATION. A resend that could re-position would be a promotion with no audit
        // trail and none of `users.position`'s gate.
        $this->actingAs($admin)
            ->post("/admin/invitations/{$first->id}/resend", ['position' => 3])
            ->assertRedirect();

        $this->assertSame(2, (int) Invitation::query()->orderByDesc('id')->value('position'));
    }

    // ------------------------------------------------------------------ who may resend

    public function test_a_chief_resident_cannot_resend_a_consultants_invitation(): void
    {
        [$consultant] = $this->inviteVia($this->admin(), 'consultant@example.org', 3);

        $this->actingAs($this->chief())
            ->post("/admin/invitations/{$consultant->id}/resend")
            ->assertForbidden();

        $this->assertSame(1, Invitation::count());
        $this->assertNull($consultant->refresh()->revoked_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'user_scope_denied']);
    }

    public function test_a_chief_resident_may_resend_a_residents_invitation(): void
    {
        $chief = $this->chief();
        [$resident] = $this->inviteVia($chief, 'resident@example.org', 4);

        $this->actingAs($chief)
            ->post("/admin/invitations/{$resident->id}/resend")
            ->assertRedirect();

        $this->assertSame(2, Invitation::count());
    }

    public function test_a_clinician_cannot_resend_anything(): void
    {
        [$invitation] = $this->inviteVia($this->admin());

        $this->actingAs(User::factory()->create(['position' => 4]))
            ->post("/admin/invitations/{$invitation->id}/resend")
            ->assertForbidden();

        $this->assertSame(1, Invitation::count());
    }

    // ------------------------------------------------------------------ expiry

    public function test_resending_an_expired_invitation_issues_a_fresh_one(): void
    {
        // The main use case: the link aged out before the night shift saw it.
        $admin = $this->admin();
        [$first, $oldToken] = $this->inviteVia($admin);

        $first->forceFill(['expires_at' => now()->subDay()])->save();
        $this->assertNull(Invitation::redeemable($oldToken));

        $this->actingAs($admin)->post("/admin/invitations/{$first->id}/resend")->assertRedirect();

        $newToken = $this->tokenFrom((string) session('invitation_link'));
        $this->assertNotNull(Invitation::redeemable($newToken));
        $this->assertTrue(Invitation::query()->orderByDesc('id')->firstOrFail()->expires_at->isFuture());
    }

    public function test_an_expired_unclaimed_invitation_does_not_block_re_inviting_the_same_person(): void
    {
        // Decision C made explicit: expiry is the MECHANISM by which an unclaimed invitation
        // becomes re-issuable, not an obstacle to it. "Nothing stops it" and "we decided nothing
        // should" are different states and only the second is safe to build on.
        $admin = $this->admin();
        [$first] = $this->inviteVia($admin);

        $first->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($admin)
            ->post('/admin/invitations', ['member_email' => 'new.doctor@example.org', 'position' => 4])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The expired row is INERT: the supersede pass only touches rows that are still open, so
        // it is neither disturbed nor counted as revoked.
        $this->assertNull($first->refresh()->revoked_at);
        $this->assertSame(2, Invitation::count());
    }

    // ------------------------------------------------------------------ what resend refuses

    public function test_a_person_with_an_account_cannot_be_resent_to(): void
    {
        $admin = $this->admin();
        [$invitation] = $this->inviteVia($admin);

        // They claimed it through some other door (a second link, an admin approval) since.
        $person = Person::query()->where('email', 'new.doctor@example.org')->firstOrFail();
        User::factory()->create(['person_id' => $person->getKey()]);

        $this->actingAs($admin)
            ->post("/admin/invitations/{$invitation->id}/resend")
            ->assertSessionHasErrors('invitation');

        $this->assertSame(1, Invitation::count(), 'A refusal writes nothing.');
        $this->assertNull($invitation->refresh()->revoked_at);
    }

    /**
     * A REFUSAL THE OPERATOR CANNOT SEE IS A BUTTON THAT DOES NOTHING (review F7).
     *
     * This endpoint has four refusals and they used to land on two different keys. Three said
     * `invitation`; the fourth — "somebody claimed this address through another door" — reused the
     * `member_email` key of the Validator it happens to be expressed through, because the rule is
     * shared with the invite path (`Person::accountEmailRule()`, D9's one predicate) and a
     * validator names its errors after the field it was handed.
     *
     * That key is rendered on Admin → Users, which loops the whole `errors` bag — but NOT on
     * Admin → People, which reads exactly one key (`errors.invitation`) and says so in its own
     * props docblock. People is where the per-person Resend button lives, so pressing it against
     * a since-claimed address flashed nothing at all: no error, no link, no change. The review
     * named Admin → Users as the silent screen; it is the other one.
     *
     * The fix is the KEY, not a second render site: a screen that had to enumerate the keys an
     * endpoint might use would drift the next time one was added. This case is the pairing —
     * every refusal this endpoint can produce, against the one key the screen reads.
     */
    public function test_every_resend_refusal_lands_on_the_one_key_the_people_screen_renders(): void
    {
        $admin = $this->admin();

        // 1. Claimed — the account exists.
        [$claimed] = $this->inviteVia($admin, 'claimed@example.org');
        $claimed->forceFill(['accepted_at' => now()])->save();

        // 2. Revoked — a deliberate administrator act meaning "this link must not work".
        [$revoked] = $this->inviteVia($admin, 'revoked@example.org');
        $this->actingAs($admin)->delete("/admin/invitations/{$revoked->id}")->assertRedirect();

        // 3. The roster row is gone, so there is nothing to address the resend to.
        [$orphaned] = $this->inviteVia($admin, 'orphaned@example.org');
        Person::query()->where('email', 'orphaned@example.org')->firstOrFail()->delete();

        // 4. Somebody claimed this address through another door since the row was minted.
        [$overtaken] = $this->inviteVia($admin, 'overtaken@example.org');
        User::factory()->create([
            'person_id' => Person::query()->where('email', 'overtaken@example.org')->firstOrFail()->getKey(),
        ]);

        // The four setup invitations above already spent four of this operator's six sends a
        // minute, and that bucket is SHARED across every `throttle:6,1` route (Laravel keys an
        // authenticated request's signature on the user id alone, not on the route) — so without
        // this the seventh request below would be a 429 and the case would fail for a reason that
        // has nothing to do with what it is about. The sharing itself is asserted separately, in
        // `test_the_send_bucket_is_shared_across_every_invitation_endpoint_that_sends()`.
        RateLimiter::clear(sha1((string) $admin->getAuthIdentifier()));

        foreach ([$claimed, $revoked, $orphaned, $overtaken] as $invitation) {
            $this->actingAs($admin)
                ->from('/admin/people')
                ->post("/admin/invitations/{$invitation->id}/resend")
                ->assertSessionHasErrors('invitation');
        }

        // ... and that key is the one the People screen actually renders. Asserted against the
        // source, because the render site is what makes the key load-bearing: if this template
        // ever stops reading `errors.invitation`, all four refusals go silent together and the
        // four assertions above would still pass.
        $screen = (string) file_get_contents(resource_path('js/Pages/Admin/People.vue'));
        $this->assertStringContainsString('errors.invitation', $screen,
            'Admin → People no longer renders `errors.invitation` — the resend refusals are silent again.');
    }

    public function test_a_claimed_invitation_cannot_be_resent(): void
    {
        $admin = $this->admin();
        [$invitation] = $this->inviteVia($admin);

        $invitation->forceFill(['accepted_at' => now()])->save();

        $this->actingAs($admin)
            ->post("/admin/invitations/{$invitation->id}/resend")
            ->assertSessionHasErrors('invitation');

        $this->assertSame(1, Invitation::count());
    }

    public function test_a_revoked_invitation_cannot_be_resent_and_the_refusal_names_the_other_door(): void
    {
        // Revoking is a deliberate administrator act meaning "this must not work". Resending from
        // that row would undo it through a shorter path. Re-inviting is still available and is a
        // DIFFERENT endpoint (admin.invitations.store) — the refusal says so, and the People
        // screen offers Invite rather than Resend for exactly this state.
        $admin = $this->admin();
        [$invitation] = $this->inviteVia($admin);

        $this->actingAs($admin)->delete("/admin/invitations/{$invitation->id}")->assertRedirect();

        $this->actingAs($admin)
            ->post("/admin/invitations/{$invitation->id}/resend")
            ->assertSessionHasErrors('invitation');

        $this->assertSame(1, Invitation::count());
    }

    // ------------------------------------------------------------------ the send bound

    /**
     * THE THROTTLE ACTUALLY FIRES, AND ITS BUCKET IS PER OPERATOR RATHER THAN PER ROUTE
     * (review F6). `MailSendingRoutesAreThrottledTest` proves every sending endpoint DECLARES a
     * bound; this proves the bound refuses, and it pins the shape of the bound.
     *
     * Laravel resolves an authenticated request's throttle signature from the user id alone —
     * not from the route — so `store`, `resend` and `bulk-resend`, all `throttle:6,1`, draw on
     * ONE bucket of six sends a minute per operator. That is the right semantics here and is
     * asserted rather than assumed: the thing being rationed is access to the SMTP relay and to
     * the minting of bearer credentials, and an attacker who could spend six on each of three
     * endpoints would have eighteen.
     */
    public function test_the_send_bucket_is_shared_across_every_invitation_endpoint_that_sends(): void
    {
        $admin = $this->admin();
        RateLimiter::clear(sha1((string) $admin->getAuthIdentifier()));

        // Six invitations — the whole minute's allowance, spent on one endpoint.
        for ($i = 1; $i <= 6; $i++) {
            $this->actingAs($admin)
                ->post('/admin/invitations', ['member_email' => "joiner{$i}@example.org", 'position' => 4])
                ->assertRedirect();
        }

        $this->assertSame(6, Invitation::count());

        // The seventh is refused on the SAME endpoint ...
        $this->actingAs($admin)
            ->post('/admin/invitations', ['member_email' => 'joiner7@example.org', 'position' => 4])
            ->assertStatus(429);

        // ... and, because the bucket is the operator's rather than the route's, the sibling
        // endpoint is refused too, without a single request having reached it.
        $resendable = Invitation::query()->orderByDesc('id')->firstOrFail();

        $this->actingAs($admin)
            ->post("/admin/invitations/{$resendable->id}/resend")
            ->assertStatus(429);

        // A refusal writes nothing: the seventh invitation was never minted, and the resend
        // neither rotated a token nor revoked the row it would have superseded.
        $this->assertSame(6, Invitation::count());
        $this->assertNull($resendable->refresh()->revoked_at);
    }

    // ------------------------------------------------------------------ the trail

    public function test_the_resend_is_audited_by_ids_only(): void
    {
        $admin = $this->admin();
        [$first] = $this->inviteVia($admin, 'audit.me@example.org');

        $this->actingAs($admin)->post("/admin/invitations/{$first->id}/resend")->assertRedirect();

        $latest = Invitation::query()->orderByDesc('id')->firstOrFail();

        $issued = (string) AuditLog::query()->where('action', 'invitation_issued')
            ->orderByDesc('id')->value('detail');
        $revoked = (string) AuditLog::query()->where('action', 'invitation_revoked')
            ->orderByDesc('id')->value('detail');

        $this->assertStringContainsString('invitation='.$latest->id, $issued);
        $this->assertStringContainsString('reason=resent', $issued);
        $this->assertStringContainsString('invitation='.$first->id, $revoked);
        $this->assertStringContainsString('reason=resent', $revoked);

        // No PHI and no personal data in a detail — ids, field names and counts only. Staff
        // personal data is covered by the same rule (docs/COMPLIANCE.md).
        $everything = AuditLog::query()->pluck('detail')->implode(' ');
        $this->assertStringNotContainsString('audit.me@example.org', $everything);
        $this->assertStringNotContainsString('@', $everything);
    }

    public function test_a_failed_mail_send_does_not_lose_the_invitation(): void
    {
        // The single path may swallow a mail failure precisely BECAUSE the one-time link is the
        // fallback delivery. Pinned here before the bulk path (Task 4) makes the opposite choice,
        // where there is nowhere to surface N bearer credentials.
        $admin = $this->admin();
        [$first] = $this->inviteVia($admin);

        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('the mail server is down'));

        $response = $this->actingAs($admin)->post("/admin/invitations/{$first->id}/resend");

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, Invitation::count());
        $this->assertNotEmpty(session('invitation_link'));
        $this->assertNotNull(Invitation::redeemable($this->tokenFrom((string) session('invitation_link'))));
    }

    // ------------------------------------------------------------------ the group's gate

    public function test_every_route_in_the_invitations_group_is_gated_in_controller(): void
    {
        // Invariant 8: the invitation endpoints are this codebase's one deliberate exception to a
        // `cap:` middleware, because the rule is two-tier and position-dependent. Deliberately
        // COARSE — it proves each handler MENTIONS the gate, not that it applies it correctly —
        // and honest about it: the alternative is a hand-written list covering only the routes
        // somebody remembered.
        $ungated = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'admin/invitations')) {
                continue;
            }

            $this->assertContains('auth', $route->gatherMiddleware(), $route->uri().' is not behind auth.');

            [$class, $method] = explode('@', (string) $route->getActionName());
            $reflection = new \ReflectionMethod($class, $method);
            $lines = file((string) $reflection->getFileName()) ?: [];
            $body = implode('', array_slice(
                $lines,
                $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1,
            ));

            if (! str_contains($body, 'ManagerScope::')) {
                $ungated[] = $route->methods()[0].' '.$route->uri().' → '.$class.'@'.$method;
            }
        }

        $this->assertNotSame([], Route::getRoutes()->getRoutes(), 'The router is empty — the scan proved nothing.');
        $this->assertSame([], $ungated,
            "Every admin/invitations route is gated IN-CONTROLLER by ManagerScope (invariant 8).\n"
            .implode("\n", $ungated));
    }
}
