> ## OWNER DECISIONS, 2026-08-08 — READ BEFORE ANY TASK
>
> Three open items from the first draft are now settled. Two overrode the drafter's
> recommendation; both are implemented as decided, with the reasoning recorded rather than
> re-argued.
>
> **1. Drop `users.full_name` and `users.position` — CONFIRMED as planned (Task 3).**
> Keep the two-commit split so rolling back the drop does not roll back the move, and keep
> the runbook's mandated dump-first plus single permitted rollback order.
>
> **2. Unify onto `people.email` — OVERRIDES the draft.** The plan's dual-column
> `users.member_email` + `people.email` sync is CANCELLED. There is to be one email column,
> on `people`.
>
> *Consequence, stated plainly:* Laravel's password broker resolves users by
> `User::where('member_email', …)`, so unifying requires overriding retrieval to join through
> `person_id` — reintroducing exactly the provider-level indirection the D3 reversal removed,
> on the credential path reconnaissance showed is easiest to get wrong. The reset broker
> already bypasses the provider once.
>
> **Therefore this is a hard requirement, not a nicety:** the task that unifies email must
> ship tests proving, end to end through the real HTTP kernel, that (a) password reset resolves
> the right account through the join, (b) a person with **no** `users` row cannot obtain a
> reset link, verification link, or OTP by any path, and (c) `routeNotificationForMail()` and
> `getEmailForPasswordReset()` both follow the link. Do not mark that task done on unit tests
> alone.
>
> **3. Encrypt neither `people.notes` nor `people.constraints` — OVERRIDES the draft.**
> Both stay plaintext. Note in `docs/COMPLIANCE.md` that free-text staff notes are stored in
> the clear and therefore appear in backups, so the choice is visible to an auditor rather
> than implicit. `constraints` stays `json` and queryable, as the solver needs.

# P0c — Identity & Auth Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Separate *the person* from *the account*. A new `people` table holds the departmental
roster; `users` stays purely the authentication record and gains a `person_id` link. A person
who is only on the roster has **no `users` row and therefore cannot authenticate by
construction** — no new gate on any credential path, and all six existing `active` defences
keep working untouched.

**Architecture:** `people` becomes the single definition of who someone *is* — full name, rota
short name, job position, training level (effective-dated), contact details, scheduling
constraints, `external` flag, and an `active` flag that governs whether they may be *named*.
`users` keeps `member_name`, `member_email`, `password`, `active`, the 2FA columns and the
signature — everything that governs whether they may *authenticate*. `handover_signoffs`' four
named-role FKs (`endorsed_by`, `endorsed_to`, `consultant_by`, `consultant_to`) move to
`person_id`; `signed_off_by_user_id` and `reopened_by_user_id` stay on `users`, because those
are *actors*, not names of record. `SignatureStore` stays keyed on `users`: that is exactly what
keeps **naming separate from signing** — a consultant can be named without an account, but
signing requires one.

**Tech Stack:** Laravel 13, Eloquent, PHPUnit (SQLite in-memory), Inertia + Vue 3, Vitest.

**Scope:** Third of four P0 plans (design doc §13). P0a (units as configuration) and P0b
(bounded custom fields) are merged. P0d is tenancy & provisioning. This plan ships working
software on its own: behaviour is unchanged for every existing account, and the roster becomes
expressible.

**Owner decision this implements — D3, REVERSED 2026-08-08.** The design doc's §5.1/§5.2
`person_status` machinery is **obsolete and must not be built**. The header block at
`docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md:244-276` is the
authoritative statement of the replacement shape, and it is what this plan implements. **D9
survives** and is implemented in Task 6.

---

## Amendments made during execution

**Task 2, 2026-08-08 — three sites the plan's enumeration missed, all found by grep + empirical
test runs, not by inspection alone:**

1. **`app/Console/Commands/LegacyImport.php::importUsers()` had to move onto `people` inside
   Task 2, not Task 7.** It writes `users` via a raw query-builder upsert that never set
   `person_id`. That's harmless while `users.full_name`/`users.position` still exist, but Task
   2's own read-through accessors need `person_id` to resolve `$user->position`/`->full_name` —
   so as soon as the accessors landed, `LegacyImportTest::test_users_import_with_verbatim_hashes`
   went red (`$user->position` resolved to null), independently of Task 3's drop. Fixed by
   pulling Task 7 Step 2's already-specified `importUsers()` rewrite (match-or-create a `person`
   per legacy member, matched by existing `member_name` → `person_id` for idempotence, falling
   back to `Person::matchByEmail()`) forward into this commit. Task 7 still owns
   `importSignoffsFor()`'s person-id resolution and its own dedicated test coverage
   (idempotence/matching assertions) — only the minimum needed to keep this commit green shipped
   here.
2. **A read-through `Attribute` accessor is not satisfied by a narrower SELECT that omits
   `person_id`, even when the query correctly joins `people`.** `$user->full_name`/`->position`
   always run the accessor (`$this->person?->full_name`), which needs `person_id` loaded to
   resolve the relation — a `select('people.full_name', ...)` without `person_id` populates the
   raw attribute with the *correct* value and then the accessor throws it away and returns null.
   Caught empirically (a throwaway test), not by the plan. Three live sites, none of them
   caught by the existing suite because nothing asserted on the actual name/position text
   returned:
   - `AccessControlController::index()`'s `users` picker — plan's own specified code
     (`select('users.id', 'users.member_name', 'people.full_name', 'people.position')`) had this
     bug; changed to `select('users.*')` + explicit `->map()`.
   - `EndorsementController::staffPickers()` — `get(['id', 'full_name'])` had the same bug;
     joined `people` and switched to `select('users.*')`, preserving current behaviour exactly
     (both endorsers and consultants still require an account — the D9 split stays Task 6's).
   - `InvitationController::openInvitations()` — `->with('invitedBy:id,full_name')` had the same
     bug; changed to `->with('invitedBy:id,person_id')`.
3. **`Eloquent\Builder::value()` does `first([$column])`, a narrow select with the identical
   problem** — `tests/Feature/Console/CreateAdminCommandTest.php:96` used
   `User::where(...)->value('position')` and got null for the same reason. Fixed the test to read
   through a full model fetch (`->firstOrFail()->position`) instead; no application code used
   this pattern.

`EndorsementController::pickerRule()` was deliberately left untouched in Task 2 — its raw
`whereIn('position', …)` still works today (the real column exists until Task 3) and it isn't
read through an accessor, so it wasn't broken by Task 2. It has to change before Task 3 drops
the column; that happens as part of Task 3, immediately before the drop, using the same
`people`-join shape and preserving current behaviour (not D9's split).

**Task 3, 2026-08-08 — two more findings, both caught by actually running the migration rather
than by reading it:**

1. **`pickerRule()` fixed as flagged above** — rewritten to a correlated `whereIn('person_id', …)`
   subquery against `people`, with `whereNull('deleted_at')` on both tables per finding 8,
   immediately before the drop landed, so the tree never had a commit where it referenced a
   dropped column.
2. **SQLite refuses to drop `users.position` while its index still exists.** The plan's `up()`
   was `$table->dropColumn(['full_name', 'position'])` verbatim; running it threw `error in
   index users_position_index after drop column: no such column: "position"`. SQLite rebuilds
   the whole table for a column drop and does not discover on its own that an index on that
   column must go first. Fixed by adding `$table->dropIndex(['position'])` before
   `dropColumn()`. Harmless on MySQL/Postgres, required on SQLite (the test suite's engine).
3. **`NameAndRoleOfRecordTest::test_the_accessor_wins_over_a_stale_column`** (Task 2) simulated a
   stale raw column via `DB::table('users')->update(['full_name' => 'STALE', ...])` — a query
   that is now itself invalid, since the column doesn't exist. Rewritten to
   `test_full_name_and_position_are_not_columns_on_users()`, asserting the stronger fact
   directly (mirroring `RosterOnlyCannotAuthenticateTest`'s structural check on `people`).

**Task 4, 2026-08-08 — no deviations.** Implemented as specified: `levels` + `person_levels`,
`Person::levelAt()`/`currentLevel()`, no seeded rows, no `people.level_id`. `LevelHistoryTest`
(9 cases, covering both inclusive boundary dates, the unique `(person_id, effective_from)` pair,
`restrictOnDelete` on a level with history, and `Level::code` uniqueness) went red on
`Class "App\Models\Level" not found`, then green after the migration/models landed. Full suite
564 (up from 555).

**Task 5, 2026-08-08 — implemented as specified; the backfill's correctness was verified by hand,
per the plan's own admission that `RefreshDatabase` starts empty and so the automated suite can
only prove the *rule*, not that the migration resolves real divergent ids correctly end to end:**

`SignoffPersonBackfillTest` re-runs the migration's literal SQL against rows inserted through the
query builder and asserts the resolved `*_person_id` is the linked person, not the raw
`*_user_id` integer (4 cases, including a null-safety case and a same-account-in-two-role-columns
case). That is a real, valuable regression guard, but it exercises the *statements*, not the
*migration file* — so it would not have caught a bug in how `Schema::table()`'s column placement
or `nullOnDelete()` interacts with a live SQLite rebuild.

To close that gap, the migration was run for real, end to end, against a throwaway SQLite file
built to look like a database that had been live for a while:

1. Ran every migration through `2026_08_10_120003` against a scratch `.sqlite` file (the Task 5
   migration was temporarily moved out of `database/migrations/` so it stayed pending).
2. Seeded 5 roster-only `people` rows first (ids 1-5, no account — ordinary in production: a
   department roster entered before every consultant has logged in), then 3 accounts each
   explicitly linked to a person created *after* those five. Result: `users` ids 1, 2, 3 map to
   `people` ids 6, 7, 8 — a constant +5 offset, chosen so a copy-instead-of-join bug would be
   immediately visible (every `*_person_id` would read 1/2/3 instead of 6/7/8).
3. Inserted 3 `handover_signoffs` rows the *old* way (`*_user_id` only, exactly what every row in
   production looks like today) covering: all four roles filled by three different accounts; only
   one role filled (the other three must resolve to `NULL`, not to person 1); and the same account
   named in two different role columns on one row (each column must resolve independently).
4. Moved the migration back into `database/migrations/` and ran `php artisan migrate` again — this
   time it actually executed, for real, against the seeded data.
5. Queried the resulting `handover_signoffs` rows directly and compared each `*_person_id` against
   the correct answer (looked up fresh via `users.person_id`) and against what a copy bug would
   have produced.

**Observed:** every non-null role resolved to the correct divergent person id (6, 7, or 8 — never
1, 2, or 3), every null `*_user_id` backfilled to a null `*_person_id`, and the row naming the same
account twice resolved both columns to the same person id independently. No mismatches. The
scratch database and seeding/inspection scripts were throwaway (deleted after the run, per the
scratchpad convention) and are not part of the commit — the reasoning and result are recorded here
instead. Full suite 568 (up from 564).

**Task 6, 2026-08-08 — implemented as specified; four gaps found in the plan's own file list and
one construction in its test guidance that does not reach the branch it names:**

1. **`tests/Feature/SignatureTest.php` was missing from the plan's Step 7 file list.** It submits
   `endorsed_by_user_id` in two request payloads (`test_signing_freezes_the_signature_and_the_print_sheet_shows_it`,
   `test_removing_a_signature_leaves_already_signed_sheets_intact`) and would have 422'd the moment
   the wire rename landed. Found by grep before editing, not by a failing run. Both updated to
   `endorsed_by_person_id => $resident->person_id`.
2. **`show()` needed a real refactor, not the one-line swap the plan's Step 4 implies.** The plan
   says `'staff' => $this->staffPickers($signoffRow)` "where `$signoffRow` is the `HandoverSignoff`
   the method already resolves for `signoffPayload()`" — but `show()` never held that row;
   `signoffPayload()` queried it internally and privately. Fixed by having `show()` query it once
   and threading it through: `signoffPayload()` gained a fourth, optional `?HandoverSignoff $signoff`
   parameter (defaulting to its own query, so `print()`'s existing call site needed no change), and
   `staffPickers()` takes the same row so a stored-but-no-longer-offered id still renders (finding 9).
3. **The two new `SignatureAttributionTest` cases the plan asks for (Step 7 bullet) needed real
   construction, not the sketch in the plan.** Case (a) — a roster-only consultant is accepted,
   named, and leaves no signature path — is tested by asserting the signature-path keys are absent
   from `$s->getAttributes()` altogether (the schema has never had `consultant_*_signature_path`
   columns; asserting a plain `null` would pass even if the column had been added by mistake and
   left unset). Case (b) — the `unclaimed` provenance token — the plan suggests constructing it "by
   deleting the account between draft and sign" over two HTTP requests. Traced through
   `updateSignoff()`: the freeze loop only calls `resolveSignature()` for a field present in *that*
   request's payload, and D9's validation re-checks the predicate on every request that names the
   field — so a second request that re-submits the now-unclaimed id is refused before
   `resolveSignature()` ever runs, and a second request that omits the field never calls it at all.
   There is no two-request HTTP path that reaches this branch — which is exactly what the method's
   own docblock already says ("D9's rule already refuses an unclaimed endorser at validation").
   Tested instead via `ReflectionMethod` on the private method directly, which is the first use of
   reflection in this suite; noted in the test's own docblock so a future reader does not go looking
   for an HTTP construction that does not exist.
4. **`AuditHardeningTest`'s two D9 cases needed roster-only fixtures the plan's prose names but
   doesn't spell out.** Added `Person::factory()->create(['position' => 4, ...])` (no account) as a
   third refused case alongside the existing consultant/deactivated ones in
   `test_signoff_refuses_an_endorser_who_is_not_an_active_resident_or_chief`, and a new
   `test_signoff_accepts_a_roster_only_consultant_who_has_no_account` mirroring it for the
   consultant side — both fields of the D9 split now have a dedicated audit-hardening regression,
   not just the parity matrix.
5. **`docs/spec/` has no page documenting the signature-provenance tokens** (`self`/`proxy`/
   `withheld`/`none`/`draft`, now plus `unclaimed`) — the plan's Step 5 says to add `unclaimed` to
   "whatever `docs/spec/` slice lists" them; `grep -rn "withheld" docs/spec` returns nothing. There
   is nothing to update; the token set lives only in `resolveSignature()`'s own docblock, which
   already carries the addition.

`PickerParityTest` passed as specified on the first real run (167 assertions across 13 fixtures ×
4 fields), needing no changes from the plan's own test body. Full suite 572 (up from 568): +1
`PickerParityTest`, +1 `SignatureAttributionTest` (roster-only consultant), +1
`SignatureAttributionTest` (unclaimed via reflection), +1 `AuditHardeningTest` (roster-only
consultant accepted) — the `EndorsementSignoff` Vitest file's own new retired-option case is
JS-side and does not count against the PHPUnit total. JS suite 102 (up from 101, +1 retired-option
render case); `npm run build` green.

**Carried-forward fix, 2026-08-08 (landed as its own commit before Task 7) — `staffPickers()`'s
`$keep` argument only surfaced ONE retired endorser per field pair.** `$keep` was
`endorsed_by_person_id ?? endorsed_to_person_id` — when endorsed-by and endorsed-to were two
DIFFERENT people who had both stopped being offered, only the first reappeared as a disabled
option; the second rendered as a `<select>` with no matching `<option>`, and `Sheet.vue`'s next
save turns an unmatched stored id into `null` — silently clearing a named endorser on a signed
medico-legal record. Fixed by widening `SignoffPickers::offer()`'s `$keep` parameter from
`?int` to `list<int|null>` and passing every stored id per field pair
(`[$signoff?->endorsed_by_person_id, $signoff?->endorsed_to_person_id]`), each carried forward
independently. New regression test constructs exactly the two-distinct-retired-people case and
asserts both ids — not just one — appear flagged `retired` in the offered list.

**Task 7, 2026-08-08 — implemented as specified; no deviations found on review.** `importUsers()`
already had its match-or-create-a-person rewrite (pulled forward into Task 2, per that task's own
amendment). This task's remaining piece — `importSignoffsFor()`'s resolver — was rewritten exactly
as specified: `member_name → users → users.person_id` (never `member_name → people` directly,
since the legacy identity is the login handle, not the roster address), writing
`endorsed_by_person_id`/`endorsed_to_person_id` and leaving the frozen `*_user_id` columns unset.
Consultant fields stay name-only (documented as deliberate, not an omission). New coverage: one
linked `people` row per member, idempotence extended to `people` counts, matching onto an existing
roster-only person by email, and nurse rows (position 1) still importing nothing. Full suite 573
(up from 572, +1 carried-forward-fix case) then 576 after Task 7's own additions.

**Task 8, 2026-08-08 — five deviations, one of them a real bug found and fixed, not merely a plan
error:**

1. **The plan's own Task 8 prose still assumed finding 6's dual-column design** (`users.member_email`
   written independently of `people.email`) — written before the OWNER DECISIONS block overrode it.
   Implementing OWNER DECISION 2 instead of the stale prose meant: a read-through `memberEmail()`
   accessor on `User` (`$this->person?->email`) so `getEmailForPasswordReset()` and
   `routeNotificationForMail()` needed no code change to "follow the link" — they already read
   `$this->member_email`; `Person::accountEmailRule()`, a closure-based validation rule (one
   definition, used at `CreateAdmin`, `InvitationController::store()`, and
   `UserManagementController::assertStillUnique()`) replacing every live
   `Rule::unique('users','member_email')`, since that raw column stopped being trustworthy as a
   collision check the moment it stopped being independently written; and closure-credential
   resolution in `PasswordResetLinkController`/`NewPasswordController`
   (`'member_email' => function ($query) { $query->whereHas('person', ...) }`), which
   `EloquentUserProvider::retrieveByCredentials()` supports natively — preserving finding 1's "no
   custom user provider" property while still joining through `person_id`.
2. **A real, reachable bug found by this session's own QA pass, not by the plan:**
   `users.member_email` still physically carries its original UNIQUE index, but after OWNER
   DECISION 2 nothing keeps it in step with `people.email` once a profile edit moves the address on
   — the raw column freezes at whatever it held at account creation. `InvitationAcceptController::store()`
   and `UserManagementController::approve()` both still wrote it into every new account. Constructed
   the exploit: create an account, change its `people.email` via `PATCH /profile` (which correctly
   never touches the raw column), then invite the now-FREED address to a different person and redeem
   — the redemption 500'd on the stale column's own unique index despite `people.email` having no
   live collision at all. Proven with a dedicated regression test
   (`ClaimLifecycleTest::test_an_address_freed_by_a_profile_email_change_can_be_reinvited_without_a_raw_column_collision`,
   confirmed red — `500` instead of a redirect — before the fix), fixed by dropping the
   `'member_email'` key from both raw inserts, mirroring the precedent `CreateAdmin` already set.
   `LegacyImport`'s own raw upsert (Task 7, a one-time run against a fixed historical dataset keyed
   on `member_name`) was deliberately left untouched — it is not a live, ongoing write path and
   cannot self-collide the way two independently-timed live writes can.
3. **Session continuity:** this task's controller/model rewiring for OWNER DECISION 2 (the
   accessor, `Person::accountEmailRule()`, the broker closures, and `InvitationController::store()`'s
   match-or-create) was already implemented and sitting uncommitted when this session began, but
   `tests/Feature/Identity/ClaimLifecycleTest.php` (the plan's own Step 1 deliverable) did not yet
   exist, and `InvitationAcceptController::store()`'s redemption — Step 5, "claim, not insert" — was
   still the OLD unconditional-insert-a-new-person code pulled forward from Task 2. Writing
   `ClaimLifecycleTest` first (per the plan's 9 cases) surfaced this directly: with the old
   redemption code, `Person::count()` after redeeming an invitation issued through the real
   `POST /admin/invitations` → `POST /invitation/{token}` path was one higher than expected,
   because a second `people` row forked off the one `InvitationController::store()` had already
   matched-or-created at issue time. Fixed with the Step 5 rewrite (claim the invitation's
   `person_id`, restore-if-trashed, keep a rostered person's existing name, take the name only for a
   blank placeholder, guard against an issue-to-redeem collision, then `AccessControl::flush()`).
   Two of `ClaimLifecycleTest`'s own assertions initially failed too, but on inspection those were
   test bugs, not application bugs: `$before = Person::count()` was captured before the inline
   `$this->admin()` call that itself creates a linked person, off by exactly one. Fixed by hoisting
   the admin fixture out.
4. **The owner's hard requirement — end-to-end kernel tests for the email unification — is met by
   four new cases in `tests/Feature/Auth/PasswordResetTest.php`** (none of which existed before this
   session): the request leg and the completion leg each proven to resolve through the `person_id`
   join rather than the frozen raw column, by deliberately making the two disagree (hand-drifting
   `users.member_email` away from `people.email` and proving the CURRENT address resolves while the
   STALE one does not); `getEmailForPasswordReset()` proven via the `password_reset_tokens.email`
   value the broker actually persists; `routeNotificationForMail()` proven via
   `Notification::assertSentTo()`'s notifiable callback, triggered through the real
   `POST /forgot-password` route rather than called directly. The "person with no account" side (b)
   needed no new coverage beyond one added case, since `RosterOnlyCannotAuthenticateTest` (Task 1)
   already proved it structurally (route-model-binding 403 for verification links, session-key
   resolution for OTP/2FA) in a way the email-unification refactor could not have quietly broken
   without also breaking that file.
5. **Two items found but explicitly OUT OF SCOPE for Task 7/8, flagged rather than fixed:**
   `users.member_email` itself is not dropped — CLAUDE.md's additive-migration and
   owner-runs-production-migrations rules put that in a future, separate migration, not this task —
   so it survives as an unused, increasingly stale column still carrying its original UNIQUE index.
   Separately, and unrelated to email: `Person::casts()` (written in Task 1, before this session)
   still casts `notes` through `\App\Casts\EncryptedString::class`, and `docs/COMPLIANCE.md` has no
   mention of `notes` or `constraints` at all — both appear to directly contradict OWNER DECISION 3
   ("Encrypt neither `people.notes` nor `people.constraints`... Both stay plaintext. Note in
   `docs/COMPLIANCE.md`..."). This predates Task 7/8, is unrelated to identity/auth lifecycle, and
   was left untouched rather than silently changed under a different task's mandate — flagging for
   the owner.

   **RESOLVED**, both, after this session: `Person::casts()` no longer encrypts `notes` (commit
   `f9832d5`, "people.notes stays plaintext -- owner decision 3, not the draft's encrypted cast"),
   and `docs/COMPLIANCE.md` now documents `notes`/`constraints` (commit `6a61708`, "identity is two
   tables; correct the rules and the pack that said otherwise"). Left here as a record rather than
   deleted, so a reader does not go hunting a bug that no longer exists.

Full suite: 576 (Task 7 baseline) → 593 after `ClaimLifecycleTest` (13 cases) and
`PasswordResetTest`'s 4 new cases → 594 after the raw-column-collision regression test and fix.

---

## Ten findings from reconnaissance that shape this plan

Read these before any task. Each is a bug, a trap, or a whole task's worth of work that would
otherwise be discovered late.

1. **There is no authentication chokepoint, which is *why* the separate table wins.** This
   application never calls `Auth::attempt()` — grep for `Auth::login|loginUsingId|Auth::attempt|onceUsingId`
   across `app/ routes/ database/ config/` returns exactly one call site,
   `app/Support/Login.php:32`. So `EloquentUserProvider::validateCredentials()` is never
   invoked and a custom provider would gate nothing. Login reads
   `User::where('member_name', …)->first()` directly (`app/Http/Controllers/Auth/AuthenticatedSessionController.php:70`);
   the password-reset broker resolves by `member_email` through `retrieveByCredentials()` and
   bypasses everything else; remember-me resolves through `retrieveByToken()`, which compares
   `remember_token` **and nothing else**, and `AuthenticateSession` is not registered so the
   recaller's password segment is never validated. Keeping roster people in `users` would have
   needed a new predicate at six defence sites plus a gate on six credential paths. With a
   separate table, **all twelve disappear**: a roster-only person has no row in `users` for any
   of those queries to find.

2. **The `person_status` CHECK-constraint work is cancelled outright.** No nullable `password`,
   no nullable `member_name`, no `ALTER TABLE … ADD CONSTRAINT`, no driver-switched SQLite table
   rebuild, no CI `sqlite_version()` investigation. Recon report 3 §5.4 proved that whole path is
   fragile (a later `->change()` on `users` silently drops table-level CHECKs on SQLite, leaving
   production constrained and the suite green against a schema that no longer has the invariant
   it exists to prove). None of it is needed now. **If you find yourself writing a CHECK
   constraint in P0c, you are building the reversed design.**

3. **`people.id` and `users.id` are independent autoincrement sequences — this is the sharpest
   new risk in the whole plan.** Person 7 and user 7 are different humans. Every place that
   passes an id from one space to the other silently names the wrong person, with no error and no
   FK violation (both ids are valid in their own table). Two consequences that are non-negotiable:
   - The `handover_signoffs` backfill must **join through `users.person_id`**, never copy the
     integer across (Task 5).
   - The wire contract renames from `endorsed_by_user_id` to `endorsed_by_person_id` (Task 6).
     Leaving a field named `*_user_id` while it holds a person id is precisely how this bug
     ships.

4. **Six risks the old design carried evaporate by construction, and the plan should not spend
   effort on them.** With no roster rows in `users`: the password-reset escalation
   (recon 0 §B8) is gone — the broker cannot find a person; capability resolution cannot grant
   anything to a non-account (`app/Support/AccessControl.php:141-148` keys off a `users` row);
   `isLastActiveAdministrator()` (`app/Http/Controllers/Admin/UserManagementController.php:365-376`)
   cannot be fooled by a phantom position-0 roster row; `ReportDormantAccounts`
   (`app/Console/Commands/ReportDormantAccounts.php:41-48`) still sees only accounts, so the
   leaver control keeps working; `Admin → Access Control`'s "ship the whole roster to the client"
   (`app/Http/Controllers/Admin/AccessControlController.php:56-58`) still ships only accounts;
   and `InvitationAcceptController`'s unconditional `INSERT` can no longer collide with a roster
   row on `users.member_email`. Record this in the docs task; do not build mitigations for it.

5. **`full_name` and `position` cannot live in two places.** Capability resolution reads
   `$user->position`; the D9 pickers must read `people.position` (a roster-only consultant has
   no account). Two copies of the same fact is exactly the duplication CLAUDE.md blames for the
   audit-chain false alarm. Both columns therefore move to `people`, with read-through
   accessors on `User` so every PHP read (`$user->full_name`, `$user->position`) keeps working
   untouched. Only the **eight SQL-level sites** need editing:
   `app/Support/AccessControl.php:102,105,109`;
   `app/Http/Controllers/Admin/UserManagementController.php:72,73,373`;
   `app/Http/Controllers/Admin/AccessControlController.php:57,58`;
   `app/Http/Controllers/EndorsementController.php:1149,1160,1162` (rewritten wholesale in Task 6).

6. **`users.member_email` must stay on `users`, and it is the one deliberate denormalization.**
   Laravel's password broker does `User::where('member_email', $x)` inside
   `EloquentUserProvider::retrieveByCredentials()`; removing the column would break
   `/forgot-password` and force the custom provider this design exists to avoid. So `people.email`
   (the roster/contact address, and the roster-import matching key) and `users.member_email` (the
   account address) both exist. They are written together at exactly three sites — invitation
   claim, `ProfileController::update()`, `UserManagementController::updateProfile()` — each with
   its own test. Say this out loud in the code, or someone will "tidy" one of them away.

7. **`staffPickers()` shares one closure between the endorser and consultant lists**
   (`app/Http/Controllers/EndorsementController.php:1155-1172`). Adding a predicate inside
   `$byPositions` applies it to consultants too, which is the exact opposite of D9. The closure
   must be split *before* any per-field predicate is added, and offer and validation must be
   generated from **one** predicate definition or they will drift again — the 2026-07-26
   invariant, now per field.

8. **`Rule::exists` runs on the raw query builder and never sees the `SoftDeletes` global
   scope.** `pickerRule()` writes `whereNull('deleted_at')` explicitly for that reason
   (`EndorsementController.php:1151`). The new `people`-backed rules need
   `whereNull('people.deleted_at')` **and** `whereNull('users.deleted_at')` inside the
   correlated account subquery. A missing one is invisible until a soft-deleted account is
   accepted as an endorser.

9. **`Sheet.vue` resubmits all four ids on every save** (`resources/js/Pages/Endorsement/Sheet.vue:222-230`),
   and a stored id with no matching `<option>` renders as a blank `<select>` whose next submit
   sends `null` — **silently clearing a recorded endorser on an unsigned day**. Deactivating a
   *person* is a new way to reach that state. Task 6 has the server append the stored person to
   the offered list flagged `retired: true`, rendered as a disabled option.

10. **`resolveSignature()` treats a null actor as an Administrator.**
    `EndorsementController.php:1032` is `in_array((int) $actor?->position, self::SIGNATURE_PROXY_POSITIONS, true)`
    and `(int) null === 0` — position 0. Unreachable today (the route carries `auth`), but it is a
    null-coalescing footgun sitting on the signature-forgery path, and Task 6 opens that function
    anyway.

---

## Where the design doc is wrong, and what this plan does instead

The design doc is a decision record, not a description of the code. It has been wrong twice
already. These are the discrepancies the reconnaissance found; each is resolved here and must be
corrected in the doc itself in Task 9.

| Design doc says | Reality / this plan |
|---|---|
| §5.1: `short_name` is "unique per institution" | `institution_id` is **nullable** on `users` today and would be on `people`, and a UNIQUE index treats NULLs as distinct on **both** MySQL/InnoDB and SQLite — so `UNIQUE(institution_id, short_name)` is toothless for exactly the bootstrap and fixture rows. D11 makes one database = one customer, so this plan makes `short_name` **UNIQUE outright**. Honest and enforceable. |
| §5.1: `level_id` on the person, "history in `user_levels`" | Two definitions of "current level" drift. This plan stores **history only**, in `person_levels` (effective-dated per LV-04), and resolves the current level through `Person::levelAt()`. There is no `people.level_id`. |
| §5.1 lists a `status` column | The claim lifecycle is now *structural* — a person is claimed iff a `users` row links to them. A second status enum would recreate the six-defence-sites problem the reversal exists to avoid. `people.active` is the only state flag, and it governs **naming**; `users.active` governs **authenticating**. |
| §5.2.4 "Roster import must match onto existing people by email, never create duplicates" — framed as an import concern | It is equally an **invitation** concern. `InvitationController.php:42` validates `Rule::unique('users','member_email')->withoutTrashed()`, which after a roster import refuses to invite exactly the people it exists to invite. Task 8 rewrites it to "not already an *account*", and issuing matches onto the existing person. |
| §5.2.5 "Three overlapping state machines … must be reconciled" | Recon report 1 §2.3 found `pending_registrations` has **no writer at all** — `routes/auth.php:39` binds `GET /register` to `RegisteredUserController::closed()`, and `EmailVerificationController::sendRegistrationLink()` is called by nothing in `app/`. It is a frozen legacy queue, not a live machine. Task 8 makes its approval path terminate in the same place as invitations (a person + a linked account) and **defers deleting the queue** until the owner confirms the production count is zero. |
| §12 test list names "the `person_status` CHECK constraints" | Obsolete (finding 2). Replaced by the structural guard in Task 1: `people` carries **no credential column**, asserted by name. |
| §5.3 "Every scope is bounded by institution, unit eligibility, position/level and `active`" | Unit eligibility and level scoping do not exist yet — `units` gained capability flags only in the design, and `levels` is created empty by this plan. Institution bounding is **not** applied here: `institution_id` is nullable and `UserFactory` defaults it to null, so an unconditional `where('institution_id', $actor->institution_id)` would break every existing fixture, and D11 makes the database the isolation boundary anyway. The pickers scope by **position + `people.active` + soft-delete + (endorsers only) an active account**. Record the deferral; do not half-build it. |
| §5.2.2 (already corrected once in the doc) "a custom user provider" | Still worth restating in Task 9: no custom provider is written, and none is needed. The doc's corrected text says so; the surrounding §5.2 text does not. |

---

## Migration ordering and the production risk

**Production has three unrun migrations and has never gone live:**
`2026_08_08_120001_add_configuration_to_units`, `2026_08_09_120001_create_unit_field_definitions_table`,
`2026_08_09_120002_add_extra_fields_to_handovers`. P0c's five migrations use the
`2026_08_10_*` prefix so they sort strictly after all three, and the owner's next
`php artisan migrate` runs all eight in one pass:

```
2026_08_08_120001_add_configuration_to_units              (P0a — units backfill)
2026_08_09_120001_create_unit_field_definitions_table     (P0b)
2026_08_09_120002_add_extra_fields_to_handovers           (P0b)
2026_08_10_120001_create_people_and_link_users            (P0c Task 1 — DATA MIGRATION)
2026_08_10_120002_create_levels_and_person_levels         (P0c Task 4)
2026_08_10_120003_move_name_and_position_off_users        (P0c Task 3 — DATA MIGRATION, DESTRUCTIVE)
2026_08_10_120004_add_person_ids_to_handover_signoffs     (P0c Task 5 — DATA MIGRATION)
2026_08_10_120005_add_person_id_to_invitations            (P0c Task 8)
```

Three of the five carry data migrations, and one is destructive.

- **`120001` is the load-bearing one.** It creates one `people` row per existing `users` row
  (including soft-deleted ones — a trashed account's person is still the name of record on every
  sheet they signed) and sets `users.person_id`. If it runs and the backfill silently does
  nothing, every subsequent task reads NULL. Task 1 Step 8 records the production verification
  query.
- **`120003` drops `users.full_name` and `users.position`.** It is the only irreversible-in-
  practice step in P0c. `down()` re-adds both columns and copies the values back from `people`,
  so it *is* reversible while the `people` rows exist — but a `down()` that runs after `120001`'s
  `down()` has dropped `people` recovers nothing. **The runbook step (Task 3 Step 6) requires a
  database dump before `migrate` and states the recovery order explicitly.**
- **`120004`'s backfill must JOIN, never copy** (finding 3):
  `endorsed_by_person_id = (select users.person_id from users where users.id = handover_signoffs.endorsed_by_user_id)`.
  A `SET endorsed_by_person_id = endorsed_by_user_id` would rename every clinician on every
  imported sheet, undetectably.
- Every backfill in this plan runs **before** any dependent read and inside the same migration
  that adds the column, so there is no window in which a deployed tree reads a half-populated
  column.
- The owner runs production migrations (CLAUDE.md). Task 9 adds the verification queries to
  `docs/RUNBOOK-DEPLOY.md`.

---

### Task 1: `people`, the link, and the proof that a roster-only person cannot authenticate

**Files:**
- Create: `database/migrations/2026_08_10_120001_create_people_and_link_users.php`
- Create: `app/Models/Person.php`
- Create: `database/factories/PersonFactory.php`
- Modify: `app/Models/User.php` (add the `person()` relation)
- Modify: `database/factories/UserFactory.php` (create and mirror onto a linked person)
- Test: `tests/Feature/Identity/PersonRosterTest.php`
- Test: `tests/Feature/Auth/RosterOnlyCannotAuthenticateTest.php`

This task is inert: nothing reads `people` yet except its own tests. The tree is deployable and
every existing test still passes.

- [ ] **Step 1: Write the failing structural test**

Create `tests/Feature/Auth/RosterOnlyCannotAuthenticateTest.php`. **This is the security
deliverable of P0c.** It enumerates every credential path recon report 0 found (§B1–B10) and
proves the roster-only person is refused on each. Under the reversed D3 most of them are refused
*structurally* — the point of the test is that a future migration or refactor which reintroduces
the coupling turns them red.

```php
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
```

- [ ] **Step 2: Run it and watch it fail**

```powershell
php artisan test --filter RosterOnlyCannotAuthenticateTest | Select-Object -Last 20
```

Expected: FAIL — `Class "App\Models\Person" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_10_120001_create_people_and_link_users.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D3 (REVERSED 2026-08-08) — the roster becomes its own table.
 *
 * `people` is who someone IS: the name of record on a handover sheet, the row a rota assignment
 * points at, the person a duty-hours report counts. `users` stays what it has always been: an
 * authentication record. The link is one-to-at-most-one (`users.person_id` UNIQUE).
 *
 * The whole security argument rests on one property of this schema: THERE IS NO CREDENTIAL ON
 * `people`. No password, no login handle, no remember token, no second factor. A person who has
 * never claimed an account has no row in `users`, so `AuthenticatedSessionController`'s lookup by
 * `member_name`, the password broker's lookup by `member_email`, and
 * `EloquentUserProvider::retrieveById/retrieveByToken` all find nothing — with no new gate
 * anywhere and all six existing `active` defences untouched. Do not add a credential column here.
 *
 * BACKFILL: every existing `users` row is, by definition, a claimed account, so each gets exactly
 * one person carrying its name, position, address and institution. Soft-deleted accounts are
 * INCLUDED — a trashed account's person is still the name of record on every sheet they signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();

            // The name of record. NOT NULL: a person with no name cannot be named on a sheet.
            $table->string('full_name');

            // Munawib `shortName` — the ROTA handle, distinct from `users.member_name`, the LOGIN
            // handle. Unique outright, not per institution: `institution_id` is nullable and a
            // UNIQUE index treats NULLs as distinct on both MySQL/InnoDB and SQLite, so
            // UNIQUE(institution_id, short_name) would be toothless for exactly the bootstrap and
            // fixture rows. D11 makes one database one customer, so plain UNIQUE is both honest
            // and enforceable.
            $table->string('short_name', 50)->nullable()->unique();

            // Job role. Orthogonal to training level (design §5.1): a person is a Resident AND a
            // PGY-2. This is the ONLY copy — `users.position` is dropped in 2026_08_10_120003.
            $table->unsignedTinyInteger('position')->index();

            // The roster/contact address and the roster-import matching key. `users.member_email`
            // survives separately because Laravel's password broker resolves accounts with
            // `User::where('member_email', …)`; see the plan's finding 6.
            $table->string('email')->nullable()->unique();

            // PE-01 staff personal data. PDPL: `phone` and `notes` must never reach audit_log
            // details, exception messages, URLs or push payloads — the same rule as PHI.
            $table->string('phone', 32)->nullable();
            $table->date('joined_at')->nullable();
            // Free text ABOUT A NAMED PERSON is the field most likely to acquire something
            // sensitive, and nothing searches it, so it is encrypted at rest like `reopen_reason`.
            $table->text('notes')->nullable();

            // PE-01 structured scheduling constraints, read by the solver. Deliberately NOT
            // encrypted: Rota holds no PHI (design §9.2), the engine and solver must read these,
            // and `text` + `encrypted:array` would forfeit any SQL-side querying and force a
            // retype later — which the project rules forbid on a column holding real data.
            $table->json('constraints')->nullable();

            // PE-03 ad-hoc external rotator. NOT nullable: a three-valued "is this external" is a
            // bug generator.
            $table->boolean('external')->default(false);

            // Governs whether this person may be NAMED. `users.active` separately governs whether
            // they may AUTHENTICATE. Keeping those two questions apart is the point of D3's
            // reversal; never express one as the other.
            $table->boolean('active')->default(true)->index();

            $table->timestamps();
            $table->softDeletes();   // people are deactivated, never deleted (owner ruling)
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->after('id')
                ->constrained('people')->nullOnDelete();
            // At most one account per person.
            $table->unique('person_id');
        });

        $now = now()->toDateTimeString();

        DB::table('users')
            ->select('id', 'full_name', 'member_name', 'member_email', 'position', 'active', 'institution_id', 'created_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($now) {
                foreach ($rows as $u) {
                    $personId = DB::table('people')->insertGetId([
                        'institution_id' => $u->institution_id,
                        // `users.full_name` is nullable; `member_name` is not. Fall back rather
                        // than insert a NULL into a NOT NULL column and abort the migration.
                        'full_name' => (string) ($u->full_name ?? $u->member_name),
                        'position' => (int) $u->position,
                        'email' => $u->member_email,
                        'external' => false,
                        'active' => (bool) $u->active,
                        'created_at' => $u->created_at ?? $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('users')->where('id', $u->id)->update(['person_id' => $personId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['person_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('person_id');
        });

        Schema::dropIfExists('people');
    }
};
```

- [ ] **Step 4: Write the `Person` model**

Create `app/Models/Person.php`:

```php
<?php

namespace App\Models;

use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person on the departmental roster — the name of record.
 *
 * A person may or may not have an account. `hasAccount()` (a `users` row exists) is what D9 calls
 * "claimed", and it is a JOIN, not a column: there is no lifecycle enum to keep in step with
 * reality. Naming is governed here (`active`); authenticating is governed on `users` (`active`).
 */
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'institution_id',
        'full_name',
        'short_name',
        'position',
        'email',
        'phone',
        'joined_at',
        'notes',
        'constraints',
        'external',
        'active',
    ];

    /**
     * Staff personal data (PDPL). `User` is serialised into Inertia props in several places and
     * this model will be too; neither of these may travel by accident.
     *
     * @var list<string>
     */
    protected $hidden = ['phone', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'active' => 'boolean',
            'external' => 'boolean',
            'joined_at' => 'date',
            'constraints' => 'array',
            'notes' => \App\Casts\EncryptedString::class,
        ];
    }

    /**
     * The account, if this person has ever claimed one. At most one (`users.person_id` UNIQUE).
     *
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** "Claimed", in D9's sense: this person can authenticate, so they can also sign. */
    public function hasAccount(): bool
    {
        return $this->user()->exists();
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position');
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('people.active', true);
    }

    /**
     * The ONE normalization for a roster address. Case and whitespace differ between a hospital
     * spreadsheet, an invitation form and a self-registration; matching must not.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        $email = $email === null ? null : mb_strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    /**
     * Find the person an imported or invited address already belongs to. Soft-deleted people are
     * INCLUDED: they still occupy the unique index, and re-inviting someone who left is a
     * reactivation, never a second human. Returns null for a null/blank address — a missing
     * address never matches, it does not match everyone.
     */
    public static function matchByEmail(?string $email): ?self
    {
        $normalized = self::normalizeEmail($email);

        if ($normalized === null) {
            return null;
        }

        return static::withTrashed()->where('email', $normalized)->first();
    }
}
```

- [ ] **Step 5: Write the factory**

Create `database/factories/PersonFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 *
 * A person created through this factory ALONE is roster-only — there is no `->rosterOnly()` state
 * because there is nothing to switch off. An account is `User::factory()->for($person, 'person')`,
 * or simply `User::factory()`, which creates its own linked person.
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'institution_id' => null,
            'full_name' => fake()->name(),
            'short_name' => null,
            'position' => 4,               // Resident (position 1 / Nurse is retired)
            'email' => fake()->unique()->safeEmail(),
            'external' => false,
            'active' => true,
        ];
    }

    /** On the roster but no longer nameable — a leaver, or someone off the department. */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }
}
```

- [ ] **Step 6: Link `User` to its person and teach `UserFactory` to create one**

In `app/Models/User.php`, add the import `use App\Models\Person;` is unnecessary (same
namespace) and append after `institution()` (currently ends at line 171):

```php
    /**
     * The person this account belongs to — the name of record.
     *
     * Since P0c (D3 reversed) `users` is the AUTHENTICATION record and nothing else. Who this
     * account belongs to, what their job role is and what they are called all live on `people`.
     *
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
```

Add `'person_id'` to `$fillable` (after `'institution_id'`, line 25).

In `database/factories/UserFactory.php`, add `use App\Models\Person;` and append **as the last
key** of `definition()` (after `'setup_completed_at' => now(),`):

```php
            // Every account belongs to a person (P0c). This key is LAST on purpose: factory
            // closures are resolved against the already-expanded attribute array, so the person
            // inherits whatever the caller overrode — `full_name`, `position`, `member_email`,
            // `institution_id` — with no per-test change anywhere.
            'person_id' => fn (array $attributes) => Person::factory()->create([
                'full_name' => $attributes['full_name'] ?? fake()->name(),
                'position' => $attributes['position'] ?? 4,
                'email' => $attributes['member_email'] ?? null,
                'institution_id' => $attributes['institution_id'] ?? null,
                // The ACCOUNT's kill switch is not the PERSON's. `->inactive()` means "cannot log
                // in", which is what every existing test that uses it means; the person stays
                // nameable, which is exactly the distinction P0c introduces.
                'active' => true,
            ])->id,
```

- [ ] **Step 7: Write the roster test, then run everything**

Create `tests/Feature/Identity/PersonRosterTest.php` asserting, with `RefreshDatabase`:

1. `Person::factory()->create()` has no account: `$person->hasAccount()` is `false` and
   `$person->user` is `null`.
2. `User::factory()->create(['full_name' => 'Dr Alpha', 'position' => 5])` produces
   `$user->person->full_name === 'Dr Alpha'` and `$user->person->position === 5`.
3. `users.person_id` is unique: creating a second `User` `->for($person, 'person')` throws
   `Illuminate\Database\QueryException`.
4. `people.short_name` is unique: two people with `short_name => 'AA'` throws.
5. `Person::matchByEmail(' Dr.X@Example.ORG ')` finds the person stored as `dr.x@example.org`,
   and `Person::matchByEmail(null)` and `Person::matchByEmail('  ')` both return `null`.
6. `Person::matchByEmail()` finds a **soft-deleted** person.
7. `notes` round-trips through the encrypted cast and the raw column is not the plaintext:
   `$this->assertNotSame('Left in June', DB::table('people')->where('id', $p->id)->value('notes'))`.
8. `constraints` round-trips as an array.

```powershell
php artisan test --filter "PersonRosterTest|RosterOnlyCannotAuthenticateTest" | Select-Object -Last 10
php artisan test | Select-Object -Last 5
```

Expected: both filters PASS; the full suite PASS with **the same test count as before plus the
new cases**. Write the count down — Task 3 must not lose any.

- [ ] **Step 8: Record the production verification query**

Append to `docs/RUNBOOK-DEPLOY.md` under a new heading
`## Verifying the 2026-08-10 identity migrations`:

```markdown
### 2026_08_10_120001_create_people_and_link_users

Every account must have gained exactly one person. Run all three; all three must return 0.

    SELECT COUNT(*) FROM users WHERE person_id IS NULL;                    -- unlinked accounts
    SELECT COUNT(*) FROM people p LEFT JOIN users u ON u.person_id = p.id
      WHERE u.id IS NULL;                                                  -- orphan people
    SELECT COUNT(*) FROM (SELECT person_id FROM users WHERE person_id IS NOT NULL
      GROUP BY person_id HAVING COUNT(*) > 1) d;                           -- shared people

And the counts must match, including soft-deleted accounts:

    SELECT (SELECT COUNT(*) FROM users) AS accounts, (SELECT COUNT(*) FROM people) AS people;

A non-zero first query means the backfill did not run — do NOT edit the migration after it has
run. Re-link by hand, or roll back with `php artisan migrate:rollback --step=1` and re-run.
```

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_10_120001_create_people_and_link_users.php app/Models/Person.php app/Models/User.php database/factories/PersonFactory.php database/factories/UserFactory.php tests/Feature/Identity/PersonRosterTest.php tests/Feature/Auth/RosterOnlyCannotAuthenticateTest.php docs/RUNBOOK-DEPLOY.md
git commit -m "feat: the roster becomes its own table, and a roster-only person has no account to log in with"
```

---

### Task 2: `people` becomes the name and role of record

**Files:**
- Modify: `app/Models/User.php` (accessors, `$fillable`, `casts()`, `role()`)
- Modify: `app/Support/AccessControl.php:101-112`
- Modify: `app/Http/Controllers/Admin/UserManagementController.php:71-91, 242, 267-300, 365-376`
- Modify: `app/Http/Controllers/Admin/AccessControlController.php:56-58`
- Modify: `app/Http/Controllers/ProfileController.php:55-80`
- Modify: `app/Http/Controllers/Auth/InvitationAcceptController.php:89-104`
- Modify: `app/Http/Controllers/Admin/UserManagementController.php:132-161` (`approve()`)
- Modify: `app/Console/Commands/CreateAdmin.php:69-81`
- Modify: `database/seeders/E2eSeeder.php:24-38`, `database/seeders/DemoSeeder.php:37-47`
- Test: `tests/Feature/Identity/NameAndRoleOfRecordTest.php`
- Modify: `tests/Feature/Admin/ChiefResidentTest.php:154`

The two `users` columns still exist after this task and simply stop being read or written — the
accessors take precedence over the raw attributes. Task 3 drops them. Splitting it this way means
neither commit is red.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Identity/NameAndRoleOfRecordTest.php`, `RefreshDatabase`, seeding
`ReferenceSeeder` and `AccessControlSeeder`. Assert:

1. **The accessor wins over a stale column.** Create a user, then
   `DB::table('users')->where('id', $u->id)->update(['full_name' => 'STALE', 'position' => 9]);`
   `$u->fresh()->full_name` is the person's name and `$u->fresh()->position` is the person's
   position — not `'STALE'`, not `9`.
2. **Capabilities follow the person's position.** A user whose person is position 0 resolves
   `access.manage` through `AccessControl::allows()`; move the *person* to position 4 (and
   `AccessControl::flush($u->id)`) and it no longer does.
3. **`holdersOf()` orders by the person's name and returns real `User` models.** Create three
   admins with person names `'Cc'`, `'Aa'`, `'Bb'`; assert
   `array_map(fn ($u) => $u->full_name, AccessControl::holdersOf('endorsement.reopen'))` is
   `['Aa', 'Bb', 'Cc']`, and that `$holders[0]->getKey()` is a **users** id (not a people id).
4. **`Admin → Users` is scoped and ordered through the person**: a Chief Resident sees only
   position-4 accounts, ordered by person name.
5. **A self profile edit writes both rows**: `PATCH /profile` with a new `full_name` and
   `member_email` updates `people.full_name`, `people.email` **and** `users.member_email` in one
   transaction; the person's `email` and the account's `member_email` agree afterwards.
6. **An admin profile edit writes both rows**, same assertion through
   `PATCH /admin/users/{user}/profile`.
7. **`setPosition` writes the person**: `PATCH /admin/users/{user}/position` with `position=3`
   leaves `people.position === 3` and busts the capability cache.

- [ ] **Step 2: Run it, watch it fail**

```powershell
php artisan test --filter NameAndRoleOfRecordTest | Select-Object -Last 20
```

Expected: FAIL on case 1 — `full_name` still returns `'STALE'`.

- [ ] **Step 3: Add the read-through accessors to `User`**

In `app/Models/User.php`, add `use Illuminate\Database\Eloquent\Casts\Attribute;` and insert
before `hasVerifiedEmail()` (line 79):

```php
    /**
     * The name of record, read through the person (P0c).
     *
     * `users.full_name` is dropped by 2026_08_10_120003; this accessor exists so that the ~40
     * PHP reads of `$user->full_name` — the signed-off-by snapshot, the print header, the Inertia
     * props, the OTP mail — need no change at all. Only the handful of SQL-level reads
     * (`orderBy('full_name')`) had to move, and they are listed in the P0c plan.
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->person?->full_name);
    }

    /**
     * The job role, read through the person (P0c). Capability resolution
     * (App\Support\AccessControl::resolve) keys off this, and so does the two-factor privilege
     * classifier — so there must be exactly one copy of it, and it is the roster's.
     */
    protected function position(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => $this->person === null ? null : (int) $this->person->position,
        );
    }
```

Remove `'full_name'` and `'position'` from `$fillable` (lines 28 and 26) so nothing can write the
dead columns by mass assignment, and remove `'position' => 'integer'` from `casts()` (line 66) —
the accessor already returns an int and a cast on a non-existent column is a trap for Task 3.

**Delete `User::role()` (lines 173-181).** It is `belongsTo(Position::class, 'position')` and
`position` is no longer a column on this table, so it cannot survive. It is also dead:

```powershell
Select-String -Path app\,resources\,tests\,database\ -Pattern "->role\(\)" -Recurse
```

Expected: **no matches** — confirmed 2026-08-08. `Person::role()` (written in Task 1) is the
replacement, and the one future caller writes `$user->person->role()`. If the grep now returns a
hit, point that caller at the person instead of keeping a second relation here.

- [ ] **Step 4: Move the eight SQL-level sites onto `people`**

`app/Support/AccessControl.php:101-112` — `holdersOf()`. Replace the query with:

```php
        // `people` carries `position` and `full_name` since P0c, so the inverse of
        // capabilitiesFor() joins it. `select('users.*')` is mandatory: without it the join
        // clobbers `id` with people.id and every caller gets the wrong key.
        return User::query()
            ->join('people', 'people.id', '=', 'users.person_id')
            ->whereNull('people.deleted_at')
            ->where('users.active', true)
            ->where(function ($q) use ($positions, $granted): void {
                // A role default OR an explicit per-user grant is enough to hold it.
                $q->whereIn('people.position', $positions === [] ? [-1] : $positions)
                    ->orWhereIn('users.id', $granted === [] ? [-1] : $granted);
            })
            ->when($denied !== [], fn ($q) => $q->whereNotIn('users.id', $denied))
            ->orderBy('people.full_name')
            ->select('users.*')
            ->get()
            ->all();
```

`app/Http/Controllers/Admin/UserManagementController.php:71-73` — the users list. Replace

```php
            'users' => User::query()
                ->when(! $all, fn ($q) => $q->where('position', self::RESIDENT))
                ->orderBy('full_name')
```

with

```php
            'users' => User::query()
                ->join('people', 'people.id', '=', 'users.person_id')
                ->when(! $all, fn ($q) => $q->where('people.position', self::RESIDENT))
                ->orderBy('people.full_name')
                ->select('users.*')
```

`…UserManagementController.php:365-376` — `isLastActiveAdministrator()`. Replace the
`->where('position', 0)` line with a join:

```php
        return ! User::query()
            ->join('people', 'people.id', '=', 'users.person_id')
            ->whereKeyNot($user->getKey())
            ->where('people.position', 0)
            ->where('users.active', true)
            ->exists();
```

`app/Http/Controllers/Admin/AccessControlController.php:56-58` — the override picker. Replace with

```php
            // Only ACCOUNTS appear here: a per-user capability override is meaningless for
            // someone who has no account, and since P0c the roster lives in `people`, so this
            // list is small again by construction rather than by hope.
            'users' => User::query()
                ->join('people', 'people.id', '=', 'users.person_id')
                ->orderBy('people.full_name')
                ->select('users.id', 'users.member_name', 'people.full_name', 'people.position'),
```

…followed by `->get()`. (Keep the prop shape `{id, member_name, full_name, position}` — the Vue
picker at `resources/js/Pages/Admin/AccessControl.vue:64,198,203` is unchanged.)

- [ ] **Step 5: Move the writers**

`app/Http/Controllers/ProfileController.php:55-80` — `update()`. Keep the validation rules
exactly as they are (they guard `users.member_name` / `users.member_email`, which still exist),
add `Rule::unique('people', 'email')->ignore($user->person_id)` to the `member_email` rule, and
replace the single `$user->update([...])` with a transaction writing both rows:

```php
        // The address lives twice on purpose (P0c finding 6): `users.member_email` because
        // Laravel's password broker resolves accounts by that column, `people.email` because it
        // is the roster's contact address and the import matching key. They are written together,
        // here and at exactly two other sites, and never anywhere else.
        DB::transaction(function () use ($user, $data): void {
            $user->update([
                'member_name' => $data['member_name'],
                'member_email' => $data['member_email'],
            ]);

            $user->person?->update([
                'full_name' => $data['full_name'],
                'email' => \App\Models\Person::normalizeEmail($data['member_email']),
            ]);
        });
```

`…/Admin/UserManagementController.php:267-300` — `updateProfile()`. Same shape: add
`Rule::unique('people', 'email')->ignore($user->person_id)` to the `member_email` rule and write
both rows in a transaction. The audit detail stays ids-only.

`…/Admin/UserManagementController.php:242` — `setPosition()`. Replace
`$user->update(['position' => $position]);` with
`$user->person?->update(['position' => $position]);`. The `isLastActiveAdministrator()` guard at
line 236 and the `AccessControl::flush()` at line 245 are unchanged and still correct.

`…/Admin/UserManagementController.php:196-222` — `setActive()`. After the existing
`$user->update(['active' => $active]);`, add:

```php
        // Deactivating a leaver must stop them being NAMED as well as stop them logging in. The
        // two flags answer different questions (P0c) and the admin screen offers one control, so
        // this is where they are kept in step.
        $user->person?->update(['active' => $active]);
```

`app/Console/Commands/CreateAdmin.php:69-81` — create the person first, then the account:

```php
        $user = DB::transaction(function () use ($username, $fullName, $email, $password): User {
            $person = \App\Models\Person::create([
                'full_name' => $fullName,
                'position' => 0,                  // Admin
                'email' => \App\Models\Person::normalizeEmail($email),
                'active' => true,
            ]);

            return User::create([
                'person_id' => $person->id,
                'member_name' => $username,
                'member_email' => $email,
                'password' => $password,          // hashed by the model's `hashed` cast
                'active' => true,
                // Verified on creation: SMTP is configured in-app, AFTER login, so gating the
                // bootstrap admin behind an email would deadlock the system it exists to open.
                'email_verified_at' => now(),
                'pass_exp_date' => now()->addYear()->format('Y-m-d'),
            ]);
        });
```

Add `Rule::unique('people', 'email')` alongside the existing `unique:users,member_email` at
line 58, so the bootstrap admin cannot collide with a rostered person.

`app/Http/Controllers/Auth/InvitationAcceptController.php:89-104` — for now, add a person inside
the existing transaction and set `person_id` on the insert, moving `full_name` and `position`
onto it. **Task 8 rewrites this properly** (match-or-create against the invitation's person); this
step only keeps the tree green:

```php
            $personId = DB::table('people')->insertGetId([
                'institution_id' => $locked->institution_id,
                'full_name' => $data['full_name'],
                'position' => $locked->position,   // FROM THE INVITATION, never from the request
                'email' => \App\Models\Person::normalizeEmail($locked->member_email),
                'external' => false,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $userId = DB::table('users')->insertGetId([
                'person_id' => $personId,
                'institution_id' => $locked->institution_id,
                'member_email' => $locked->member_email,
                'member_name' => $data['member_name'],
                'password' => Hash::make($data['password']),
                'active' => true,
                'email_verified_at' => $now,
                'pass_exp_date' => $now->copy()->addDays(90)->format('Y-m-d'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
```

`…/Admin/UserManagementController.php:132-161` — `approve()`. Same shape: insert a `people` row
carrying `full_name`/`position`/`email` from the pending registration, then the `users` row with
`person_id`, **still through the query builder** so `$pending->getRawOriginal('password')` lands
byte-for-byte (the `hashed` cast must not re-derive it).

`database/seeders/E2eSeeder.php` and `database/seeders/DemoSeeder.php` — replace each
`User::updateOrCreate([...])` with a `Person::updateOrCreate(['email' => …], [...])` followed by
`User::updateOrCreate(['member_name' => …], ['person_id' => $person->id, ...])`, dropping
`full_name`/`position` from the user array. Keep both production hard-stops untouched.

- [ ] **Step 6: Fix the one test that asserts on the dropped columns**

`tests/Feature/Admin/ChiefResidentTest.php:154`:

```php
        $this->assertDatabaseHas('users', ['member_name' => 'new_res', 'position' => 4]);
```

becomes

```php
        $this->assertDatabaseHas('users', ['member_name' => 'new_res']);
        $this->assertSame(4, User::where('member_name', 'new_res')->firstOrFail()->position);
```

- [ ] **Step 7: Green, then commit**

```powershell
php artisan test | Select-Object -Last 5
```

Expected: PASS, count from Task 1 Step 7 plus the new `NameAndRoleOfRecordTest` cases.

```bash
git add app/ database/seeders tests/
git commit -m "refactor: the person is the name and role of record; users is the account"
```

---

### Task 3: Drop `users.full_name` and `users.position`

**Files:**
- Create: `database/migrations/2026_08_10_120003_move_name_and_position_off_users.php`
- Modify: `database/factories/UserFactory.php`
- Modify: `docs/RUNBOOK-DEPLOY.md`

Small, but it is the destructive one. It lands separately so that a rollback of the *drop* does
not roll back the *move*.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `full_name` and `position` now live on `people` (2026_08_10_120001) and nothing reads them here
 * any more — `User` resolves both through the person relation. Two copies of one fact is the
 * duplication CLAUDE.md blames for the audit-chain false alarm, so the dead copies go.
 *
 * REVERSIBLE ONLY WHILE `people` EXISTS. `down()` re-adds both columns and copies the values back
 * through `users.person_id`. Rolling back 2026_08_10_120001 first would drop `people` and leave
 * nothing to copy from — the runbook states the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['full_name', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('full_name')->nullable()->after('member_name');
            $table->unsignedTinyInteger('position')->default(1)->index()->after('full_name');
        });

        DB::statement(
            'update users set full_name = (select people.full_name from people where people.id = users.person_id) '.
            'where person_id is not null'
        );
        DB::statement(
            'update users set position = (select people.position from people where people.id = users.person_id) '.
            'where person_id is not null'
        );
    }
};
```

- [ ] **Step 2: Teach `UserFactory` to route person-owned overrides**

`full_name` and `position` are no longer `users` columns, so the ~40 existing calls of the shape
`User::factory()->create(['position' => 4, 'full_name' => 'Dr X'])` would now try to insert
columns that do not exist. Rewriting all forty would be a mechanical diff with no behavioural
content and one chance in forty of a silent typo. Route them instead.

Replace `definition()`'s `full_name` and `position` keys (they move into the person state) and
add the routing:

```php
use App\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

    /**
     * Attributes that belong to the PERSON, not the account — `people` columns since P0c.
     *
     * @var list<string>
     */
    private const PERSON_ONLY = ['full_name', 'position', 'short_name', 'external'];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'institution_id' => null,
            'member_name' => fake()->unique()->userName(),
            'member_email' => $email,
            'password' => static::$password ??= Hash::make('password'),
            'active' => true,
            'remember_token' => Str::random(10),
            // An account in NORMAL USE, which is what almost every test means by "a user".
            // Left null, RequireSetup would redirect every request in the suite to /setup.
            'setup_completed_at' => now(),
            // LAST on purpose: closures see the already-expanded attributes, so the person
            // inherits whatever the caller overrode.
            'person_id' => fn (array $attributes) => Person::factory()->create([
                'email' => $attributes['member_email'] ?? null,
                'institution_id' => $attributes['institution_id'] ?? null,
            ])->id,
        ];
    }

    public function admin(): static
    {
        return $this->for(Person::factory()->state(['position' => 0]), 'person');
    }

    /**
     * Deactivate the ACCOUNT — "cannot log in", which is what every existing caller means. The
     * PERSON stays nameable; that separation is what P0c introduces.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }

    /**
     * Route person-owned overrides onto the linked person.
     *
     * Termination: the returned attribute array never contains a PERSON_ONLY key, and Laravel's
     * `create($attrs)` re-enters as `state($attrs)->create([])`, so the second pass sees an empty
     * override array and falls straight through.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: static, 1: array<string, mixed>}
     */
    private function routePersonAttributes(array $attributes): array
    {
        $personOnly = Arr::only($attributes, self::PERSON_ONLY);

        if ($personOnly === []) {
            return [$this, $attributes];
        }

        // Mirror the two columns that live on BOTH rows, when the caller named them here.
        if (array_key_exists('member_email', $attributes)) {
            $personOnly['email'] = $attributes['member_email'];
        }

        if (array_key_exists('institution_id', $attributes)) {
            $personOnly['institution_id'] = $attributes['institution_id'];
        }

        return [
            $this->for(Person::factory()->state($personOnly), 'person'),
            Arr::except($attributes, self::PERSON_ONLY),
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    public function create($attributes = [], ?Model $parent = null)
    {
        [$factory, $rest] = $this->routePersonAttributes((array) $attributes);

        return $factory === $this
            ? parent::create($rest, $parent)
            : $factory->create($rest, $parent);
    }

    /** @param  array<string, mixed>  $attributes */
    public function make($attributes = [], ?Model $parent = null)
    {
        [$factory, $rest] = $this->routePersonAttributes((array) $attributes);

        return $factory === $this
            ? parent::make($rest, $parent)
            : $factory->make($rest, $parent);
    }
```

> **Known and accepted limit, state it in review:** when a caller overrides a person-only
> attribute *without* also naming `member_email`, the fixture's `people.email` is PersonFactory's
> own faker address rather than the account's. Nothing reads `people.email` on the endorsement
> paths — it is the roster-matching key, and Task 8's tests build that data deliberately. The
> address-sync invariant is asserted at the three writer paths (Task 2 Step 1 cases 5 and 6, Task
> 8), which is behaviour, not fixture shape.

- [ ] **Step 3: Verify no writer of the dropped columns survives**

```powershell
Select-String -Path app\,database\ -Pattern "'full_name'\s*=>|'position'\s*=>" -Recurse | Select-String -NotMatch "people|Person|pending|role_capabilities|invitation"
```

Expected: **no matches** other than the `people` inserts written in Task 2. Anything else is a
write to a column that no longer exists and will 500 at runtime.

- [ ] **Step 4: Run the full suite**

```powershell
php artisan test | Select-Object -Last 5
```

Expected: PASS, **no fewer tests than Task 2**. If a test errors with
`no such column: users.position`, it is an SQL-level read Task 2 missed — fix it there, not in
the test.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_10_120003_move_name_and_position_off_users.php database/factories/UserFactory.php
git commit -m "refactor: drop the dead name and position columns from users"
```

- [ ] **Step 6: Runbook — the backup requirement**

Append to `docs/RUNBOOK-DEPLOY.md` under the Task 1 heading:

```markdown
### 2026_08_10_120003_move_name_and_position_off_users — TAKE A DUMP FIRST

This migration DROPS `users.full_name` and `users.position`. `down()` restores them by copying
back from `people`, so it is reversible — but only while `people` exists.

Before `php artisan migrate`:

    mysqldump --single-transaction --routines <db> > pre-p0c-$(date +%F).sql

To roll back, roll back in THIS order and no other:

    php artisan migrate:rollback --step=1   # 120005 invitations.person_id
    php artisan migrate:rollback --step=1   # 120004 handover_signoffs person ids
    php artisan migrate:rollback --step=1   # 120003 restores users.full_name / users.position
    php artisan migrate:rollback --step=1   # 120002 levels
    php artisan migrate:rollback --step=1   # 120001 drops `people` — nothing to copy from after this

Rolling 120001 back before 120003 loses every name and role. Restore from the dump instead.

After migrating, confirm the copy is complete:

    SELECT COUNT(*) FROM people WHERE full_name IS NULL OR full_name = '';   -- must be 0
```

```bash
git add docs/RUNBOOK-DEPLOY.md
git commit -m "docs: the identity migrations need a dump first, and roll back in one order only"
```

---

### Task 4: Training levels, effective-dated

**Files:**
- Create: `database/migrations/2026_08_10_120002_create_levels_and_person_levels.php`
- Create: `app/Models/Level.php`, `app/Models/PersonLevel.php`
- Create: `database/factories/LevelFactory.php`
- Modify: `app/Models/Person.php` (relations + `levelAt()`)
- Test: `tests/Feature/Identity/LevelHistoryTest.php`

Munawib LV-01 (levels) and LV-04 (effective-dated history). Self-contained: nothing else reads
these yet, and no level is seeded — the QCH level set is departmental data the owner supplies, and
inventing one would be a clinical guess.

**Design deviation, deliberate:** the design doc's §5.1 puts a current `level_id` on the person
*and* a history table. That is two definitions of "current level" and they will drift. There is no
`people.level_id`; `Person::levelAt($date)` resolves from `person_levels` and is the only answer.

- [x] **Step 1: Write the failing test**

`tests/Feature/Identity/LevelHistoryTest.php`, `RefreshDatabase`. Assert:

1. `Person::levelAt('2026-03-01')` is `null` when the person has no history.
2. With `R1` effective `2025-07-01 → 2026-06-30` and `R2` effective `2026-07-01 → null`:
   `levelAt('2026-01-15')?->code === 'R1'`, `levelAt('2026-08-08')?->code === 'R2'`,
   `levelAt('2025-01-01')` is `null`.
3. `levelAt()` with no argument uses today.
4. `currentLevel()` equals `levelAt(today())`.
5. Boundary days are **inclusive at both ends**: `levelAt('2026-06-30')?->code === 'R1'` and
   `levelAt('2026-07-01')?->code === 'R2'`.
6. `(person_id, effective_from)` is unique — a second row on the same start date throws.
7. A level that has history cannot be deleted: `$level->delete()` throws `QueryException`
   (`restrictOnDelete`). Deactivating it (`active = false`) is the supported action, and history
   still resolves.
8. `Level::code` is unique.

- [x] **Step 2: Run it, watch it fail** — `php artisan test --filter LevelHistoryTest | Select-Object -Last 15`

- [x] **Step 3: The migration**

```php
Schema::create('levels', function (Blueprint $table) {
    $table->id();
    $table->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
    // The rota-facing identity, e.g. R1 / PGY-2. Unique for the same reason people.short_name
    // is: D11 makes one database one customer, and NULL institution_id would make a composite
    // unique toothless on both engines.
    $table->string('code', 20)->unique();
    $table->string('name');
    $table->unsignedSmallInteger('display_order')->default(1000);
    $table->boolean('active')->default(true);
    $table->timestamps();
});

Schema::create('person_levels', function (Blueprint $table) {
    $table->id();
    $table->foreignId('person_id')->constrained()->cascadeOnDelete();
    // restrictOnDelete, NOT nullOnDelete: a history row that has forgotten which level it
    // records is worse than no history. Retire a level with active = false.
    $table->foreignId('level_id')->constrained()->restrictOnDelete();
    $table->date('effective_from');
    $table->date('effective_to')->nullable();   // null = current
    $table->timestamps();

    $table->unique(['person_id', 'effective_from']);
    $table->index(['person_id', 'effective_from', 'effective_to']);
});
```

No level rows are seeded. `ReferenceSeeder` is untouched.

- [x] **Step 4: Models and the resolver**

`app/Models/Level.php` — `$fillable = ['institution_id','code','name','display_order','active']`,
casts `display_order:integer`, `active:boolean`, `scopeActive`, `scopeOrdered` mirroring
`App\Models\Unit`, and `personLevels(): HasMany`.

`app/Models/PersonLevel.php` — `$fillable = ['person_id','level_id','effective_from','effective_to']`,
casts `effective_from:date`, `effective_to:date`, `person()` and `level()` belongsTo.

In `app/Models/Person.php` add:

```php
    /**
     * The person's training-level history (Munawib LV-04). There is deliberately no `level_id`
     * column on this table: a denormalized "current" pointer beside a history table is two
     * definitions of one fact, and they drift.
     *
     * @return HasMany<PersonLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(PersonLevel::class);
    }

    /**
     * The level in force on a given date. Both bounds are INCLUSIVE: a level that runs to
     * 30 June is still in force on 30 June, and its successor starts on 1 July.
     */
    public function levelAt(Carbon|string|null $date = null): ?Level
    {
        $on = $date === null ? today() : Carbon::parse($date)->startOfDay();

        return $this->levels()
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('effective_from')
            ->with('level')
            ->first()?->level;
    }

    public function currentLevel(): ?Level
    {
        return $this->levelAt();
    }
```

- [x] **Step 5: Green, then commit**

```powershell
php artisan test --filter LevelHistoryTest | Select-Object -Last 5
php artisan test | Select-Object -Last 5
```

```bash
git add database/migrations/2026_08_10_120002_create_levels_and_person_levels.php app/Models database/factories tests/Feature/Identity/LevelHistoryTest.php
git commit -m "feat: training levels with effective-dated history"
```

---

### Task 5: `handover_signoffs` gains person columns, backfilled by join

**Files:**
- Create: `database/migrations/2026_08_10_120004_add_person_ids_to_handover_signoffs.php`
- Modify: `app/Models/HandoverSignoff.php` (`$fillable`)
- Modify: `tests/Feature/ClinicalSchemaTest.php:76-95`
- Test: `tests/Feature/Identity/SignoffPersonBackfillTest.php`

Inert: the columns are populated but nothing reads or writes them until Task 6. Deployable.

- [x] **Step 1: Write the failing test**

`tests/Feature/Identity/SignoffPersonBackfillTest.php`. The backfill runs inside the migration, so
the test proves the *rule*, not the historical run: create three users (so `users.id` and
`people.id` diverge — delete the first person's account or create extra people first), write a
`handover_signoffs` row through the query builder with the four `*_user_id` columns set, then
re-run the backfill statements and assert each `*_person_id` equals the *linked* person, **not**
the integer that was in the user column. Add the negative case explicitly:

```php
    /**
     * The whole reason this is a join and not a copy: `people.id` and `users.id` are independent
     * sequences, so the integer 3 names two different humans. A backfill written as
     * `SET endorsed_by_person_id = endorsed_by_user_id` would rename every clinician on every
     * imported sheet and raise no error at all.
     */
    public function test_the_backfill_follows_the_link_and_not_the_integer(): void
    {
        // Force the sequences apart: three people with no account, then one account.
        Person::factory()->count(3)->create();
        $endorser = User::factory()->create(['full_name' => 'Dr Endorser', 'position' => 4]);

        $this->assertNotSame($endorser->id, $endorser->person_id, 'fixture: ids must diverge');
        // …write the row, run the backfill, assert person_id === $endorser->person_id
        //   and assertNotSame($endorser->id, $signoff->endorsed_by_person_id)
    }
```

- [x] **Step 2: Run it, watch it fail** — `no such column: endorsed_by_person_id`.

- [x] **Step 3: The migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The four NAMED ROLES on a signed sheet move from `users` to `people` (D3 reversed, D9).
 *
 * They are names of record, not actors: the on-call consultant is frequently someone who never
 * logs in, and under the new shape that person has no `users` row at all. `signed_off_by_user_id`
 * and `reopened_by_user_id` stay on `users` — those ARE actors, and an actor is by definition
 * someone who authenticated.
 *
 * The old `*_user_id` columns are KEPT, frozen. They stop being written by 2026_08_10 (P0c Task
 * 6) and are the only independent cross-check that this backfill was right; on a medico-legal
 * table that is worth four dead columns. `ClinicalSchemaTest` pins both sets.
 *
 * THE BACKFILL IS A JOIN, NOT A COPY. `people.id` and `users.id` are independent autoincrement
 * sequences: `SET endorsed_by_person_id = endorsed_by_user_id` would silently rename clinicians
 * on historical sheets with no error and no FK violation, because both integers are valid keys in
 * their own table.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const FIELDS = ['endorsed_by', 'endorsed_to', 'consultant_by', 'consultant_to'];

    public function up(): void
    {
        Schema::table('handover_signoffs', function (Blueprint $table) {
            foreach (self::FIELDS as $field) {
                $table->foreignId($field.'_person_id')->nullable()
                    ->after($field.'_user_id')
                    ->constrained('people')->nullOnDelete();
            }
        });

        foreach (self::FIELDS as $field) {
            DB::statement(
                "update handover_signoffs set {$field}_person_id = ".
                "(select users.person_id from users where users.id = handover_signoffs.{$field}_user_id) ".
                "where {$field}_user_id is not null"
            );
        }
    }

    public function down(): void
    {
        Schema::table('handover_signoffs', function (Blueprint $table) {
            foreach (self::FIELDS as $field) {
                $table->dropConstrainedForeignId($field.'_person_id');
            }
        });
    }
};
```

- [x] **Step 4: Model and schema test**

Add the four `*_person_id` keys to `HandoverSignoff::$fillable` (after each matching `*_user_id`,
`app/Models/HandoverSignoff.php:102-126`).

In `tests/Feature/ClinicalSchemaTest.php:78-90`, add the four new columns to the asserted set and
add a sentence to the method docblock recording that the `*_user_id` four are frozen legacy
columns that new writes must leave NULL.

- [x] **Step 5: Green, commit, runbook**

```powershell
php artisan test | Select-Object -Last 5
```

Append to `docs/RUNBOOK-DEPLOY.md`:

```markdown
### 2026_08_10_120004_add_person_ids_to_handover_signoffs

Every historical named role must have resolved. Expect 0 from each:

    SELECT COUNT(*) FROM handover_signoffs WHERE endorsed_by_user_id IS NOT NULL AND endorsed_by_person_id IS NULL;
    SELECT COUNT(*) FROM handover_signoffs WHERE endorsed_to_user_id IS NOT NULL AND endorsed_to_person_id IS NULL;
    SELECT COUNT(*) FROM handover_signoffs WHERE consultant_by_user_id IS NOT NULL AND consultant_by_person_id IS NULL;
    SELECT COUNT(*) FROM handover_signoffs WHERE consultant_to_user_id IS NOT NULL AND consultant_to_person_id IS NULL;

A non-zero count means a signoff pointed at a `users` row that has no person — check
`SELECT id FROM users WHERE person_id IS NULL` first (that is the 120001 verification).

Spot-check that the names still agree with the frozen snapshots — this is the check that would
catch a copy-instead-of-join:

    SELECT s.id, s.endorsed_by_name, p.full_name
    FROM handover_signoffs s JOIN people p ON p.id = s.endorsed_by_person_id
    WHERE s.endorsed_by_name IS NOT NULL AND s.endorsed_by_name <> p.full_name;

Rows here are people who were renamed after signing (legitimate) OR a mis-joined backfill
(not). Read them; do not assume.
```

```bash
git add database/migrations/2026_08_10_120004_add_person_ids_to_handover_signoffs.php app/Models/HandoverSignoff.php tests/ docs/RUNBOOK-DEPLOY.md
git commit -m "feat: named roles on a signed sheet point at people, backfilled through the link"
```

---

### Task 6: D9 — who may be named on a signed sheet

**Files:**
- Create: `app/Support/SignoffPickers.php`
- Modify: `app/Http/Controllers/EndorsementController.php:242, 293-433, 927-966, 968-1000, 1015-1037, 1039-1047, 1139-1172`
- Modify: `resources/js/Pages/Endorsement/Sheet.vue:195-200, 222-230, 347-392`
- Modify: `tests/js/EndorsementSignoff.test.js`
- Modify: `tests/Feature/Endorsement/HandoverSignoffTest.php`, `tests/Feature/Endorsement/SignatureAttributionTest.php`, `tests/Feature/Endorsement/ReopenCapabilityTest.php`, `tests/Feature/Security/AuditHardeningTest.php`
- Test: `tests/Feature/Endorsement/PickerParityTest.php`

**This is one task and cannot be split.** Offer and validation must never be apart across a
commit boundary — that is the 2026-07-26 invariant CLAUDE.md names, and D9 makes it per field.

**Wire contract rename.** The four request/response keys become `endorsed_by_person_id`,
`endorsed_to_person_id`, `consultant_by_person_id`, `consultant_to_person_id`. Leaving a field
named `*_user_id` while it carries a person id is exactly how the id-space confusion of finding 3
ships to production.

- [ ] **Step 1: Write the failing parity test**

Create `tests/Feature/Endorsement/PickerParityTest.php` — the machine-checkable form of the
invariant, and the most valuable test in this change.

```php
<?php

namespace Tests\Feature\Endorsement;

use App\Models\HandoverSignoff;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * D9 — the pickers scope DIFFERENTLY per field, and offer must equal validation for each.
 *
 *   endorsed_by / endorsed_to   claimed accounts only — these clinicians attest and receive, and
 *                               their signature is the evidence
 *   consultant_by / consultant_to  any ACTIVE PERSON, including someone with no account — the
 *                               on-call consultant is a name of record and frequently never
 *                               logs in
 *   signed_off_by               the authenticated user, by construction
 *
 * The 2026-07-26 audit restored "a picker's write-side validation must match what it OFFERS"
 * after `exists:users,id` let any account be frozen onto medico-legal evidence. D9 makes that
 * rule per-field, which is two chances to drift instead of one — so it is asserted as a MATRIX
 * rather than as examples.
 */
class PickerParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    /**
     * Every fixture: [label, person, should be offered/accepted as endorser, ditto as consultant].
     *
     * @return array<string, array{0: Person, 1: bool, 2: bool}>
     */
    private function matrix(): array
    {
        $cases = [];

        // Roster-only (no account) at each position.
        foreach ([0, 2, 3, 4, 5] as $position) {
            $person = Person::factory()->create(['position' => $position, 'full_name' => "Roster {$position}"]);
            $cases["roster-only p{$position}"] = [$person, false, $position === 3];
        }

        // Claimed, active account at each position.
        foreach ([0, 2, 3, 4, 5] as $position) {
            $user = User::factory()->create(['position' => $position, 'full_name' => "Claimed {$position}"]);
            $cases["claimed p{$position}"] = [$user->person, in_array($position, [4, 5], true), $position === 3];
        }

        // Claimed but the ACCOUNT is deactivated: cannot endorse (no live account to sign with),
        // but the person is still on the roster, so a consultant may still be named.
        $inactiveAccount = User::factory()->create(['position' => 4, 'active' => false, 'full_name' => 'Locked Out']);
        $cases['claimed p4, account inactive'] = [$inactiveAccount->person, false, false];

        $inactiveConsultant = User::factory()->create(['position' => 3, 'active' => false, 'full_name' => 'Locked Consultant']);
        $cases['claimed p3, account inactive'] = [$inactiveConsultant->person, false, true];

        // The PERSON is deactivated: nameable nowhere, whatever the account says.
        $leaver = User::factory()->create(['position' => 4, 'full_name' => 'Leaver']);
        $leaver->person->update(['active' => false]);
        $cases['person inactive'] = [$leaver->person->fresh(), false, false];

        $leaverConsultant = Person::factory()->inactive()->create(['position' => 3, 'full_name' => 'Gone Consultant']);
        $cases['roster-only p3, person inactive'] = [$leaverConsultant, false, false];

        // Soft-deleted person: gone from both, and Rule::exists never sees the SoftDeletes scope,
        // so this case is the one that catches a missing whereNull('people.deleted_at').
        $trashed = Person::factory()->create(['position' => 3, 'full_name' => 'Trashed Consultant']);
        $trashed->delete();
        $cases['roster-only p3, trashed'] = [$trashed, false, false];

        // Soft-deleted ACCOUNT with a live person: no live account, so no endorsing.
        $trashedAccount = User::factory()->create(['position' => 4, 'full_name' => 'Trashed Account']);
        $trashedAccount->delete();
        $cases['p4, account trashed'] = [$trashedAccount->person, false, false];

        return $cases;
    }

    public function test_every_field_accepts_exactly_who_it_offers(): void
    {
        $unit = Unit::where('code', 'PICU')->firstOrFail();
        $actor = User::factory()->create(['position' => 2]);
        $cases = $this->matrix();

        $page = $this->actingAs($actor)->get("/endorsement/{$unit->code}/2026-08-08")->assertOk();

        $offered = ['endorsers' => [], 'consultants' => []];
        $page->assertInertia(function (Assert $p) use (&$offered) {
            $staff = $p->toArray()['props']['staff'];
            $offered['endorsers'] = collect($staff['endorsers'])->reject(fn ($s) => $s['retired'] ?? false)->pluck('id')->all();
            $offered['consultants'] = collect($staff['consultants'])->reject(fn ($s) => $s['retired'] ?? false)->pluck('id')->all();
        });

        foreach ($cases as $label => [$person, $endorsable, $consultable]) {
            $this->assertSame($endorsable, in_array($person->id, $offered['endorsers'], true), "offer/endorser: {$label}");
            $this->assertSame($consultable, in_array($person->id, $offered['consultants'], true), "offer/consultant: {$label}");

            foreach ([
                'endorsed_by_person_id' => $endorsable,
                'endorsed_to_person_id' => $endorsable,
                'consultant_by_person_id' => $consultable,
                'consultant_to_person_id' => $consultable,
            ] as $field => $accepted) {
                $response = $this->actingAs($actor)
                    ->patch("/endorsement/{$unit->code}/2026-08-08/signoff", [$field => $person->id]);

                if ($accepted) {
                    $response->assertSessionHasNoErrors();
                    // Accepted means STORED, not merely un-refused: `sometimes|nullable` would
                    // silently pass a field that never reached the row.
                    $this->assertSame(
                        $person->id,
                        (int) HandoverSignoff::where('unit_id', $unit->id)
                            ->whereDate('handover_date', '2026-08-08')
                            ->value($field),
                        "stored/{$field}: {$label}"
                    );
                } else {
                    $response->assertSessionHasErrors($field);
                }
            }
        }
    }
}
```

Each accepted write lands on the same unsigned day and is overwritten by the next fixture, which
is fine — the assertion reads the row immediately after its own write. The day is never signed, so
the "already signed off" guard at `EndorsementController.php:324-328` never fires.

- [ ] **Step 2: Run it, watch it fail**

```powershell
php artisan test --filter PickerParityTest | Select-Object -Last 25
```

Expected: FAIL — the request keys `endorsed_by_person_id` etc. are unknown, so nothing validates
and nothing is stored.

- [ ] **Step 3: Extract the one predicate per field**

Create `app/Support/SignoffPickers.php`:

```php
<?php

namespace App\Support;

use App\Models\Person;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * WHO may be named on a handover sign-off — one definition per field, used for BOTH the offered
 * list and the write-side rule.
 *
 * `staffPickers()` used to build both lists from one shared closure, so any predicate added
 * inside it applied to consultants too — the exact opposite of D9. And `Rule::exists` runs on the
 * raw query builder, which never sees Eloquent's SoftDeletes global scope, so a predicate written
 * once as Eloquent and once as raw SQL is two predicates that drift. Both problems are solved the
 * same way: the predicate is a closure over a QUERY BUILDER, applied to the validation rule
 * directly and to the Eloquent offer query through `getQuery()`.
 *
 * D9 (design §5.3): endorsers must have a claimed, live account, because their SIGNATURE is the
 * evidence; consultants need only be an active person, because the covering consultant is a name
 * of record and frequently never logs in.
 */
final class SignoffPickers
{
    /**
     * RULING 6 — residents and chief residents. A Chief Resident (5) is a resident clinically;
     * promotion must not remove them from the handover.
     *
     * @var list<int>
     */
    public const ENDORSER_POSITIONS = [4, 5];

    /**
     * The COVERING / RECEIVING consultant — a different question from who personally handed
     * over, so this list stays position 3 alone.
     *
     * @var list<int>
     */
    public const CONSULTANT_POSITIONS = [3];

    /** @return \Closure(QueryBuilder): void */
    public static function endorserPredicate(): \Closure
    {
        return function (QueryBuilder $query): void {
            self::rosteredIn($query, self::ENDORSER_POSITIONS);

            // …and holds a live account. This is D9's "claimed", expressed as a join rather than
            // as a status column, so it cannot disagree with reality.
            $query->whereExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.person_id', 'people.id')
                    ->where('users.active', true)
                    ->whereNull('users.deleted_at');
            });
        };
    }

    /** @return \Closure(QueryBuilder): void */
    public static function consultantPredicate(): \Closure
    {
        return fn (QueryBuilder $query) => self::rosteredIn($query, self::CONSULTANT_POSITIONS);
    }

    /**
     * `whereNull('people.deleted_at')` is written EXPLICITLY: Rule::exists bypasses the
     * SoftDeletes global scope, and this same closure is used on both sides.
     *
     * @param  list<int>  $positions
     */
    private static function rosteredIn(QueryBuilder $query, array $positions): void
    {
        $query->whereIn('people.position', $positions)
            ->where('people.active', true)
            ->whereNull('people.deleted_at');
    }

    /** The write-side rule. @param  \Closure(QueryBuilder): void  $predicate */
    public static function rule(\Closure $predicate): Exists
    {
        return Rule::exists('people', 'id')->where($predicate);
    }

    /**
     * The offered list, from the SAME predicate.
     *
     * `$keep` is the id currently stored on the sheet. A stored id absent from the list renders
     * as a `<select>` with no matching `<option>`, and Sheet.vue's next submit then sends null —
     * silently clearing a recorded endorser on an unsigned day. It is appended flagged `retired`
     * and rendered disabled, so the value is visible and cannot be lost by accident. It is NOT
     * accepted by the rule: parity is per offered-and-selectable option.
     *
     * @param  \Closure(QueryBuilder): void  $predicate
     * @return list<array{id: int, name: string, retired?: bool}>
     */
    public static function offer(\Closure $predicate, ?int $keep = null): array
    {
        $query = Person::query()->orderBy('people.full_name');
        $predicate($query->getQuery());

        $list = $query->get(['people.id', 'people.full_name'])
            ->map(fn (Person $p): array => ['id' => (int) $p->id, 'name' => (string) $p->full_name])
            ->all();

        if ($keep !== null && ! in_array($keep, array_column($list, 'id'), true)) {
            $person = Person::withTrashed()->find($keep);

            if ($person !== null) {
                $list[] = ['id' => (int) $person->id, 'name' => (string) $person->full_name, 'retired' => true];
            }
        }

        return $list;
    }
}
```

- [ ] **Step 4: Rewrite the controller's picker surface**

In `app/Http/Controllers/EndorsementController.php`:

- Delete `ENDORSER_POSITIONS` (line 979) and `CONSULTANT_POSITIONS` (line 988); their docblocks
  move to `SignoffPickers` verbatim. Keep `SIGNATURE_PROXY_POSITIONS` (line 1000) where it is —
  it is about the *actor*, who is always an account.
- Delete `pickerRule()` (lines 1139-1153) and replace `staffPickers()` (lines 1155-1172) with:

```php
    /**
     * The staff pickers behind the four sign-off selects.
     *
     * Legacy left the two consultant fields as FREE TEXT — which is how a handover sheet ends up
     * attesting to a misspelled name. Both lists are real rostered PEOPLE, and since D9 they are
     * scoped differently: the endorser lists hold only people with a live account, because their
     * signature is the evidence; the consultant list holds any active person, because the
     * covering consultant is a name of record and frequently never logs in. The chosen NAME is
     * frozen into a `*_name` snapshot at write time (updateSignoff), so a later rename cannot
     * rewrite a signed sheet.
     *
     * @return array{endorsers: list<array{id: int, name: string, retired?: bool}>, consultants: list<array{id: int, name: string, retired?: bool}>}
     */
    private function staffPickers(?HandoverSignoff $signoff): array
    {
        return [
            'endorsers' => SignoffPickers::offer(
                SignoffPickers::endorserPredicate(),
                $signoff?->endorsed_by_person_id ?? $signoff?->endorsed_to_person_id,
            ),
            'consultants' => SignoffPickers::offer(
                SignoffPickers::consultantPredicate(),
                $signoff?->consultant_by_person_id ?? $signoff?->consultant_to_person_id,
            ),
        ];
    }
```

  Move the orphaned docblock at lines 1039-1047 (it currently sits above `recordRevisions()`'s
  own docblock, describing a method 100 lines away) onto this method — its
  *"Every list here is real, ACTIVE user accounts"* sentence is now false and is replaced above.

- `show()` line 242 becomes `'staff' => $this->staffPickers($signoffRow),` where `$signoffRow` is
  the `HandoverSignoff` the method already resolves for `signoffPayload()`. If `show()` does not
  currently hold that row, fetch it once and pass it to both.

- `updateSignoff()` lines 303-313:

```php
        // The id must be someone the picker would actually have OFFERED, per field. A bare
        // `exists:users,id` accepted any row in the table and the handler below then froze that
        // person's name AND their handwritten signature onto a medico-legal record; D9 narrows
        // it further, and offer and rule come from one predicate so they cannot drift.
        $endorser = SignoffPickers::rule(SignoffPickers::endorserPredicate());
        $consultant = SignoffPickers::rule(SignoffPickers::consultantPredicate());

        $data = $request->validate([
            'endorsed_by_person_id' => ['sometimes', 'nullable', 'integer', $endorser],
            'endorsed_to_person_id' => ['sometimes', 'nullable', 'integer', $endorser],
            'consultant_by_person_id' => ['sometimes', 'nullable', 'integer', $consultant],
            'consultant_to_person_id' => ['sometimes', 'nullable', 'integer', $consultant],
            'endorsement_time' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sign_off' => ['sometimes', 'boolean'],
        ]);
```

- Line 335: `unset($data['consultant_to_person_id']);`
- The freeze loop, lines 343-364:

```php
        foreach (['endorsed_by', 'endorsed_to', 'consultant_by', 'consultant_to'] as $field) {
            if (! array_key_exists($field.'_person_id', $data)) {
                continue;
            }

            $personId = $data[$field.'_person_id'];
            $signoff->{$field.'_person_id'} = $personId;
            // Freeze the name at write time; a later rename must not rewrite a signed sheet.
            $chosen = $personId === null ? null : Person::find($personId);
            $signoff->{$field.'_name'} = $chosen?->full_name;

            if (in_array($field, ['endorsed_by', 'endorsed_to'], true)) {
                [$path, $why] = $this->resolveSignature($request->user(), $chosen, $signing);

                $signoff->{$field.'_signature_path'} = $path;
                $provenance[$field === 'endorsed_by' ? 'sig_by' : 'sig_to'] = $why;
            }
        }
```

- Line 392: `if ($signing && $signoff->endorsed_by_person_id === null) {` and the error key
  becomes `endorsed_by_person_id`.
- `signoffPayload()` lines 952-961: rename the four `*_user_id` keys to `*_person_id` and read
  `$s?->endorsed_by_person_id` etc.

- [ ] **Step 5: Rewrite `resolveSignature()` to follow person → account**

Replace lines 1015-1037:

```php
    /**
     * Whose signature image this write may freeze, and why — the medico-legal heart of the sheet,
     * so it answers in one place and records its reasoning.
     *
     * The named party is a PERSON; the signature is on their ACCOUNT (`SignatureStore` is keyed
     * on `users`, and `ProfileController::updateSignature()` binds to the session identity). That
     * is precisely what keeps NAMING separate from SIGNING: a consultant can be named without an
     * account, but signing requires one.
     *
     * Returns [path|null, reason], where reason is one of:
     *   self      — applied; the actor is the person named
     *   proxy     — applied; the actor holds SIGNATURE_PROXY_POSITIONS
     *   withheld  — the named clinician HAS a signature, but this actor may not apply it
     *   unclaimed — the named person has no account, so there is no signature to apply
     *   none      — nobody named, or the named clinician has no signature on file
     *   draft     — this request does not attest the day, so nothing is frozen at all
     *
     * @return array{0: ?string, 1: string}
     */
    private function resolveSignature(?User $actor, ?Person $named, bool $signing): array
    {
        // An unsigned day carries no attestation, so it may carry no handwriting — not even the
        // drafter's own.
        if (! $signing) {
            return [null, 'draft'];
        }

        if ($named === null) {
            return [null, 'none'];
        }

        // Defence in depth: D9's rule already refuses an unclaimed endorser at validation, and
        // consultants never reach this branch (no consultant signature columns exist). A distinct
        // token means the audit trail can still say WHY, rather than reporting it as "no
        // signature on file".
        $account = $named->user;

        if ($account === null) {
            return [null, 'unclaimed'];
        }

        if ($account->signature_path === null) {
            return [null, 'none'];
        }

        if ($actor !== null && $account->getKey() === $actor->getKey()) {
            return [$account->signature_path, 'self'];
        }

        // `(int) $actor?->position` was the guard here, and (int) null is 0 — Administrator. An
        // unauthenticated actor was therefore granted the proxy path. Unreachable behind `auth`,
        // but it sat on the signature-forgery path.
        if ($actor !== null && in_array((int) $actor->position, self::SIGNATURE_PROXY_POSITIONS, true)) {
            return [$account->signature_path, 'proxy'];
        }

        return [null, 'withheld'];
    }
```

Add the `unclaimed` token to the documented set at lines 1006-1012 (done above) and to whatever
`docs/spec/` slice lists the provenance tokens — `grep -rn "withheld" docs/spec` to find it.

- [ ] **Step 6: The client**

`resources/js/Pages/Endorsement/Sheet.vue`:
- lines 195-200 — rename the four `signForm` keys to `*_person_id`, initialised from
  `props.signoff?.endorsed_by_person_id` etc.
- lines 222-230 — rename the four keys in `signPayload()`.
- lines 350, 358, 383, 391 — add `:disabled="s.retired"` to each `<option>` and append
  ` (no longer offered)` to the label when `s.retired`:
  ```html
  <option v-for="s in staff.endorsers" :key="s.id" :value="s.id" :disabled="s.retired">
      {{ s.retired ? `${s.name} (no longer offered)` : s.name }}
  </option>
  ```

`tests/js/EndorsementSignoff.test.js` — rename the four asserted payload keys at lines 94-97 and
the `signoff` fixture keys; add a case that a `retired: true` option renders and is disabled.

- [ ] **Step 7: Update the PHP tests that name the old fields**

Mechanical, ~40 lines across four files: `endorsed_by_user_id` → `endorsed_by_person_id` (and the
other three), and the value changes from `$user->id` to `$user->person_id`. Files and line
anchors:

- `tests/Feature/Endorsement/HandoverSignoffTest.php` — lines 141, 146, 167, 244-247, 255, 274,
  289, 316, 336, 371, 395-396, 432, 457, 485, 532, 553-554, 560, 562, 573. Also line 130's exact
  match `assertSame([$consultant->id], $consultants)` becomes
  `assertSame([$consultant->person_id], $consultants)`.
- `tests/Feature/Endorsement/SignatureAttributionTest.php` — lines 86-87, 108-109, 126-127, 145,
  160, 179, 202, 224. Add two cases: **(a)** a roster-only consultant named as `consultant_by`
  is accepted, freezes `consultant_by_name`, and leaves **no** signature path anywhere on the row;
  **(b)** `resolveSignature` returns `unclaimed` provenance if an endorser without an account ever
  reaches it (construct it by deleting the account between draft and sign).
- `tests/Feature/Endorsement/ReopenCapabilityTest.php` — line 66.
- `tests/Feature/Security/AuditHardeningTest.php` — lines 41-61 and 62-76. Extend
  `test_signoff_refuses_an_endorser_who_is_not_an_active_resident_or_chief` with a **roster-only
  Resident** (no account) and assert refusal; add
  `test_signoff_accepts_a_roster_only_consultant_who_has_no_account` asserting acceptance, a frozen
  `consultant_by_name`, and `consultant_by_person_id` set with no signature.
- `tests/Feature/Endorsement/HandoverSignoffTest.php:103`
  `test_the_endorser_pickers_list_active_residents_only` — rename to
  `..._list_active_residents_with_an_account` and add a roster-only Resident fixture that must not
  appear.

Also assert once, anywhere in `HandoverSignoffTest`, that a **new** write leaves the frozen legacy
columns alone: `$this->assertNull($s->endorsed_by_user_id);`.

- [ ] **Step 8: Green — both suites**

```powershell
php artisan test --filter "PickerParityTest|HandoverSignoffTest|SignatureAttributionTest|AuditHardeningTest" | Select-Object -Last 10
php artisan test | Select-Object -Last 5
npm test 2>&1 | Select-Object -Last 10
npm run build 2>&1 | Select-Object -Last 5
```

- [ ] **Step 9: Commit**

```bash
git add app/Support/SignoffPickers.php app/Http/Controllers/EndorsementController.php resources/js/Pages/Endorsement/Sheet.vue tests/
git commit -m "feat: D9 — endorsers need an account, consultants need only be on the roster"
```

---

### Task 7: The legacy import on the new shape

**Files:**
- Modify: `app/Console/Commands/LegacyImport.php:127-179` (`importUsers`), `:292-360` (`importSignoffsFor`)
- Modify: `tests/Feature/LegacyImportTest.php`

The import is one-way, read-only against its source, idempotent and audited, and only the owner
runs it against production (CLAUDE.md). It carries **real bcrypt hashes for real historical
users**, so the `password` value must continue to travel through the query builder and never
through the model's `hashed` cast.

- [ ] **Step 1: Write the failing test**

Extend `tests/Feature/LegacyImportTest.php`. Assert:

1. Importing legacy `members` creates one `people` row and one linked `users` row per member,
   with `people.full_name`/`position`/`email` from the legacy row and the bcrypt hash verbatim in
   `users.password`.
2. **Idempotence**: running the import twice leaves the same counts — no duplicate person.
3. A legacy member whose email already matches an existing **roster-only** person links onto that
   person rather than creating a second one (`Person::matchByEmail`).
4. `importSignoffsFor` resolves `endorsed_by_person_id` from legacy `member_id`, and leaves
   `endorsed_by_user_id` NULL.
5. The consultant fields stay **name-only** with `consultant_by_person_id` NULL — legacy stored
   free text and there is no person to resolve it against. The docblock must say so, because it
   looks like an omission.
6. Legacy position 1 (Nurse) rows are still skipped entirely — no person, no account.

- [ ] **Step 2: Rewrite `importUsers()`**

The `DB::table('users')->upsert(..., ['member_name'], ...)` at lines 151-165 becomes a per-row
match-then-write inside the existing transaction and chunk (roughly 80 rows; the cost is
irrelevant):

```php
                    $email = Person::normalizeEmail(Plausibility::cleanMissing($r->member_email ?? null));

                    // The account, if this member has been imported before.
                    $existingUser = DB::table('users')->where('member_name', (string) $r->member_name)->first();

                    // Match onto an existing PERSON before creating one: an imported roster and a
                    // legacy members table describe the same humans, and `people.email` is unique,
                    // so a blind insert would either 23000-crash mid-import or duplicate a person.
                    $personId = $existingUser->person_id
                        ?? (Person::matchByEmail($email)?->getKey());

                    $personAttributes = [
                        'institution_id' => $institutionId,
                        'full_name' => (string) ($r->full_name ?? $r->member_name),
                        'position' => $r->position === null ? 1 : (int) $r->position,
                        'email' => $email,
                        'active' => (bool) $r->active,
                        'updated_at' => $now,
                    ];

                    if ($personId === null) {
                        $personId = DB::table('people')->insertGetId(
                            $personAttributes + ['external' => false, 'created_at' => $now]
                        );
                    } else {
                        DB::table('people')->where('id', $personId)->update($personAttributes);
                    }

                    // Query-builder upsert on purpose: the model's 'hashed' cast would re-hash the
                    // already-bcrypt legacy hash and lock everyone out.
                    DB::table('users')->upsert([[
                        'person_id' => $personId,
                        'member_name' => (string) $r->member_name,
                        'member_email' => Plausibility::cleanMissing($r->member_email ?? null),
                        'active' => (bool) $r->active,
                        'pass_exp_date' => $this->cleanDate($r->pass_exp_date ?? null),
                        'password' => (string) $r->member_password,
                        'institution_id' => $institutionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]], ['member_name'], [
                        'person_id', 'member_email', 'active', 'pass_exp_date',
                        'password', 'institution_id', 'updated_at',
                    ]);
```

The `recordCount()` comparison at lines 171-178 is unchanged (it counts `users` by
`member_name`); add a second `recordCount('people', …)` alongside it.

- [ ] **Step 3: Rewrite the signoff member resolver**

`importSignoffsFor()` lines 300-314 — resolve to a **person id**, through the account link:

```php
        // legacy member_id → [people.id, full_name]. Small table (~80 rows); resolved once.
        // Resolution goes member_name → users → users.person_id, NOT member_name → people, because
        // the legacy identity IS the login handle and only `users` carries it.
        $members = [];

        if (Schema::connection($legacy->getName())->hasTable('members')) {
            $personIdByMemberName = DB::table('users')->pluck('person_id', 'member_name');

            foreach ($legacy->table('members')->get(['member_id', 'member_name', 'full_name']) as $m) {
                $members[(string) $m->member_id] = [
                    'person_id' => $personIdByMemberName[(string) $m->member_name] ?? null,
                    'name' => $m->full_name === null ? null : (string) $m->full_name,
                ];
            }
        }

        $resolve = fn (mixed $memberId): array => $members[(string) $memberId] ?? ['person_id' => null, 'name' => null];
```

Lines 339-342 become `'endorsed_by_person_id' => $by['person_id'],` /
`'endorsed_to_person_id' => $to['person_id'],`. Leave the frozen `*_user_id` columns unset.

Keep the comment at line 357 (*"Legacy free text — no account to resolve against"*) and extend it:
*"…and no person either. Historical consultant fields are a typed name and stay one; D9's wider
consultant scope applies to NEW writes, not to what legacy recorded."*

Add a note to the class docblock stating that this command deliberately **bypasses
`SignoffPickers`** — historical rows legitimately name people who no longer qualify, and
"fixing" that would rewrite the medico-legal record.

- [ ] **Step 4: Green, commit**

```powershell
php artisan test --filter LegacyImportTest | Select-Object -Last 10
php artisan test | Select-Object -Last 5
```

```bash
git add app/Console/Commands/LegacyImport.php tests/Feature/LegacyImportTest.php
git commit -m "feat: the legacy import writes people and links accounts to them"
```

---

### Task 8: One identity lifecycle

**Files:**
- Create: `database/migrations/2026_08_10_120005_add_person_id_to_invitations.php`
- Modify: `app/Models/Invitation.php:54-70`
- Modify: `app/Http/Controllers/Admin/InvitationController.php:36-118`
- Modify: `app/Http/Controllers/Auth/InvitationAcceptController.php:55-126`
- Modify: `app/Http/Controllers/Admin/UserManagementController.php:112-171, 343-359`
- Modify: `tests/Feature/Auth/InvitationTest.php`, `tests/Feature/Admin/UserManagementTest.php`, `tests/Feature/Admin/ChiefResidentTest.php`
- Test: `tests/Feature/Identity/ClaimLifecycleTest.php`

**The reconciled lifecycle, stated once so every future reader has it:**

```
  people row (roster)  ──issue──▶  invitations row OPEN  ──redeem──▶  users row (person_id set)
        │                                │                                 = "claimed"
        │                                └─ revoked / expired ──▶ person stays, no live credential
        │
        └─ people.active = false : stops being NAMEABLE (users.active separately stops LOGIN)

  pending_registrations (frozen legacy queue, no writer)  ──approve──▶ people row + users row
```

`person_status` does not exist. "Claimed" is *a `users` row exists*, which is a join and therefore
cannot disagree with reality. `invitations` and `pending_registrations` are the two doors and they
now terminate in the same place.

**Retention (recon 1 §R8):** *abandoned credentials expire; people do not.* Nothing in this task
teaches `DataRetention` to touch `people` or `users`. `invitations` still has no retention rule
and still accumulates `member_email`; record that as a follow-up in Task 9, do not build it here.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Identity/ClaimLifecycleTest.php`. Assert:

1. **Inviting a rostered person links to them, and creates no duplicate.** Create a roster-only
   person with `email = 'dr.x@example.org'`; `POST /admin/invitations` with `Dr.X@Example.ORG`
   succeeds, `Person::count()` is unchanged (plus the admin's own), and the invitation's
   `person_id` is that person.
2. **Inviting an unknown address creates the person at issue time**, with the invitation's
   position, `active = true` and no account.
3. **Inviting an address that already has an ACCOUNT is refused** with a validation error on
   `member_email` — that is what `Rule::unique('users','member_email')` used to mean and still
   should.
4. **Redemption claims the existing person**: `POST /invitation/{token}` creates the `users` row
   with `person_id` equal to the invitation's person, `Person::count()` unchanged, and
   `people.email === users.member_email`.
5. **Redemption does not let the invitee rename a rostered person.** The person's `full_name` was
   `'Dr Roster Name'`; the invitee submits `'Someone Else'`; afterwards `people.full_name` is
   still `'Dr Roster Name'`. (For a person created at issue time, whose name is blank, the
   submitted name **is** taken.)
6. **Redemption is still single-use under concurrency** — the existing
   `lockForUpdate()` + `isOpen()` case in `InvitationTest` must still pass.
7. **`AccessControl::flush()` is called on claim**: grant the person's future account a capability
   through a role default, claim, and assert the new account resolves it immediately (no 600 s
   cache lag).
8. **`approve()` promotes a pending registration into a person + account** with the hash verbatim,
   and `assertStillUnique()` still 422s on a colliding `member_name`, but **no longer** 422s
   merely because a roster-only person holds that email.
9. A superseded/revoked invitation leaves the person in place with no account.

- [ ] **Step 2: Run it, watch it fail** — `php artisan test --filter ClaimLifecycleTest | Select-Object -Last 20`

- [ ] **Step 3: The migration**

```php
Schema::table('invitations', function (Blueprint $table) {
    // The invitation is now issued TO A PERSON. `member_email` and `position` stay: they are the
    // frozen terms of this particular invitation, and a rostered person's details can change
    // between issue and redemption without silently changing what was offered.
    $table->foreignId('person_id')->nullable()->after('institution_id')
        ->constrained('people')->nullOnDelete();
    $table->index('person_id');
});
```

Nullable, because rows issued before P0c have no person. `Invitation::redeemable()` is unchanged.

- [ ] **Step 4: Issue against a person**

`app/Models/Invitation.php:54-70` — `issue()` gains a `?Person $person` argument and writes
`person_id`.

`app/Http/Controllers/Admin/InvitationController.php:36-47` — replace the `member_email` rules:

```php
            'member_email' => [
                'required', 'email', 'max:255',
                // NOT ALREADY AN ACCOUNT. This used to read `unique:users,member_email`, which
                // after a roster import refuses exactly the people the invitation exists to
                // invite — an address on the roster is the NORMAL case, not a collision. A
                // person without an account is matched onto below; a person WITH one already
                // has what this link would give them.
                Rule::unique('users', 'member_email')->withoutTrashed(),
                Rule::unique('pending_registrations', 'member_email'),
            ],
```

The rule text is unchanged; the **comment** changes because the meaning changed — soft-deleted
accounts still occupy the unique index, and a roster-only person no longer appears in `users` at
all, so this rule now says the right thing by construction. Add the match-or-create between
line 52 (`$email = Str::lower(trim(...))`) and line 61 (the supersede scan):

```php
        // Match onto the roster before creating anything (design §5.2.4). An imported person who
        // is later invited must be the SAME person, not a second row with the same name.
        $person = Person::matchByEmail($email) ?? Person::create([
            'institution_id' => $request->user()?->institution_id,
            // Blank until the invitee tells us; a person created here is a placeholder for
            // someone the roster does not yet know.
            'full_name' => '',
            'position' => (int) $data['position'],
            'email' => $email,
            'active' => true,
        ]);

        if ($person->trashed()) {
            $person->restore();
        }
```

…and pass `$person` to `Invitation::issue()` at line 85. The audit detail at line 89 gains
` person=`.$person->id` — an id, never an address.

- [ ] **Step 5: Redeem by claiming, not inserting**

`app/Http/Controllers/Auth/InvitationAcceptController.php` — replace the transaction body written
in Task 2 Step 5:

```php
        $userId = DB::transaction(function () use ($data, $invitation): int {
            $locked = Invitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isOpen()) {
                throw new \RuntimeException('invitation-consumed');
            }

            $now = now();

            // CLAIM the person this invitation was issued to — never insert alongside them. The
            // old code did an unconditional INSERT into `users`, which collided on the unique
            // `member_email` the moment the invitee was already on the roster, and surfaced as an
            // opaque 500. Under D3-reversed the collision cannot happen (roster people are not in
            // `users`), but the identity would still fork into two humans without this.
            $person = $locked->person_id === null
                ? Person::create([
                    'institution_id' => $locked->institution_id,
                    'full_name' => $data['full_name'],
                    'position' => $locked->position,
                    'email' => Person::normalizeEmail($locked->member_email),
                    'active' => true,
                ])
                : Person::withTrashed()->lockForUpdate()->findOrFail($locked->person_id);

            if ($person->trashed()) {
                $person->restore();
            }

            // The ROSTER is the name of record. A person the department already knows keeps the
            // name the department gave them — an invitee must not be able to rename themselves
            // onto a signed sheet. A placeholder created at issue time has no name yet, so the
            // one they supply is taken.
            if (trim((string) $person->full_name) === '') {
                $person->full_name = $data['full_name'];
            }

            $person->active = true;
            $person->save();

            $userId = DB::table('users')->insertGetId([
                'person_id' => $person->getKey(),
                'institution_id' => $locked->institution_id,
                'member_email' => $locked->member_email,
                'member_name' => $data['member_name'],
                'password' => Hash::make($data['password']),
                'active' => true,
                // Redeeming a link delivered to this address IS the proof of the address.
                'email_verified_at' => $now,
                'pass_exp_date' => $now->copy()->addDays(90)->format('Y-m-d'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $locked->forceFill(['accepted_at' => $now, 'accepted_user_id' => $userId])->save();

            return $userId;
        });

        // Capabilities resolve from the person's position and are cached for 600 seconds per user
        // id. A brand-new id has no entry, but the flush is written explicitly so that a future
        // re-claim of a recycled account cannot serve a stale set.
        AccessControl::flush($userId);
```

Do **not** add a `Rule::unique('people', 'email')` to the request validation at lines 66-75: the
address is never submitted — it travels with the invitation, which is the property that makes this
flow un-tamperable. Assert it inside the transaction instead, immediately before the insert, so
the check runs against the locked row rather than against a read from seconds earlier:

```php
            $collision = Person::withTrashed()
                ->where('email', Person::normalizeEmail($locked->member_email))
                ->whereKeyNot($person->getKey())
                ->exists();

            if ($collision) {
                throw ValidationException::withMessages([
                    'member_name' => 'This invitation can no longer be completed. Ask the person who invited you to send a new one.',
                ]);
            }
```

- [ ] **Step 6: The frozen pending queue terminates in the same place**

`app/Http/Controllers/Admin/UserManagementController.php:132-161` — `approve()` (already given a
`people` insert in Task 2 Step 5) now uses `Person::matchByEmail()` first, so approving a pending
registration for someone already on the roster claims them rather than duplicating.

`…:343-359` — `assertStillUnique()` keeps both `User::withTrashed()` checks (they still guard the
right table) and gains a third: `Person::matchByEmail($pending->member_email)` that already has an
account is the same collision, reported the same way. A roster-only match is **not** a collision —
it is the match we want.

Add a class-docblock paragraph to `UserManagementController` recording that
`pending_registrations` has had no writer since 2026-07-27, that its only remaining exits are
approve / reject / the 30-day `data:retention` sweep, and that **removing the queue entirely is
deferred until `InvitationController::pendingCount()` reads zero on production** — the owner
observes it, the owner decides.

- [ ] **Step 7: Green, commit**

```powershell
php artisan test --filter "ClaimLifecycleTest|InvitationTest|UserManagementTest|ChiefResidentTest" | Select-Object -Last 10
php artisan test | Select-Object -Last 5
```

```bash
git add database/migrations/2026_08_10_120005_add_person_id_to_invitations.php app/ tests/
git commit -m "feat: one identity lifecycle — an invitation is issued to a person and claimed by them"
```

---

### Task 9: Correct the documents this invalidates

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md` (§5, §12, §15, §14)
- Modify: `docs/spec/` — the slices describing users, pickers and sign-off (`grep -rln "member_name\|pickerRule\|endorsed_by" docs/spec`)
- Modify: `docs/COMPLIANCE.md`, `docs/PDPL-PACK.md`
- Modify: `docs/SECURITY-AUDIT-2026-07-26.md`

All of these are read as law by future sessions and all of them now say something false.

- [ ] **Step 1: `CLAUDE.md`**

Under *Domain vocabulary*, replace the endorser/consultant paragraph:

```
- Endorsed by/to pickers: active Residents (4) and Chief Residents (5). Consultants: position 3.
  WARD has a single "Consultant Oncall" stored in `consultant_by_*`.
```

with

```
- Identity is TWO tables: `people` is the roster (name of record, `short_name`, `position`,
  level history, contact, `constraints`, `external`, `active` = may be NAMED); `users` is the
  auth record (`member_name`, `member_email`, `password`, 2FA, signature, `active` = may LOG
  IN), linked by `users.person_id` (UNIQUE, nullable). A person with no `users` row cannot
  authenticate BY CONSTRUCTION — never add a credential column to `people`, and never
  reintroduce a `person_status` lifecycle enum: "claimed" is a join.
- Endorsed by/to pickers: active people at position 4 or 5 WHO HAVE A LIVE ACCOUNT (D9 — their
  signature is the evidence). Consultant pickers: any active person at position 3, account or
  not. Both come from ONE predicate per field in `App\Support\SignoffPickers`. WARD has a single
  "Consultant Oncall" stored in `consultant_by_*`.
```

Under *Invariants the 2026-07-26 audit had to restore*, replace the `pickerRule()` bullet with:

```
- A picker's write-side validation must match what it OFFERS, PER FIELD since D9. `exists:users,id`
  let any account be named as endorser — and sign-off freezes that person's signature onto
  medico-legal evidence. `App\Support\SignoffPickers` holds one predicate per field, applied to
  both the `Rule::exists` and the offered list, because `Rule::exists` bypasses the SoftDeletes
  global scope and a predicate written twice is two predicates. `tests/Feature/Endorsement/PickerParityTest.php`
  asserts it as a matrix.
- `handover_signoffs`' four NAMED roles are `*_person_id`; `signed_off_by_user_id` and
  `reopened_by_user_id` are `users` — names of record versus actors. `people.id` and `users.id`
  are independent sequences: never move an id between them without a join.
```

- [ ] **Step 2: The design doc**

Replace §5's superseded body (lines 277-341) with the shipped shape: the `people` column list, the
lifecycle diagram from Task 8, the `users.member_email` denormalization and why, the three
deviations from the table at the top of this plan (`short_name` uniqueness, no `level_id`, no
`status`), and a line recording that the six §5.2 mitigations are **withdrawn as unnecessary** —
naming which risk each one addressed and which structural property now covers it. Keep §5.3
(D9) as it stands; it shipped.

Correct §12's PHPUnit list: strike *"the `person_status` CHECK constraints"*, add *"`people`
carries no credential column (asserted by name)"* and *"the roster-only-cannot-authenticate matrix
across all six credential paths"*. Correct §15's risk row *"One `users` table weakens auth
invariants"* to record the reversal and its outcome. Add to §14 Open items: **invitations still
have no retention rule and accumulate `member_email` indefinitely** (recon 1 §R8), and **removing
`pending_registrations` awaits a production count of zero**.

- [ ] **Step 3: The spec slices**

`grep -rln "member_name\|pickerRule\|endorsed_by" docs/spec` and correct each hit: the picker
population, the four sign-off field names, and any statement that `users` is the staff roster.

- [ ] **Step 4: The compliance pack**

`docs/COMPLIANCE.md` and `docs/PDPL-PACK.md` describe `users` as the staff-account table. Add a
paragraph to each:

- `people` holds personal data (name, `email`, `phone`, `notes`, `joined_at`) about staff **who may
  never have created an account** — external rotators in particular. Lawful basis is the
  employment/rota relationship, not consent to a login.
- `notes` is encrypted at rest; `phone` and `notes` are `$hidden` on the model and must never
  reach `audit_log.detail`, exception messages, URLs or push payloads — the same rule as PHI.
- Accounts are deactivated, never deleted; **people are deactivated, never deleted** — the four
  named roles on `handover_signoffs` depend on the row remaining resolvable.
- Subject-access and erasure now have to answer for two tables. Note the DPIA needs re-signing by
  the system owner (it was signed in `bb7e1d7` against a one-table model).

- [ ] **Step 5: `docs/SECURITY-AUDIT-2026-07-26.md`**

Add a dated addendum: the single-scope `pickerRule()` fix described there became per-field on
2026-08-08 (D9); the invariant is unchanged and is now machine-checked by `PickerParityTest`.

- [ ] **Step 6: Verify and commit**

```powershell
php artisan test | Select-Object -Last 5
npm run build 2>&1 | Select-Object -Last 5
```

```bash
git add CLAUDE.md docs/
git commit -m "docs: identity is two tables; correct the rules and the pack that said otherwise"
```

---

## Definition of done

- `php artisan test` passes with **no fewer tests than before Task 1**, and `npm test` and
  `npm run build` are green.
- `Select-String -Path app\,database\ -Pattern "users\.(full_name|position)|'person_status'" -Recurse`
  returns nothing.
- `tests/Feature/Auth/RosterOnlyCannotAuthenticateTest.php` passes, including the assertion that
  `people` carries no credential column — the structural proof, machine-checked.
- `tests/Feature/Endorsement/PickerParityTest.php` passes: for every fixture in the matrix and
  every one of the four fields, offered ⟺ accepted.
- A roster-only person can be named as `consultant_by`, freezes a name on the signed sheet, and
  acquires **no** signature path anywhere.
- A roster-only person cannot be named as `endorsed_by` or `endorsed_to`.
- Inviting someone already on the roster links to their existing person; redeeming claims that
  person; `Person::count()` does not move.
- The four `handover_signoffs` `*_person_id` columns are populated for every historical row that
  had a `*_user_id`, resolved **through `users.person_id`**, and new writes leave the legacy
  columns NULL.
- `docs/RUNBOOK-DEPLOY.md` carries the verification query for each of the three data migrations,
  the pre-migration dump requirement, and the one permitted rollback order.
- `CLAUDE.md` describes two identity tables and the per-field picker rule.

## Next plan

**P0d — Tenancy & provisioning (D11):** the database-per-customer provisioning script, the
`institution_id` defence-in-depth review now that a second identity table carries it, and the
reserved-unit-code guard (design §14 item 6) that must land before any admin UI can create units.
Depends on this plan for `people`; nothing else.
