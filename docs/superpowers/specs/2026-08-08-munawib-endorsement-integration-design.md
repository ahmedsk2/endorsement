# Munawib × Endorsement — Integration Design

**Date:** 2026-08-08 · **Status:** Revised after owner discussion; awaiting final review
**Inputs:** the Munawib build prompt + Spec v1.0, frozen 2026-08-08 (currently at
`C:\Users\ahmed\Downloads\munawib-claude-code-build-prompt-v1.md`; **P0's first action is to
save its Part B into this repo as `docs/munawib/SPEC.md`**, per Munawib §A0) · this repo's
`docs/spec/` slices · the QHN Block 11 prototype review
(`C:\Users\ahmed\Documents\qhn-block11-schedule-review.md`)

This document designs **the seam only** — where the Munawib duty-rota platform and the
Paediatric Endorsement System join, and which stack hosts what. Munawib's internal
specification (domain model, conditions catalog, ranked hints, solver, five stages) is
frozen and is **not** re-opened here. What was genuinely undesigned, and what this document
settles, is the merge.

---

## 1. Decisions

### 1.1 Owner decisions, 2026-08-08

| # | Question | Decision |
|---|---|---|
| D1 | How tightly joined? | **One Laravel application.** Munawib becomes a module in this codebase: one login, one schema, one audit chain, one backup. |
| D2 | Who is it for? | **Full platform now.** Both modules become department-agnostic immediately; PICU/NICU/SCBU/WARD become seed data, not code. |
| D3 | Person vs account? | **One `users` table holds everyone**, including people who never log in. |
| D4 | Where does the engine run? | **One TypeScript engine, two runtimes** — browser for hints, Node sidecar for server authority. |
| D5 | Which cross-module links? | **All four** (§8). |
| D6 | Go-live? | **Single launch.** Reaffirmed after sizing; objection logged in §1.3. |
| D7 | Unauthenticated access? | **Tokenized share links.** No anonymous route exists anywhere in the platform (§9). |
| D8 | Sheet configurability? | **Bounded custom fields ("Ceiling 2")** — per-unit field definitions over an encrypted JSON column (§6.2). |
| D9 | Who may be named on a signed sheet? | **Split by field**: endorsed-by/to require a claimed account; consultant fields accept any rostered person (§5.3). |
| D10 | Liveness? | **Tiered polling**, Reverb deferred behind the same contract (§3.3). |
| D11 | Customer isolation boundary? | **Database per customer** — one codebase, one image, N deployments (§3.4). |
| D12 | Server capacity? | **Assume 4 OCPU / 24 GB**; scaling to it is a **prerequisite of P4**, not an assumption. |
| D13 | Condition catalog scope? | **All 21 types in P2.** Objection logged in §1.3. |
| D14 | Relationship to the QHN prototype? | Used for **idea curation only** — not a code ancestor, not a data source, and no ongoing collaborator. *(Revised 2026-08-08: the co-developer/reviewer arrangement recorded earlier no longer applies. This is a solo build.)* |
| D15 | AU-06 solver fixture? | **Synthesized fixtures**; AU-06's chief-acceptable criterion moves to post-first-month acceptance (§11.2). *(Revised with D14 — the pseudonymised real-block export it originally depended on is no longer available.)* |

### 1.2 Munawib clauses this adapts or overrides

Munawib Part A §A8 reserves stack-level changes to the owner. Each of these is a deliberate,
authorized change:

| Munawib clause | Original | Disposition |
|---|---|---|
| §A5 frozen stack | React 18 + Vite SPA; Firebase Auth/Firestore/Hosting/Functions | **Overridden** — Laravel 13 + Inertia + Vue 3 + MySQL |
| §AR-01 isolation | One isolated Firebase project per department | **Kept, re-hosted** — one Laravel deployment + own MySQL per customer (D11) |
| §AR-02 listeners | Firestore listeners are the only view updaters | **Overridden** — Inertia server-state re-reads + tiered polling (§3.3) |
| §AR-03 one engine | Pure TS engine for hints *and* server validation/reports | **Kept verbatim** — via the Node sidecar (§4) |
| §AR-05 data model | Firestore collections | **Adapted** — relational equivalents (§6.3); semantics binding, names adjusted |
| §PE-03 / AC-01 | `people` separate from accounts | **Overridden** — one `users` table with a `person_status` lifecycle (§5) |
| §5 viewer access | "link-public or login-only per department setting" | **Overridden** — no anonymous route ever; tokenized share links (§9). This also resolves Munawib's own contradiction with §A2.4 and SC-02. |
| §17 solver contract | Generate-only JSON contract | **Extended** — an evaluation mode with reified hard constraints is required (§4.2) |
| §33 FL-05 | Migrate the prototype's live data; accept when it "renders identically to the prototype" | **Overridden on premise** — the prototype is not a data source (D14). Rewritten in §11.1. |

Everything else in Munawib Spec v1.0 stands. Requirement IDs (MR-03, CG-07, AU-06…) remain
the binding vocabulary.

### 1.3 Objections logged, per Munawib §A8

Both were raised, both were reaffirmed by the owner, and both are being implemented as
decided. Recorded so the reasoning survives, not to re-litigate.

- **D6, single launch.** The stated reason for waiting — avoiding a migration of live
  handover records — expires at the end of P0. After P0 the clinical schema is stable and
  every later phase adds *new* scheduling tables rather than altering clinical ones. The
  cost of D6 is that a finished handover system sits unused for a multi-month programme,
  and the P0 refactor gets no real-world validation until the very end.
- **D13, full catalog in P2.** Nine of the 21 condition types are enough to schedule a real
  month; the rest (duty-hours, fairness, holiday equity, shift transitions) belong to modules
  that ship in P5 and Stage 5. Building all 21 with dual implementations up front makes P2 a
  long phase with nothing demoable at its end, and fixes fairness and duty-hour semantics
  before a single real month has tested them.

### 1.4 Provenance

The QHN Block 11 prototype **informed the requirements; no code was derived from it.**
Munawib's own framing as a "production successor" refers to succeeding it in *use*, not in
*source*. Stated explicitly so the distinction is on record.

### 1.5 Naming

Builds under **Munawib**; display name is configuration (§A8.5). The two modules are
**Endorsement** (handover, holds PHI) and **Rota** (scheduling, holds none).

---

## 2. What the merge buys

- **One identity, one directory.** Residents, consultants and nurses exist once.
- **One audit chain.** Every scheduling write joins the existing append-only hash-chained
  `audit_log`, with `audit:verify` already built. Munawib AD-01 arrives stronger than specced.
- **One compliance posture.** One backup regime, one encryption story, one data-residency
  answer, one PDPL pack — rather than splitting staff personal data into a second provider
  and jurisdiction.
- **Structural concurrency safety.** Munawib SC-03 forbids whole-doc last-write-wins on
  shared mutable data. One assignment per relational row satisfies that by construction; the
  prototype's concurrency problem cannot recur.
- **Cross-module intelligence** the prototype could never have (§8).

---

## 3. Architecture

### 3.1 Topology

Each customer deployment is the existing Coolify Compose stack plus two sidecars, both on
the `internal` network only, neither publicly routable — the isolation pattern already used
for MySQL.

| Container | Role | Network |
|---|---|---|
| `app` | Laravel 13 + Inertia/Vue 3 + Tailwind 4 (unchanged shape) | `coolify`, `internal` |
| `db` | MySQL 8.4 (unchanged) | `internal` |
| `engine` | **new** — Node; the compiled TypeScript conditions engine; JSON over HTTP; stateless | `internal` |
| `solver` | **new** — Python OR-Tools CP-SAT; Munawib §17 contract plus §4.2's evaluation mode; stateless | `internal` |

Node still never ships inside the app image — `engine` is a separate container, not something
PHP shells out to.

**Capacity (D12):** the host must be scaled to **4 OCPU / 24 GB** before P4 begins. OR-Tools
ships `manylinux2014_aarch64` wheels, so no source build is needed on ARM. The CP-SAT model
for 60 people × 31 days × 8 slots is roughly 15,000 booleans — small — but solve *quality* is
a direct function of CPU time, so generation runs as a **queued job**: a 90-second solve on
modest hardware is acceptable UX provided the workbench stays responsive.

Repo layout (additive):

```
packages/engine/        # pure TypeScript conditions engine + golden fixtures; zero framework imports
services/engine/        # thin Node HTTP wrapper around packages/engine
services/solver/        # Python CP-SAT service (Munawib §17 + evaluation mode)
```

The browser bundle imports `packages/engine` directly through Vite, so hints run client-side
and never block on the network (UX-05, NF-01: <100 ms p95).

### 3.2 The cost of D2

Smaller than first estimated, and the estimate is corrected here.

`handovers` is **already one table with every per-unit column present and nullable** —
`bed`, `mrn`, `patient_name`, `dob`, `age`, `ward_unit`, plus the four rich-text fields. The
four legacy tables were collapsed during the original build. `UnitProfile` is a value object
with eight properties. Making unit variation configuration therefore means **moving eight
properties onto the `units` table** — a migration, a seeder, an accessor swap, and updates to
the tests that assert the four profiles. Days, not weeks.

D8 (Ceiling 2) is what adds real work to P0, not the `UnitProfile` move. **The largest single
effort in the whole programme is the dual-implementation conditions engine (§4, §11.2)** — an
order of magnitude beyond this.

### 3.3 Liveness (D10)

Munawib's most successful inherited pattern is law here: **writes go to the database; no view
mutates local schedule state.** Every mutation is a POST/PATCH/DELETE to Laravel; views re-read
from server state.

Where Firestore's `onSnapshot` gave live updates, a per-schedule version endpoint returning
`304 Not Modified` on an ETag is polled at intervals matched to the surface:

| Surface | Interval | Load at Munawib QA-04's target |
|---|---|---|
| Draft workbench (3 concurrent editors) | 3 s | ~1 req/s |
| Published schedule views | 30 s | ~6.6 req/s |
| Who's-on-call board, wall displays | 60 s | ~3.3 req/s |

≈11 req/s total, versus 40 req/s for flat 5-second polling — which matters because
`SESSION_DRIVER` and `CACHE_STORE` are both `database`, so every poll costs a session read
plus the version query.

This matches the standard the product already sets: endorsement's concurrency model is
per-field save-on-blur with the UI reflecting the server response, not live presence.
**Laravel Reverb is deferred**, documented as the upgrade path behind the identical version
endpoint. SSE is excluded — each connection would pin a php-fpm worker.

### 3.4 Customer isolation (D11)

**The isolation boundary is the database, not the row.** One codebase, one image, one CI
pipeline; a provisioning script stands up a separate Compose stack with its own MySQL per
customer — Munawib FL-02 translated from Firebase to Coolify. FL-03's "no per-instance code
changes, ever" holds.

Rationale: `institution_id` is nullable on every existing table and the anchor has never been
exercised. Row-level tenancy **fails open** — one missing global scope, one bare `find()`, and
one customer reads another's children's PHI. With a database per customer, PDPL's
non-commingling and right-to-erasure claims are true by construction and provable by pointing
at a dropped volume, and the blast radius of any single flaw is one customer.

`institution_id` is **retained** as in-instance grouping and defence in depth, not as the
security boundary. Today this is exactly one deployment, so the choice costs nothing now.

Accepted cost: N backups, monitors, upgrade runs and restore drills once N exceeds one.

---

## 4. The conditions engine (D4)

### 4.1 One definition, three consumers

Munawib's second ground rule — **one engine** — is the one most at risk in a PHP host, and
the one the prototype's history most justifies.

- **`packages/engine`** is the single definition of condition semantics: pure TypeScript, no
  Firebase, no framework imports. Implements the full CG-07 catalog and the CG-10 contract,
  `(schedule, config, conditions) → violations[]`.
- **Browser runtime** — bundled by Vite. Live hints, fitness-ordered pickers, the client-side
  greedy fallback (AU-05).
- **Server runtime** — the `engine` container runs the same compiled package behind JSON.
  Laravel calls it for authoritative validation, the publish gate (CG-05), and compliance
  reports (TL-03).
- **Solver mapping** — `services/solver` maps the same conditions to CP-SAT constraints and
  rank-weighted penalties. This is the one permitted second implementation, exactly as AR-03
  already contemplates.

**No PHP implementation of the rules exists anywhere.** That is the point.

### 4.2 The solver needs an evaluation mode (new requirement)

Cross-validation only works if the Python service can *evaluate* a fixed schedule, not just
generate one. Pin every assignment and ask CP-SAT to solve, and a hard-constraint breach comes
back as bare `infeasible`, where the TS engine returns a located list of violations.

**Requirement:** `services/solver` exposes an evaluation mode in which hard constraints are
**reified** as booleans, so it reports *which* constraints break and *where*, in the same shape
as the TS engine's `violations[]`. This is additive to the Munawib §17 contract and is a
prerequisite for the CI job below.

### 4.3 The cross-validation job

One golden-fixture suite runs through both the TS engine and the Python mapping, asserting
identical verdicts. **A divergence fails the build.** This is the single most important test in
the repository — the failure it prevents is the one that killed the prototype.

Types where the two formulations are genuinely hard to reconcile, flagged so they are not
discovered late: `fairness_distribution` (violation count vs. min-max objective),
`rolling_hours_max` and `free_day_min` (sliding windows, including partial windows at period
boundaries), `holiday_equity` (multi-year lookback reduced to a per-schedule violation), and
`we_pairing` (a shared definition of what "preference broken" means).

---

## 5. Identity (D3)

`users` holds everyone who appears in a rota or on a handover sheet, whether or not they ever
authenticate.

### 5.1 Shape

| Column | Purpose |
|---|---|
| `person_status` | `roster_only` → `invited` → `claimed`. **Claim lifecycle only.** |
| `active` | **unchanged** — remains the kill switch, wired to `EnsureAccountActive` and the audit. Deactivation is `active = false` at any lifecycle stage. |
| `short_name` | the rota handle (Munawib `shortName`); unique per institution; distinct from `member_name`, the login handle |
| `level_id` | current training level; history in `user_levels` (LV-04, effective-dated) |
| `phone`, `joined_at`, `notes` | PE-01 |
| `constraints` | JSON, structured per-person constraints (PE-01) |
| `external` | ad-hoc external rotator flag (PE-03) |

**`position` (job role) and `level_id` (training level) are orthogonal and both are retained.**
A person is a Resident *and* PGY-2. Collapsing either into the other breaks the endorsement
capability model or Munawib's level-based coverage templates.

### 5.2 Mitigations — requirements, not suggestions

D3 weakens invariants the 2026-07-26 audit hardened. These close the gaps:

1. `password` and `member_name` are nullable **only** for non-claimed rows, enforced by a
   MySQL `CHECK` constraint — engine-level, not convention. The unique index on `member_name`
   tolerates multiple NULLs.
2. **Authentication is gated in exactly one place.** A custom user provider refuses any row
   whose `person_status <> 'claimed'` or `active = false`. A roster-only row can never
   authenticate, hold a session, or be issued a token.
3. **The password-reset broker needs its own gate.** `password_reset_tokens` is keyed by email
   and does **not** pass through the user provider, so a `roster_only` row carrying a
   `member_email` — which imported rosters will — could request a reset link and mint itself an
   account outside the invitation flow. This is a privilege-escalation path created by D3. It
   requires an explicit gate and a dedicated test. Email verification needs the same treatment.
4. **Roster import must match onto existing people by email**, never create duplicates:
   `member_email` is unique, so an imported person who later self-registers otherwise causes a
   hard failure or a duplicate human.
5. **Three overlapping state machines** — `invitations`, `pending_registrations`, and
   `person_status` — must be reconciled into one lifecycle. This is **P0 work**, not something
   to discover in P1.
6. Capability resolution returns nothing for non-claimed rows.

### 5.3 Naming versus signing (D9)

The schema already supports this: all four named roles on `handover_signoffs` are user FKs
**paired with a frozen name snapshot** (`endorsed_by_user_id` + `endorsed_by_name`, and the
same for endorsed-to, consultant-by, consultant-to), plus `signed_off_by_name` added
2026-07-27. The FK is `nullOnDelete`; the name survives it. The 2026-07-27 signature ruling
already treats name-without-signature as a valid attestation state: *"wherever a signature is
withheld, this line is the whole attestation of who documented the handover."*

`pickerRule()` therefore enforces **different scopes per field**:

| Field | Who may be named |
|---|---|
| `endorsed_by`, `endorsed_to` | **Claimed accounts only** — these clinicians attest and receive; their signature is the evidence |
| `consultant_by`, `consultant_to` | **Any rostered person**, including `roster_only` — the on-call consultant is a name of record and frequently never logs in |
| `signed_off_by` | The authenticated user, by construction |

Every scope is bounded by institution, unit eligibility, position/level and `active`, so
write-side validation matches what the picker offers — the 2026-07-26 invariant, now stricter.

---

## 6. Data model

### 6.1 Units as configuration (D2)

`UnitProfile`'s eight properties move onto the `units` table: which identity columns appear,
`bed`/`room` label, consultant sign-off shape (pair vs. single "Consultant Oncall"), print plan
and narrative labels, hue token. `EndorsementController::UNIT_CODES` and the four-unit
assumption are removed; PICU/NICU/SCBU/WARD become seed rows.

Units also gain Munawib UN-02's three independent capability flags (training rotation /
on-call coverage target / clinic owner) and UN-03 import aliases. `positions` become
admin-managed; **capabilities stay code-defined** (they name features) with role→capability
defaults as data. Position 1 (Nurse) remains retired and is never reused. New: `levels`
(LV-01), `user_levels` (LV-04).

### 6.2 Bounded custom fields (D8, "Ceiling 2")

- `unit_field_definitions` — per unit: key, label, type (`text` | `date` | `select` +
  options), required, display order.
- `handovers.extra_fields` — JSON, **encrypted whole**, since it will hold clinical text.
  Consequence: extra fields are **not searchable or indexable**. Nothing searches them today;
  recorded so it is a known limit rather than a surprise.
- **The four rich-text narrative fields stay first-class**, not user-definable — they carry
  the `SanitizedHtml` cast, the editor contract, and the print schema. Their *labels* are
  per-unit configuration.
- Validation rules are built dynamically from the definitions; the print view gains a generic
  renderer for extra fields; the legacy import maps legacy columns onto definitions.

### 6.3 Rota tables

Munawib §AR-05's collections become Eloquent tables. Semantics binding, names adjustable:

| Table | Munawib origin |
|---|---|
| `periods` | months or week-blocks (MR-01) |
| `master_rota_assignments` | person × period × unit, date-bounded splits (MR-02) |
| `vacations` | week or exact-date granularity (MR-03) |
| `clinics`, `clinic_attendees` | CL-01, CL-02 |
| `slots` | SL-01 (kind, window, cadence, days, unit, counts_hours, tally_key) |
| `coverage_templates` | SL-03 (slot × day type → ordered level requirements, min/target/composition) |
| `conditions` | CG-01 (type_key, params, scope, class, rank, active, source) |
| `schedules` | draft \| published \| archived, version |
| `assignments` | **source of truth**, one row per placement. Munawib's `dayIndex` render index is deliberately **not** carried over — it existed to make Firestore reads cheap; an indexed query on `(schedule_id, date, slot_id)` replaces it with no denormalized copy to keep in sync. |
| `ignored_warnings` | CG-06 ledger (who ignored what, when) |
| `coverage_overrides` | MC-01 / MC-03 |
| `requests` | RQ-01 including swap payloads (§23) |
| `holidays` | greg or hijri rule, `equity_tracked` |
| `schedule_changes` | PU-02 versioned change log |
| `notifications`, `mail_queue` | §25 |
| `feeds` | tokenized, revocable (IN-01, PS-02) — **moved forward to P3** (§9) |
| `archives` | snapshot at publish-over (PU-01) |

**Audit:** all Rota writes join the existing hash-chained `audit_log`, respecting the standing
invariant — one canonical string definition (`AuditChain::canonical()`), and a stored naive
datetime is never re-parsed in the current timezone.

---

## 7. Calendar

One `App\Support\Calendar` (PHP) plus a mirrored calendar inside `packages/engine` for
client-side date math, both reading the same per-institution configuration: period type,
weekend days, Hijri display, `hijri_offset_days`, timezone (default `Asia/Riyadh`),
academic-year start. **Nothing outside those two converts dates** (AR-08).

The prototype established `hijri_offset_days = −1` for this hospital, verified against the
department's published calendar across a month boundary; that value seeds the QCH institution.

The calendar converts for display and scheduling day-boundary math only — **never** for audit
canonicalization, which stays byte-verbatim.

---

## 8. Cross-module links (D5)

| # | Link | Mechanism | What crosses |
|---|---|---|---|
| **L1** | Rota fills endorsement's people fields | `OnCallDirectory::forUnitAt(unit, instant)` returns users rostered to that unit's slots covering the instant. Endorsed-By/To pickers default and scope to it (within D9's per-field rules); WARD's Consultant Oncall defaults to the scheduled consultant. An audited override remains, because reality diverges from the rota. | user ids, unit ids |
| **L2** | Handovers feed duty evidence | `HandoverEvidence` exposes sign-off timestamps and rostered-versus-actual for the duty-hour report (TL-03); the missed-days view names who was rostered on a day with no sheet. | timestamps, unit ids, user ids, counts |
| **L3** | Unified daily surface | A *My Day* page: calls, clinics, rotation, and the person's own pending or unsigned handovers. The morning coverage board gains a per-unit handover-status chip (signed / in progress / missing). | status enums, unit ids |
| **L4** | One notification stream | `push_subscriptions` and handover reminders merge with `mail_queue` and the notification centre into one event model, one fan-out (push / mail / in-app), one preferences screen. NT-05 holds: approval-chain emails cannot be muted where the person is a required actor. | — |

---

## 9. Access boundaries

### 9.1 No anonymous route, ever (D7)

Munawib §5 permits link-public viewer access while §A2.4 and SC-02 forbid personal data on any
unauthenticated surface. A published rota **is** a list of named people and when they work, so
both cannot hold. This design resolves it in favour of the stricter rule, which is also this
repo's own non-negotiable.

- **No unauthenticated route exists** anywhere in the platform. Every route stays behind
  `auth` + a `cap:` capability.
- **Tokenized share links** are the only way schedule data leaves the login wall: a Scheduler
  mints a signed, **expiring, revocable, audited** token granting read-only access to one
  published period. Names and duties are shown; **contacts never are.**
- This extends Munawib's own `feeds/{token}` mechanism and SC-04 ("single-purpose, revocable,
  never write-granting") rather than fighting it. A bearer token differs in kind from a public
  URL: it expires, it can be revoked, and its use is logged. `X-Robots-Tag: noindex` already
  applies.
- Expiry and retention policy go in the PDPL pack. Wall displays use a share link or a kiosk
  account with a view-only capability.
- **Consequence:** the `feeds` table and token semantics move from P5 to **P3** — the share
  link is how the rota gets used from day one.

### 9.2 PHI stays put

Enforced, not intended:

1. Rota models and queries **never** reference `handovers` or `handover_revisions`.
2. All cross-module reads go through the two named query services in §8, returning only ids,
   timestamps, counts and status enums.
3. A **guard test** asserts the Rota namespace references no PHI model or column — the same
   species as `CompiledCssIsLightOnlyTest`, which exists because conventions decay and tests
   do not.
4. The `engine` and `solver` containers receive **no PHI, ever**: their payloads are people
   ids, dates, slots and conditions. Neither has a public route.
5. The standing rule now covers Rota too: no PHI in URLs, query strings, logs, `audit_log`
   details, exception messages, or push payloads.

---

## 10. Degradation

| Failure | Behaviour |
|---|---|
| `engine` down | Hints keep working (browser runtime). Server-side validation and the publish gate **fail closed** with a clear message. Nothing publishes unvalidated. |
| `solver` down | Auto-generation unavailable; the manual workbench is untouched; the client-side greedy fallback (AU-05) runs, labelled heuristic. |
| Solver infeasible | Scheduler-readable conflict report naming the tightest constraints and where (AU-07). Never a silent under-fill. |
| Either sidecar unhealthy | Compose healthchecks report it. **`/up` deliberately does not depend on the sidecars** — it stays a pure database-reachability probe (2026-07-26 invariant). A scheduling sidecar being down must not mark the clinical application unhealthy. |

---

## 11. Migration and fixtures

### 11.1 FL-05 rewritten (D14)

The prototype informed requirements only. FL-05's migration therefore **does not** draw from
it. Sources are this system's own roster plus imported spreadsheets (ST-04: xlsx/csv with
column mapping, validation report, dry-run preview). Munawib's "renders identically to the
prototype" acceptance criterion does not apply and is replaced by: *the imported master rota
and clinics reproduce the department's own source spreadsheets, verified by the dry-run
report.*

IN-01's webhooks and feeds are designed as a **generic public contract**, not shaped to any
existing bot.

### 11.2 Solver fixtures (D15)

No real-data fixture is available. Fixtures are **synthesized**: hand-built rosters,
rotations, vacations, unwanted days and coverage templates that exercise every condition
type, plus deliberately over-constrained cases to drive the infeasibility path (AU-07).

**Consequence, stated plainly:** Munawib AU-06 binds solver acceptance to regenerating *a
past real month from archived real inputs* and being chief-acceptable. Without real archived
inputs, that criterion **cannot be met in P4**. It splits in two:

- **In P4 (automated, binding):** property tests — hard constraints never violated, coverage
  minima always met when feasible, infeasibility reported rather than silently under-filled,
  and determinism from a stored seed. These do not need real data.
- **After the first real month is scheduled (binding before the solver is trusted
  unsupervised):** regenerate that month from its own archived inputs and confirm it is
  acceptable with minor edits. This is Munawib §38's warning inverted — the risk it flags
  (CP-SAT producing schedules a chief will not sign) is now carried until first use, and must
  be surfaced in the P4 gate rather than discovered later.

Under no circumstances commit real resident names, phone numbers or emails as CI fixtures —
QA-05 requires a security review before any real names enter the system, and this repository's
whole posture forbids personal data in version control.

---

## 12. Testing

TDD throughout — failing test first, tree deployable after every commit, `php artisan test`
and `npm run build` green before any commit.

- **Engine:** exhaustive unit tests per condition type plus golden fixtures (QA-01).
- **Cross-validation:** the same fixtures through the Python mapping in CI, using §4.2's
  evaluation mode. Divergence fails the build.
- **Solver:** property tests (hard constraints never violated; coverage minima met when
  feasible; infeasibility reported per AU-07) plus the AU-06 regeneration test against §11.2's
  pseudonymised block.
- **PHPUnit:** database-per-customer provisioning, capability enforcement, `pickerRule()`
  offer/validation parity **per field** (D9), the `person_status` CHECK constraints, the
  roster-only-cannot-authenticate gate, **the password-reset gate (§5.2.3)**, the
  roster-import email-collision path, the no-PHI guard test, share-token expiry/revocation,
  audit-chain integrity.
- **Playwright:** invite → claim → request → approve → draft → auto-fill → manual fix →
  publish → share link → handover with rota-filled pickers → sign-off → swap → sick
  replacement.
- Existing guard tests stay green: light-theme-only compiled CSS, audit canonical string.

---

## 13. Sequencing (D6 — single launch)

No intermediate production deployment. The tree stays deployable after every commit, but QCH
paediatrics goes live once, with both modules ready.

| Phase | Content |
|---|---|
| **P0 — Platform foundation** | Save Munawib Part B as `docs/munawib/SPEC.md`. Units and `UnitProfile` become configuration; **Ceiling-2 custom fields** (definitions, encrypted JSON, dynamic validation, generic print renderer, import mapping); `levels` + `user_levels`; `users` extended with §5.1 columns and CHECK constraints; **auth lifecycle reconciled into one state machine**; the password-reset and email-collision gates; `pickerRule()` rewritten per D9; provisioning script for database-per-customer. Endorsement stays green throughout. |
| **P1 — Munawib Stage 1** | People, invitations, roles on the merged identity; master rota (both period systems, splits, vacations, import/export, publish view); clinics; holidays. |
| **P2 — Engine** | `packages/engine` with **all 21 CG-07 types** (D13), golden fixtures, plain-language previews, severity/rank model; `services/engine`; the CI cross-validation job. |
| **P3 — Munawib Stage 2** | Slots, call windows, coverage templates, conditions gate with drag ranking, draft workbench with live hints, trackers, undo ≥30, unfilled lens, publish + archive, morning coverage, who's-on-call board, personal pages, tallies, exports. **L1 and the §9.1 share-token feed land here.** |
| **P4 — Munawib Stage 3** | *Prerequisite: host scaled to 4 OCPU / 24 GB.* Solver service, §4.2 evaluation mode, ranked-sacrifice report, per-placement explanations, partial modes, infeasibility reporting, AU-06 against §11.2's fixture; requests with deadlines and reminders; approval queue with coverage impact; versioned change log; ICS feeds. **L4 lands here.** |
| **P5 — Munawib Stage 4** | Swaps, backup slots and sick replacement, equity and holiday equity, duty-hour compliance (ACGME preset), audit and version browser, webhooks, condition builder. **L2 lands here.** |
| **P6 — Launch** | FL-05 import dry-run then live; `security-pan-check:security-audit` and `prod-ready:audit` green; runbooks updated; single go-live for QCH paediatrics. |
| **P7 — Stage 5 (shifts)** | Only on explicit go-ahead, per Munawib §35. |

L3 lands incrementally across P3–P5.

**Each phase gets its own implementation plan.** This document is too large to plan as one
unit; P0 is planned and built first, and its completion triggers planning P1.

**Review process:** solo build (D14). There is no second human reviewer, so the automated
gates carry the whole load: `requesting-code-review` per slice, the golden-fixture
cross-validation job, and `security-pan-check` / `prod-ready` audits at each stage gate. Where
a decision would normally be caught by a colleague, it must instead be written down — the
design doc's decision log and the plans' amendment sections exist for that.

---

## 14. Open items

None block starting P0.

1. **Product name.** Builds under Munawib; display name in config.
2. **SCFHS / hospital duty-hour policy in numeric form.** Ship the ACGME-style preset; encode
   the local preset when the numbers arrive (§A8.3).
3. **Email delivery credentials.** The existing SMTP settings screen covers this; until
   configured, Munawib's dev-outbox pattern (NT-06) applies.
4. **AU-06's real-month regeneration** (§11.2) cannot run until the platform has scheduled its
   own first real month. The P4 gate must state that the solver is unproven against real
   inputs rather than imply otherwise.
5. Whether the existing `docs/spec/` slices are rewritten in place or superseded by a platform
   spec — a documentation decision, taken during P0.
6. **Reserved unit codes.** `routes/web.php` declares `/endorsement/today`,
   `/endorsement/compliance` and `/endorsement/rows/{handover}` before `/endorsement/{unit}`
   specifically so those literal segments never bind as a unit code. That ordering trick stops
   working once units are created through an admin UI: a unit with code `TODAY`, `COMPLIANCE`
   or `ROWS` would be permanently route-shadowed by the earlier route and unreachable. This was
   impossible while the unit registry was hardcoded; it becomes reachable the moment P0d/P0b
   ships unit creation. A reserved-code guard (reject those three codes, case-insensitively, at
   creation) is needed before any admin UI can create units.

---

## 15. Risks

| Risk | Mitigation |
|---|---|
| Engine and solver semantics drift | The CI cross-validation job (§4.3) with the §4.2 evaluation mode; divergence fails the build. This is the failure that killed the prototype and is designed against explicitly. |
| D13 makes P2 long and undemoable | Accepted by the owner (§1.3). Mitigate by ordering the 21 types so the prototype's proven nine land first and are demoable, even though all 21 ship before P3. |
| One `users` table weakens auth invariants | The six §5.2 mitigations, especially the password-reset gate — a genuine escalation path, not a theoretical one. |
| PHI leaks into Rota | Named query services as the only crossing, plus the §9.2 guard test. |
| Share tokens forwarded outside the department | Expiry, revocation, audit, no contact data, `noindex`; policy documented in the PDPL pack. |
| N deployments once there is a second customer | Provisioning, backup and restore are scripted from the start; drills are runbook items. |
| Solver too slow on the host | Queued generation; capacity is a P4 prerequisite (D12); measured against a real fixture rather than assumed. |
| D6 delays clinical value for months | Accepted and reaffirmed by the owner (§1.3). |
