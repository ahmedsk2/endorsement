> ## OWNER DECISIONS, 2026-08-10 — READER'S INDEX ONLY
>
> **Every decision below is already folded into the task text it governs.** This block is a
> reader's index, not a patch applied on top of tasks that contradict it. Four times in this
> programme a plan carried decisions in a prepended block and left the task text below unchanged
> (P1b Task 1's `clinic_owner` seed; P1b Tasks 6/7/8's `terminal` column; P1c-1's own Tasks 5 and
> 12), and each time an implementer was instructed by task text to build the thing the decision
> had forbidden. **If any task text below appears to disagree with this index, the task text is
> the bug — but it should not, because it was written after these decisions, not before.**
>
> **1. Sign-in stays password-based. There is no passwordless login and no magic-link sign-in.**
> AC-01's *"email link; password optional"* means the **invitation** is an email link that reaches
> the claim screen once, where the person sets a password. That is exactly what P0c built and what
> is live today. **AC-01 therefore needs no new work in this plan** — verified against the tree in
> finding 18, and there is deliberately no task for it. → **finding 18; Task 2's Invite affordance
> is a convenience on top of a satisfied requirement, not a gap being closed.**
>
> **2. Capability grants stay keyed to the account** (`user_capabilities.user_id`). AC-04 is
> satisfied by granting **per person on the People screen**, writing through to that person's
> linked account. **There is no move to `people`, no change to `AccessControl::resolve()`,
> `holdersOf()` or the cache key.** The deliberate consequence, which the UI and the docs must
> state: a person who leaves and later returns on a new account does **not** regain their old
> roles — an administrator re-grants them. Auto-restoring privileges on re-bind is the security
> anti-pattern this avoids. A person with no account has nothing to grant to; the screen says so
> rather than offering a control that silently does nothing. → **Decision F, Task 6.**
>
> **3. Unbinding deactivates the account and keeps it.** AC-03's unbind clears the person link,
> deactivates the account so nobody can log in, and preserves it as history (who signed off what,
> who invited whom). Accounts are never deleted. → **Decision E, Task 5.**
>
> **4. Invitation lifetime is configurable, default 7 days** (P1 owner decision 5, round 2,
> 2026-08-08). This **overrides Munawib AC-02's "default 14 days"** and is logged as an override,
> not applied silently. The knob sits behind **`settings.manage`**. → **Decision A, Task 1;
> recorded in the overrides table by Task 7.**

# P1c-2 — Account lifecycle

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** the account half of Munawib Stage 1's people layer. P1c-1 built the roster — who exists,
what level they hold, how they are imported and promoted — and deliberately touched no account. This
plan builds what happens to an account over its life: how long an invitation is good for, how it is
sent again when the first one is lost, how an administrator can see who has actually claimed theirs,
what happens when somebody leaves, and how roles are granted to the person rather than hunted for on
a second screen.

**Binding requirements:** AC-01, AC-02, AC-03, AC-04 and LV-02's account subset — quoted verbatim
from `docs/munawib/SPEC.md:67` and `:63`:

> AC-01 — **Email invitation link** creates accounts: scheduler adds/imports entry → invitation →
> person claims profile and sets sign-in (email link; password optional). AC-02 — Invitations
> expire (default 14 days), resendable singly or in bulk; claim status visible. AC-03 — One account
> ↔ one person; unbinding on turnover is an admin action preserving history. AC-04 — Roles granted
> per person by Admin, enforced via server-side claims.

> LV-02 — People screens support multi-select bulk actions: set level, set status, **resend
> invitations**, deactivate, export.

Plus **D3** held (identity is two tables; a roster-only person cannot authenticate by
construction), **D9** held (a picker's write-side validation matches what it offers, per field),
**D11** held (`institution_id` is provenance, never a filter, never part of a key), **D7** held (no
unauthenticated route), and **ST-06** held (`App\Support\Calendar` is the only converter and the
client does no date arithmetic).

**Tech Stack:** Laravel 13, PHP 8.4, Inertia 3 + Vue 3, PHPUnit 12 (SQLite in-memory,
`APP_TIMEZONE=Asia/Riyadh`), Vitest, Playwright, Tailwind 4 via `@theme`, MySQL 8.4 in production.

**Baseline this plan was written against:** branch `feat/p1-master-rota` at `d3733ce` (*"feat:
moving a year of the rota, and getting it back out again"* — the P1d-2b merge). **Measured, not
assumed**, by running the commands below via **Bash**, with a clean working tree:

```bash
npm run build     # ✓ built
php artisan test  # {"tool":"phpunit","result":"passed","tests":1297,"passed":1297,"assertions":5920,"duration_ms":214561}
npm test          # Test Files 19 passed (19) / Tests 187 passed (187)
```

`npm run test:e2e` was **not** re-measured for this document; `tests/e2e/` carries **seven** spec
files (plus `fixtures.js` and `global-setup.js`) and none of them exercises an invitation. **Measure it yourself before you claim a number** —
five of P1c-1's thirteen amendments and one of P1d-2's were the plan's own expected-count arithmetic
being stale before the task began. Every count below is arithmetic, not evidence.

**`php artisan test` takes ~3 minutes 35 seconds.** Budget for it. Filter (`| tail -5`), and on a
failure re-run only the failing filter (`php artisan test --filter <TestName> | head -30`).

---

## What this plan is, and is not

**It is** one new runtime setting, one projection of "where has this person's invitation got to",
one writer for issuing and reissuing an invitation, one bulk operation that sends real email, one
writer for retiring an account, one second surface onto the capability writer that already exists,
and the document corrections all of that invalidates.

**It adds NO migration.** Not one. Every column it needs exists: `invitations.person_id` (P0c,
`2026_08_10_120005`), `users.person_id` (P0c, UNIQUE, nullable), `user_capabilities.effect`
(`2026_07_24_120003`), `app_settings.key`/`value` (`2026_07_25_120001`), and
`handover_signoffs.signed_off_by_name` (`2026_07_27_120001`, additive and nullable). **The
`2026_08_14_1201*` migration slot P1c-1 reserved for this plan is released, unclaimed** — see
Decision H. If a task's implementation reaches for a migration, the task is being implemented
wrong; stop and re-read Decision G and Decision H.

**It creates no new path to a credential that is not the invitation.** `RosterNeverMintsCredentialsTest`
stays green with no allow-list change. `PersonController` gains no write to `users` — the Invite and
Resend affordances this plan adds to the People screen POST to `InvitationController`'s own
endpoints, which carry `ManagerScope`'s two-tier gate, and never to `/admin/people/*`.

**It moves no capability off the account.** Owner decision 2. `user_capabilities.user_id` stays.
`AccessControl::resolve()`, `holdersOf()` and the cache key are untouched, and Task 6 asserts they
are.

**It builds no passwordless sign-in.** Owner decision 1. No magic-link login, no
"email me a code to sign in", no `login_tokens` table. The only email link that authenticates
anything is the one-time invitation link, and it authenticates a *claim*, once, not a session.

**It touches one clinical table, once, deliberately, and only to preserve an attestation that
unbinding would otherwise erase.** Task 5 writes `handover_signoffs.signed_off_by_name` where it is
currently `null`, with the value the system already renders on those rows today. Nothing else in
this plan reads or writes `handovers`, `handover_revisions` or a `handover_signoffs` row. Finding 6
is why; Decision E is the reasoning; refusing to do it means blanking the signer's name on
medico-legal evidence, which is worse.

**It is ONE branch**, with a declared fallback seam after Task 4. See
[The split](#the-split-one-branch-with-a-declared-fallback-seam).

---

## Inherited invariants — stated as things a task must not break

Not preferences. Each has a test that fails, or a live defect that was once caused by breaking it.

1. **Identity is two tables.** `people` is the roster and the name/role of record; `users` is purely
   the account, linked by `users.person_id` (UNIQUE, nullable). A roster-only person has **no
   `users` row and therefore cannot authenticate by construction** —
   `RosterOnlyCannotAuthenticateTest` is what proves the structural half. **Never add a credential
   column to `people`. Never reintroduce a `person_status` lifecycle enum** — "claimed" is a join
   (`Person::hasAccount()`), and this plan's claim status is a *derived projection*, never a stored
   column (Decision B).
2. **`people.id` and `users.id` are independent sequences.** Never compare them, never copy one
   positionally into the other, never move an id between them without a join through
   `users.person_id`.
3. **`$user->full_name`, `$user->position` and `$user->member_email` are READ-THROUGH ACCESSORS onto
   the linked `Person`** (`User.php:85-116`). None is a real column any more. Any
   `select()`/`pluck()`/`value()`/constrained `with()` that omits `person_id` makes the accessor
   return **null silently, with no error**. This broke four live sites in P0c with zero test
   coverage. It is also the whole reason Task 5's unbind is dangerous — see finding 6.
4. **`users.member_email` is dead.** `people.email` is the single authoritative address; the
   password broker and every uniqueness check resolve through `Person::matchByEmail()` /
   `Person::accountEmailRule()`. Never read or write the raw column. `UserFactory::definition()`
   still populates it, so any "nothing writes `users.member_email`" assertion must scope itself to
   `app/` + `database/seeders/`, never `database/factories/`.
5. **`App\Support\PersonPresenter` is the ONLY path from a `Person` to Inertia props**, gated by
   `App\Policies\PersonPolicy`, and **a withheld contact field is ABSENT from the array, never
   `null`** — the two look identical on screen and a future consumer eventually renders one as the
   other. `ContactFieldsAreProjectedOnceTest` pins it at source level. **`Person::$hidden` is not
   the control and never was.**
6. **`PersonPresenter`'s BASE keys reach every `rota.view` holder** — that is every seeded position —
   through `contactFree()` (P1d-1 Task 7, P1d-2 Decision C). Anything added to the base map is
   published to the whole department. Claim status is per-caller `$extra`, never a base key
   (Decision B), and Task 2 asserts it.
7. **`PersonStatus::apply()` is the one definition of deactivating a PERSON** (`people.active` **and**
   the linked `users.active`), guarded at source level by `PersonActiveHasOneWriterTest`. Task 5's
   unbind is a **distinct** definition — it retires an *account* and never touches `people` — and it
   gets its own single-writer guard in the same house style. Decision E states why they are two
   definitions rather than one, and Task 5 proves `PersonActiveHasOneWriterTest` stays green with no
   allow-list change.
8. **Every route behind `auth` + a capability gate**; writes are POST/PATCH/DELETE + CSRF. The
   invitation endpoints are the codebase's one deliberate exception to a `cap:` middleware — they
   sit in the `auth`-only group and are gated **in-controller** by `ManagerScope`, because the rule
   is two-tier and position-dependent (`routes/web.php:111-134`). Every endpoint this plan adds to
   that group carries the same in-controller gate, and Task 3 asserts over the router that no route
   in the group is ungated.
9. **No PHI and no personal data in `audit_log.detail`** — ids, field names and counts only. Never a
   name, never an email address, never a phone number, never a filename. Staff personal data is
   covered by the same rule as PHI (`docs/COMPLIANCE.md:120-123`).
10. **Additive, nullable migrations; never retype a column holding real data; accounts deactivated,
    never deleted.** This plan adds no migration at all, and Decision G is what keeps
    `invitations.member_email`/`.position` from being "tidied" into a retype.
11. **LIGHT THEME ONLY. Semantic classes only.** No `dark:` utility, no raw Tailwind palette class,
    no hex in markup. **There is no `bg-panel-soft` token** — finding 15 shows three live sites, two
    of them on files this plan touches anyway.
12. **No client-side date arithmetic.** `CalendarIsTheOnlyConverterTest`'s JS needle list covers
    `new Date(`, `toISOString(`, `toLocaleDateString(`, `Date.parse(`, `Intl.DateTimeFormat` and six
    more, with no allow-list, and it matches docblock prose too. Every date this plan shows —
    expiry, claimed-on, revoked-on — is formatted server-side and arrives as a string. Decision B
    states exactly how.
13. **A bulk operation validates and authorizes the WHOLE set before acting**, in one transaction,
    with **one summary audit row**, preview-then-confirm. `PersonController::bulk()`'s six-step
    ordering (`:205-238`) is the shape to copy. Task 4 adds the one thing no previous bulk operation
    in this codebase had to handle: **it sends N emails, and mail cannot be rolled back.**
14. **`institution_id` is provenance, never a query filter, and never leads an index** (D11). This
    plan adds no index and no `where('institution_id', ...)`; `InstitutionProvenanceTest` must stay
    green untouched.

---

## Findings

Read these before any task. Each was verified against the tree at `d3733ce` by reading or running,
not inferred from a document.

1. **`Invitation::LIFETIME_DAYS = 7` has exactly two references in the whole tree** — its
   declaration (`app/Models/Invitation.php:18`) and its single use (`:73`,
   `'expires_at' => now()->addDays(self::LIFETIME_DAYS)`). Nothing in `app/` or `tests/` else reads
   it. Making the lifetime configurable is therefore one new method and one changed line, not a
   sweep.

2. **`AppSettings::KEYS` already contains a key that maps onto no framework config, and it is read
   directly** — `alert_email` (`app/Support/AppSettings.php:33`) has **no entry** in
   `applyOverrides()`'s `$map` (`:120-131`, mail and vapid only) and is read at three sites as
   `AppSettings::get('alert_email')` (`OpsAlert.php:53`, `SettingsController.php:129`,
   `InstanceShow.php:64-65`). P1c-1's scoping worried that a lifetime key "maps onto nothing, so the
   plan must decide whether to widen that class or give the setting its own home." **Neither: there
   is an established precedent for exactly this shape.** Decision A.

3. **`invitations` has no single-writer discipline.** `Invitation::issue()` (`:60-77`) is the only
   INSERT, but `accepted_at`/`revoked_at` are written at three separate controller sites via
   `forceFill([...])->save()`: `InvitationController::store():94-97` (supersede),
   `InvitationController::revoke():151-154`, and `InvitationAcceptController::store():181-184`.
   There is no `Invitation` factory and no scope on the model. Adding a resend path without first
   collecting the write shapes is how a fourth site appears.

4. **`InvitationController::openInvitations()` returns OPEN invitations only, and its
   `?User $viewer` parameter is declared and never used** (`:173-194`:
   `whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at','>',now())`). The scoping
   the parameter implies is done by the caller instead —
   `UserManagementController::index():113-116` applies `->when(! $all, fn ($c) => $c->where('position',
   self::RESIDENT))` to the returned array. AC-02's *"claim status visible"* renders nothing today
   for an accepted, revoked or expired invitation, and a **second** caller would have to remember to
   re-apply a scoping rule that lives in the first caller's body.

5. **`UserManagementController::index()`'s user list is an INNER JOIN on `people`** (`:86`:
   `->join('people', 'people.id', '=', 'users.person_id')`). **An account with `person_id = null`
   disappears from the Users screen entirely.** This is load-bearing for Task 5: after an unbind,
   the row is gone from the console, which is either the right behaviour stated plainly or a
   confusing silent vanishing. Decision E chooses, and says which.

6. **Nulling `users.person_id` blanks the signer's name on every pre-2026-07-27 signed handover that
   account signed.** The chain: `handover_signoffs.signed_off_by_name` was added additive and
   nullable by `2026_07_27_120001_freeze_signed_off_by_name.php`, whose own docblock says
   *"Existing rows keep resolving through the relation (the payload falls back to it), so nothing is
   backfilled and no history moves"*; the read at `EndorsementController.php:1054` is
   `$s?->signed_off_by_name ?? $s?->signedOffBy?->full_name`; and `$u->full_name` is
   `$this->person?->full_name`. So for any signoff written before that date, the signer's name is
   resolved live through `users.person_id` — and unbinding sets it to null. **That is precisely the
   failure mode the freeze migration exists to prevent** (its docblock: *"a deactivated account …
   made the attribution disappear entirely, because the print template hides the line when the name
   is null"*), reintroduced through a different door, and under the 2026-07-27 signature ruling that
   line is the whole attestation wherever a signature is withheld. Decision E.

7. **Two other name resolutions run through the same accessor and are NOT evidence.**
   `PersonPresenter::history()`'s `'by' => $span->createdBy?->full_name` (`:142`) and
   `openInvitations()`'s `'invited_by' => $i->invitedBy?->full_name`
   (`InvitationController.php:187`). Both blank on unbind. Neither is frozen onto a signed sheet;
   both have an audit row carrying the actor's id. Accepted, with the reason recorded rather than
   discovered.

8. **`handover_signoffs.reopened_by_user_id` is stored and rendered nowhere.** `grep` over `app/` and
   `resources/js/` finds it only in `EndorsementController.php:583` (the write) and
   `HandoverSignoff.php:126` (`$fillable`). There is no `reopened_by_name` column, no `reopenedBy()`
   relation, and none is needed — nothing resolves that id to a name, so unbinding cannot blank it.

9. **There is no audit-viewing UI.** `app/Http/Controllers/Admin/` holds fifteen controllers and none
   reads `audit_log`. No screen resolves `audit_log.user_id` to a name, so unbinding does not blank
   an audit trail either — the trail is ids, which is the rule anyway.

10. **`user_capabilities` is `(user_id, capability_id, effect)` with `effect ∈ {'grant','deny'}` and
    `unique(user_id, capability_id)`** (`2026_07_24_120003_create_access_control_tables.php:35-43`).
    **There is no `capability` string column and no `granted` boolean** — a plan that assumes either
    shape is wrong. `AccessControl::resolve()` is **private** (`AccessControl.php:147`); the public
    API is `capabilitiesFor()`, `allows()`, `holdersOf()`, `flush()`. The cache key is
    generation-scoped (`access_control.caps.v{gen}.user.{id}`, `:186-191`), `CACHE_TTL = 600`. Deny
    wins unconditionally over grant (`:168-178`, two passes).

11. **`AccessControlController::updateUser()` already is the per-user override writer, and it already
    does the whole discipline** (`:246-319`): validates capability ids against the catalog, computes
    the change diff inside the transaction, calls `AccessControl::flush($userId)`, then writes one
    summary row plus one row per changed override (`access_user_grant` / `access_user_deny` /
    `access_user_override_clear`) **after** the commit. **AC-04 is a second SURFACE onto this
    writer, not a second writer.**

12. **Granting a capability is `cap:access.manage` (`routes/web.php:89-99`); the People screen is
    `cap:people.manage` (`:219`).** A role-granting control served from the People screen's own route
    group would make `people.manage` a path to `access.manage` — and `people.manage` was scoped in
    P1c-1's Decision A as "who exists and what level they hold", explicitly *not* the account
    console. **A UI convenience must not widen a security boundary.** Decision F.

13. **`InvitationController::OFFERABLE` is `[2 => Charge Nurse, 3 => Consultant, 4 => Resident]`**
    (`:29-33`), and its class docblock states why 0, 1 and 5 are absent: *"Widening this list would
    make an invitation a route to privilege, which is exactly what it must not be."* Nothing in this
    plan widens it, and Task 3 asserts the constant is unchanged so a resend cannot become the wider
    door.

14. **Mail is synchronous, conditional, and its failure is swallowed.** `App\Mail\InvitationMail`
    does **not** implement `ShouldQueue`, and `grep -rn ShouldQueue app/Mail app/Notifications`
    returns nothing — no mailable or notification in this codebase is queued. `InvitationController::store():122-131`
    sends only `if (config('mail.default') === 'smtp')` and wraps the send in
    `try { … } catch (\Throwable $e) { Log::warning(…); }`. The single-invite path can afford to
    swallow, because it flashes the one-time link on screen as a fallback (`:136-141`,
    `back()->with('invitation_link', $link)`). **A bulk path cannot** — there is nowhere to surface
    N one-time bearer credentials. Decision D.

15. **`bg-panel-soft` compiles to nothing, and there are THREE live sites, not one.**
    `resources/css/app.css` declares `--color-ground` (`:51`), `--color-ground-deep` (`:52`) and
    `--color-panel` (`:53`), and **no `--color-panel-soft`**. P1c-1's finding 18 named only
    `resources/js/Components/StaffPrivacyNotice.vue:25`. The other two are
    `resources/js/Pages/Admin/Users.vue:179` (the invitations table header) and
    `resources/js/Pages/Auth/AcceptInvitation.vue:43` — both on files this plan edits anyway.

16. **`Users.vue:367` still carries `colspan="7"` on an eight-column table.** Verified, unmoved by
    P1c-1. The eight `<th>` are at `:277-291` (Name, Username, Email, Role, 2FA, Last signed in,
    Status, Actions — "Last signed in" at `:289` is the one added after the colspan was written).
    It is a static literal; contrast `People.vue`'s `:colspan="showsPhone ? 8 : 7"` binding at
    `:599` and `:669`, which is the house pattern.

17. **`invitations.position` is an authorization subject, not a role assignment.**
    `InvitationAcceptController::store():102-110` takes `'position' => $locked->position` **only on
    the branch that CREATES a person** (commented in place: *"FROM THE INVITATION, never from the
    request"*); for an invitation already bound to a roster person it resolves the existing row and
    never writes `people.position`. So a stale invitation cannot demote someone on claim — but
    nothing asserts it. Decision G, pinned by a test in Task 3.

18. **AC-01 is already satisfied end to end, and nothing in this plan implements it.** The chain
    exists in full: `Invitation::issue()` mints a 64-hex `random_bytes(32)` token and stores only its
    sha256 (`:71`); `InvitationMail` carries the link (`app/Mail/InvitationMail.php`);
    `routes/auth.php:41-44` serves `GET|POST /invitation/{token}` under `throttle:20,1` / `10,1`;
    `Invitation::redeemable()` (`:85-97`) is the single redemption predicate;
    `InvitationAcceptController::show()` renders `Auth/AcceptInvitation`, and `store()` (`:62-206`)
    re-resolves under `lockForUpdate()`, claims the existing roster person rather than creating a
    second one, and inserts the `users` row with the password the claimant sets. Coverage lives in
    `tests/Feature/Auth/InvitationTest.php`. Owner decision 1 fixes the reading of *"password
    optional"*: the **email link** is the invitation, the **password** is what the claim screen sets.
    **No task in this plan implements AC-01, and none should be added.**

19. **Three definitions of "normalise an email address" exist, not two.** `Person::normalizeEmail()`
    (`Person.php:295-300`, mb-safe, `''` → `null`) is the one definition; `Invitation::issue():69`
    has `Str::lower(trim($email))` inline; and `InvitationController::store():57` has a **second**
    inline copy of the same expression. Design §14 item 17 records only the first of the two. Both
    collapse in Task 1 and Task 3, in the files those tasks already open.

20. **`invitations` carries a composite index `(member_email, accepted_at)` (`:58`) and a separate
    index on `person_id` (`2026_08_10_120005:24`).** A per-person "latest invitation" lookup is
    served by the second. **Neither leads with `institution_id`**, correctly — CLAUDE.md names an
    `institution_id`-led index as a recurring mistake caught twice already. This plan adds no index.

21. **`pending_registrations` has no writer anywhere in this codebase** (design §14 item 8;
    `GET /register` binds to `RegisteredUserController::closed()`), yet
    `InvitationController::store():48` still validates `Rule::unique('pending_registrations',
    'member_email')` against it. Dropping the table is owner-gated on a production count of zero and
    is **not** this plan's job. Do not "tidy" the rule away — while the table exists, the check is
    free and correct.

---

## Where the design doc, the P1c-1 plan and the Munawib spec are wrong or thin about this slice

Every plan in this programme has found at least one; P1b found seven, P1c-1 found seven. These are
P1c-2's.

| Claim | Reality |
|---|---|
| Munawib AC-02: invitations expire *"(default 14 days)"* | **Overridden to 7, deliberately, by owner decision 5 (round 2, 2026-08-08)** and already recorded in design §14 item 13 and `docs/OPEN-DECISIONS.md:228-238`. An invitation is a credential that reaches children's clinical records once redeemed; a shorter window means a forwarded link is live for less time. **Task 7 adds the row to design §1.2's overrides table** — until now the deviation lived only in an open-items entry, which is where deviations go to be forgotten. |
| P1c-1's P1c-2 scoping, item 1: *"note `AppSettings::KEYS` is an allow-list mapped onto framework config by `applyOverrides()`, and this key maps onto nothing, so the plan must decide whether to widen that class or give the setting its own home"* | Neither is needed. **`alert_email` is already a `KEYS` entry with no `$map` entry, read directly at three sites** (finding 2). The class already supports settings that are values rather than config overrides; the lifetime key is the second of that species, not the first. |
| P1c-1's P1c-2 scoping, item 4: an unbind *"nulls `users.person_id`"*, and the plan *"must state what an unbound account resolves to"* | Correct as far as it goes, and it misses the sharper problem: **nulling `person_id` also blanks the signer's name on pre-2026-07-27 signed handovers** (finding 6), because the read falls back to a live accessor that resolves through that very column. "What does it resolve to" is answerable (no role capabilities, no login, invisible to `holdersOf()`); "what does it erase" was not asked and is the part that touches medico-legal evidence. Decision E. |
| P1c-1 finding 18: *"`bg-panel-soft` … is still used at `resources/js/Components/StaffPrivacyNotice.vue:25`"* | Undercounted. **Three sites** (finding 15), two of them on files this plan opens anyway (`Users.vue:179`, `AcceptInvitation.vue:43`). |
| P1c-1's migration ordering: *"**P1c-2 uses `2026_08_14_1201*`**"* | **P1c-2 needs no migration at all** (Decision H). Every column it uses exists. The slot is released unclaimed; P1d's `2026_08_15_*` and P1e's `2026_08_16_*` are unaffected either way. |
| P1 plan, P1c item 12 / P1c-1's P1c-2 scoping item 5 (AC-04): *"Moving it touches `AccessControl::resolve()`, `holdersOf()` and the cache key"* | True of a move, and **owner decision 2 is that there is no move.** AC-04 is satisfied by a second surface onto `AccessControlController::updateUser()`'s existing per-account writer (finding 11). The security-boundary change this scoping anticipated does not happen; a *different* one would happen by accident if the surface were gated on `people.manage` (finding 12), and Decision F is what prevents it. |
| Munawib AC-01: *"email link; password optional"* | Read by owner decision 1 as: the **invitation** is the email link; the password is what the claim screen sets, and it is not optional. Already shipped in P0c (finding 18). **No task.** |
| Munawib LV-02: bulk *"resend invitations"* listed alongside set level / set status / deactivate / export | It is an **account** action sharing a **roster** screen's selection. P1c-1 correctly shipped it as a visibly disabled control (`People.vue:481-484`, `title="Arrives with the invitation work (AC-02)"`). Task 4 replaces it — but the endpoint it posts to is **`InvitationController`'s, under `ManagerScope`**, not `/admin/people/bulk` under `cap:people.manage`. The screen is shared; the authorization is not. |
| Design §14 item 17: *"`Invitation::issue()`'s own email normalisation is a second definition of one fact"* | There are **three** (finding 19) — `Person::normalizeEmail()`, `Invitation::issue():69`, and `InvitationController::store():57`. |

---

## Decision A: the lifetime is an `app_settings` value behind `settings.manage`, read through `Invitation::lifetimeDays()`, and `applyOverrides()` is not widened

Owner decision 4, and the home question P1c-1 left open (`docs/OPEN-DECISIONS.md:82-92`) answered:
**`settings.manage`.**

**Where and why.** It goes in `app_settings` as `invitation_lifetime_days`, added to
`AppSettings::KEYS` as a **non-secret** (`false`), with **no entry in `applyOverrides()`'s `$map`** —
it overrides no framework config, exactly like `alert_email` (finding 2). It is read directly:

```php
/**
 * How long an invitation stays redeemable, in days.
 *
 * `LIFETIME_DAYS` is the DEFAULT, not the value — an unset or unparseable setting falls back
 * to it, so a department that never opens the settings screen behaves exactly as it did before
 * this method existed. The clamp is not belt-and-braces over the FormRequest: `app_settings` is
 * a plain key/value table an operator can also reach with a database console, and an invitation
 * is a bearer credential whose lifetime must not be settable to "effectively never" by a route
 * this method cannot see.
 */
public static function lifetimeDays(): int
{
    $configured = (int) (AppSettings::get('invitation_lifetime_days') ?? 0);

    return $configured >= self::LIFETIME_MIN && $configured <= self::LIFETIME_MAX
        ? $configured
        : self::LIFETIME_DAYS;
}
```

with `LIFETIME_MIN = 1` and `LIFETIME_MAX = 30` as constants beside `LIFETIME_DAYS = 7`, and
`SettingsController::update()` validating `['sometimes','nullable','integer','min:1','max:30']` from
those same two constants rather than from repeated literals.

**Why `settings.manage` rather than `users.manage`.** Two reasons, and the second is the one that
decides it. First, every other runtime knob lives there — SMTP, VAPID, the operational-alert address —
and a second settings surface is a second place for a validated write to go wrong. Second, this is a
**credential-exposure parameter**: how long a bearer link that leads to children's clinical records
stays live. It belongs on the screen an administrator reviews in one pass alongside the other
security parameters, not on the console where day-to-day invitations are issued, because the person
issuing invitations all day is exactly the person for whom "make the links last longer" is a
convenience.

**Why 30 as the ceiling.** It is a bound, not a default, and it exists so the knob cannot be turned
to something absurd (owner decision 5's own words). Munawib's own figure is 14 and the owner's is 7;
a month is the longest window anyone has argued for and it still expires. `min:1` because zero or
negative would mint an invitation that is dead on arrival — a support call, not a security event, but
a confusing one.

**D11.** `app_settings` carries no `institution_id` and this plan adds none. One database per
customer means one row; there is nothing to scope and nothing to filter. Contrast
`institutions.contact_visibility` (P1c-1 Decision B), which lives on `institutions` because it is a
*department* fact like `hijri_offset_days` — the difference is which screen an administrator reviews
it on, not tenancy, and both are one value per database.

---

## Decision B: `App\Support\InvitationStatus` is the one projection of "where has this person's invitation got to", and it is never a `PersonPresenter` base key

AC-02's *"claim status visible"*. Finding 4: today nothing renders for an accepted, revoked or
expired invitation, and the scoping rule lives in one caller's body.

**The projection.** `App\Support\InvitationStatus::forPeople(iterable $personIds, ?User $viewer): array`
returns, per person id, **one** array describing the latest invitation for that person:

```
['state' => 'none'    ]                                  // no invitation row
['state' => 'open',    'at' => '2026-08-17 09:30', 'id' => 12]   // expires_at
['state' => 'expired', 'at' => '2026-08-03 09:30', 'id' => 11]   // expires_at, in the past
['state' => 'claimed', 'at' => '2026-08-02 14:05', 'id' => 10]   // accepted_at
['state' => 'revoked', 'at' => '2026-08-01 11:20', 'id' => 9 ]   // revoked_at
```

Five states, derived, in that precedence order, from the columns that already exist
(`accepted_at`, `revoked_at`, `expires_at`) — **never a stored `status` column**, because that is the
`person_status` lifecycle enum D3 removed, wearing a different name. "Claimed" is a join
(`Person::hasAccount()`); "invited" is a row.

**One query for a whole page**, not one per person: a single `whereIn('person_id', $ids)` ordered by
`id` desc, folded to the first row per person id in PHP. The People screen lists the whole roster, so
a per-person lookup is the N+1 that P1c-1 finding 5 exists to prevent.

**The viewer parameter is used, not declared and ignored** (finding 4). `InvitationStatus` applies
`ManagerScope`'s rule itself: a viewer who is not a full manager sees a state only for people at
position 4, and `'state' => 'hidden'` otherwise. The predicate is
`ManagerScope::mayTarget(?User, int): bool` — a new **non-throwing** sibling of
`assertMayTarget()`, expressing the same rule, with a matrix test asserting the two agree for every
(capability set × position) pair. Two functions, one proven-equivalent rule, matrix-tested: the
`PickerParityTest` discipline (D9) applied to a read. Writing the scoping a second time inline is how
a Chief Resident ends up seeing a Consultant's claim state through the newer of two doors —
`ManagerScope`'s own docblock says so about the write side.

**It is `$extra`, never a base key.** `PersonPresenter::one()` does `$base + $extra`, and its base
map reaches the rota read view for every `rota.view` holder — which is every seeded position
(invariant 6). "Who in this department has not claimed their account yet" is not a fact the whole
department gets. The People controller supplies it as `$extra`; `PersonPresenter` never learns about
invitations at all. **Task 2 asserts this both ways**: the People props carry it, and the rota read
view's props carry no `invitation` key anywhere in the tree.

**Dates.** Every timestamp above is formatted **server-side** and arrives as a string. The date part
goes through `Calendar::label()` (Gregorian plus Hijri, the department's own habit and ST-06's rule);
the time part is appended from the stored instant. `resources/js` formats nothing and computes
nothing — `CalendarIsTheOnlyConverterTest` fails the build otherwise, and its needle list has no
allow-list. The existing invitations table's `->format('Y-m-d H:i')` (`InvitationController.php:190`)
moves onto the same helper in Task 2 so there is one shape, not two.

---

## Decision C: resend rotates the token, supersedes the old row, and never deletes it

**Resend rotates.** A resend exists because the first link was lost, expired, or went somewhere it
should not have. Re-mailing the same token would (a) extend the life of a credential that may already
be in the wrong hands, (b) make revoking the first link meaningless, since the "revoked" one and the
"new" one are the same bearer secret, and (c) break the property the system already has — that at
most one live link exists per address at any moment, which `InvitationController::store():83-105`'s
supersede loop already guarantees. Resend is therefore **not a new invariant; it is the existing one
reached by a shorter path.**

**The superseded row is kept**, `revoked_at` and `revoked_by_user_id` set, exactly as the supersede
loop does today. Never deleted. Who invited whom, and how many times, is the history AC-03 exists to
preserve — and it is also the only evidence available if a link is later found somewhere it should
not be. `invitations` accumulating rows is a known open item with no retention rule (design §14 item
7); this plan makes rows accumulate slightly faster and **does not** solve it — see the acceptance
section.

**One writer.** `App\Support\Invitations\InvitationIssue::issue(Person $person, int $position, User $actor, string $reason): array{invitation: Invitation, link: string, superseded: ?int}`
becomes the only thing that mints or supersedes an invitation. `InvitationController::store()` is
**refactored onto it in the same task**, so the invite path and the resend path cannot drift — the
same discipline P1c-1 Task 4 applied to `PositionChange` and P1d-1 Task 5 to `RotaAssignment`.
`$reason` is `'invited'` or `'resent'` and lands in the audit detail of the superseded row, never in
its own column.

`tests/Feature/Build/InvitationWritersAreSingularTest.php` is the source-level guard, in the house
style: a coarse substring scan over `app/`, `database/` and `routes/` for `Invitation::issue(`,
`Invitation::create(`, `->invitations()->create(`, `DB::table('invitations')`,
`'revoked_at' =>`, `'accepted_at' =>`; allow-listing `InvitationIssue.php` (the writer),
`Invitation.php` (the model's own `$fillable`/`issue()`), `InvitationController.php` (`revoke()` —
an explicit administrator act, not an issue path), `InvitationAcceptController.php` (the claim writes
`accepted_at`) and the two migrations. It collects `$offenders[]` and ends with
`assertSame([], $offenders, …)` — **never a `foreach` that stops guarding once the last offender is
fixed** — plus the companion `test_every_allow_listed_file_still_exists()`, because a stale allow-list
is a silently disabled guard.

**Resend does not widen who may be invited.** `InvitationController::OFFERABLE` stays `[2,3,4]`
(finding 13) and resend takes the position from the **superseded invitation row**, re-authorized
through `ManagerScope::assertMayTarget()` before anything is written. A resend cannot promote.

**An expired, unclaimed invitation does not block re-inviting or resending.** Verified against the
tree: `Person::accountEmailRule()` fails only when the matched person **has an account**
(`Person.php:337-350`), and the supersede loop only touches invitations that are still open
(`InvitationController.php:83-88`). So an expired row is inert — it neither blocks nor is disturbed.
**That is correct and deliberate: expiry is the mechanism by which an unclaimed invitation becomes
re-issuable.** Task 3 pins it with a test, because "nothing stops it" and "we decided nothing should"
are different states and only the second is safe to build on.

---

## Decision D: bulk resend is preview-then-confirm, capped, mail-only, sends AFTER the commit, and refuses outright when mail is not configured

This is the first bulk operation in this codebase with a side effect the database cannot roll back.
Five properties, each answering a specific way it can go wrong.

**1. It refuses when mail is not configured.** If `config('mail.default') !== 'smtp'`, the endpoint
returns a validation error naming the fix ("Configure SMTP under Settings before sending
invitations") and writes nothing. The single-invite path may legitimately proceed without mail — it
flashes the one-time link on screen (finding 14) — but a bulk path has **nowhere to surface N one-time
bearer credentials**, and a bulk resend that silently mails nothing is the live possibility P1c-1's
scoping named. Refusing is the only honest branch.

**2. Preview, then confirm, pinned by a digest.** The preview names the exact count, lists each
selected person with their current claim state and the outcome they would get, and says **how many
emails will be sent**. The confirm carries a digest of the resolved id set (`RosterImport`'s and
`RotaFill`'s discipline) and is refused outright on a mismatch, so a confirm cannot act on a
different set than was previewed.

**3. A hard cap of 50, not `PersonBulkRequest`'s 500.** Fifty covers a whole resident cohort. Five
hundred synchronous SMTP sends inside one HTTP request would time out halfway, leaving some
recipients mailed and the operator with no idea which — and a request that mails 500 people is
indistinguishable from a mis-click. The cap is stated on screen next to the button, and the endpoint
carries `throttle:6,1` (the precedent is `admin.settings.test-email`, `routes/web.php:156-157`), so a
mis-click cannot be repeated into a mail storm.

**4. One predicate decides both who is offered and who is accepted** (D9). `InvitationIssue::resendable()`
returns the query predicate: a person who is `active`, not trashed, has an email, has **no** account,
and whom the viewer may target under `ManagerScope::mayTarget()`. The preview offers exactly that set;
the FormRequest validates against exactly that set; a person outside it is reported as
`skipped_has_account` / `skipped_no_email` / `skipped_out_of_scope` from the **writer's own return
value**, never from a guess made before the write. The whole selection is authorized in a full pass
**before** the transaction opens — `ManagerScope::assertMayTarget()` audits its refusal and then
`abort(403)`s (P1c-1 finding 12), and inside a transaction that audit row unwinds with the abort and
the attempt vanishes from the trail.

**5. Mail is sent AFTER the transaction commits — never inside it.** The row work (supersede, insert,
rotate) is one `DB::transaction`. The sends happen after it returns. Two reasons, and the second is
the sharper one:

- P1c-1's post-merge finding 6 is the milder version of this mistake: `RosterImport::commit()` called
  an audit writer from inside its own transaction, serialising the hash chain for the import's
  duration and raising a false ops alert. That cost latency and a page.
- **Mail cannot be rolled back.** If the transaction failed after the sends, recipients would hold
  live links to invitations that do not exist, and the operator's screen would say the whole thing
  was refused. There is no recovery from that, and no test would catch it because the failure is
  outside the process. Committing first means the worst case is the reverse — rows exist, some mail
  did not go — which is visible, reportable per person, and fixable by resending.

**Per-recipient failure is reported, not swallowed.** Each send is wrapped individually; a throw is
caught, logged **without the address**, and recorded as `mail_failed` against that person id. The
screen shows "47 sent, 3 could not be delivered" with the three named, so the operator knows exactly
who to chase. The audit ordering is Decision H's shape from P1c-1, applied here: **one summary row
after the commit** — `invitation_bulk_resend` with `n=<count>;sent=<count>;failed=<count>` — plus one
`invitation_resent` per person carrying `person=<id>;invitation=<id>;superseded=<id|none>`.

**Deliberately NOT the single path's audit pair.** The single-invite path writes `invitation_issued`
plus one `invitation_revoked` per superseded row. On a bulk path of 50 that is 100 `AuditLog::record()`
calls, each opening its own transaction and taking `lockForUpdate` on the chain tail
(`AuditLog.php:58-83`). One action per person carrying both ids is the same information at half the
chain contention. **Neither new action joins `AuditAnomalies`' single-occurrence watch list** — that
list fires `OpsAlert::critical` per occurrence (`AuditAnomalies.php:83-99`), and a routine cohort
resend would page an operator fifty times. The summary row is the one a human should look at, and
the honest reason it is *not* added to the watch list is that resending invitations to residents who
have not claimed theirs is ordinary work, not an anomaly — unlike `person_promotion` and `rota_fill`,
which rewrite many rows behind one confirmation. This is stated so a later reader does not "fix" the
omission.

---

## Decision E: unbinding snapshots the attestation it would otherwise erase, then clears the link and deactivates the account — one transaction, one writer, and reactivation of an unbound account is refused

Owner decision 3: the unbind clears the person link, deactivates the account so nobody can log in,
and preserves it as history. Accounts are never deleted. Four things follow, and only the first is
obvious.

**1. Deactivate and unbind are ONE atomic act, in one transaction.** An active-but-unbound account is
the trap owner decision 3 names: `$user->full_name` and `$user->position` are read-through accessors
onto the linked `Person` (`User.php:85-100`), so such an account appears **nameless and positionless
on every screen with no error at all**, and `AccessControl::resolve()` resolves against a null
position. Never one without the other.

**2. It snapshots `signed_off_by_name` first.** Finding 6: for any handover signed before 2026-07-27,
`signed_off_by_name` is `null` and the signer's name is resolved live through
`signedOffBy?->full_name` → `users.person_id` → `people.full_name`. Nulling the link blanks the
attestation on medico-legal evidence — the exact failure the freeze migration exists to prevent,
reached through a different door. So, **inside the same transaction and before the link is cleared**:

```php
HandoverSignoff::query()
    ->where('signed_off_by_user_id', $user->getKey())
    ->whereNull('signed_off_by_name')
    ->update(['signed_off_by_name' => $person->full_name]);
```

This writes a currently-null column with **the value the system already renders on those rows
today**. It preserves evidence; it does not alter it. It is the only clinical-table write in this
plan, and the alternatives were both worse: refusing to unbind an account that has un-snapshotted
signoffs blocks turnover permanently (the thing AC-03 exists to enable), and unbinding without the
snapshot silently destroys attribution. The count goes in the audit detail; the name never does. On a
deployment younger than 2026-07-27 the update matches zero rows and is a no-op — which is why the
test must **construct** an un-snapshotted signoff rather than assume one exists.

**3. What an unbound account resolves to, proven rather than asserted.** With `person_id = null`:

- `AccessControl::resolve()` reads `$user->position` as `null`, so the role-defaults join
  (`role_capabilities.position = $user->position`) matches nothing. **Only explicit
  `user_capabilities` overrides remain** — and the account is deactivated, so they cannot be
  exercised. The override rows are kept: they are history, they are keyed to the account, and
  deleting them would be the "auto-restore on re-bind" behaviour owner decision 2 forbids, inverted.
- `AccessControl::holdersOf()` inner-joins `people` (`AccessControl.php:104-117`), so an unbound
  account **drops out of every holder lookup automatically** — it can never be selected as an alert
  recipient or a picker option.
- Login is refused because `users.active = false`.
- `AccessControl::flush($userId)` is called after the commit, so no cached capability set survives
  the change (`CACHE_TTL = 600` — ten minutes of a retained privilege set is exactly the window
  P1c-1's Decision C existed to close on the other side).

**4. Reactivation of an unbound account is refused, and that is the load-bearing guard.**
Without it, `PATCH /admin/users/{user}/active` would produce a live account with `person_id = null` —
nameless, positionless, holding stale overrides. `UserManagementController::setActive()` therefore
refuses `active = true` when `person_id === null`, with a message naming the actual remedy: *"This
account was unbound from its person and is kept only as history. Invite the person again to give them
a new account."* There is no rebind action, deliberately — owner decision 2's whole point is that a
returning person gets a new account and re-granted roles.

**Where it lives, and what happens to the row.** The unbind is an **account** action, so it sits on
the Users console behind `ManagerScope::assertMayTarget()` against the account's current position,
with a confirmation naming the person. Finding 5: `UserManagementController::index()` inner-joins
`people`, so **after the unbind the row disappears from the Users screen entirely.** That is the
right behaviour — a dead account that cannot log in and cannot be reactivated is not console clutter —
but it must be *stated*, not discovered: the flash message reads *"Account unbound and deactivated. It
is kept as history and no longer appears in this list."* There is no undo in the UI, exactly as
CLAUDE.md already records for clearing a period's rota assignments; the audit row
(`account_unbound`, `user=<id>;person=<id>;signoffs_snapshotted=<n>`) is the trace.

**It is refused for the last active Administrator**, reusing `PositionChange::isLastActiveAdministrator()`
rather than writing a second copy — unbinding the last admin is deactivating the last admin with
extra steps.

**Two definitions, deliberately, not one.** `PersonStatus::apply()` is "deactivate a **person**": it
writes `people.active` **and** the linked `users.active`, and it is guarded by
`PersonActiveHasOneWriterTest`. `App\Support\AccountUnbind::apply()` is "retire an **account**": it
writes `users.active` and `users.person_id` and **never touches `people` at all**. They are not the
same act — a person can turn over to a new account (a hospital email change, a returning locum) while
remaining a perfectly active member of the roster, and conflating them would deactivate a person the
department still schedules. Because it is distinct, **it gets its own source-level guard in the same
house style**: `tests/Feature/Build/AccountLinkHasOneWriterTest.php`, scanning `app/`, `database/` and
`routes/` for writes to `users.person_id` (`'person_id' => null`, `->person_id =`,
`update(['person_id'`, `DB::table('users')`), allow-listing `AccountUnbind.php` (the writer),
`InvitationAcceptController.php` (the claim inserts it), `UserManagementController.php`
(`approve()` inserts it) and the P0c migrations — collected into `$offenders[]` and asserted over the
whole set, never inside a `foreach`, with the companion stale-allow-list test. Task 5 also asserts
`PersonActiveHasOneWriterTest` stays green **with no allow-list change**, which is what proves the
two definitions really are disjoint rather than merely differently named.

**What is accepted, and why.** Finding 7: unbinding blanks `PersonPresenter::history()`'s `by` (who
recorded a level span) and `openInvitations()`'s `invited_by`. Neither is frozen onto a signed sheet;
both have an audit row carrying the actor's id; and neither is worth a second snapshot column on a
table this plan is not otherwise touching. Recorded here rather than found later.

---

## Decision F: AC-04 is a second surface onto the capability writer that already exists, gated `access.manage`, and a person with no account is told so

Owner decision 2: grants stay on `user_capabilities.user_id`. No move, no resolver change, no cache
key change.

**Gated on `access.manage`, not `people.manage`.** Finding 12 is the reason and it is not a style
question: `people.manage` was scoped in P1c-1's Decision A as "who exists and what level they hold",
explicitly *not* the account console, and its holder is a department administrator who may rename a
ward. A role-granting control served from the People screen's own `cap:people.manage` route group
would let that holder grant themselves `access.manage` — **a privilege-escalation path created by a
UI convenience.** So: the People screen *renders* the panel only for a viewer who also holds
`access.manage` (via `useCan()`), and the panel's endpoint sits in the existing
`cap:access.manage` route group. The People screen becomes a second **surface**; the boundary does
not move.

**One writer, refactored, not copied.** `AccessControlController::updateUser()` (`:246-319`) already
validates capability ids against the catalog, diffs inside the transaction, calls
`AccessControl::flush($userId)`, and audits one summary row plus one row per changed override after
the commit (finding 11). Task 6 extracts that body into
`App\Support\CapabilityGrant::applyForUser(User $user, array $grants, array $denies, User $actor, ?string $ip): array`
and **refactors the existing controller onto it in the same commit**, so the Access Control screen
and the People panel cannot drift. `tests/Feature/Build/CapabilityWritersAreSingularTest.php` is the
guard: needles for `UserCapability::create(`, `->userCapabilities()->`, `DB::table('user_capabilities')`,
`'effect' =>`, allow-listing `CapabilityGrant.php`, the seeder and the migration; whole-set assertion,
stale-allow-list companion.

**A person with no account gets a sentence, not a disabled control.** *"This person has no account.
Roles are granted to an account — invite them first."* A control that silently does nothing is the
thing P1c-1 refused to ship for bulk resend, and the same reasoning applies here.

**The consequence is stated in the UI and in the docs, not left as folklore.** Beside the panel:
*"Roles belong to the account, not the person. If this person leaves and later returns on a new
account, an administrator grants their roles again — they are not restored automatically."* Task 7
puts the same sentence in `docs/spec/08-foundation.md` and the design doc's §9. The reasoning is worth
keeping where a future implementer will read it: auto-restoring privileges when a person is re-bound
to a new account means a departed administrator's grants silently reattach to whoever claims that
identity next, and nobody reviews a restore that nobody performed.

---

## Decision G: `invitations.member_email` and `invitations.position` are kept and redocumented, not migrated

Both predate the two-table split (P0c added `invitations.person_id` afterwards,
`2026_08_10_120005`), so the question "are they now duplicating `people`?" is fair. The answer is no,
for different reasons, and **nothing is retyped or dropped** — CLAUDE.md's rule about columns holding
real data applies to both.

**`member_email` is a delivery record, not a duplicate.** It is the address a bearer credential was
actually mailed to, frozen at send time. `people.email` is the person's *current* address and can be
corrected afterwards — by an administrator on the People screen, or by a roster re-import. If the two
diverge, "which address did we send it to?" is answerable only from the invitation row, and that is
exactly the question asked when a link turns up somewhere it should not have. It is the same species
of column as `handover_signoffs.signed_off_by_name`: a snapshot that must not follow a later edit. It
also serves the `(member_email, accepted_at)` index and is the only identifying fact on an invitation
whose person row was created with a blank `full_name` placeholder
(`InvitationController.php:62-74`). **Verdict: keep. Task 3 corrects the model docblock to say what
it is** — a delivery record — so the next reader does not "collapse it onto `people.email`".

**`position` is the authorization subject, not a role assignment.** It is what
`ManagerScope::assertMayTarget()` authorizes against at issue, at revoke and (Task 3) at resend, and
it is the position a **newly created** person row is opened at. Finding 17: for an invitation already
bound to a roster person, `InvitationAcceptController::store()` never writes `people.position` — the
person's role of record stays the roster's. That is correct and nothing asserts it, so **Task 3 pins
it**: accepting a stale invitation issued at position 4 must not demote a person the roster has since
moved to position 5. Without that test, a future "helpful" line copying `$locked->position` onto the
existing person would pass every other test in the suite. **Verdict: keep, redocument, pin.**

---

## Decision H: this plan adds no migration, and the `2026_08_14_1201*` slot is released

Every column needed already exists: `invitations.person_id`, `users.person_id`,
`user_capabilities.effect`, `app_settings.key`/`value`, `handover_signoffs.signed_off_by_name`.

Things a reader might expect and which are deliberately **not** added:

- **No `invitations.resent_at` / `resend_count`.** Resend rotates (Decision C), so each resend is a
  new row; the count is `invitations.where('person_id', $id)->count()` and the history is the rows
  themselves. A counter column would be a second, drift-prone answer to a question the rows already
  answer.
- **No stored claim-status column.** Decision B — it is derived from `accepted_at`/`revoked_at`/
  `expires_at`, and a stored one is `person_status` with a new name.
- **No `user_capabilities.granted_by` / `granted_at`.** The hash-chained `audit_log` already carries
  actor and time for every grant (finding 11), and adding provenance columns to a security table
  belongs in a plan that reviews the whole table, not as a side effect of adding a second screen.
- **No `invitations` retention column.** Design §14 item 7 is still open, still unassigned, and this
  plan does not pretend to close it.

**P1c-1 reserved `2026_08_14_1201*` for this plan; it is released unclaimed.** P1d's `2026_08_15_*`
and P1e's `2026_08_16_*` allocations are unaffected. A migration appearing in this slice means
something in it was designed wrong — stop and re-read this decision before writing one.

---

## The split: one branch, with a declared fallback seam

**Recommendation: one branch, seven tasks.** P1c-1 scoped this at "~6 tasks" and seven is the honest
count once AC-04's writer extraction and the document corrections are counted separately. It is
comfortably smaller than P1b (13), P1c-1 (13) and P1d-2 (13), and every task leaves the tree
deployable with the suite green.

**The fallback seam, on P1b's and P1c-1's own precedent, is after Task 4.** Tasks 1–4 are entirely
`invitations` — one object, one security story (a bearer credential and its lifetime). Tasks 5–7 are
the account **link** and its **roles** — a different object, and Task 5 is the one that touches a
clinical table. If execution stalls, merge after Task 4: AC-02 and LV-02's bulk resend ship complete
and useful on their own, and AC-03/AC-04 follow as `P1c-2b`.

**Do not split anywhere else.** Tasks 2 and 3 both edit `InvitationController` and Task 3's writer
extraction is what Task 4 builds on; Task 5's guard test and Task 6's guard test are independent but
Task 7 corrects the documents for all six.

**Nothing depends on this plan.** P1d-1 and P1d-2 have already merged and depended only on P1c-1.
P1e (clinics) does not depend on it either. It is the last of Stage 1's people work and its only
downstream consumer is Munawib §35's acceptance clause *"residents claimed accounts"*, which becomes
**observable** for the first time in Task 2.

---

## Amendments made during execution

**Task 2 (2026-08-10) — `forPeople()` takes Person MODELS, not ids, and the plan's own signature
would have cost a second query.** Decision B gives the signature as
`forPeople(iterable $personIds, ?User $viewer)` while its prose says the position for the scoping
decision may be taken "from the already-loaded person collection". Those are not compatible: with
bare ids the projection cannot know a position, and a person with **no** invitation row has nothing
in the `invitations` result to join a position from — so scoping could not be applied to exactly the
people whose state is `none`, and a Chief Resident would learn that a Consultant had never been
invited. Implemented as `forPeople(iterable $people, ?User $viewer)` taking the collection
`PersonController::rosterProps()` already holds. One query, no leak.

**Task 2 — the budget case caught a real N+1 on its first run, from an unexpected direction.**
`test_the_whole_page_costs_one_query` reported **34** queries for 30 people against a bound of 1.
The extra thirty were not the invitation lookup (that was correctly one `whereIn`) but a per-person
`hasAccount()` `EXISTS`, added while computing whether to offer the Invite button. `has_account` is a
caller-set `withExists()` alias, so it would have been free on the real screen and catastrophic for
any caller that forgot — the worst shape of defect, invisible in production until the one caller that
did not know. `mayInvite()` now asks only what is free (role offerable × viewer tier); the screen
answers the account and address questions from props it already holds. Both budget cases were then
re-run against the offence deliberately reintroduced (31 and 12→37) before being trusted.

**Task 2 — the claim tag is rendered BESIDE the account tag, not replacing it.** Step 5 has
`{{ person.has_account ? 'Account' : 'Roster only' }}` *becoming* the claim-state tag, with
*"Account (claimed 2 Aug)"* as one of five strings. It is shipped as two tags instead, because the
two facts are independent: a person can hold an account with **no invitation row at all** (the
bootstrap administrator, a legacy-imported member, an approved pending registration), and a single
tag would label them *"No invitation"* — which reads as "unclaimed" and is the opposite of true.
`InvitationStatus` stays purely invitation-derived, exactly as Decision B specifies.

**Task 2 — one `bg-panel-soft` site (Task 7's list) was closed early.** `Users.vue`'s invitations
table header is the table this task rewrote; leaving a class that compiles to nothing on markup being
edited anyway would have been deliberate. Two sites remain for Task 7 (`StaffPrivacyNotice.vue`,
`AcceptInvitation.vue`), and `Users.vue:367`'s stale `colspan` is untouched — it is on the *users*
table, which this task did not open.

**Task 2 — measured, not computed.** Suite left at **1320** PHPUnit tests (1304 before the task; +12
`InvitationStatusTest`, +3 `ManagerScopeParityTest`, +1 `RotaReadViewTest`), 6027 assertions, 0
skipped. Vitest 187, `npm run build` green, `npm run test:e2e` 22 passed. The People screen costs
**7 queries at any roster size**.

**Task 3 (2026-08-10) — Decision C's claim that "the supersede loop only touches invitations that
are still open" is FALSE against the tree, and the test written to pin it went red on its first
run.** `InvitationController::store():84-88` filtered on `accepted_at` and `revoked_at` and **not on
the clock**, so re-inviting somebody also stamped `revoked_at` and `revoked_by_user_id` onto an
invitation that had merely aged out — rewriting *"this expired"* as *"a person killed this"* in the
very projection Task 2 had just shipped, and disagreeing with `revoke()`, which has always treated a
spent or expired row as a no-op. The predicate now carries `where('expires_at','>',now())`, which is
exactly `Invitation::isOpen()`. This was caught only because
`test_an_expired_unclaimed_invitation_does_not_block_re_inviting_the_same_person` asserts the
expired row is **untouched**, not merely that the new invite succeeds.

**Task 3 — the superseded set is matched by person OR address, not address alone.** Decision G says
`invitations.member_email` is frozen at send time while `people.email` can be corrected afterwards;
those two facts together mean an address-only supersede leaves a live link addressed to the OLD
mailbox every time somebody's address is fixed and their invitation resent — which is one of the
cases a resend is most often reaching for. `InvitationIssue::liveFor()` matches
`person_id = X OR member_email = Y`. Strictly wider than what it replaced, and the invariant it
enforces is per-PERSON, which is what a bearer credential to an account actually is.

**Task 3 — `InvitationIssue::issue()`'s signature differs from Decision C's in three ways, each
forced.** (a) It takes the `Request`, not a `User $actor`: `ManagerScope::assertMayTarget()` needs
one, and the actor comes off it — two parameters collapsed into the one both uses need. (b) It has
no `$reason` parameter. Decision C both gives it one *and* says the writer does not audit; a
parameter declared and never read is precisely finding 4's defect (`openInvitations()`'s unused
`?User $viewer`), so the reason stays with the caller that writes the audit. (c) `superseded` is
returned as `list<int>`, not `?int` — the pass genuinely can revoke more than one row, and a
singular return would silently drop the rest of them out of the trail.

**Task 3 — Decision C's "the writer resolves-or-creates the person" contradicts its own signature,
and the signature won.** A method taking `Person $person` cannot resolve one. Resolve-or-create (and
the `trashed()` restore with it) stays in `store()`, which is the only path that can legitimately
create a roster row from an address nobody knows. `resend()` resolves the person from the
invitation (`person` relation, falling back to `Person::matchByEmail()` for a pre-P0c row) and
REFUSES if it cannot, rather than inventing one — including for a soft-deleted person, so a resend
never silently restores a roster row.

**Task 3 — the Definition of Done's "`ContactFieldsAreProjectedOnceTest` green with no allow-list
change" cannot hold, and the guard is what proved it.** The clause's reasoning is about claim status
being `$extra`, which is Task 2's concern and still true. But the one writer of `invitations` must
read `people.email` — it is the address the credential is frozen onto and half the predicate
deciding which live links to kill — so `app/Support/Invitations/InvitationIssue.php` is allow-listed
with that reason written out. The alternative was passing the address in from the caller, which
makes "an invitation is addressed to the roster row's current address" a thing each caller can get
wrong. `RosterNeverMintsCredentialsTest`, `PersonActiveHasOneWriterTest` and
`InstitutionProvenanceTest` are all green **untouched**, as specified.

**Task 3 — Invite and Resend PARTITION the claim states; `expired` moved.** Task 2 shipped
`invitableStates = ['none','expired','revoked']`. Offering Resend for `open`/`expired` on top of
that would have given an expired person two buttons doing the same thing, so `expired` moved to the
resend list: `none`/`revoked` invite (nothing live to replace, and reviving a deliberately-revoked
link from its own row would undo an administrator's act through a shorter path), `open`/`expired`
resend. One affordance per person, and the write side refuses exactly what the offer withholds (D9).

**Task 3 — the single path's mail-failure log no longer carries the exception message.** It was
`Log::warning('...: '.$e->getMessage())`. SMTP transport errors routinely quote the envelope
recipient back (*"550 5.1.1 &lt;someone@hospital.example&gt;"*), and staff personal data is covered by
the same rule as PHI — so the line now carries the person id, the invitation id and the exception
class, and nothing else. Decision D already requires this of the bulk path; it was true of the
single path too and had simply not been looked at.

**Task 3 — measured, not computed.** Suite left at **1338** PHPUnit tests (1320 before the task;
+14 `InvitationResendTest`, +2 `InvitationWritersAreSingularTest`, +2 `InvitationTest`), 6108
assertions, 0 skipped. Vitest 187, `npm run build` green, `npm run test:e2e` 22 passed. Both new
guards were watched failing against planted offences before being trusted —
`InvitationWritersAreSingularTest` against an `Invitation::create(` / `DB::table('invitations')` /
`'revoked_at' =>` trio and again against a bare `Invitation::issue(` in `PersonController`, and the
router-gate test against an extra ungated route in the group. The Decision G position pin was
mutation-tested too (writing `$person->position` on the claim path's existing-person branch turns it
red), because a pin that passes on its first run has proved nothing about what it can detect.

**Task 4 (2026-08-10) — Decision D property 4 contradicts itself, and the tests decide which half
survives.** It says a person outside the actionable set is *"reported as `skipped_has_account` /
`skipped_no_email` / `skipped_out_of_scope` from the writer's own return value"* **and** that
*"the FormRequest validates against exactly that set"*. Those cannot both hold: a `Rule::exists`
predicate that excludes a claimed person 422s the whole submission, and then nothing is reported as
skipped. The plan's own cases 6 and 7 demand the skip (*"the rest proceed"*), and they are right —
a selection made with "select all filtered" routinely contains three of fifty who have already
claimed, and refusing the batch for them would make the feature unusable. So `person_ids.*` carries
a **bare** `Rule::exists('people', 'id')` (`PersonBulkRequest`'s shape, for the same reason it has
it), and `App\Support\Invitations\BulkResend` decides every outcome. `skipped_out_of_scope` does
not exist: authorization is the one thing that is **not** a skip (case 5 requires a 403 and a
`user_scope_denied` row), and expressing `ManagerScope` twice — once as capability logic, once as
SQL inside a `Rule::exists` closure — would be the two-predicates-that-drift shape D9 exists to
refuse.

**Task 4 — the predicate is a PHP classifier, not a query closure, and there is only one consumer
either way.** The plan's implementation step 1 gives `InvitationIssue::resendable(?User): \Closure`
applied to both the offer and the FormRequest. With the FormRequest holding a bare `exists` (above),
the SQL consumer disappears, and a per-person outcome needs the individual facts anyway — "which of
inactive / has-account / no-address / never-invited / revoked" — which a membership test cannot
give. `BulkResend::outcomeFor()` is therefore the one definition, consumed by the preview and by the
commit's own re-derivation. D9 still holds, and more directly than a closure written once as
Eloquent and once as raw SQL would have.

**Task 4 — bulk resend is a RESEND, and Decision D property 4's predicate would have made it a
wider invite door.** As written it is *"active, not trashed, has an email, has no account, and whom
the viewer may target"* — it says nothing about the invitation, so a selected person with **no
invitation row** would be minted one, and a **revoked** one would be revived. Both are refusals on
the single path, deliberately (Task 3's amendment: `none`/`revoked` are an INVITE, from a different
endpoint). Worse, a person with no invitation has no superseded row to take a position from, and the
only other source is `people.position` — where 0 and 5 are absent from
`InvitationController::OFFERABLE` precisely so an invitation cannot be a route to privilege. The
bulk path therefore acts on `open`/`expired` only, exactly what `resend()` accepts and exactly what
the People screen offers, and reports `skipped_no_invitation` / `skipped_revoked` for the rest.

**Task 4 — the digest is a `StatePin`, not the "digest of the resolved id set" Decision D asks for,
and an id-set pin was watched failing before the state pin was trusted.** An id-set digest answers
"is this the same selection", which is necessary and not sufficient — it is the exact insufficiency
`StatePin`'s own docblock records against the rota importer. A person who claims their account
between the preview and the confirm passes an id-set check, and the operator who approved "47
emails" sends against a roster that moved. `BulkResend::digest()` reuses `StatePin` unchanged: the
scope is the operation, the identity slot is empty (a resend has no input beyond the people), and
each "cell" is a PERSON — the second id slot being the one `StatePin` already documents as *"null
where the concept does not apply"*, the current projection being the account/invitation facts every
outcome is derived from, and the proposal being the position the fresh invitation would carry.
`outcome` and `reason` stay out of it for `RotaFill::digest()`'s stated reason: they are a pure
function of that state, so a change invisible to the hash writes nothing different.

**Task 4 — `ContactFieldsAreProjectedOnceTest` went red on the first run, and the fix was to read
LESS rather than to allow-list.** `BulkResend` needed a person's address twice: to decide
`skipped_no_email`, and to address the mail. The second became
`$result['invitation']->member_email` — the address the credential is actually bound to, frozen at
mint by the one writer — which is more correct than re-reading the person and cannot disagree with
what the link says. The first became `Person::hasEmail()`, a predicate on the model that answers
yes/no and never hands the value out. **No allow-list entry was added**, and the guard is what
forced the better shape rather than merely recording a worse one.

**Task 4 — the audit shape is the plan's, not the task brief's, and the arithmetic is why.** One
`invitation_bulk_resend` summary **plus** one `invitation_resent` per person acted on, carrying
`person`/`invitation`/`superseded`/`mailed`. The alternative reading — a single summary row with
counts only — loses which people were resent from the one tamper-evident record there is
(`invitations` is not hash-chained), for a saving that is smaller than it looks: Decision D's own
point is that this replaces the single path's **pair** per person, halving the appends rather than
adding them, and `PersonController::bulk()` already writes `person_bulk` + one `person_bulk_item`
per person at up to **500**. Fifty is well inside what this codebase has already accepted.

**Task 4 — the plan's rehearsal recipe contradicts Decision D property 1.** It says to set
`MAIL_MAILER=log` and read five links out of `laravel.log`; the endpoint refuses outright unless
`config('mail.default') === 'smtp'`, so that recipe exercises the refusal and nothing else. The
rehearsal was run against a throwaway SQLite database and `php artisan serve` with `MAIL_MAILER=smtp`
pointed at a disposable local SMTP sink instead — which is strictly better evidence, because
`Mail::fake()` proves the call and a real transport proves the mailable **renders**. Result: five
selected, preview `will_send=5`, confirm `sent=5 failed=0`, **five messages delivered to five
distinct recipients carrying five distinct 64-hex tokens**, one summary audit row plus five
per-person rows with no `@` anywhere in the trail, and replaying the same digest a second time
refused with *"Something changed since you previewed this resend"* and **no sixth email**. The
throwaway database, the sink and the driver were deleted; nothing entered the repository.

**Task 4 — the suite crossed PHP's stock 128M CLI ceiling, and `phpunit.xml` now sets it.** At 1,360
tests `php artisan test` died with `Allowed memory size exhausted` inside `vendor/`, on a different
test each run. Measured, not assumed: green at 1,338 under 128M, fatal at 1,360, green at both under
512M. It is environmental rather than a defect — but `php artisan test` runs PHPUnit in a
**subprocess**, so `php -d memory_limit=… artisan test` does not reach it, which is its own dead end
worth an hour. Set in `phpunit.xml`'s `<php><ini>` so CI, the container and every developer share one
ceiling.

**Task 4 — a Task 2 case was a faker-name lottery, and adding tests is what drew the losing
ticket.** `InvitationStatusTest::test_the_people_screen_carries_the_claim_state_without_paying_per
_person` asserted on `$captured[0]`, the first row of a list ordered by `full_name` — which is just
as likely to be the ADMIN's own person, who has no invitation and correctly reads `none`. It passed
on three consecutive full runs and then went red once twenty-two new tests moved the sequence of
names faker draws before it. Fixed to look up the row for a person the case itself invited, and
proved both ways: with the admin's person renamed to sort first, the old assertion goes red and the
new one stays green. A case that can fail for a reason it does not name is worse than no case, and
this one would have cost the next person an hour of hunting a defect that is not there.

**Task 4 — measured, not computed.** Suite left at **1360** PHPUnit tests (1338 before the task;
+22 `InvitationBulkResendTest`), 6244 assertions, 0 skipped. Vitest 187, `npm run build` green,
`npm run test:e2e` 22 passed. Four guards were watched failing against planted offences before being
trusted: mail moved inside `BulkResend::commit()`'s transaction (the ordering case went red on
`Mail::assertNothingSent()`), the digest reduced to an id-set pin (the state case went red), the
`invitation_bulk_preview` share key removed from `HandleInertiaRequests` (the flash-props case went
red), and an `Invitation::create(`/`'revoked_at' =>` pair planted in `BulkResend`
(`InvitationWritersAreSingularTest` named the file and both needles). `RosterNeverMintsCredentials
Test`, `PersonActiveHasOneWriterTest`, `InstitutionProvenanceTest`, `CompiledCssIsLightOnlyTest` and
`CalendarIsTheOnlyConverterTest` are green untouched.

**Task 5 (2026-08-11) — the snapshot was watched preventing the blanking, not assumed to.** The
whole unbind was implemented FIRST with the snapshot deliberately omitted, and
`test_an_unsnapshotted_signoff_keeps_its_signer_name` went red on exactly the failure finding 6
predicts: the rendered `signoff.signed_off_by_name` on a signed sheet moved from `'Dr Alpha'` to
**`null`** the moment the link was cleared, with every other case in the file green. The audit case
went red alongside it (`signoffs_snapshotted=0`), which is the count agreeing with the defect rather
than papering over it. The fixture is CONSTRUCTED, per the plan: a day is signed through the real
endpoint and then aged back to the pre-freeze shape with a query-builder write, because the freeze
migration backfilled nothing and a test that merely signed a day would be asserting against a row
that already carries its snapshot.

**Task 5 — `apply()` audits, because the plan's stated reason for its return value contradicts its
own signature.** The implementation note says the count is returned *"so the caller's audit detail
comes from the writer's own answer"* — but the signature it gives in the same sentence is
`apply(User $user, User $actor, ?string $ip)`, and `$actor`/`$ip` exist for nothing except an audit
row. A caller-written audit leaves both parameters declared and never read, which is finding 4's
defect exactly (`openInvitations()`'s unused `?User $viewer`). Resolved the way Task 3's amendment
resolved the same species: the writer audits — `PositionChange::apply()`'s shape (write, guard,
flush, audit), which is what a once-per-request act should be, as against `PersonStatus::apply()`'s
deliberate silence for a bulk loop — and the count is still returned, for the tests and for any
future caller that must report what happened.

**Task 5 — the route sits in the `cap:users.manage` group and carries NO second in-controller
gate.** The task's Files-touched line says `cap:users.manage`; Decision E says the action sits
"behind `ManagerScope::assertMayTarget()`". Both can be done, and doing both is wrong: every request
reaching that group already holds `users.manage`, for which `assertMayTarget()` can only ever return
true, so the second gate is unreachable by construction — the same declared-and-never-used shape as
above. The group wins because it is **strictly narrower** (Administrator only, versus Administrator
*or* a Chief Resident acting on a Resident) and unbinding is strictly larger than the
deactivate it sits beside: irreversible by design, no undo in the UI, and the one action in this plan
that writes a clinical table. `PATCH`, not `DELETE` — nothing is deleted, and a `DELETE` here would
read as the return of the capability withdrawn on 2026-07-19.

**Task 5 — all three accepted-blanking claims hold against the tree, and one line reference is
stale.** Checked rather than trusted, because one of them is a clinical record. (a) Finding 8:
`reopened_by_user_id` appears in `app/` and `resources/js/` at exactly two sites —
`EndorsementController.php:583` (the write) and `HandoverSignoff.php:126` (`$fillable`). No relation,
no `reopened_by_name`, nothing resolves that id to a name, so unbinding cannot blank it. **True.**
(b) Finding 7's `PersonPresenter::history()` `'by' => $span->createdBy?->full_name` is live at
`PersonPresenter.php:142` and does blank. **True, accepted.** (c) Finding 7's `invited_by` is live
and does blank, but **not at `InvitationController.php:187`** — Task 2 replaced `openInvitations()`
with `statusList()`, so it is now `:452`, rendered at `Users.vue:227`. The fact is unchanged; the
citation is not. A fourth question the plan did not ask was checked too: the other four named roles
on `handover_signoffs` are **not** a second door. Their `*_name` columns were frozen at write time
from the start, `signoffPayload()` reads them with **no relation fallback at all**, and their FKs are
`*_person_id` (people, which an unbind never touches). `signed_off_by_name` is the only blanking site
on that table, which is why it is the only one snapshotted.

**Task 5 — the snapshot reads `withTrashed()`.** Nothing in `app/` soft-deletes a `handover_signoffs`
row today (`UnitMerge` reads them `withTrashed()` but writes no `deleted_at`), so this matches the
same set either way. It is written that way because a snapshot that quietly skipped a row on account
of a global scope would be a snapshot that missed evidence, and a scope is not the thing that should
be deciding which attestations are preserved.

**Task 5 — one case beyond the plan's thirteen.**
`test_the_snapshot_does_not_reach_another_accounts_signoff` re-points a signoff at a second account
and asserts the unbind leaves it null. Without it, an implementation that dropped the
`where('signed_off_by_user_id', …)` clause and stamped the unbound person's name onto **every**
un-snapshotted row in the table would pass all thirteen — the failure mode of a snapshot is not only
"too few rows".

**Task 5 — measured, not computed.** Suite left at **1381** PHPUnit tests (1360 before the task; +19
`AccountUnbindTest`, +2 `AccountLinkHasOneWriterTest`), 6346 assertions, 0 skipped. Vitest 187,
`npm run build` green, `npm run test:e2e` 22 passed. `AccountLinkHasOneWriterTest` was watched failing
against a second link-writer planted in `PersonController` — it named the file and all four write
shapes (`'person_id' => null`, `->person_id = `, `->update(['person_id'`, `DB::table('users')`) — and
then watched staying GREEN against a planted `$user->person_id === null` **read**, which is what
proves the trailing space in that needle is load-bearing rather than decorative; the plant was
reverted and `git status` left clean. `PersonActiveHasOneWriterTest` is green **with no allow-list
change**, which is the disjointness proof: `AccountUnbind` writes `users.active` and the link and
never touches `people`. `RosterNeverMintsCredentialsTest`, `InstitutionProvenanceTest`,
`CompiledCssIsLightOnlyTest` and `CalendarIsTheOnlyConverterTest` are green untouched.

**Task 6 (2026-08-11) — `applyForUser()` takes the override MAP, not Decision F's
`array $grants, array $denies`.** The signature in Decision F is
`applyForUser(User $user, array $grants, array $denies, User $actor, ?string $ip)`. Every other
part of this surface already speaks one shape — `capability id => 'grant'|'deny'` — and it is what
the request carries, what the editors bind to and what the existing
`pluck('effect', 'capability_id')` diff reads. Splitting it into two lists would have added a lossy
conversion at both callers and, worse, would have made "the same capability id in BOTH lists"
representable, which the map cannot express at all and which no rule in the plan resolves. Same
species as Task 3's and Task 5's amendments: the plan's signature contradicted the tree it had to
plug into, and the tree won. `$user` (not `int $userId`) is the plan's, and is kept.

**Task 6 — the READ moved into the writer too, and the guard is what forced it.**
`AccessControlController::selectedUser()` read `UserCapability::where(...)` directly. A guard whose
needles are narrow enough to miss a `::where(...)->delete()` is not a guard, and a delete is
exactly the write shape a fluent chain hides across line breaks — so the needle set includes
`UserCapability::where(`/`::query(`, and the projection had to move with it
(`CapabilityGrant::overridesFor()`, plus `overridesByPerson()` for the roster screen). That is
strictly better anyway: both screens now render the same projection, and the allow-list is two
files rather than four.

**Task 6 — the plan's allow-list is wrong in both directions.** Decision F says to allow-list
`CapabilityGrant.php`, "the seeder and the migration". Neither is needed:
`AccessControlSeeder` mentions `user_capabilities` only in prose (it writes `role_capabilities`
only), and `2026_07_24_120003` carries `Schema::create('user_capabilities'` and
`$table->string('effect')`, neither of which matches a needle. What DOES need an entry, and the
plan does not mention, is **`app/Support/AccessControl.php`**: the resolver and `holdersOf()` read
the table through `DB::table('user_capabilities')`, and a coarse raw-builder needle cannot tell a
read from a write. Entered with that reason written out.

**Task 6 — `updateUser()` resolves with `withTrashed()`, and that is preservation rather than a
change.** The extracted writer takes a `User`, so the controller must load one where it previously
used the validated int. `exists:users,id` runs on the raw query builder and never sees the
SoftDeletes scope, so a trashed id has always passed validation on this endpoint; a plain
`findOrFail()` would have turned that blind spot into a 404 that never used to happen. Nothing in
this codebase sets `users.deleted_at` (deletion was withdrawn 2026-07-19), so the two match the
same set — the point is not to invent a refusal while claiming a refactor.

**Task 6 — the escalation surface was measured on both doors, and the finding is pre-existing.**
`assertNoSelfLockout()` guards the ROLE matrix only; it does not run on the per-user override path
at all. Measured against the tree: an administrator can deny `access.manage` **to themselves**
through `PUT /admin/access-control/user`, after which `AccessControl::holdersOf('access.manage')`
answers **nobody** and the console is unreachable without database access. Also measured: a holder
of `access.manage` can grant themselves any capability in the catalog — a Resident holding
`access.manage` as a per-user grant went from not holding `endorsement.reopen` to holding it in one
request. Both are inherent to what `access.manage` means, both predate this task, and Task 6
changes neither: the extraction is behaviour-preserving and adding a refusal would be new behaviour
on a path whose tests must pass untouched. What Task 6 does add is
`test_the_two_doors_agree_about_denying_the_last_access_manage_holder`, which asserts the two
surfaces AGREE rather than that either permits — so when a lockout guard is eventually added (to
`CapabilityGrant`, the only place it can now be added), that case stays green and becomes the proof
the guard reached both doors. It was watched failing against a guard planted in `updatePerson()`
alone.

**Task 6 — measured, not computed.** Suite left at **1396** PHPUnit tests (1381 before the task;
+13 `PersonRolesTest`, +2 `CapabilityWritersAreSingularTest`), 6419 assertions, 0 skipped. Vitest
**192** (187 before; +5 `PeopleRolesPanel`), `npm run build` green, `npm run test:e2e` 22 passed.
`AccessControlPageTest` is green **with no edit of any kind**, which is the behaviour-preservation
evidence the task asked for. `CapabilityWritersAreSingularTest` was watched failing twice against
planted offences before being trusted — a `UserCapability::create(` + `DB::table('user_capabilities')`
+ `'effect' =>` trio, and separately the RELATION-shaped writer
(`$user->userCapabilities()->updateOrCreate(...)`) that no model-name needle would see — and both
plants were reverted with `git status` left clean. The panel's two client-side gates were
mutation-tested as well (dropping the prop check to `can('people.manage')` turns two cases red;
removing the no-account branch turns a third red).
`RosterNeverMintsCredentialsTest`, `ContactFieldsAreProjectedOnceTest`, `InstitutionProvenanceTest`,
`CompiledCssIsLightOnlyTest` and `CalendarIsTheOnlyConverterTest` are green **untouched**, and no
allow-list anywhere gained an entry for this task.

**Task 6 — a stale line reference.** Decision F and the task both cite `updateUser()` at
`AccessControlController.php:246-319`; it was at `:236-322` before this task opened the file.
`AccessControl.php:168-178` (the deny-wins two-pass) and `:186-191` (the cache key) are correct.

**Task 7 (2026-08-11) — the tidies were TWO sites and one moved line, not the three and the `:367`
this task's own step 1 lists.** Both were checked against the tree before anything was edited, as
step 1 itself instructs. `bg-panel-soft`: finding 15 named three, Task 2 closed `Users.vue:179`
early on markup it was rewriting anyway (recorded in its own amendment), so **two** were live —
`StaffPrivacyNotice.vue:25` and `AcceptInvitation.vue:43`, both at exactly the cited lines. Both are
now `bg-ground-deep`, and `resources/css/app.css` declares `--color-ground`, `--color-ground-deep`
and `--color-panel` and **no `--color-panel-soft`**, which is what makes this a defect rather than a
preference: the class compiled to nothing. The `colspan`: finding 16 puts it at `Users.vue:367` and
Task 5's report repeats that; it is at **`:444`**, moved by Task 5's own Unbind button and the
markup around it. The defect is real and unchanged — the users table has eight `<th>` (`:349-363`)
and the empty-state cell spanned seven. A third line reference in the same family was already
recorded stale by Task 5 (`invited_by` at `InvitationController.php:187`, now `:452`). **Four stale
line references across seven tasks: cite by symbol, verify by reading.**

**Task 7 — the design doc section this task was pointed at is not the one that needed correcting.**
The brief named §11; §11 is *Migration and fixtures* and P1c-2 invalidates nothing in it (it adds no
migration and no fixture). The sections that actually carried false or now-incomplete claims were
**§1.2** (the overrides table, which had no AC-02 row — the whole point of the correction), **§5.1**
(no claim-status projection, no unbind, no snapshot), **§9** (a new §9.4 for AC-04's second surface
and why it is not on `people.manage`), **§12** (whose whole account of the source-level guard family
was one bullet naming two of them — `tests/Feature/Build/` now holds seventeen files), **§13** (the
sequencing table still read "P1c-2 … planned once P1c-1 merged"), and **§14** (items 7, 13 and 17,
plus two new items, 20 and 21). Recorded because "the doc is wrong at
§N" is exactly the kind of claim this plan family keeps finding to be *nearly* right.

**Task 7 — three things the brief said to fix were ALREADY CORRECT on disk, and one claim in it is
wrong against the tree.** Checked before editing, per P1d-1 Task 12's and P1d-2 Task 13's recorded
experience of being told to fix wording that only existed stale in a cached context. Already right,
and left alone: `docs/spec/15-rulings.md`'s existing 29 rulings (nothing P1c-2 did contradicts one —
three new rows were **appended**, none amended); `docs/RUNBOOK-DEPLOY.md`'s P1d-2 operating section;
and `phpunit.xml`'s memory-limit block, which Task 4 had already written with its full empirical
reasoning in place — CLAUDE.md gained the note, the file needed nothing. Wrong against the tree: the
brief says *"four new single-writer guards exist now"* and then names **three**.
`tests/Feature/Build/` gained exactly three (`InvitationWritersAreSingularTest`,
`AccountLinkHasOneWriterTest`, `CapabilityWritersAreSingularTest`); the fourth new test file of the
guard *species* is `ManagerScopeParityTest`, which is a **parity matrix**, not a single-writer scan,
and it lives in `tests/Feature/Admin/`.

**Task 7 — the pre-existing lockout gap is recorded in FOUR places, deliberately, because each has a
different reader.** `docs/superpowers/specs/…-design.md` §14 item 20 is the technical record with the
measurement and what pins a future fix to both doors; `docs/spec/08-foundation.md` states it where
the access-control model is described, so nobody reads "self-lockout guard" there and assumes it
covers everything; `CLAUDE.md` carries it as a standing rule so an implementer meets it before
touching `CapabilityGrant`; and `docs/OWNER-CHECKLIST.md` item 13 carries the only mitigation that
exists today, which is procedural — **keep two active accounts holding `access.manage`**. A gap whose
only mitigation is a human habit belongs in the document the human reads.

**Task 7 — measured, not computed.** Suite unchanged at **1396** PHPUnit tests, 6419 assertions, 0
skipped; Vitest **192**; `npm run test:e2e` **22 passed**; `npm run build` green. No test moved,
which is the expected shape for a task that changes documents plus one `colspan` and two utility
classes — none of the three has an assertion pointed at it. `grep -rn "bg-panel-soft" resources/`
returns nothing.

*(Follow the P0c/P0d/P1a/P1b/P1c/P1d convention: when a task turns up something
this plan's enumeration missed — a site not listed, a test that goes red for a reason the plan did
not predict, a behaviour that differs between SQLite and MySQL — record it here, dated, with what was
found and how it was resolved. Findings caught empirically rather than by inspection are the ones
worth writing down. P1a recorded nine amendments across nine tasks, P1b eight across thirteen and
P1c-1 thirteen across thirteen plus a nine-finding post-merge review; assume this plan is wrong
somewhere too. The single most common species so far, five times over, is **the plan's own expected
test count being stale before the task began** — measure, do not compute.)*

---

## Standing rules for every task

Verified against the tree; these are not preferences.

- **TDD, strictly.** Write the test, run it, **watch it fail for the reason you expect** (not a typo,
  not a missing class), then implement. A test that passes on first run has proved nothing.
- **Build before test, every time.** `npm run build && php artisan test`, or
  `CompiledCssIsLightOnlyTest`'s artifact layer skips rather than passes.
- **Verify with Bash, never PowerShell.** PowerShell's PATH on this machine lacks `openssl`, so the
  backup tests self-skip there — a false green indistinguishable from a real one. Every command block
  opens with:
  ```bash
  export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
  ```
- **Filter output.** `| tail -5` for a full run; `php artisan test --filter <TestName> | head -30` on
  a failure. Never dump a failing suite into context.
- **Assert over the whole set, never inside a `foreach`.** Every source-scanning guard collects
  `$offenders[]` and ends with `assertSame([], $offenders, …)`, and ships with a companion
  `test_every_allow_listed_file_still_exists()`.
- **Prove a new guard can fail.** Before trusting any of the three source-level guards this plan adds,
  introduce the offence it is meant to catch in a throwaway edit, watch it go red, then revert. P1c-1's
  post-merge minor 7 found a guard whose needle (`->users()->create(`) could never match anything,
  because `Person::user()` is singular — it had been green from the day it was written.
- **Beware the self-referential docblock.** Writing a forbidden identifier or string as literal prose
  inside an explanatory comment trips the very guard the prose explains. This has happened **four**
  times in this programme (P1a's periods migration, P1c-1 Tasks 4 and 10 twice). Describe the absent
  thing without reproducing its literal name; do not allow-list a file that otherwise needs no entry.
- **Every route behind `auth` + a gate.** Writes are POST/PATCH/DELETE + CSRF. Invitation endpoints
  are gated in-controller by `ManagerScope` (invariant 8) — every new one carries the same gate, and
  Task 3 asserts it over the router.
- **Eloquent/bindings only.** Never concatenate SQL.
- **Audit by ids, field names and counts only.** Never a name, never an email address, never a phone
  number, never a filename. Audit **after** the transaction commits, matching
  `AccessControlController::applyRoleSet()` and `PersonController::bulk()`.
- **Any narrowed query that will later resolve `$user->full_name` / `->position` / `->member_email`
  carries `person_id`** — a `select()`/`pluck()`/`value()`/`with('rel:id,col')` that omits it makes
  the accessor return null silently. This broke four live sites in P0c with zero test coverage.
- **Light theme only, semantic classes only.** No `dark:`, no raw Tailwind palette class, no hex in
  markup, and **no `bg-panel-soft`** — it compiles to nothing (finding 15).
- **The client performs no date arithmetic.** Ten needles, no allow-list, and the scan matches
  docblock prose too.
- **`institution_id` is provenance.** Never a `where`, never inside an `index([...])`/`unique([...])`
  array. `InstitutionProvenanceTest` stays green untouched.
- **No migration.** Decision H.
- Commit at the end of each task with the message given, only after `npm run build` and
  `php artisan test` are both green.

---

# P1c-2 — tasks

---

### Task 1: AC-02's configurable lifetime, and one definition of an email address

**Files touched**

- `app/Support/AppSettings.php` (one `KEYS` entry)
- `app/Models/Invitation.php` (`LIFETIME_MIN`/`LIFETIME_MAX`, `lifetimeDays()`, `issue()` reads it,
  and `Person::normalizeEmail()` replaces the inline normalisation)
- `app/Http/Controllers/Admin/SettingsController.php` (one validation rule, built from the model's
  own constants)
- `resources/js/Pages/Admin/Settings.vue` (one field)
- `tests/Feature/Admin/SettingsTest.php` (extend)
- `tests/Feature/Auth/InvitationTest.php` (extend)

**The resolved behaviour this task implements.** Owner decision 4: the invitation lifetime is
configurable, **default 7 days**, behind **`settings.manage`**. Seven is a deliberate override of
Munawib AC-02's fourteen — an invitation is a bearer credential that reaches children's clinical
records once redeemed, so a forwarded link stays live for half as long. The knob sits on the settings
screen beside SMTP, VAPID and the operational-alert address because it is a credential-exposure
parameter, and an administrator should be able to review every such parameter in one pass.

**The failing test to write first**

In `SettingsTest`:

1. `test_the_invitation_lifetime_is_saved_and_read_back` — PUT `/admin/settings` with
   `invitation_lifetime_days = 14`, assert the row and that `Invitation::lifetimeDays()` returns 14.
2. `test_the_lifetime_is_validated_against_its_bounds` — `0`, `-1`, `31` and `"soon"` each produce a
   session error on that field and change nothing. Assert via `assertSessionHasErrors()` +
   `assertDatabaseMissing()`, the codebase's convention for every admin PATCH/PUT endpoint (P1c-1's
   Task 2 amendment records this).
3. `test_the_lifetime_write_is_audited_by_key_never_by_value` — the `settings_update` detail contains
   `invitation_lifetime_days` and **not** the number. (`SettingsController::update():86-91` already
   audits `'keys='.implode(',', $changed)`; this pins that the new key rides the same channel.)
4. `test_the_setting_is_behind_settings_manage` — a user without it gets 403 on the PUT.

In `InvitationTest`:

5. `test_an_invitation_expires_after_the_configured_number_of_days` — set 14, issue, assert
   `expires_at` is 14 days out.
6. `test_an_unset_or_absurd_setting_falls_back_to_seven` — with no row, and again with the row
   force-written to `'999'` directly through `AppSetting` (simulating a database-console edit that
   never passed the FormRequest), `lifetimeDays()` returns 7. **This is the case the clamp exists
   for** — the FormRequest cannot see that write.
7. `test_the_default_is_seven_and_the_constant_is_the_default_not_the_value` — assert
   `Invitation::LIFETIME_DAYS === 7` and that `lifetimeDays()` returns it when nothing is configured.

Run all seven. 1, 2, 5 and 6 must go red on a missing method or a rejected key.

**The implementation**

1. `AppSettings::KEYS` gains `'invitation_lifetime_days' => false` (non-secret). **No entry in
   `applyOverrides()`'s `$map`** — it overrides no framework config, exactly like `alert_email`
   (finding 2). Add a one-line comment saying so, or the next reader will "fix" the omission.
2. `Invitation` gains `LIFETIME_MIN = 1` and `LIFETIME_MAX = 30` beside `LIFETIME_DAYS = 7`, and
   `lifetimeDays(): int` exactly as in Decision A, with that docblock. `issue()`'s line 73 becomes
   `now()->addDays(self::lifetimeDays())`.
3. **`issue()`'s inline `Str::lower(trim($email))` becomes `Person::normalizeEmail($email) ?? ''`**
   (finding 19; design §14 item 17). The `?? ''` matters: `member_email` is `NOT NULL`, and
   `normalizeEmail()` can return null for a blank input — which `issue()`'s own callers already
   prevent, but the column contract must not depend on that. The second inline copy, in
   `InvitationController::store():57`, is Task 3's (that file is opened there anyway).
4. `SettingsController::update()` gains
   `'invitation_lifetime_days' => ['sometimes','nullable','integer','min:'.Invitation::LIFETIME_MIN,'max:'.Invitation::LIFETIME_MAX]`.
   Built from the constants, not from repeated literals — one definition, two consumers, the
   `PeriodGenerator::assertMonthAligned()` shape.
5. `Settings.vue` gains one number field in the existing form, with helper text stating the default
   and the bound: *"How long an invitation link stays usable. Default 7 days; 30 at most. Shorter is
   safer — the link is a credential."* Semantic classes, `Levels.vue`'s `inputClass` verbatim.

**How to verify**

```bash
export PATH="/c/Users/ahmed/AppData/Local/php84:/c/Users/ahmed/AppData/Local/composer-bin:$PATH"
npm run build && php artisan test --filter 'SettingsTest|InvitationTest' | tail -5
php artisan test | tail -5
```

`CalendarIsTheOnlyConverterTest` must stay green: `now()->addDays()` on a timestamp is instant
arithmetic on an already-typed Carbon value, not a date conversion, and it is already there today —
this task changes the argument, not the call shape. If it goes red, you reproduced a forbidden
identifier in a docblock (standing rules).

```bash
git commit -am "feat: how long a link to a child's records stays live is now a decision"
```

---

### Task 2: AC-02's claim status — one projection, scoped by the rule it belongs to, and never a base key

**Files touched**

- `app/Support/ManagerScope.php` (add the non-throwing `mayTarget()`)
- `app/Support/Invitations/InvitationStatus.php` (new)
- `app/Http/Controllers/Admin/InvitationController.php` (`openInvitations()` widened and its dead
  `?User $viewer` used)
- `app/Http/Controllers/Admin/UserManagementController.php` (drop the caller-side scoping the
  projection now owns)
- `app/Http/Controllers/Admin/PersonController.php` (`rosterProps()` supplies the `$extra`)
- `resources/js/Pages/Admin/People.vue` (the Account column becomes a claim-state column, plus an
  Invite affordance)
- `resources/js/Pages/Admin/Users.vue` (the invitations table gains a Status column)
- `tests/Feature/Admin/InvitationStatusTest.php` (new)
- `tests/Feature/Admin/ManagerScopeParityTest.php` (new)
- `tests/Feature/Rota/RotaReadViewTest.php` (extend — the leak assertion)

**The failing test to write first**

`ManagerScopeParityTest`:

1. **`test_may_target_agrees_with_assert_may_target_for_every_capability_set_and_position`** — a
   matrix over {no capability, `users.manage_residents`, `users.manage`, both} × positions
   {0,2,3,4,5}. For each cell, call `mayTarget()` and separately call `assertMayTarget()` inside a
   `try`/`catch (HttpException)`, and assert they agree. **A matrix, not a list of examples** — this
   is `PickerParityTest`'s discipline (D9) applied to an authorization read, and the whole reason the
   predicate is being written twice at all.

`InvitationStatusTest`:

2. `test_a_person_with_no_invitation_is_none`.
3. `test_an_open_invitation_reports_its_expiry`.
4. `test_an_expired_invitation_is_expired_not_open` — issue, then `forceFill(['expires_at' => now()->subDay()])`.
5. `test_a_claimed_invitation_is_claimed_with_its_accepted_at` — through the real claim flow, not a
   hand-written `accepted_at`, so the projection is proved against what the redemption path actually
   writes.
6. `test_a_revoked_invitation_is_revoked`.
7. **`test_the_latest_invitation_wins`** — three invitations for one person (revoked, expired,
   open); the projection reports `open`. Precedence is by row id descending, and this is the case a
   resend produces every time.
8. **`test_a_chief_resident_sees_a_state_only_for_residents`** — a viewer holding
   `users.manage_residents` gets a real state for a position-4 person and `'hidden'` for a
   position-3 person, in **one** call. The projection scopes itself; the caller does not.
9. **`test_the_whole_page_costs_one_query`** — `DB::enableQueryLog()` around
   `InvitationStatus::forPeople()` over 30 person ids; assert exactly one query. Finding 4's
   projection replaces an N+1 that does not exist yet, and this is what stops it being written.
10. `test_every_timestamp_is_a_preformatted_string_carrying_a_hijri_label` — assert the shape, so a
    later change cannot start shipping raw ISO strings for the client to parse.

`RotaReadViewTest` (extend):

11. **`test_no_invitation_or_claim_state_reaches_the_rota_read_view_for_any_viewer`** — seed a person
    with an open invitation, then walk the whole props tree of `/rota` (as a resident **and** as an
    administrator holding `people.manage`) asserting no `invitation`, `claim_state` or `invited`
    key exists anywhere. Walk the tree rather than checking one row, exactly as
    `test_no_contact_field_reaches_the_props_for_any_viewer` already does — a future presenter change
    must not be able to leak through a row the test did not look at.

Run all eleven. 2–10 go red on a missing class; 11 must go **green** from the start — write it first
anyway, because it is the assertion that stops Task 2's implementer taking the obvious shortcut of
adding claim state to `PersonPresenter`'s base map. If it goes red at any point during this task, the
shortcut was taken.

**The implementation**

1. `ManagerScope::mayTarget(?User $user, int $targetPosition): bool` —
   `canManageAll($user) || ($user !== null && AccessControl::allows($user, 'users.manage_residents') && $targetPosition === self::RESIDENT)`.
   `assertMayTarget()` keeps its own body: it has **two distinct refusal audits**
   (`access_denied` for the missing capability, `user_scope_denied` for the out-of-scope target) and
   collapsing it onto the boolean would lose that distinction. The parity test is what keeps them
   honest — that is the deal, and it is stated in both docblocks.
2. `App\Support\Invitations\InvitationStatus::forPeople(iterable $personIds, ?User $viewer): array`
   exactly as Decision B specifies: one `whereIn('person_id', …)->orderByDesc('id')` query, folded to
   the first row per person id, five derived states plus `'hidden'`, dates through `Calendar::label()`
   with the time appended. It reads `people.position` for the scoping decision — carry it in the same
   query or take it from the already-loaded person collection; **do not** open a second query per
   person.
3. `InvitationController::openInvitations(?User $viewer)` becomes `statusList(?User $viewer)`:
   drops the three open-only `whereNull`/`where` clauses, projects a `state` per row, and **applies
   the viewer scoping itself** through `mayTarget()`. `UserManagementController::index():113-116`
   drops its `->when(! $all, …)` filter — the projection owns it now, and a second caller
   (`PersonController`) cannot forget it. Keep `->with('invitedBy:id,person_id')`; the `person_id` is
   load-bearing for the `full_name` accessor and its existing comment says so.
4. `PersonController::rosterProps()` calls `InvitationStatus::forPeople()` once for the whole listed
   set and passes each person's entry as `PersonPresenter::one($person, $viewer, ['invitation' => …])`
   — **`$extra`, never a base key** (Decision B, invariant 6). `PersonPresenter` is not modified in
   this task at all, and `ContactFieldsAreProjectedOnceTest` must stay green with no allow-list
   change.
5. `People.vue`'s existing `{{ person.has_account ? 'Account' : 'Roster only' }}` tag
   (`:509`/`:515`, `:586`/`:587`) becomes a claim-state tag: *Account (claimed 2 Aug)* / *Invited,
   expires 17 Aug* / *Invitation expired 3 Aug* / *Invitation revoked 1 Aug* / *No invitation*. All
   five strings are server-supplied; the component interpolates and formats nothing.
6. **The Invite affordance** — on a row whose state is `none`, `expired` or `revoked`, and only for a
   viewer who could actually use it, a button POSTing to the **existing**
   `admin.invitations.store` endpoint with that person's email and position. It is a convenience on
   top of an already-satisfied requirement (finding 18, owner decision 1), **not** an AC-01 gap being
   closed, and it must POST to `InvitationController` — which carries `ManagerScope`'s gate — never
   to anything under `/admin/people/*`. `RosterNeverMintsCredentialsTest` must stay green **with no
   allow-list change**; if it goes red, the button was wired to `PersonController` and Decision I of
   P1c-1 has been broken.
7. `Users.vue`'s invitations table gains a Status column (six `<th>` now) and stops being open-only.

**How to verify**

```bash
npm run build && php artisan test --filter 'InvitationStatusTest|ManagerScopeParityTest|RotaReadViewTest|ContactFieldsAreProjectedOnceTest|RosterNeverMintsCredentialsTest|UserManagementTest|ChiefResidentTest|PeopleAccessTest' | tail -5
php artisan test | tail -5
```

`ChiefResidentTest` is the one most likely to move: it exercises the scoping that just relocated from
the caller into the projection. If it goes red, check the *behaviour* is unchanged before changing
the test — a scoped manager must see exactly what they saw before, no more.

```bash
git commit -am "feat: who has actually claimed their account, and who is still waiting"
```

---

### Task 3: resend, singly — one writer, a rotated token, and a superseded row that is kept

**Files touched**

- `app/Support/Invitations/InvitationIssue.php` (new — the only writer)
- `app/Http/Controllers/Admin/InvitationController.php` (`store()` refactored onto it; new
  `resend()`)
- `app/Models/Invitation.php` (docblock corrections per Decision G)
- `routes/web.php` (one POST in the existing invitations group)
- `resources/js/Pages/Admin/Users.vue` (a Resend button per invitation row)
- `resources/js/Pages/Admin/People.vue` (a Resend button where the state is `open` or `expired`)
- `tests/Feature/Admin/InvitationResendTest.php` (new)
- `tests/Feature/Build/InvitationWritersAreSingularTest.php` (new)
- `tests/Feature/Auth/InvitationTest.php` (extend — the two pins)

**The failing test to write first**

`InvitationResendTest`:

1. **`test_a_resend_rotates_the_token`** — capture the first `token_hash`, resend, assert the new row
   has a different one **and that the old plaintext token no longer redeems**
   (`Invitation::redeemable($oldToken)` is null). Asserting the hash changed is not enough; the
   property that matters is that the old link is dead.
2. `test_the_superseded_row_is_kept_and_marked_revoked` — the old row still exists, has `revoked_at`
   and `revoked_by_user_id` set, and is **not** deleted.
3. `test_a_resend_does_not_change_the_position` — the new invitation carries the superseded row's
   position, not a request-supplied one.
4. `test_a_chief_resident_cannot_resend_a_consultants_invitation` — 403, and a `user_scope_denied`
   audit row exists.
5. `test_resending_an_expired_invitation_issues_a_fresh_one` — the main use case.
6. **`test_an_expired_unclaimed_invitation_does_not_block_re_inviting_the_same_person`** — issue,
   expire it, then POST a plain new invitation for the same address: it succeeds, and the expired row
   is untouched (its `revoked_at` stays null — the supersede loop only touches open rows). Decision C
   made explicit.
7. `test_a_person_with_an_account_cannot_be_resent_to` — refused by
   `Person::accountEmailRule()`'s rule, a validation error not a 500.
8. `test_the_resend_is_audited_by_ids_only` — `invitation_issued` + `invitation_revoked` with
   `reason=resent`, and **no email address anywhere in either detail**.
9. `test_a_failed_mail_send_does_not_lose_the_invitation` — force `Mail::fake()` to throw; the
   invitation row still exists and the one-time link is still flashed. (The single path's swallow is
   correct precisely because the link is the fallback — pin it before Task 4 makes the opposite
   choice for bulk.)

`InvitationWritersAreSingularTest`: the two guard tests from Decision C. **Prove it can fail** —
temporarily add an `Invitation::create([...])` to `PersonController`, watch it go red, revert.

`InvitationTest` (extend), the two Decision G pins:

10. **`test_claiming_an_invitation_never_changes_an_existing_roster_persons_position`** — a roster
    person at position 5 with an invitation issued at position 4 claims it; `people.position` is
    still 5 afterwards. Finding 17.
11. `test_the_offerable_positions_are_unchanged` — `InvitationController::OFFERABLE` is exactly
    `[2,3,4]`. Finding 13: resend must not become the wider door.

Also, over the router:

12. **`test_every_route_in_the_invitations_group_is_gated_in_controller`** — enumerate routes whose
    URI starts `admin/invitations`, and assert each maps to a controller method whose source contains
    a `ManagerScope::` call. Coarse, and honest about being coarse; the alternative is a hand-written
    list that covers only the routes somebody remembered (invariant 8).

**The implementation**

1. `App\Support\Invitations\InvitationIssue` — one public method, `issue()`, with the signature in
   Decision C. It: resolves-or-creates the person (moving
   `InvitationController::store():62-74` verbatim, including the `trashed()` restore); authorizes the
   **entire** superseded set in a full pass **before** any mutation (the existing `:89-91` loop,
   preserved — P1c-1 finding 12); revokes each superseded row; calls `Invitation::issue()`; and
   returns the invitation, the link and the superseded id. **It does not send mail and it does not
   audit** — both are the caller's, because the bulk caller in Task 4 needs a different ordering for
   each and a writer that decides them cannot serve both.
2. `InvitationController::store()` becomes a thin caller: validate, `ManagerScope::assertMayTarget()`,
   `InvitationIssue::issue(..., 'invited')`, audit, mail-with-swallow, flash the link. **Its inline
   `Str::lower(trim(...))` at `:57` becomes `Person::normalizeEmail()`** — the last of finding 19's
   three copies.
3. `InvitationController::resend(Request, Invitation)` — resolve the person from the invitation,
   `assertMayTarget()` against **the invitation's own position**, `InvitationIssue::issue(..., 'resent')`,
   audit, mail-with-swallow, flash the new link. Route:
   `POST /admin/invitations/{invitation}/resend`, name `admin.invitations.resend`, in the existing
   `auth`-only group with the in-controller gate.
4. `Invitation`'s docblock gains Decision G's two paragraphs: `member_email` is the address a
   credential was mailed to, frozen at send time (**not** a duplicate of `people.email`, which can be
   corrected later); `position` is what `ManagerScope` authorized against, **not** a role assignment,
   and the claim path deliberately never writes it onto an existing roster person.
5. Both screens get a Resend button with a confirmation naming the person, and both post to the same
   endpoint.

**How to verify**

```bash
npm run build && php artisan test --filter 'InvitationResendTest|InvitationWritersAreSingularTest|InvitationTest|UserManagementTest|ChiefResidentTest' | tail -5
php artisan test | tail -5
```

```bash
git commit -am "feat: send it again, and kill the link that went astray"
```

---

### Task 4: LV-02's bulk resend — preview, confirm, commit, then mail

**Files touched**

- `app/Http/Requests/Admin/InvitationBulkResendRequest.php` (new)
- `app/Support/Invitations/InvitationIssue.php` (add the `resendable()` predicate)
- `app/Http/Controllers/Admin/InvitationController.php` (`bulkPreview()`, `bulkResend()`)
- `routes/web.php` (two POSTs, `throttle:6,1`)
- `app/Http/Middleware/HandleInertiaRequests.php` (**two new flash keys** — see below)
- `resources/js/Pages/Admin/People.vue` (the disabled button at `:481-484` becomes real)
- `tests/Feature/Admin/InvitationBulkResendTest.php` (new)
- `docs/OPEN-DECISIONS.md` (mark item G answered — done properly in Task 7, referenced here)

**The resolved behaviour this task implements.** LV-02's bulk resend replaces the control P1c-1
shipped disabled (`People.vue:481-484`, `title="Arrives with the invitation work (AC-02)"`). It is
an **account** action on a **roster** screen: the selection comes from People, the endpoint and the
authorization are `InvitationController`'s under `ManagerScope`, never `/admin/people/bulk` under
`cap:people.manage`.

**The failing test to write first**

1. **`test_bulk_resend_is_refused_outright_when_mail_is_not_configured`** — `config(['mail.default' => 'log'])`;
   the endpoint returns a validation error naming Settings and **writes no invitation row**. Decision
   D property 1, and the first case to write because it is the one that makes the feature honest.
2. `test_the_preview_names_the_count_and_the_number_of_emails` — and changes nothing.
3. **`test_a_confirm_against_a_different_set_than_was_previewed_is_refused`** — the digest guard.
4. `test_more_than_fifty_ids_is_refused_by_validation` — the cap, before anything is authorized.
5. **`test_the_whole_selection_is_authorized_before_any_write`** — a Chief Resident's selection of
   nine residents and one consultant: 403, **zero** new invitation rows, and a `user_scope_denied`
   audit row. Not nine sent and one refused. P1c-1 finding 12's shape.
6. `test_a_person_with_an_account_is_skipped_not_refused` — outcome `skipped_has_account`; the rest
   proceed.
7. `test_a_person_with_no_email_is_skipped` — outcome `skipped_no_email`.
8. **`test_mail_is_sent_after_the_transaction_commits_not_inside_it`** — `Mail::fake()`, then force a
   failure inside the transaction (a `LevelAssignment`-style throw, or a deliberately colliding row);
   assert **zero** mailables were queued/sent and zero rows written. Then the success case: rows
   written **and** N mailables sent. Decision D property 5 — this is the test that proves the
   ordering, and it must be watched failing against an implementation that sends inside the
   transaction before you trust it.
9. `test_a_single_failed_send_is_reported_per_person_and_does_not_abort_the_rest` — one recipient
   throws; the report says 1 failed, N-1 sent, and the failed person is named **on screen** while the
   **log line and the audit detail carry no address**.
10. `test_one_summary_audit_row_plus_one_row_per_person` — `invitation_bulk_resend` with
    `n=;sent=;failed=`, plus N `invitation_resent`. Ids and counts only.
11. **`test_neither_new_action_is_on_the_anomaly_watch_list`** — run `audit:anomalies` after a bulk
    resend and assert no mail is sent. Decision D's last paragraph, asserted rather than assumed.
    Beware the self-referential docblock: describe the absent action without writing its literal
    string in a comment inside `AuditAnomalies.php` (P1c-1 Task 10 tripped exactly this).
12. `test_every_resent_token_is_unique_and_every_old_one_is_dead` — over a set of five.

**The implementation**

1. `InvitationIssue::resendable(?User $viewer): \Closure` — the one predicate (Decision D property 4),
   applied to **both** the offered preview set and the FormRequest's `Rule::exists`/`Rule::in`.
   `Rule::exists` runs on the raw query builder and never sees Eloquent's SoftDeletes global scope, so
   write the predicate once as a builder closure and hand it to both — a predicate written once as
   Eloquent and once as raw SQL is two predicates that drift (the 2026-07-26 `pickerRule()` finding).
2. `InvitationBulkResendRequest` — `person_ids` `required|array|min:1|max:50`; `person_ids.*`
   `integer` + the predicate; `digest` `required_with:confirm`. The mail-not-configured refusal lives
   here too, as a `withValidator()` rule, so it fails **before** the controller opens anything.
3. `InvitationController::bulkPreview()` — resolve, apply the predicate, build the per-person outcome
   preview, compute the digest, flash `invitation_bulk_preview`. No write.
4. `InvitationController::bulkResend()` — digest check → **full authorization pass** over every
   distinct position in the set via `assertMayTarget()` (before the transaction, because it audits
   then aborts) → `DB::transaction` calling `InvitationIssue::issue()` per person and collecting
   outcomes → **commit** → **then** mail, per recipient, each in its own `try`/`catch` → **then**
   audit (one summary, N per-person) → `back()->with('invitation_bulk_report', $outcomes)`.
5. **`HandleInertiaRequests` must share both new flash keys.** `back()->with(...)` flashes to the
   session; without adding `invitation_bulk_preview` and `invitation_bulk_report` to that
   middleware's `flash` array, neither reaches an Inertia prop and the screen renders nothing. **This
   file has been missed by a plan's own Files list three times** (P1c-1 Tasks 7, 9 and 10 all recorded
   it as an amendment); it is listed here so it is four-for-four caught rather than four-for-four
   missed.
6. `People.vue`: the disabled button becomes a real one, with the cap stated beside it ("up to 50 at
   a time"), a preview panel listing each person and their outcome, and a confirm that names the
   number of emails. Remove the `title="Arrives with the invitation work (AC-02)"` attribute and the
   rationale comment at `:218-225`, and the matching server-side comment at
   `PersonController.php:206-208`.

**How to verify**

```bash
npm run build && php artisan test --filter 'InvitationBulkResendTest|InvitationResendTest|PeopleBulkTest|AuditAnomalies' | tail -5
php artisan test | tail -5
```

Then, **a dress rehearsal against a real running server**, on P1c-1 Task 12's precedent — the feature
sends real email and PHPUnit's `Mail::fake()` proves the call, not the delivery. Migrate and seed a
throwaway SQLite database (`ReferenceSeeder`, `AccessControlSeeder`, `E2eSeeder` — the harness's own
fictional identities, never a real name), set `MAIL_MAILER=log`, `php artisan serve`, select five
people, preview, confirm, and read `storage/logs/laravel.log` for exactly five distinct links. Tear
the database and the server down afterwards; nothing from this check enters the repository.

```bash
git commit -am "feat: fifty people, fifty new links, and nothing sent until the rows are safe"
```

> **Fallback seam.** If execution stalls, this is where to merge. AC-02 and LV-02's bulk resend are
> complete and useful; Tasks 5–7 become `P1c-2b`.

---

### Task 5: AC-03 — unbinding, which preserves the attestation before it clears the link

**Files touched**

- `app/Support/AccountUnbind.php` (new — the only writer of `users.person_id = null`)
- `app/Http/Controllers/Admin/UserManagementController.php` (`unbind()`; `setActive()` refuses to
  reactivate an unbound account)
- `routes/web.php` (one PATCH/DELETE in the `cap:users.manage` group)
- `resources/js/Pages/Admin/Users.vue` (the action, its confirmation, and the flash text)
- `tests/Feature/Admin/AccountUnbindTest.php` (new)
- `tests/Feature/Build/AccountLinkHasOneWriterTest.php` (new)

**The resolved behaviour this task implements.** Owner decision 3: unbinding **clears the person
link, deactivates the account so nobody can log in, and keeps the account as history** — who signed
off what, who invited whom. Accounts are never deleted. The trap this avoids is named explicitly:
`$user->full_name` and `$user->position` are read-through accessors onto the linked `Person`, so an
**active-but-unbound** account would appear nameless and positionless on every screen with no error
at all. Deactivate and unbind are therefore one atomic act, never one without the other.

**The failing test to write first**

`AccountUnbindTest`:

1. `test_unbinding_clears_the_link_and_deactivates_in_one_act` — `person_id` null **and**
   `active` false, in one request.
2. **`test_the_account_row_is_kept_and_never_deleted`** — `users` count unchanged, `deleted_at` still
   null.
3. **`test_the_person_is_untouched`** — `people.active` is still true, the person is still listed on
   the People screen, still assignable on the rota. This is what makes unbind a *different*
   definition from `PersonStatus::apply()` rather than a special case of it.
4. **`test_an_unsnapshotted_signoff_keeps_its_signer_name`** — construct a `handover_signoffs` row
   with `signed_off_by_user_id` set and `signed_off_by_name` **null** (the pre-2026-07-27 shape;
   the freeze migration deliberately backfilled nothing, so this must be built, not assumed), read
   the rendered signer name before, unbind, read it after, assert it is **the same string**. Finding
   6 — and it must be watched failing against an implementation that skips the snapshot, or the
   snapshot is untested code.
5. `test_a_signoff_that_already_has_a_name_is_not_rewritten` — the update touches only null rows.
6. **`test_an_unbound_account_resolves_to_no_role_capabilities`** — `AccessControl::capabilitiesFor()`
   returns only explicit overrides (an empty set for an ordinary account), because the role-defaults
   join is on a null position.
7. `test_an_unbound_account_is_invisible_to_holders_of` — `AccessControl::holdersOf('users.manage')`
   never returns it (the inner join on `people` does this for free — assert it rather than assume
   it).
8. `test_an_unbound_account_cannot_log_in`.
9. **`test_an_unbound_account_cannot_be_reactivated`** — `PATCH /admin/users/{user}/active` with
   `active = true` is refused with the message naming the remedy, and `users.active` stays false.
   The guard that stops the trap being reopened through a different endpoint.
10. `test_unbinding_the_last_active_administrator_is_refused` — reusing
    `PositionChange::isLastActiveAdministrator()`, not a second copy.
11. `test_the_account_disappears_from_the_users_console` — finding 5's inner join, asserted so the
    behaviour is decided rather than discovered.
12. `test_the_unbind_is_audited_by_ids_and_a_count` — `account_unbound` with
    `user=<id>;person=<id>;signoffs_snapshotted=<n>`, no name anywhere.
13. `test_the_capability_cache_is_flushed` — after the commit.

`AccountLinkHasOneWriterTest`: the two guard tests from Decision E. **Prove it can fail** —
temporarily null a `person_id` in `PersonController`, watch it go red, revert.

And the disjointness proof:

14. In the same run, assert `PersonActiveHasOneWriterTest` is green **with no allow-list change**.
    `AccountUnbind` writes `users.active` but never `people.active`, so it must not need an entry —
    if it does, the two definitions have been conflated.

**The implementation**

`App\Support\AccountUnbind::apply(User $user, User $actor, ?string $ip): int` — returns the number of
signoffs snapshotted, so the caller's audit detail comes from the writer's own answer rather than a
guess. Inside one `DB::transaction`, **in this order**:

1. refuse if `$user->person_id === null` already (idempotence: a second unbind is a no-op error, not a
   crash);
2. refuse via `PositionChange::isLastActiveAdministrator($user)`;
3. **snapshot** — the targeted `HandoverSignoff` update from Decision E, capturing the affected count;
4. `$user->forceFill(['person_id' => null, 'active' => false])->save()`.

After the commit: `AccessControl::flush((int) $user->getKey())`, then the audit row. Audit after
commit, matching `AccessControlController::applyRoleSet()` — `AuditLog::record()` opens its own
transaction and locks the chain tail, and an audit row nested inside a transaction that later rolls
back erases the record of the attempt.

`UserManagementController::setActive()` gains the reactivation refusal from Decision E, with the
message naming the actual remedy. `Users.vue` gets the action behind a confirmation naming the
person, and the flash text stating plainly that the row will vanish and why.

**How to verify**

```bash
npm run build && php artisan test --filter 'AccountUnbindTest|AccountLinkHasOneWriterTest|PersonActiveHasOneWriterTest|PersonStatusTest|UserManagementTest|EndorsementTest' | tail -5
php artisan test | tail -5
```

`EndorsementTest` is in the filter deliberately: this task writes to `handover_signoffs`, and the
clinical suite is what proves the write is a snapshot rather than a change. If anything there moves,
stop.

```bash
git commit -am "feat: someone leaves, and the sheets they signed still say who signed them"
```

---

### Task 6: AC-04 — roles granted per person, on the account, through the one writer that already exists

**Files touched**

- `app/Support/CapabilityGrant.php` (new — extracted from the existing controller body)
- `app/Http/Controllers/Admin/AccessControlController.php` (`updateUser()` refactored onto it)
- `app/Http/Controllers/Admin/PersonController.php` (`rosterProps()` supplies the per-person grant
  summary **only** to a viewer holding `access.manage`)
- `routes/web.php` (one PUT in the existing `cap:access.manage` group)
- `resources/js/Pages/Admin/People.vue` (the panel, and the sentence for a person with no account)
- `tests/Feature/Admin/PersonRolesTest.php` (new)
- `tests/Feature/Build/CapabilityWritersAreSingularTest.php` (new)

**The resolved behaviour this task implements.** Owner decision 2: **capability grants stay keyed to
the account** (`user_capabilities.user_id`). AC-04 is satisfied by granting **per person on the
People screen**, writing through to that person's linked account. There is **no move to `people`, no
change to `AccessControl::resolve()`, `holdersOf()` or the cache key.** A person with no account has
nothing to grant to, and the screen says so rather than offering a control that silently does
nothing. And the deliberate consequence, stated on the screen: **a person who leaves and later
returns on a new account does not regain their old roles — an administrator re-grants them.**
Auto-restoring privileges on re-bind is the security anti-pattern this avoids.

**The failing test to write first**

`PersonRolesTest`:

1. **`test_the_people_screen_grant_endpoint_requires_access_manage_not_people_manage`** — a user
   holding **only** `people.manage` gets **403**. Finding 12 and Decision F, and the single most
   important assertion in this task: without it, `people.manage` becomes a path to `access.manage`.
2. `test_a_holder_of_access_manage_can_grant_and_deny_per_person`.
3. **`test_the_grant_lands_on_the_account_not_the_person`** — the `user_capabilities` row's
   `user_id` is the linked account's id, and `people` is unchanged. Owner decision 2, asserted.
4. `test_a_person_with_no_account_is_refused_with_a_message_not_a_500`.
5. `test_the_panel_is_absent_from_the_props_for_a_people_manage_only_viewer` — **absent, not empty**,
   the same discipline `PersonPresenter` uses for a withheld contact field (invariant 5): an empty
   list and a withheld list look identical on screen.
6. **`test_deny_still_wins_over_grant_through_the_new_surface`** — the resolver's two-pass rule
   (`AccessControl.php:168-178`) is unchanged by the second surface. This is what proves "no resolver
   change" rather than asserting it.
7. `test_the_cache_is_flushed_for_that_account`.
8. `test_the_write_is_audited_as_a_summary_plus_one_row_per_changed_override` — reusing the existing
   `access_user_grant`/`access_user_deny`/`access_user_override_clear` actions, not new ones.
9. **`test_the_access_control_screen_and_the_people_panel_produce_identical_rows`** — grant the same
   capability to the same account through both endpoints and assert the resulting
   `user_capabilities` rows are indistinguishable. One writer, two surfaces, proved.
10. `test_unbinding_then_re_inviting_does_not_restore_old_roles` — unbind (Task 5), invite, claim; the
    new account holds no override rows. Owner decision 2's deliberate consequence, asserted so it
    cannot be "fixed" later by someone who thinks it is a bug.

`CapabilityWritersAreSingularTest`: the two guard tests from Decision F. **Prove it can fail.**

**The implementation**

1. Extract `AccessControlController::updateUser()`'s body (`:246-319`) into
   `App\Support\CapabilityGrant::applyForUser()` — catalog validation, the in-transaction diff,
   `AccessControl::flush($userId)`, and the after-commit audit rows, unchanged. **Refactor the
   controller onto it in the same commit**; two surfaces onto one body is the whole point, and a
   copy would be the drift `ManagerScope`'s own docblock warns about.
2. `PUT /admin/access-control/person` in the existing `cap:access.manage` group: resolve the person,
   refuse if `hasAccount()` is false, delegate.
3. `PersonController::rosterProps()` adds the per-person grant summary **only** when
   `AccessControl::allows($viewer, 'access.manage')`. Absent otherwise.
4. `People.vue` renders the panel behind `useCan('access.manage')`, shows the no-account sentence
   where applicable, and carries the consequence sentence verbatim from Decision F.

**How to verify**

```bash
npm run build && php artisan test --filter 'PersonRolesTest|CapabilityWritersAreSingularTest|AccessControlPageTest|AccessControlSeederRespectsRevocationsTest|PeopleAccessTest' | tail -5
php artisan test | tail -5
```

`AccessControlPageTest` must stay green **unchanged** — the existing screen's behaviour did not move,
only where its body lives. If it needs edits, the refactor changed behaviour and the extraction is
wrong.

```bash
git commit -am "feat: roles granted where the person is, still held where the account is"
```

---

### Task 7: the tidies, and the documents this invalidates

**Files touched**

- `resources/js/Pages/Admin/Users.vue` (`colspan`, `bg-panel-soft`)
- `resources/js/Components/StaffPrivacyNotice.vue` (`bg-panel-soft`)
- `resources/js/Pages/Auth/AcceptInvitation.vue` (`bg-panel-soft`)
- `CLAUDE.md`
- `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`
- `docs/OPEN-DECISIONS.md`
- `docs/spec/08-foundation.md`, `docs/spec/15-rulings.md`
- `docs/superpowers/plans/2026-08-08-p1-master-rota.md`
- `docs/RUNBOOK-DEPLOY.md`

**Step 1: the two `Users.vue` tidies P1c-1 deferred, plus the one it undercounted**

- `Users.vue:367` — `colspan="7"` on an eight-column table (finding 16) becomes `colspan="8"`.
  **Verify the count against the file before changing it**: Tasks 2 and 3 added no column to the
  *users* table, but count the `<th>` rather than trusting this sentence.
- `bg-panel-soft` at **three** sites, not the one P1c-1 named (finding 15) — `Users.vue:179`,
  `StaffPrivacyNotice.vue:25`, `AcceptInvitation.vue:43`. All become `bg-ground-deep`, the token
  every other table header and inset surface in this codebase uses. Confirm with
  `grep -rn "bg-panel-soft" resources/` returning nothing afterwards. Note the class compiles to
  nothing today, so this is a visual *improvement*, not a regression risk — but check the three
  surfaces render as intended.

**Step 2: `CLAUDE.md`**

Add to the non-negotiables, in the file's own voice:

- **The invitation lifetime is configurable, default 7, bounded [1, 30], behind `settings.manage`**
  (`Invitation::lifetimeDays()`), a deliberate override of Munawib AC-02's 14 because an invitation
  is a bearer credential that reaches children's clinical records once redeemed. `LIFETIME_DAYS` is
  the default, not the value.
- **`App\Support\Invitations\InvitationIssue` is the only writer of an invitation, and a resend
  rotates the token.** The superseded row is kept and marked revoked, never deleted.
  `InvitationWritersAreSingularTest` proves it. A bulk resend sends mail **after** the transaction
  commits, never inside it — mail cannot be rolled back.
- **Unbinding an account is `App\Support\AccountUnbind` and nothing else.** It snapshots
  `handover_signoffs.signed_off_by_name` for that account's un-snapshotted rows **before** clearing
  the link, because the signer's name is otherwise resolved live through `users.person_id` and
  unbinding would blank an attestation on medico-legal evidence. It clears the link and deactivates
  in one act (an active-but-unbound account is nameless on every screen with no error), never touches
  `people`, and an unbound account **cannot be reactivated**. `AccountLinkHasOneWriterTest` and
  `PersonActiveHasOneWriterTest` are the two guards, and they are deliberately separate: deactivating
  a **person** and retiring an **account** are different acts.
- **Capability grants stay keyed to the account.** The People screen is a second *surface* onto
  `App\Support\CapabilityGrant`, gated `access.manage` — **never `people.manage`**, which would make
  the roster console a path to the security console. A person who returns on a new account does not
  regain old roles; an administrator re-grants them.
- **Claim status is derived, never stored** — five states folded from `accepted_at`/`revoked_at`/
  `expires_at`. There is no `person_status` column and none is coming.

**Step 3: the design doc**

- **§1.2's overrides table gains a row for AC-02's lifetime** — Munawib's *"default 14 days"*,
  overridden to 7 and made configurable, with the reasoning. Until now the deviation lived only in
  §14 item 13, and an override recorded only in an open-items list is one nobody finds.
- §14 item 13 (AC-02 lifetime): **mark SHIPPED**, dated, naming `Invitation::lifetimeDays()`,
  `app_settings.invitation_lifetime_days` and the [1, 30] bound.
- §14 item 17 (`Invitation::issue()`'s second normalisation): **mark CLOSED**, and correct its text —
  there were **three** definitions, not two (finding 19).
- §5.1: record that claim status is a projection (`App\Support\Invitations\InvitationStatus`), five
  derived states, no column; and that unbinding is an explicit audited act with the signoff snapshot
  as its first step.
- §9: record that AC-04 is a second surface on `access.manage`, and **why it is not on
  `people.manage`**.
- §14 item 7 (`invitations` has no retention rule): **leave OPEN, and amend it** — resend rotates, so
  invitation rows now accumulate faster than before. Say so. Do not mark it closed.
- §14: a **new item** — *the invitations route group is gated in-controller by `ManagerScope`, not by
  `cap:` middleware*, with the reason (the rule is two-tier and position-dependent) and the router
  test that keeps it honest.

**Step 4: `docs/OPEN-DECISIONS.md`**

- **Item G** (*"Where does the configurable invitation lifetime live?"*) — **ANSWERED, 2026-08-10:
  `settings.manage`**, with Decision A's two reasons. Move it out of the open section into the dated
  decided section, following the file's own convention.
- The AC-02 lifetime entry at `:228-238` — mark the "until then, the constant stays 7" clause as
  **superseded**; it is now a default with a knob.

**Step 5: the spec slices**

- `docs/spec/08-foundation.md`: the invitation lifetime setting and its capability; AC-04's second
  surface and its gate; the "roles are not restored on a new account" sentence verbatim, so the UI
  copy and the spec cannot drift.
- `docs/spec/15-rulings.md`: two entries — **the signoff snapshot on unbind** (2026-08-10, with
  finding 6's chain), and **no passwordless sign-in** (owner decision 1, so a future reader does not
  reopen AC-01's *"password optional"*).

**Step 6: the P1 master plan**

Amend the P1c scoping section the way P1b's and P1c-1's amendments amend their own — leave the
original as written, append what changed and why. Name: the split completing (P1c-1 + P1c-2 both
merged); **AC-01 found already satisfied and deliberately given no task**; the `2026_08_14_1201*`
migration slot **released unclaimed**; and the two claims this plan found wrong (P1c-1's
`bg-panel-soft` undercount; P1c-1's `AppSettings` "widen or rehome" dilemma, answered by an existing
precedent).

**Step 7: `docs/RUNBOOK-DEPLOY.md`**

No migration, so no migration verification — say so explicitly, because a runbook that is silent
about a release reads as an oversight. Add the one post-deploy check that matters: **confirm
`invitation_lifetime_days` is unset or 7** on each instance, since an absent row is the intended
default and a surprising value would mean somebody set it.

**How to verify**

```bash
npm run build && php artisan test | tail -5
npm test | tail -5
grep -rn "bg-panel-soft" resources/ || echo "clean"
```

```bash
git commit -am "docs: an account has a beginning, a middle and an end, and the documents say so"
```

---

## Definition of done

*Measured at Task 7, 2026-08-11, via Bash after `npm run build`. Every number below is a reading,
not arithmetic.*

- [x] `php artisan test` — green, **1396 tests, 6419 assertions, 0 failures, 0 skipped**. (The
      baseline was 1297; the six implementing tasks added 99. Each task's amendment records what it
      actually left behind, and one of them — Task 4's — records the suite crossing PHP's stock 128M
      CLI ceiling on the way.)
- [x] `npm test` green — **192**, not the 187 written above. The baseline was measured before Task 6
      added five `PeopleRolesPanel` cases; the stated number was stale by the time the box was read,
      which is this plan family's single most common species of error. `npm run build` green.
- [x] **No migration was added.** `git diff --stat main -- database/migrations` is empty, and no
      `2026_08_14_1201*` file exists — the slot P1c-1 reserved is **released unclaimed** (Decision H).
      The two `2026_08_14_1200*` files in the tree are P1c-1's.
- [x] `CompiledCssIsLightOnlyTest`, `TextContrastMeetsAaTest`, `CalendarIsTheOnlyConverterTest`,
      `CalendarWritersFlushTest` and `InstitutionProvenanceTest` all green, **none with a new
      allow-list entry**.
- [ ] ~~`ContactFieldsAreProjectedOnceTest` green **with no allow-list change**~~ — **THIS BOX
      CANNOT BE TICKED AS WRITTEN, and the guard is what proved it (Task 3's amendment).** The test
      is green, and the clause's *reasoning* holds in full: claim status is `$extra`, never a base
      key. But the one writer of `invitations` must read `people.email` — it is the address the
      credential is frozen onto and half the predicate deciding which live links to kill — so
      `app/Support/Invitations/InvitationIssue.php` carries one allow-list entry with that reason
      written out beside it. The alternative, passing the address in from each caller, makes "an
      invitation is addressed to the roster row's current address" a thing every caller can get
      wrong. Task 4 then hit the same guard and fixed it the other way, by reading **less**:
      `BulkResend` takes the address off the minted invitation rather than the person, and uses
      `Person::hasEmail()` for the yes/no question — **no second entry was added**.
- [x] `RosterNeverMintsCredentialsTest` green **with no allow-list change** — the People screen's
      Invite and Resend buttons POST to `InvitationController`, never to `PersonController`.
- [x] `PersonActiveHasOneWriterTest` green **with no allow-list change** — `AccountUnbind` never
      writes `people.active`. This is the disjointness proof, not a formality.
- [x] The three new guard tests exist, are green, and **each was watched failing against a
      deliberately introduced offence before being trusted**: `InvitationWritersAreSingularTest`
      (an `Invitation::create(`/`DB::table('invitations')`/`'revoked_at' =>` trio, and separately a
      bare `Invitation::issue(` in `PersonController`), `AccountLinkHasOneWriterTest` (a second link
      writer in `PersonController` — and then watched staying **green** against a planted
      `person_id === null` *read*, which is what proves the trailing space in that needle is
      load-bearing), `CapabilityWritersAreSingularTest` (twice: a model-name trio, and the
      relation-shaped `$user->userCapabilities()->updateOrCreate(...)` that no model-name needle
      would see). Every plant was reverted with `git status` left clean.
- [x] `PickerParityTest` green — D9 survived everything here.
- [x] `ManagerScopeParityTest` green over the whole matrix — `mayTarget()` and `assertMayTarget()`
      agree for every (capability set × position) pair.
- [x] `AccessControlPageTest` green **unchanged** — `git diff main` reports no edit of any kind to
      that file. Task 6's extraction moved a body, not a behaviour.
- [x] No `dark:` utility, no raw Tailwind palette class, no hex in any new markup, and
      `grep -rn "bg-panel-soft" resources/` returns nothing (Task 7 closed the last two sites;
      Task 2 had closed the third early, on markup it was rewriting anyway).
- [x] No date arithmetic anywhere in `resources/js`; every date and time this plan shows arrives
      preformatted.
- [x] No staff name, email address, phone number or filename in any `audit_log.detail` written by
      this plan, and none in any log line either. Task 3 also found and closed a **pre-existing**
      leak of the same species on the path it was refactoring: the single-invite mail-failure log
      carried `$e->getMessage()`, and SMTP transport errors routinely quote the envelope recipient
      back. It now carries the person id, the invitation id and the exception class, nothing else.
- [x] `users` row count is unchanged by every unbind test; no account is deleted anywhere.
- [x] `tests/fixtures/roster/` and `tests/fixtures/calendar/golden.json` untouched — `git diff
      --name-only main -- tests/fixtures/` is empty.
- [x] The dress rehearsal in Task 4 was run against a real server — **but not the recipe written
      above, which contradicts Decision D property 1.** `MAIL_MAILER=log` makes the endpoint refuse
      outright, so reading five links out of `laravel.log` would have exercised the refusal and
      nothing else. It was run with `MAIL_MAILER=smtp` against a disposable local SMTP sink instead,
      which is strictly better evidence: `Mail::fake()` proves the call, a real transport proves the
      mailable **renders**. Five distinct recipients, five distinct 64-hex tokens, no `@` anywhere in
      the audit trail, and a replayed digest refused with no sixth email. The throwaway database, the
      sink and the driver were destroyed; nothing entered the repository.
- [x] `npm run test:e2e` — **22 passed**. Unchanged: nothing in this plan touches a path the e2e
      world exercises, and no invitation spec was added (the harness has no mail transport).

---

## Owner decisions — answered 2026-08-10, recorded here as the source of record

1. **Sign-in stays password-based; no passwordless or magic-link login.** AC-01's *"email link;
   password optional"* means the invitation is the email link and the claim screen sets the password.
   **Already shipped in P0c and verified against the tree (finding 18) — this plan implements no part
   of AC-01.**
2. **Capability grants stay keyed to the account.** No move to `people`; no change to
   `AccessControl::resolve()`, `holdersOf()` or the cache key. A returning person does not regain old
   roles. → Decision F, Task 6.
3. **Unbinding deactivates the account and keeps it.** → Decision E, Task 5.
4. **Invitation lifetime is configurable, default 7, behind `settings.manage`** — a logged override
   of Munawib AC-02's 14. → Decision A, Task 1; recorded in the design doc's §1.2 overrides table by
   Task 7.

**And one open item this plan deliberately does not close.** `invitations` still has no retention
rule (design §14 item 7): nothing prunes accepted, revoked or expired rows, and `member_email`
accumulates on that table indefinitely. **Resend rotates, so rows now accumulate faster.** Folding a
disposal policy into `data:retention` (which already prunes abandoned registrations and expired
one-time codes) is the obvious home, but it is a data-disposal decision on a table holding staff
email addresses, and it belongs in a plan that reviews the whole retention policy rather than as a
side effect of adding a resend button. Task 7 amends the open item to say the rate changed; it does
not mark it closed.

---

## Acceptance

**AC-01** — already satisfied; verified, not built (finding 18). The chain
`roster entry → invitation → email link → claim → password` runs today and is covered by
`tests/Feature/Auth/InvitationTest.php`. Task 2 adds an Invite affordance beside a roster-only
person as a convenience, POSTing to the existing gated endpoint.

**AC-02** — invitations expire after a **configurable** number of days, default 7 (Task 1); they are
**resendable singly** (Task 3) **and in bulk** (Task 4), with the token rotated and the old link
dead; and **claim status is visible** on both the People and Users screens, in five derived states,
scoped by the same rule that governs acting on an account (Task 2).

**AC-03** — one account ↔ one person, and **unbinding on turnover is an admin action preserving
history** (Task 5): the account is deactivated and kept, never deleted; the signer's name on every
handover it signed is preserved before the link is cleared; and the account cannot be brought back
to life without a person.

**AC-04** — **roles are granted per person by an administrator, enforced via server-side claims**
(Task 6): the grant is made on the People screen, lands on the linked account's `user_capabilities`
row, and is resolved server-side by `AccessControl` exactly as before — with the granting boundary
still at `access.manage`.

**LV-02** — the People screen's multi-select now supports set level, set status, **resend
invitations**, deactivate and export. The last of the five arrives in Task 4; the disabled control
P1c-1 shipped honestly is replaced by a real one.

**Munawib §35, Stage 1 acceptance** — *"the pilot's real master rota and clinics live; residents
claimed accounts; availability summaries match reality."* The rota is live (P1d-1/P1d-2) and the
summaries exist (P1d-2 Task 2). **"Residents claimed accounts" becomes observable for the first time
in Task 2** — before it, the system could not answer the question. Clinics remain P1e.

---

## Next plan

**P1e — Clinics**, which depends on P1b's structure work and on nothing here. P1c-2 is the last of
Stage 1's people work. Three outputs P1e and anything after it must respect:

- **`App\Support\Invitations\InvitationIssue` is the only writer of an invitation**, and
  `InvitationWritersAreSingularTest` fails the build for a second one. Anything that "helpfully"
  invites somebody writes through it.
- **`App\Support\AccountUnbind` is the only writer of `users.person_id = null`**, and it snapshots
  before it clears. A future "merge these two accounts" feature is the shape most likely to reach for
  a direct write; it must not.
- **`App\Support\CapabilityGrant` is the only writer of `user_capabilities`**, and a third surface
  onto it is a screen change, not a security change — provided it is gated on `access.manage`. A
  surface gated on anything narrower is a privilege-escalation path, not a convenience.

---

## Amendments from the adversarial review (2026-08-11)

Four security findings, worked in order; F1's fix largely closed F3. Suite left at **1421**
PHPUnit tests (1396 at the review's baseline; +8 `InvitationPositionEscalationTest`, +8
`RosterPositionEscalationTest`, +9 `BulkResendAuthorizationTest`), 6517 assertions. Vitest 192,
`npm run test:e2e` 22 passed, `npm run build` green.

**F1 (CRITICAL) — an invitation was authorized against a column nothing downstream consults.**
Every endpoint checked `invitations.position`; `InvitationAcceptController` takes the
`person_id !== null` branch for every row this system mints and that branch does not write
`position`, so the account resolves its capabilities from `people.position`. The two diverge with
no misuse at any step (invite at 4 → correct the roster row to 0 → the live invitation still reads
4 → a Chief Resident may target 4). Fixed at `InvitationIssue::issue()`, the one writer, beside the
supersede loop and before the transaction opens; a no-op on `store()`'s create branch, which opens
the person at exactly `$position`. **Red first:** four legs failed with 302 where 403 was expected,
and `test_a_redeemed_account_resolves_capabilities_from_the_roster_not_the_invitation` **passed on
the unfixed tree** — the escalation as a measured fact rather than an inference. The plan's premise
that `InvitationStatus::mayInvite()` and the write side agreed (Decision B's D9 note) held for the
offer only. Ruling 34.

**F2 (IMPORTANT) — Decision F's stated premise was already false.** The decision gates the roles
panel on `access.manage` "because hanging a role control off the roster gate would create an
escalation path"; the path existed one field to the left, since `PersonRequest::POSITIONS` offers 0
and position 0 carries `access.manage` by role default. Gated in `PositionChange::write()` — the
one definition, because a FormRequest rule would not reach `RosterImport`, whose position column
resolves by NAME against `positions` from a `cap:people.manage` route. `applyWithoutAudit()` now
takes the actor positionally and non-optionally. **This changes People-screen behaviour**: the two
role selects offer `grantable_positions` plus the row's own current position. The gate is on the
TRANSITION, so a sitting Administrator's row stays editable from the roster console. Ruling 35.

**F3 (IMPORTANT) — the bulk gate iterated a list that is routinely empty.** `bulkPreview()` and
`bulkResend()` are `auth`-only by design (the two-tier rule is position-dependent and applied
in-controller), and that design is only sound while the in-controller pass is guaranteed to assert
something. `positionsToAuthorize()` returned live-invitation positions alone, so a cohort that had
all claimed — or was never invited — authorized nothing and any authenticated account received the
plan. Now a union with every selected person's `people.position`, which is also the column the
writer authorizes against since F1. Ruling 36.

**F4 (IMPORTANT) — the pre-authorization pass did not mirror the writer's supersede set.**
`InvitationIssue::supersededBy()` is now the one definition of it. The red run stated the finding
in as many words: the confirm 403s on the address-carry-over fixture, and `audit_log` is **empty** —
the `user_scope_denied` row written from inside `commit()`'s transaction rolled back with it.
Building that fixture took one correction: `people.email` is UNIQUE and `issue()` supersedes by
address, so the collision can only arise AFTER both invitations exist — a consultant invited, moving
mailbox, and a resident's address later corrected onto the freed one (Decision G). Ruling 37.

**What the review got wrong, in one place.** Finding F1's step 3 says the stale invitation is shown
to a Chief Resident by `statusList()` "filtering on `mayTarget(viewer, invitation.position)`". That
is true of Admin → Users' invitation list, and it remains true — but the People screen, which the
finding's own reproduction walks through, already scopes each row on `people.position`
(`InvitationStatus::forPeople()`), so it hides the row correctly. The disclosure that survives is
one invitation row's existence and expiry on a different screen, not an escalation: every write path
now refuses. Left as-is rather than widened into scope creep, and recorded here so it is not
rediscovered as new.

### The remaining six (2026-08-11, same review)

Suite **1421 → 1429** PHPUnit (+2 `AccountUnbindTest`, +2
`MailSendingRoutesAreThrottledTest`, +2 `InvitationResendTest`, +2 rewritten in place with no
count change), 6588 assertions. Vitest 192, e2e 22, `npm run build` green.

**F5 (IMPORTANT) — the detail panel and the picker disagreed about which accounts exist.**
`index()`'s picker inner-joins `people`; `selectedUser()` resolved `?user_id=N` through a raw
`User::find()` and projected `(int) $user->position`, which is **0 — the Administrator id** — once
`AccountUnbind` has nulled the link, because `position` is a read-through accessor. The panel then
titled itself with the login name (`full_name || member_name`) and `AccessControl.vue:216` rendered
`positionName(0)` as **"Administrator"**. Every other read of that link fails toward a blank; this
one failed toward the top of the ladder, on the screen whose whole subject is privilege. Fixed with
`whereNotNull('person_id')`, stated as one predicate on both sides. Returning null rather than
throwing is deliberate — already this method's answer for an unknown or array-shaped `user_id`, so
no Vue change was needed. **Red first:** the failure printed the defect verbatim
(`'full_name' => null, … 'position' => 0`). Ruling 38.

**The fifth path the search turned up, and it is a WRITE.**
`UserManagementController::updateProfile()` writes `member_name` on `users` and
`full_name`/`member_email` through `$user->person?->update(...)`. On an unbound account that
null-safe call is a **no-op that raises nothing**: two of three submitted corrections evaporated, a
`user_profile_update` audit row was appended, and the screen flashed "Account details updated."
Refused now, mirroring its sibling `setPosition()`, which already refuses an unbound account by
name. Ruling 39. The full survey of that link — every read that resolves a name, position or email
through `users.person_id` — is summarised under F5 in the report; the remainder are either blanks
(`invited_by` → "—", `PersonPresenter::history()`'s `by`, `printed_by`) or are unreachable because
an unbound account is inactive by construction and cannot authenticate.

**F6 (IMPORTANT) — two mailing endpoints carried no throttle, and the comment said otherwise.**
`admin.invitations.store` and `admin.invitations.resend` both end in `Mail::to(...)`, both sat in
an `auth`-only group with no bound at all, while `bulk-resend`'s comment three lines away named
`admin.settings.test-email` as "the only other endpoint in this application that sends on a button
press". Both now `throttle:6,1`, and the property is **derived rather than asserted**:
`MailSendingRoutesAreThrottledTest` walks the router and follows one level of same-class call, so
a new mailing endpoint is covered the day it registers. That indirection is the whole design —
`store()` contains no `Mail::` at all, so a handler-body scan would have been green on this exact
defect, and a whole-file scan would have named `bulkPreview()`/`revoke()` and bought an allow-list
to excuse them. A behavioural case proves the bound refuses **and** pins its shape: Laravel keys an
authenticated request's throttle signature on the user id alone, so all three sending endpoints
draw on ONE bucket of six a minute per operator. Ruling 40.

**F7 (MINOR) — one of four refusals landed on a key no screen was reading.** The
"somebody claimed this address through another door" refusal was keyed `member_email`, because a
Validator names its errors after the field it was handed and the field this shared rule
(`Person::accountEmailRule()`) is normally handed is the invite form's address input. Admin →
People renders **exactly one** key, `errors.invitation`, and says so in its own props docblock — so
the per-person Resend button flashed nothing at all. The key moves; the predicate does not. **The
review named the wrong screen**: Admin → Users loops the whole bag and did render it, and the two
screens disagreeing is what let this survive review. Ruling 41.

**F8 (MINOR) — three guards could not see three ways of writing a row.** Property assignment then
`->save()`, four of five relation-write spellings, and `find()` then `destroy()`/`delete()`. Every
new needle was proved by planting a writer of exactly its shape, watching the guard name the file,
and reverting — plus a negative control per guard proving a plain READ still passes, which is what
keeps the `= ` trailing space load-bearing rather than decorative. Two needles were **tried and
withdrawn on measurement**: `User::find(` completes the third shape on paper but named four files
on this tree, three of them auth controllers resolving the session's own pending user and one that
merely QUOTES the expression in a comment about an unrelated defect. The remaining gap
(`$model->delete()` on a bound instance, invisible to any substring) is now stated in each docblock
rather than implied away.

**F9 (MINOR) — the prose was wrong and the code was right.** All three claimed
`database/factories/` was not scanned; `base_path('database')` contains it, and the two established
siblings (`PersonLevelsHaveOneWriterTest`, `RotaWritersAreSingularTest`) both scan factories and
NAME the offending factory in the allow-list. Scanning is the better discipline: a factory writing
the column directly IS a second writer of this shape, merely one whose blast radius stops at the
suite, and naming it costs a line where excluding the directory exempts every future factory
invisibly. It mattered most on the capability guard, where `database/` also holds
`AccessControlSeeder`, which runs in **production**. Proved by planting a factory under each guard.

**F10 (MINOR) — the two-doors test never opened the second door.** Both halves posted to
`/admin/access-control/person`. Every case now goes through both endpoints and the answers are
compared as values — status, the `overrides`-shaped error keys, and the rows each actually wrote —
with a fourth case for a well-formed grant, because two doors that both refuse everything also
"agree". Proved by planting three divergences in `updatePerson()` alone (a narrowed effect rule, a
dropped payload, a rewritten effect) so that each part of the projection is shown to be
load-bearing. One incidental finding: `session('errors')` answers with the store's raw pre-start
array rather than a `ViewErrorBag`, on which `->getBag()` is a fatal — the test reads it the way
`TestResponse` does, session start included.

**What the review got wrong, in this batch.** F7 says the silent screen is Admin → Users. It is
Admin → People. Users.vue renders `v-for="(message, key) in errors"` over the whole bag, so
`member_email` surfaced there; People.vue reads `errors.invitation` alone. The defect is real and
the fix is the same one, but the reproduction as written would not have shown it. F9's premise —
that the guards contradict their docblocks — is right, and the direction it left open resolves
against the prose rather than the code.
