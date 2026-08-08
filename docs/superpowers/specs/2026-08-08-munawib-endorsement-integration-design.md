# Munawib × Endorsement — Integration Design

**Date:** 2026-08-08 · **Status:** Draft, awaiting owner review
**Inputs:** the Munawib build prompt + Spec v1.0, frozen 2026-08-08 (currently at
`C:\Users\ahmed\Downloads\munawib-claude-code-build-prompt-v1.md`; **P0's first action is to
save its Part B into this repo as `docs/munawib/SPEC.md`**, per Munawib §A0) · this repo's
`docs/spec/` slices · the QHN Block 11 prototype review
(`C:\Users\ahmed\Documents\qhn-block11-schedule-review.md`)

This document designs **the seam only** — where the Munawib duty-rota platform and the
Paediatric Endorsement System join, and which stack hosts what. Munawib's internal
specification (domain model, conditions catalog, ranked hints, solver, five stages) is
frozen and is **not** re-opened here; it is carried forward intact. What was genuinely
undesigned, and what this document settles, is the merge.

---

## 1. Decisions taken (owner, 2026-08-08)

| # | Question | Decision |
|---|---|---|
| D1 | How tightly joined? | **One Laravel application.** Munawib becomes a module inside this codebase: one login, one database, one deployment, one audit chain, one backup. |
| D2 | Who is it for? | **Full platform now.** Both modules become department-agnostic immediately; PICU/NICU/SCBU/WARD become seed data, not code. |
| D3 | Person vs account? | **One `users` table holds everyone**, including people who never log in. |
| D4 | Where does the conditions engine run? | **One TypeScript engine, two runtimes** — browser for hints, Node sidecar for server-side authority. |
| D5 | Which cross-module links? | **All four** (§8). |
| D6 | Go-live? | **Single launch.** QCH paediatrics waits for the whole platform; endorsement does not ship separately. |

### 1.1 Munawib decisions this overrides

Munawib Part A §A8 reserves stack-level changes to the owner. D1 and D3 are such changes
and are recorded here as deliberate, authorized overrides:

| Munawib clause | Original | Superseded by |
|---|---|---|
| §A5 frozen stack | React 18 + Vite SPA; Firebase Auth/Firestore/Hosting/Functions | Laravel 13 + Inertia + Vue 3 + MySQL (this repo's §2 stack) |
| §AR-01 | One isolated Firebase project per department | One Laravel instance, multi-institution via the existing `institutions` anchor |
| §AR-02 | Firestore listeners are the only view updaters | Inertia server-state re-reads; polling for liveness (§3.3) |
| §AR-03 | Pure TS engine for hints **and** server validation/reports | Retained verbatim — via a Node sidecar (§4) |
| §AR-05 | Firestore collection model | Relational equivalents (§6); semantics binding, names adjusted |
| §PE-03 / AC-01 | `people` separate from accounts | Single `users` table with a `person_status` discriminator (§5) |

Everything else in Munawib Spec v1.0 stands as written. Requirement IDs (MR-03, CG-07,
AU-06…) remain the binding vocabulary and are cited throughout.

### 1.2 Naming

The platform builds under **Munawib**; the display name is configuration (Munawib §A8.5).
The two modules are **Endorsement** (shift handover, holds PHI) and **Rota** (scheduling,
holds none). The final product name is an open item and blocks nothing.

---

## 2. What this merge buys

Stated plainly, because it justifies the cost in §3.2:

- **One identity, one directory.** Residents, consultants, and nurses exist once.
- **One audit chain.** Every scheduling write joins the existing append-only hash-chained
  `audit_log` with `audit:verify` already built. Munawib AD-01 arrives stronger than specced.
- **One compliance posture.** One backup regime, one encryption story, one data-residency
  answer, one PDPL pack — instead of splitting staff personal data into a second provider
  and jurisdiction.
- **Structural concurrency safety.** Munawib SC-03 forbids whole-doc last-write-wins on
  shared mutable data. One assignment per relational row satisfies that by construction;
  the prototype's concurrency problem cannot recur.
- **Cross-module intelligence** the prototype could never have (§8).

---

## 3. Architecture

### 3.1 Topology

The existing Coolify Compose stack gains two sidecars, both on the `internal` network only,
neither publicly routable — the isolation pattern already used for MySQL.

| Container | Role | Network |
|---|---|---|
| `app` | Laravel 13 + Inertia/Vue 3 + Tailwind 4 (unchanged shape) | `coolify`, `internal` |
| `db` | MySQL 8.4 (unchanged) | `internal` |
| `engine` | **new** — Node; hosts the compiled TypeScript conditions engine; JSON over HTTP; stateless | `internal` |
| `solver` | **new** — Python OR-Tools CP-SAT; JSON contract per Munawib §17; stateless | `internal` |

Node still never ships inside the app image — the Dockerfile's deliberate property holds,
because `engine` is a separate container rather than something PHP shells out to.

Repo layout (additive to the current tree):

```
packages/engine/        # pure TypeScript conditions engine + golden fixtures; zero framework imports
services/engine/        # thin Node HTTP wrapper around packages/engine
services/solver/        # Python CP-SAT service (Munawib §17 contract)
```

The browser bundle imports `packages/engine` directly through Vite, so hints run
client-side and never block on the network (Munawib UX-05, NF-01: <100 ms p95).

### 3.2 The cost of D2, stated honestly

"Full platform now" means generalizing a hardened, nearly-production system before it has
run a single real handover. That is the largest single piece of work in this design and it
touches the handover sheet, the print view, the day lifecycle, and their tests.

Two things make it the right trade anyway: there is **no production data to migrate and no
users to disrupt** today, and this repo's existing rule — *unit variation lives in ONE
place, `App\Support\UnitProfile`* — means the change is a data migration plus a
configuration surface, not a rewrite. Deferring it until after go-live would convert it
into a live-schema migration with downtime.

### 3.3 Liveness without Firestore

Munawib's most successful inherited pattern is law here: **writes go to the database;
no view ever mutates local schedule state.** In Inertia terms, every mutation is a
POST/PATCH/DELETE to Laravel and views re-read from server state.

Where Firestore's `onSnapshot` gave live updates, a cheap per-schedule version endpoint
(an integer bumped on every assignment write) covers the who's-on-call board and
concurrent draft editors. Poll interval is configuration, default **5 seconds**, and the
endpoint returns `304` when unchanged. This is a deliberate downgrade with a defined
upgrade path: Laravel Reverb slots in behind the identical contract if polling proves
insufficient under real use. Recorded as a decision, not an oversight.

---

## 4. The conditions engine (D4)

Munawib's second ground rule — **one engine** — is the one most at risk in a PHP host, and
it is the rule the prototype's failure history most justifies. The resolution:

- **`packages/engine`** is the single definition of condition semantics, in pure TypeScript
  with no Firebase and no framework imports. It implements the full CG-07 catalog and the
  CG-10 contract: `(schedule, config, conditions) → violations[]`.
- **Browser runtime:** bundled by Vite. Powers live hints, fitness-ordered pickers, and the
  client-side greedy fallback (AU-05). Instant, offline-tolerant, no round trip.
- **Server runtime:** the `engine` container runs the same compiled package behind a JSON
  endpoint. Laravel calls it for authoritative validation, the publish gate (CG-05), and
  compliance reports (TL-03).
- **Solver mapping:** `services/solver` maps the same conditions to CP-SAT constraints and
  rank-weighted penalties. This is the one permitted second implementation, exactly as
  Munawib AR-03 already contemplates.
- **The CI job that keeps them honest is non-negotiable:** one golden-fixture suite runs
  through both the TS engine and the Python mapping, asserting identical verdicts. A
  divergence fails the build.

No PHP implementation of the rules exists anywhere. That is the point.

---

## 5. Identity model (D3)

`users` holds everyone who appears in a rota or on a handover sheet, whether or not they
ever authenticate.

**Additions to `users`:**

| Column | Purpose |
|---|---|
| `person_status` | `roster_only` \| `invited` \| `claimed` \| `deactivated` |
| `short_name` | the rota handle (Munawib `shortName`); distinct from `member_name`, the login handle |
| `level_id` | current training level (PGY ladder); history in `user_levels` |
| `phone`, `joined_at`, `notes` | Munawib PE-01 |
| `constraints` | JSON, structured per-person constraints (PE-01) |
| `external` | ad-hoc external rotator flag (PE-03) |

**`position` (job role: Consultant, Resident, Charge Nurse…) and `level_id` (training
level: PGY-1…4, External) are orthogonal axes and both are retained.** A person is a
Resident *and* PGY-2. Collapsing one into the other would break either the endorsement
capability model or Munawib's level-based coverage templates.

### 5.1 Safety mitigations

D3 weakens invariants the 2026-07-26 audit specifically hardened. These mitigations are
requirements, not suggestions:

1. `password` and `member_name` are nullable **only** for non-claimed rows, enforced by a
   MySQL `CHECK` constraint — engine-level, not convention. The unique index on
   `member_name` tolerates multiple NULLs.
2. **Authentication is gated in exactly one place.** The auth user provider refuses any row
   whose `person_status <> 'claimed'` or `active = false`. A roster-only row can never
   authenticate, hold a session, or be issued a token, regardless of what other code does.
3. Capability resolution returns nothing for non-claimed rows.
4. **Naming and signing are separated.** A roster-only person may be *named* as
   endorsed-by, endorsed-to, or consultant-on-call. Freezing a signature at sign-off still
   requires a claimed account with a stored signature. Consultants who never log in appear
   on the rota without weakening medico-legal evidence.
5. `pickerRule()` is rewritten to scope by institution + unit eligibility + position/level
   + status, so **write-side validation matches what the picker offers** — the 2026-07-26
   invariant, now stricter than before.

---

## 6. Data model

### 6.1 Tenancy and configuration (D2)

`institutions` remains the tenant anchor; every new table carries `institution_id`.

- **`UnitProfile` becomes a per-unit configuration record.** Row-identity columns
  (bed / mrn / patient_name / dob / age / ward_unit), the consultant sign-off shape
  (by+to versus WARD's single "Consultant Oncall"), print column labels, and the hue token
  all become data on the unit row. `EndorsementController::UNIT_CODES` and the four-unit
  assumption are removed. PICU/NICU/SCBU/WARD become seed rows for the QCH paediatrics
  institution.
- `units` gains Munawib UN-02's three independent capability flags (training rotation /
  on-call coverage target / clinic owner) and UN-03 import aliases.
- `positions` become admin-managed per institution. **Capabilities stay code-defined**
  (they name features); role→capability defaults stay data. Position 1 (Nurse) remains
  retired and is never reused.
- New: `levels` (LV-01), `user_levels` (LV-04 effective-dated history).

### 6.2 Rota tables

Munawib §AR-05's collections become Eloquent tables. Semantics binding, names adjustable:

| Table | Munawib origin |
|---|---|
| `periods` | calendar months or week-blocks (MR-01) |
| `master_rota_assignments` | person × period × unit, date-bounded splits (MR-02) |
| `vacations` | week or exact-date granularity (MR-03) |
| `clinics`, `clinic_attendees` | CL-01, CL-02 |
| `slots` | SL-01 (kind, window, cadence, days, unit, counts_hours, tally_key) |
| `coverage_templates` | SL-03 (slot × day type → ordered level requirements, min/target/composition) |
| `conditions` | CG-01 (type_key, params, scope, class hard\|soft, rank, active, source) |
| `schedules` | draft \| published \| archived, version |
| `assignments` | **source of truth**, one row per placement. Munawib's `dayIndex` render index is *not* carried over — it existed to make Firestore reads cheap, and a relational query over indexed `(schedule_id, date, slot_id)` replaces it without a denormalized copy to keep in sync. |
| `ignored_warnings` | CG-06 ledger (who ignored what, when) |
| `coverage_overrides` | MC-01/MC-03 |
| `requests` | RQ-01 including swap payloads (§23) |
| `holidays` | greg or hijri rule, equity_tracked |
| `schedule_changes` | PU-02 versioned change log |
| `notifications`, `mail_queue` | §25 |
| `feeds` | tokenized, revocable (IN-01, PS-02) |
| `archives` | snapshot at publish-over (PU-01) |

**Audit:** all Rota writes join the existing hash-chained `audit_log`. This must respect
the standing invariant — the canonical string has exactly one definition
(`AuditChain::canonical()`), and a stored naive datetime is never re-parsed in the current
timezone.

---

## 7. Calendar

One `App\Support\Calendar` (PHP) plus a mirrored calendar inside `packages/engine` for
client-side date math. Both read the same per-institution configuration: period type,
weekend days, Hijri display on/off, `hijri_offset_days`, timezone (default `Asia/Riyadh`),
academic-year start. **Nothing outside those two converts dates** (Munawib AR-08).

The QHN prototype established `hijri_offset_days = −1` for this hospital, verified against
the department's own published calendar across a month boundary; that value seeds the QCH
institution.

The calendar converts for display and for scheduling day-boundary math only — **never** for
audit canonicalization, which stays byte-verbatim.

---

## 8. Cross-module links (D5)

| # | Link | Mechanism | What crosses the boundary |
|---|---|---|---|
| **L1** | **Rota fills endorsement's people fields** | `OnCallDirectory::forUnitAt(unit, instant)` returns users rostered to that unit's slots covering the instant. Endorsed-By / Endorsed-To pickers default and scope to it; WARD's Consultant Oncall defaults to the scheduled on-call consultant. An audited override remains, because reality diverges from the rota. | user ids, unit ids |
| **L2** | **Handovers feed duty evidence** | `HandoverEvidence` exposes sign-off timestamps and rostered-versus-actual for the duty-hour compliance report (TL-03); the missed-days view names who was rostered on a day with no sheet. | timestamps, unit ids, user ids, counts |
| **L3** | **Unified daily surface** | A *My Day* page per person: calls, clinics, rotation, and their own pending or unsigned handovers. The morning coverage board gains a per-unit handover-status chip (signed / in progress / missing). | status enums, unit ids |
| **L4** | **One notification stream** | `push_subscriptions` and handover-time reminders merge with `mail_queue` and the notification centre into one event model, one fan-out (push / mail / in-app), one preferences screen. Munawib NT-05 holds: approval-chain emails cannot be muted where the person is a required actor. | — |

---

## 9. The PHI boundary

Enforced, not intended:

1. Rota models and queries **never** reference `handovers` or `handover_revisions`.
2. All cross-module reads go through the two named query services in §8, which return only
   ids, timestamps, counts, and status enums.
3. A **guard test** asserts the Rota namespace references no PHI model or column — the same
   species of test as `CompiledCssIsLightOnlyTest`, which exists because conventions decay
   and tests do not.
4. The `engine` and `solver` containers receive **no PHI, ever**. Their payloads are people
   ids, dates, slots, and conditions. Neither has a public route.
5. The standing project rule is unchanged and now covers the Rota module too: no PHI in
   URLs, query strings, logs, `audit_log` details, exception messages, or push payloads.

---

## 10. Degradation

| Failure | Behaviour |
|---|---|
| `engine` container down | Hints keep working (browser runtime). Server-side validation and the publish gate **fail closed** with a clear message. Nothing publishes unvalidated. |
| `solver` container down | Auto-generation unavailable; the manual workbench is untouched; the client-side greedy fallback (AU-05) runs, labelled as heuristic. |
| Solver returns infeasible | Scheduler-readable conflict report naming the tightest constraints and where (AU-07). Never a silent under-fill. |
| Either sidecar unhealthy | Compose healthchecks report it. **`/up` deliberately does not depend on the sidecars** — it stays a pure database-reachability probe (2026-07-26 invariant); a scheduling sidecar being down must not mark the clinical application unhealthy. |

---

## 11. Testing

TDD throughout — failing test first, tree deployable after every commit, `php artisan test`
and `npm run build` green before any commit.

- **Engine:** exhaustive unit tests per condition type plus golden fixtures (QA-01).
- **Cross-validation:** the same golden fixtures run through the Python constraint mapping
  in CI. Divergence fails the build. This job is the single most important test in the
  repository.
- **Solver:** property tests (hard constraints never violated; coverage minima always met
  when feasible; infeasibility reported per AU-07) plus the AU-06 regeneration test against
  archived prototype months.
- **PHPUnit:** tenancy scoping, capability enforcement, `pickerRule()` offer/validation
  parity, the `person_status` CHECK constraints, the roster-only-cannot-authenticate gate,
  the no-PHI guard test, audit-chain integrity.
- **Playwright:** the merged journey end to end — invite → claim → request → approve →
  draft → auto-fill → manual fix → publish → handover with rota-filled pickers → sign-off →
  swap → sick replacement.
- Existing guard tests stay green: light-theme-only compiled CSS, audit canonical string.

---

## 12. Sequencing (D6 — single launch)

No intermediate production deployment. The tree stays deployable after every commit, but
QCH paediatrics goes live once, with both modules ready.

| Phase | Content |
|---|---|
| **P0 — Platform foundation** | Units and `UnitProfile` become configuration; `levels` and `user_levels` added; `users` extended with the §5 columns and CHECK constraints; auth gate consolidated; tenancy sweep; `pickerRule()` rewritten. Endorsement stays green throughout. |
| **P1 — Munawib Stage 1** | People, invitations, roles on the merged identity; master rota (both period systems, splits, vacations, import/export, publish view); clinics; holidays. |
| **P2 — Engine** | `packages/engine` with the full CG-07 catalog, golden fixtures, plain-language previews, severity/rank model; `services/engine` container; the CI cross-validation job. |
| **P3 — Munawib Stage 2** | Slots, call windows, coverage templates, conditions gate with drag ranking, draft workbench with live hints, trackers, undo ≥30, unfilled lens, publish + archive, morning coverage, who's-on-call board, personal pages, tallies, exports. **Cross-link 1 lands here.** |
| **P4 — Munawib Stage 3** | Solver service and contract, ranked-sacrifice report, per-placement explanations, partial modes, infeasibility reporting; requests with deadlines and reminders; approval queue with coverage impact; versioned change log; ICS feeds. **Cross-link 4 lands here.** |
| **P5 — Munawib Stage 4** | Swaps (same-level, dual approval, offer-to-many), backup slots and sick-replacement flow, equity and holiday equity, duty-hour compliance (ACGME preset), audit and version browser, read-only feed and webhooks, condition builder. **Cross-link 2 lands here.** |
| **P6 — Launch** | Prototype data migration (FL-05) dry-run then live; `security-pan-check:security-audit` and `prod-ready:audit` green; runbooks updated; single go-live for QCH paediatrics. |
| **P7 — Stage 5 (shifts)** | Only on explicit go-ahead, per Munawib §35. |

L3 (unified daily surface) lands incrementally across P3–P5.

**Each phase gets its own implementation plan.** This document is too large to plan as one
unit; P0 is planned and built first, and its completion is the trigger for planning P1.

---

## 13. Open items

None of these block starting P0.

1. **Product name.** Builds under Munawib; display name in config.
2. **SCFHS / hospital duty-hour policy in numeric form.** Ship the ACGME-style preset; encode
   the local preset when the numbers arrive (Munawib §A8.3).
3. **Email delivery credentials.** The existing SMTP settings screen covers this; until
   configured, Munawib's dev outbox pattern (NT-06) applies.
4. **Prototype data export** for the FL-05 migration; build and test against synthesized
   fixtures until provided.
5. Whether the existing `docs/spec/` slices are rewritten in place or superseded by a
   platform spec — a documentation decision, taken during P0.

---

## 14. Risks

| Risk | Mitigation |
|---|---|
| The `UnitProfile` generalization destabilizes a hardened system | It is P0, before any Rota work; the existing test suite is the regression net; no production data exists yet to lose. |
| Engine and solver semantics drift | The CI golden-fixture cross-validation job; a divergence fails the build. This is the failure that killed the prototype and it is designed against explicitly. |
| One `users` table weakens auth invariants (D3) | The five §5.1 mitigations, with the authentication gate consolidated into a single provider and backed by engine-level CHECK constraints. |
| PHI leaks into the Rota module | Named query services as the only crossing, plus the §9 guard test. |
| Scope: Munawib is a five-stage platform on top of a platform refactor | Phased in §12; each phase independently demoable; Stage 5 explicitly deferred. |
| Single-launch (D6) delays clinical value | Accepted by the owner; the counter-risk (live-schema migration of real handover records) was judged worse. |
