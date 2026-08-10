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
| D3 | Person vs account? | ~~One `users` table holds everyone.~~ **REVERSED 2026-08-08 after P0c reconnaissance → a separate `people` table holds the roster; `users` stays purely the auth record, linked by `person_id`.** See §5. |
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
| §PE-03 / AC-01 | `people` separate from accounts | **Kept** — the override this row used to record (one `users` table with a `person_status` lifecycle) was itself **reversed by the owner on 2026-08-08 (D3)**, before any of it was built. As shipped in P0c: `people` is the roster and the name/role of record, `users` is purely the account, linked by `users.person_id` (nullable, UNIQUE). There is no `person_status` column and none is coming — "claimed" is a join (`Person::hasAccount()`), and a roster-only person has no `users` row, so it cannot authenticate *by construction* rather than by a predicate that must be repeated at twelve credential and defence sites. §5 carries the full reasoning and the as-shipped shape. |
| §5 viewer access | "link-public or login-only per department setting" | **Overridden** — no anonymous route ever; tokenized share links (§9). This also resolves Munawib's own contradiction with §A2.4 and SC-02. |
| §17 solver contract | Generate-only JSON contract | **Extended** — an evaluation mode with reified hard constraints is required (§4.2) |
| §33 FL-05 | Migrate the prototype's live data; accept when it "renders identically to the prototype" | **Overridden on premise** — the prototype is not a data source (D14). Rewritten in §11.1. |
| §ST-04 roster import | "xlsx/csv with column mapping, validation report, dry-run preview" | **Overridden, CSV/TSV only (P1c Decision E, 2026-08-09).** No spreadsheet package exists in `composer.lock`, and adding one to a system holding children's PHI is an owner supply-chain decision, not a developer's. Column mapping, the validation report and the dry-run preview all ship as specified — only the file format narrows. The reader is a port (`App\Support\Roster\RosterReader`), so xlsx is one adapter class away the day the owner decides (§14 item 16, `docs/OPEN-DECISIONS.md` item F). |
| AR-05 vacation `granularity: 'week'` | Spec never defines what a week is | **Resolved, P1d-1, 2026-08-10.** `Calendar::weekStartIsoDay()` derives the week start from `institutions.weekend_days` (ST-01) rather than assuming a fixed Sunday or Monday; `weekOf()`/`weeksIn()` share the definition with the screen and, once P1d-2 lands, the importer. See §7. |
| PersonPresenter contact projection | `email` shipped ungated in P1c (every caller then held `people.manage`, a no-op distinction from `viewContact()`) | **Hardened, P1d-1, 2026-08-10.** `email` now sits behind the same `viewContact()` gate `phone` already used, because the rota grid is the first consumer holding a narrower capability (`rota.view`) than every prior caller. Not a Munawib-clause override — recorded here as the security finding that closed before any grid prop existed (P1d-1 finding 1, Task 7). |
| MR-06 CSV-only export/import for the rota | Same dependency question as ST-04 | **Carried forward, P1c Decision E.** P1d-2 (scoped, not yet built) exports/imports the rota through `App\Support\Csv` exactly as the roster importer does — no spreadsheet package, two files (`rota.csv`, `vacations.csv`), person identified by `short_name` never email. |

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
pipeline, one Compose file (`docker-compose.production.yml`) shared by every customer — Munawib
FL-02 translated from Firebase to Coolify.

Rationale: `institution_id` is nullable on every existing table and the anchor has never been
exercised. Row-level tenancy **fails open** — one missing global scope, one bare `find()`, and
one customer reads another's children's PHI. With a database per customer, PDPL's
non-commingling and right-to-erasure claims are true by construction and provable by pointing
at a dropped volume, and the blast radius of any single flaw is one customer.

**No end-to-end provisioning script exists, and by design none can.**
`.github/workflows/ci.yml` has two jobs (`test`, `audit`), `permissions: contents: read`, no
image push, no registry, no deploy job — the token that could drive Coolify onto the machine
holding patient data is owner-held by policy, deliberately. What P0d (2026-08-08) ships instead
is what is honestly automatable: `scripts/new-instance.sh` (generates conforming secrets, prints
the exact Coolify environment block, refuses a colliding slug and writes nothing to disk),
`docker/instance-env.sh` (resolves one stack's containers by **identity**, or refuses — never
by image ancestry, which cannot tell two customers' identical `mysql:8.4` containers apart:
P0d Task 9's rehearsal confirmed this directly, standing up two throwaway stacks with identical
database/user names), `php artisan instance:show` (proves an instance is fully provisioned,
printing no secret value), and `docs/RUNBOOK-PROVISION.md` for the steps that stay irreducibly a
human in the Coolify UI — the project, the domain field, the deploy key, and the DNS/TLS cutover
order. Task 9's dress rehearsal ran the whole sequence against two throwaway stacks and found
one gap in P0d's own delivery — the new per-instance variables were not actually reaching the
container until the compose file's `environment:` block was corrected — recorded in
`docs/RUNBOOK-PROVISION.md`'s appendix and fixed in the same commit that found it.

`institution_id` **is now** in-instance grouping and provenance, not the security boundary — but
this was not true until P0d Task 4, and describing it as already "retained… as defence in
depth" was false when first written. Before Task 4 the column had **no writer anywhere in the
application**: `user:create-admin` never set it, so it was NULL on the bootstrap admin and on
every row copied from that admin down the provenance chain, while the legacy import stamped a
real id on every imported row — non-null history, null present, which is worse than uniformly
null. Task 4 gives `user:create-admin` the one write that starts the chain (every other site
already copies `institution_id` from the acting user), a guarded, additive backfill migration
for existing NULL rows, and a source-level test
(`InstitutionProvenanceTest::test_no_query_filters_on_institution_id`) that fails the build if
any clinical query ever filters on the column — the "provenance, never a filter" boundary is
enforced, not only documented.

**FL-03's "no per-instance code changes, ever" now holds — it did not until P0d.** Six files
hardcoded the first customer: `docker-compose.production.yml`'s `APP_TIMEZONE`,
`ReferenceSeeder.php`'s `QCH`/`Qatif Central Hospital` institution (with `name` in the *update*
payload, so a customer's rename was silently reverted on every `db:seed --force`),
`docs/sql/least-privilege.sql`'s schema/user literals, `docker/backup-offhost-sync.sh`'s and
`docker/uptime-check.sh`'s hardcoded volume UUID and log/state paths, and `docker/smoke.sh`'s
fixed compose project name. P0d parameterised all six (Tasks 1–3, 6–7), each keeping the
existing deployment's current value as its default, so the live system's behaviour is unchanged
by any of it.

**What "N backups, monitors, upgrade runs and restore drills" understated: three of those were
not linear operating cost, they were defects that only exist once N ≥ 2.** The documented
migration procedure could target the **wrong** customer's database and report success — the
runbook resolved the app container by Coolify UUID but the database container by image ancestry,
and `head -1` on two identical-looking `mysql:8.4` containers is a coin flip (closed by
`docker/instance-env.sh`, Task 6, and proven live in Task 9's rehearsal). The uptime monitor was
**incorrect**, not merely duplicated — two crons sharing one state file each read the other's
last value and emitted a permanent stream of false `CRITICAL`/`recovered` pairs (closed by
Task 7, disproved live in Task 9). Backup retention **deleted across customers** sharing a pull
directory, because the archive filename carried no instance token (closed by Task 1's
slug-scoped, timestamp-anchored prune glob, proven destructively in Task 9 — seeding a
differently-named archive alongside 20 of the instance's own and confirming it survived a
`--keep=14` prune).

What still costs real, unautomated work per customer, and always will, because it is a human
holding owner-held credentials rather than a gap P0d left open: the Coolify project/app/domain/
deploy-key steps, the DNS/TLS cutover sequence (grey → deploy → Let's Encrypt → orange), first
login and TOTP enrolment, and the quarterly restore drill — which still has no last-run record
or ageing alert (§14).

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

## 5. Identity (D3 — REVERSED 2026-08-08)

> **This whole section is superseded.** It was written for one `users` table holding everyone.
> P0c reconnaissance showed that model cannot be made safe cheaply here, and the owner reversed
> D3. §5.1–§5.2's `person_status` machinery is **obsolete**; §5.3's per-field picker rule (D9)
> still stands, restated at the end.
>
> **Why it was reversed.** There is no single authentication chokepoint to gate: the app never
> calls `Auth::attempt()`, so `EloquentUserProvider::validateCredentials()` is never invoked.
> Keeping roster people in `users` would have required `person_status` as an extra predicate at
> **six defence sites** (login, `EnsureAccountActive`, both 2FA challenge resolvers,
> `AccessControl::holdersOf()`, `pickerRule()`/`staffPickers()`) **plus a dedicated gate on six
> credential paths** (password login, the reset broker — which is keyed by email and bypasses
> the provider entirely — email verification, email OTP, trusted devices, remember-me). Twelve
> places that must each be right, forever, on a system holding children's PHI.
>
> **The replacement shape.** A new `people` table is the roster: short name, full name, level
> history (effective-dated, in a separate table — see §5.1), position, phone, email,
> constraints, `external` flag. There is no status column: "claimed" is a join
> (`Person::hasAccount()`), not a lifecycle enum — see §5.1. `users` stays exactly as it is —
> `password` NOT NULL, `member_name` unique, `active` keeping its current meaning — and gains a
> `person_id` link. **A roster-only person has no `users` row and therefore cannot authenticate
> by construction; no gate is needed on any credential path, and all six existing defences keep
> working untouched.**
>
> Consequences P0c must handle: `handover_signoffs`' four named-role FKs move from `user_id` to
> `person_id` (the frozen `*_name` snapshots already protect the medico-legal record); every
> read of `user.full_name` follows the link; and `SignatureStore` stays keyed on `users`, which
> is what keeps **naming separate from signing** — a consultant can be named without an account,
> but signing still requires one.
>
> **D9 still stands, restated:** `endorsed_by`/`endorsed_to` may name only people who have a
> claimed `users` row; `consultant_by`/`consultant_to` may name any active person;
> `signed_off_by` is the authenticated user by construction.

### As shipped, 2026-08-08 (P0c)

### 5.1 Shape

`people` — the roster, and the name/role of record:

| Column | Purpose |
|---|---|
| `institution_id` | nullable FK (D11 defers tenancy; see §3.4) |
| `full_name` | NOT NULL — a person with no name cannot be named on a sheet |
| `short_name` | the rota handle (Munawib `shortName`); **UNIQUE OUTRIGHT**, not per institution — see the deviation below; distinct from `users.member_name`, the login handle |
| `position` | job role (tinyint, indexed) — the ONLY copy; `users.position` was dropped 2026_08_10_120003 |
| `email` | nullable, **UNIQUE outright** — the roster/contact address and the sole authoritative account address (owner decision 2, below) |
| `phone`, `joined_at` | PE-01 |
| `notes` | PE-01 free text — **plaintext** (owner decision 3, 2026-08-08); `$hidden` on the model so it never serialises by accident, but legible in a raw DB read and in backups. Recorded, not glossed, in `docs/COMPLIANCE.md` |
| `constraints` | JSON, structured per-person scheduling constraints (PE-01), queryable by the solver — also deliberately unencrypted |
| `external` | ad-hoc external rotator flag (PE-03), NOT NULL |
| `active` | governs whether this person may be **NAMED**. Orthogonal to `users.active` (may **AUTHENTICATE**) — never express one as the other |
| soft deletes | people are deactivated, never deleted (owner ruling) — the four named roles on `handover_signoffs` depend on the row staying resolvable |
| *(no `status`/`person_status` column)* | PE-01's "status" is **derived** on the READ path — Active/Retired × Account/Roster-only, from `people.active` crossed with `Person::hasAccount()` — never stored (P1c, restating deviation 3 below) |

**Correction, P1c (2026-08-09): `$hidden = ['phone', 'notes']` is NOT the control that keeps
staff contact fields out of Inertia props, and this section's earlier phrasing ("so it never
serialises by accident") reads as if it were.** `$hidden` bites on `toArray()`/`toJson()` only;
every admin screen in this codebase builds its props with an explicit `present()`-style map that
reads attributes directly and never consults `$hidden` at all. The actual control is
`App\Support\PersonPresenter` — the ONE place a `Person` becomes Inertia props — gated by
`App\Policies\PersonPolicy` (`viewContact`/`viewNotes`, this codebase's first policy) and a
two-valued department setting, `institutions.contact_visibility` (`admins` default, `members`
exposes `phone` only — `notes` is never toggled, on either setting). A withheld field is ABSENT
from the props array, never `null`: the two facts look identical on screen and a future consumer
would eventually render one as the other. `$hidden` remains in place as defence in depth against
an accidental whole-model serialisation, correctly described now, not as the control.
`tests/Feature/Build/ContactFieldsAreProjectedOnceTest.php` enforces the single-projection
property at source level.

Training level is a **separate history table**, `person_levels` (Munawib LV-04): `person_id`,
`level_id` (FK, `restrictOnDelete` — a level with history cannot simply vanish), `effective_from`,
`effective_to` (nullable = still current), unique on `(person_id, effective_from)`.
`Person::levelAt($date)` is the **only** resolver; there is deliberately no `people.level_id`
"current level" pointer beside it. **`position` (job role) and level (training stage) are
orthogonal and both are retained** — a person is a Resident *and* PGY-2.

`users` keeps exactly its pre-P0c shape (`password` NOT NULL, `member_name` unique, `active`
keeping its meaning, the 2FA columns, the signature) and gains one column: `person_id`
(nullable, **UNIQUE** — at most one account per person).

**Three deviations from this section's original draft, all owner-approved 2026-08-08:**

1. **`short_name` is UNIQUE outright, not `UNIQUE(institution_id, short_name)`.**
   `institution_id` is nullable and a UNIQUE index treats NULLs as distinct on both
   MySQL/InnoDB and SQLite, so the composite index would be toothless for exactly the bootstrap
   and fixture rows. D11 makes one database one customer, so plain UNIQUE is both honest and
   enforceable. `levels.code` uses the same reasoning.
2. **No `people.level_id`.** History only, in `person_levels`, resolved through
   `Person::levelAt()`. A denormalized "current" pointer beside a history table is two
   definitions of one fact, and they drift.
3. **No `status`/`person_status` column at all.** The claim lifecycle is *structural*: a person
   is claimed iff a `users` row links to them (`Person::hasAccount()` — a join, not a column). A
   status enum would recreate the twelve-defence-sites problem the table split exists to avoid.
   `people.active` is the only state flag on the roster, and it governs naming, not claiming.

**The claim lifecycle (as shipped, Task 8):**

```
                     no `people` row exists
                              │
        ┌─────────────────────┼──────────────────────────┐
        │                     │                            │
  roster import /       admin invites an           CreateAdmin / LegacyImport
  invite matches an     unmatched address           create person + account
  existing address      (Person::create(),          TOGETHER, in one step —
  (Person::matchByEmail)  blank full_name)           never via an invitation
        │                     │                            │
        ▼                     ▼                            │
  ┌───────────────────────────────────────────────┐        │
  │ ROSTER-ONLY — a `people` row, NO `users` row.  │        │
  │ May be NAMED (consultant_by/to, D9); cannot    │        │
  │ authenticate — nothing for any credential path │        │
  │ to find (RosterOnlyCannotAuthenticateTest).    │        │
  └───────────────────┬─────────────────────────────┘        │
                       │ InvitationController::store()        │
                       │ issues an `invitations` row with      │
                       │ person_id already set to this person  │
                       ▼                                       │
  ┌───────────────────────────────────────────────┐           │
  │ INVITED — roster-only PLUS one open, single-   │           │
  │ use, expiring `invitations` row naming this    │           │
  │ person_id. Still no `users` row.                │           │
  └───────────────────┬─────────────────────────────┘           │
                       │ InvitationAcceptController::store()     │
                       │ CLAIMS person_id — never inserts a       │
                       │ second person; a placeholder's blank     │
                       │ name is filled from the invitee's input, │
                       │ a rostered person KEEPS their name;      │
                       │ creates `users` row, person_id set,      │
                       │ stamps invitations.accepted_at            │
                       ▼                                          │
  ┌────────────────────────────────────────────────────────────┐ │
  │ CLAIMED — `people` row + linked `users` row (person_id       │◀┘
  │ UNIQUE). Can authenticate AND be named. hasAccount() is TRUE.│
  └────────────────────────────────────────────────────────────┘
```

Deactivation moves along a different axis entirely and is not shown above: `people.active`
(naming) and `users.active`/soft-delete (authenticating) are independent flags that can be set
in any combination on a claimed person.

**`users.member_email` — the one deliberate denormalization, and why it is now dead weight.**
The original recon (finding 6) assumed `people.email` and `users.member_email` would be
**dual-written**, because Laravel's password broker resolves accounts with
`User::where('member_email', …)` inside `EloquentUserProvider::retrieveByCredentials()`.
**Owner decision 2 (2026-08-08) overrode that**: there is exactly ONE email column,
`people.email`. `PasswordResetLinkController`/`NewPasswordController` pass `retrieveByCredentials()`
a **Closure** instead of a plain value — a natively-supported feature that hands the query
builder straight to the closure — so the broker resolves through `whereHas('person', …)`,
never the raw column, while still needing no custom user provider (finding 1 stands). `User`
gained a read-through `memberEmail()` accessor (`$this->person?->email`) so
`getEmailForPasswordReset()`/`routeNotificationForMail()` needed no code change at all.
**Consequence:** `users.member_email` still physically exists — dropping it is a separate,
deferred migration (design §14 open items) — and still carries its original UNIQUE index, but
nothing on any live write path writes it any more (`InvitationAcceptController::store()` and
`UserManagementController::approve()` both had this raw write removed after it produced a real,
reachable bug — a stale column colliding with itself; see the P0c plan's Task 8 amendments).
The one write path left untouched is `LegacyImport`'s one-time historical upsert, which cannot
self-collide the way a live, ongoing write can.

### 5.2 The six original mitigations — withdrawn as unnecessary, one exception

D3's reversal did not just avoid new work — it retired five of the six mitigations this section
originally required, by removing the risk they existed to close. The sixth was never a
mitigation *against* the one-table risk; it survives as ordinary roster-integrity logic.

| # | Original mitigation | Risk it addressed | Structural property that now covers it |
|---|---|---|---|
| 1 | Nullable `password`/`member_name` + a `CHECK` constraint | A roster-only row sitting inside `users` with no credential, needing an engine-level guarantee it stayed that way | There is no such row. A roster-only person has no row in `users` at all — nothing to constrain (recon finding 2; §5's `person_status` CHECK work is cancelled outright) |
| 2 | A "single chokepoint" custom user provider | Authentication drifting out of sync across paths | Moot twice over: this app never calls `Auth::attempt()` (so a provider would gate nothing — recon finding 1), and now there is nothing in `users` for a roster row to be gated *against* |
| 2a | `person_status` as an extra predicate at six `active`-checking defence sites | D9 forcing roster rows `active = true` (to satisfy the consultant picker) would silently defeat all six `active`-based defences | `people.active` (naming) and `users.active` (authenticating) are columns on **different tables**. There is nothing to defeat — the six defences query `users`, and a roster-only person isn't in it |
| 2b | A dedicated gate on each of six credential-granting paths | Privilege escalation: a roster row minting itself an account via reset/verify/OTP/etc. | Structural absence, proven per-path by `RosterOnlyCannotAuthenticateTest` (B1–B10) — zero gate code, because none of those queries can find a row that was never written |
| 3 | An explicit password-reset-broker gate | The reset broker (keyed by email, bypasses the provider) minting an account for an uninvited roster row | The broker's credential closure joins through `users.person_id`; a roster-only person has none, so the join returns nothing (§5.1 above) |
| 4 | Roster import matches onto existing people by email, never duplicates | Importing/inviting the same human twice | **Not withdrawn — this one shipped, and gained a third consumer.** `Person::matchByEmail()` is the one definition, used by `LegacyImport`, `InvitationController::store()`, and — P1c Task 12, ST-04 — `App\Support\Roster\RosterImport`, which falls back to `short_name` (also UNIQUE outright) as a documented secondary key only when a row carries no email at all. It was never a consequence of the two-table risk, it is what any email-keyed roster needs regardless |
| 5 | Reconcile three overlapping state machines (`invitations`, `pending_registrations`, `person_status`) | Three different answers to "has this person claimed an account yet?" | `person_status` was never built (§5.1). `pending_registrations` turned out to have **no writer at all** (recon report 1 §2.3) — a frozen legacy queue, not a live machine — and is left in place pending a production count of zero (design §14). `invitations` is the one live lifecycle, and "claimed" is a join, not a third state to keep in sync |
| 6 | Capability resolution returns nothing for non-claimed rows | A roster-only person being granted a capability meant for account holders | `AccessControl::resolve()` keys off a `users` row (`app/Support/AccessControl.php:141-148`); a roster-only person has none, so there is nothing to grant to |

### 5.3 Naming versus signing (D9)

As shipped (P0c Task 5/6), the four named roles on `handover_signoffs` are **person** FKs
(`endorsed_by_person_id`, and the same for endorsed-to, consultant-by, consultant-to; wire
contract renamed from `*_user_id` to `*_person_id` for the same reason — `people.id` and
`users.id` are independent sequences, and leaving a field named `*_user_id` while it holds a
person id is exactly how the id-space-confusion bug ships) — **paired with a frozen name
snapshot** (`endorsed_by_name` etc.), plus `signed_off_by_name` added 2026-07-27. The legacy
`*_user_id` columns survive, `nullOnDelete`, populated only on historical rows (backfilled by
joining through `users.person_id`, never copied) — new writes leave them NULL. The FK is
`nullOnDelete`; the name survives it either way. `signed_off_by_user_id` and
`reopened_by_user_id` stay on `users`, because those are *actors*, not names of record. The
2026-07-27 signature ruling already treats name-without-signature as a valid attestation state:
*"wherever a signature is withheld, this line is the whole attestation of who documented the
handover."* — and a roster-only consultant, named but with no account, is exactly that case:
`SignatureStore` stays keyed on `users`, so there is no signature-path column for a person who
was never claimed to occupy.

`App\Support\SignoffPickers` (the single predicate-per-field definition that replaced
`pickerRule()`/`staffPickers()`'s shared closure — see §5.1) therefore enforces **different
scopes per field**:

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

**Units gain UN-02's three independent capability flags (training rotation / on-call coverage
target / clinic owner), UN-03 import aliases, and UN-05's optional secondary display name —
shipped P1b Task 1 (2026-08-09), `2026_08_13_120001_add_munawib_configuration_to_units.php`.**
This section originally claimed they shipped with P0a; they did not (P0a's
`2026_08_08_120001_add_configuration_to_units.php` added nine presentation columns and nothing
else) — corrected once already by P1a Task 9, and now genuinely true. The three flags
(`training_rotation`, `call_target`, `clinic_owner`) default `false` and are independent, never
collapsed into one enum; `aliases` is a JSON list, source spelling preserved, matched case- and
whitespace-insensitively via `Unit::findByCodeOrAlias()` (`Unit::findByCode()` remains what
routing uses — an alias never resolves a URL segment); `name2` is stored and, per Munawib UN-05
itself, rendered **nowhere** yet. **There is no `color` column** — a P1 plan draft asked for one
distinct from `bar_class`; rejected (P1b Decision B) as two definitions of one fact. `bar_class`
is the colour: `Unit::BAR_CLASSES` is an eight-entry allow-list (four original hues plus four
more, `channel-bar-slate` the default) that both offers the choice on the units screen and
validates it. All of the above is administrator-editable from Admin → Structure → Units (P1b
Task 4); `positions` become admin-managed; **capabilities stay code-defined** (they name
features) with role→capability defaults as data. Position 1 (Nurse) remains retired and is
never reused.

**The level ladder table is `person_levels`, not `user_levels`.** P0c's
`2026_08_10_120002_create_levels_and_person_levels.php` shipped it under that name, matching
the `people`/`users` split (D3 reversed) — a level history belongs to the roster identity
(`Person`), not the account (`User`). **The ladder is seeded and administrator-editable as of
P1b Tasks 6-8 (2026-08-09):** `R1, R2, R3, R4, EXT`, explicit `display_order` 10/20/30/40/90
(gapped by ten), `EXT` flagged `external` and last, all edited from Admin → Structure → Levels
— a rename survives `db:seed --force`. **`levels` gained `external` only, never `terminal`.**
This section's earlier text (and this plan's own original Task 6 draft) asked for a `terminal`
flag and a `Level::nextAfter()` "advance one level" inference; **Owner Decision A (P1b,
2026-08-09) rejected both outright** — a wrong terminal marker fails silently in two directions
(an unmarked top level advances a cohort into a level that does not exist; a wrongly-marked
middle level graduates a cohort a year early), and removing the inference removes the whole
failure class. Whatever P1c's LV-03 annual-promotion screen needs, it takes the **target level
as explicit operator input**, not a column reading "one step up".

**`person_levels` gained three provenance columns, P1c Task 6 (2026-08-09):**
`promotion_batch_id` (nullable UUID, groups the rows one bulk act produced), `reason` (nullable
free text), `created_by` (nullable FK to `users`). Additive and nullable, landed before the first
production promotion ever ran — the only point at which adding them is additive rather than a
backfill of facts nobody recorded (P1 finding 9). `App\Support\LevelAssignment` is the table's
ONE writer (`tests/Feature/Build/PersonLevelsHaveOneWriterTest.php`); it never upserts — a
collision on `unique(person_id, effective_from)` is skipped and reported, never rewritten, because
an upsert there would silently change what level someone held on a date that may already be
rendered beside a signed handover.

### 6.2 Bounded custom fields (D8, "Ceiling 2")

**Implemented, P0b** (`docs/superpowers/plans/2026-08-08-p0b-bounded-custom-fields.md`).

- `unit_field_definitions` — per unit: key, label, type (`text` | `date` | `select` +
  options), required, display order.
- `handovers.extra_fields` — TEXT, **encrypted whole** (`App\Casts\EncryptedJson`), since it
  holds clinical text. **Not** `json` — the stored value is base64 ciphertext, which MySQL's
  JSON type rejects; SQLite maps `json` to TEXT, so that mistake passes every test and fails
  only in production. Consequence of the whole-column encryption: extra fields are **not
  searchable or indexable**. Nothing searches them today; recorded so it is a known limit
  rather than a surprise. Degradation on a bad/foreign-key ciphertext is **all-or-nothing per
  row** (unlike the named identity columns, which degrade field-by-field) — the cast surfaces
  this as an `__unreadable` sentinel rather than an empty map, and the client renders it as a
  visible row-level warning instead of a silently incomplete sheet.
- **The four rich-text narrative fields stay first-class**, not user-definable — they carry
  the `SanitizedHtml` cast, the editor contract, and the print schema. Their *labels* are
  per-unit configuration. Custom-field values, by contrast, are plain text and are **never**
  purified server-side; every consumer escapes on render (`{{ }}` / `:value`, never `v-html`).
- Validation rules are built dynamically from the definitions, namespaced under
  `extra_fields.*`; the sheet and the print view both gain a generic renderer for extra
  fields (Sheet.vue renders the census twice — mobile cards and a desktop table — so the
  renderer had to be added in both); print caps how many definitions it shows and says so,
  since it is a fixed A4 page unlike the scrolling on-screen table.
- **The legacy import does NOT map legacy columns onto definitions** — corrected from this
  section's original draft. `LegacyImport` (`app/Console/Commands/LegacyImport.php`) maps
  legacy row columns onto the NAMED identity columns only (`bed`/`mrn`/`patient_name`/the four
  rich-text fields/`dob`/`age`/`ward_unit`); it never reads or writes `extra_fields`, because
  legacy data predates custom field definitions entirely — there is nothing there to map.
- **No admin UI ships in P0b.** Definitions are seedable/insertable only, which is still zero
  *code* for a new department to gain a field. A management screen for
  `unit_field_definitions` belongs alongside a future units settings screen, not before it —
  noted here as deliberately deferred, not forgotten.

### 6.3 Rota tables

Munawib §AR-05's collections become Eloquent tables. Semantics binding, names adjustable:

| Table | Munawib origin |
|---|---|
| `periods` | months or week-blocks (MR-01) |
| `master_rota_assignments` | person × period × unit, date-bounded splits (MR-02). **SHIPPED, P1d-1, 2026-08-10.** One row shape only: `starts_on`/`ends_on` NOT NULL on every row, both bounds inclusive — a whole-period assignment is the degenerate split (one row whose bounds equal its period's), not a second, nullable representation. Overlaps for one (person, period) are refused by the model; gaps are allowed and counted (owner decision, P1d). `App\Support\Rota\RotaAssignment` is the only writer (Decision F). No soft delete — the hash-chained `audit_log` is the history. |
| `vacations` | week or exact-date granularity (MR-03). **SHIPPED, P1d-1, 2026-08-10.** Deliberately carries **no `period_id`** — a vacation overlays whatever unit the master rota already has a person on, crosses period boundaries, and must survive a department regenerating or switching its period system; which period(s) it touches is a range intersection computed at read time (P1d Decision C). `App\Support\Rota\VacationBooking` is the only writer. |
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

**Both rota tables gained a BULK write path and an IMPORT path in P1d-2 (2026-08-10), and
neither is a second writer.** `App\Support\Rota\RotaFill` (MR-06's fill-down ×2, fill-across and
copy-period — three shapes, four explicit action keys, never one control that guesses) and
`App\Support\Rota\RotaImport` (two-file CSV) both dispatch every row through `RotaAssignment` /
`VacationBooking`, which remain the only writers; `RotaWritersAreSingularTest` fails the build for a
second one and needed **no** new allow-list entry for either. P1d-2 added **no migration at all** —
verified as `git diff --stat 9c8c1cf...HEAD -- database/migrations` being empty across both halves —
so this section's table is unchanged by it. The bulk discipline is the part worth carrying forward:
the whole set is validated and authorized before the first mutation, one transaction, and **one**
summary audit row per operation written *after* it commits. Per-cell auditing was rejected outright
— several hundred chain appends serialise the audit tail for the operation's duration, and
`rota_fill` is on `AuditAnomalies`' single-occurrence watch list, so per-cell rows would put
hundreds of findings in one alert body.

**Audit:** all Rota writes join the existing hash-chained `audit_log`, respecting the standing
invariant — one canonical string definition (`AuditChain::canonical()`), and a stored naive
datetime is never re-parsed in the current timezone.

---

## 7. Calendar

**Decision A (P1a, 2026-08-08): ONE implementation, not two — this section's original "PHP
plus a mirrored `packages/engine`" plan is superseded.** `App\Support\Calendar` is the sole
converter; the client performs **no** date arithmetic at all. `packages/` does not exist in
this repository and there is no client-side date library (no dayjs/date-fns/luxon/moment).
Shipping a second implementation now would have been two definitions of one fact — the same
failure class `AuditChain::canonical()` and `Person::levelAt()` already carry docblocks
against — so P1a's screens receive pre-formatted Gregorian/Hijri labels, enumerated date
ranges, and day types as Inertia props, computed server-side, every time.

This is a **stricter** reading of AR-08 ("nothing outside that module converts dates"), not a
weaker one: it also permanently retires the four hand-rolled JS date helpers P1a found and
deleted, each of which carried a comment recording a real production bug — at +03:00,
`toISOString()` rewinds local midnight to the previous Gregorian date, which silently broke
"Start next day" once already.

**What this defers, and how it stays honest:** P2's `packages/engine` conditions engine needs
client-side date math for UX-05 (evaluating hints without a network round trip), so the
TypeScript mirror this section originally described is not cancelled — it is deferred to P2,
built alongside the package and its TypeScript toolchain that P2 creates anyway. P1a builds the
contract that mirror must satisfy **now**, while the semantics are fresh:
`tests/fixtures/calendar/golden.json`, a framework-free JSON corpus (day-level Hijri/weekend/
day-type cases across an offset recalibration and a Hijri month boundary, a +03:00 day-boundary
case, holiday cases including a span crossing a Hijri month end, a leap year, and both
period-generation systems including the week-block run's variable final block) that
`tests/Feature/Calendar/GoldenFixtureTest.php` already asserts against the PHP implementation.
P2's mirror asserts the same file against itself; a change to one side that is not matched on
the other is exactly the drift §4.3's cross-validation job exists to catch, applied here a
stage early.

**The department's own week (P1d-1, 2026-08-10) resolves a genuine gap in Munawib's own spec.**
AR-05 gives a vacation a `granularity: 'week'` and MR-07 asks for availability *"each week"*, but
nowhere does Munawib's spec say what a week **is** — while §8 ST-01 makes the weekend
(`institutions.weekend_days`) department configuration, so two departments with different
weekends would otherwise snap the same leave to different dates. `App\Support\Calendar` gained
`weekStartIsoDay()` (the ISO weekday a week begins on, derived from the LAST configured weekend
day, wrapping — Friday+Saturday off gives a Sunday start), `weekOf()` (the week containing a date,
both bounds inclusive) and `weeksIn()` (every week intersecting a range, clipped). One
implementation, shared by the on-screen week picker and — when P1d-2's importer lands — CSV
import: a `week`-granularity booking is never snapped one way when typed and another when
imported.

**P1d-2 made that last sentence literally true rather than aspirational (2026-08-10), and it did
NOT add a second consumer of `Calendar::weeksIn()`.** The snapping half held: `VacationBooking::snap()`
was extracted out of `book()`, `book()` now calls it, and `RotaImport` calls the same function to
*display* the adjustment in its preview — one rule, two entry points, and the importer writes through
`VacationBooking::book()` anyway (owner decision, 2026-08-10: a `week`-granularity vacation is snapped
on import exactly as on the screen, and the preview reports the adjustment so a snap is never silent).
The weeks half is worth stating precisely, because the obvious guess is wrong: `Calendar::weeksIn()`
still has exactly **one** production caller, `RotaGrid::forYear()`. MR-07's `AvailabilitySummary`
computes "who is on vacation each week" without touching `Calendar` at all — it folds the `weeks` the
grid already built into its props, and asks whether a vacation intersects a week by comparing four
`Y-m-d` **strings**, a format that sorts correctly as text. That is ST-06 at its strictest: the
summary handles no dates, so there is no second place for the department's week to be defined, and no
second place for it to drift.

**Timezone stays per-INSTANCE, not per-department** (owner decision 3, 2026-08-08, overriding
this section's earlier implication of a per-institution timezone): `APP_TIMEZONE`
(`config/app.php`), never an `institutions.timezone` column. Under D11 there is one institution
per database, so a per-department column beside the env var would be one fact in two places —
the same drift class that produced the audit-chain false alarm §15 records — and it would make
the handover day boundary (`UNIQUE(unit_id, handover_date)`, uncorrectable after the first
clinical write per `docs/RUNBOOK-PROVISION.md`) editable from a screen. Only
`hijri_offset_days` is per-department (`institutions.hijri_offset_days`, additive nullable
migration, P1a Task 1).

The prototype established `hijri_offset_days = −1` for this hospital, verified against the
department's published calendar across a month boundary; that value is set via
`HIJRI_OFFSET_DAYS` in Coolify (owner action, `docs/RUNBOOK-DEPLOY.md`), not hardcoded.

The calendar converts for display and scheduling day-boundary math only — **never** for audit
canonicalization, which stays byte-verbatim.

**The memo now has a production flush contract (P1b Task 10, 2026-08-09).**
`Calendar::settings()`/`::activeHolidays()` are memoised in statics for the life of the
process; `Calendar::flush()` existed since P1a but had **no production caller at all** until
P1b's calendar-settings and holiday-CRUD screens gave the module its first runtime writers.
Every write path touching `institutions`' calendar columns or a `holidays` row now calls
`flush()` in the same request, guarded at source level by
`tests/Feature/Build/CalendarWritersFlushTest.php` (observed failing against a deliberately
non-flushing throwaway file before being trusted, the same discipline
`CalendarIsTheOnlyConverterTest` uses). The same task adds
`PeriodGenerator::assertMonthAligned()` — a calendar-month period system must begin on the
first of a month, checked once and consumed by both `months()` and the settings screen's
validation — and hard-locks `period_type`/`academic_year_start` the moment any `periods` row
exists (Decision D), unlocked only by deleting that academic year's periods (P1b Task 11).

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

**MR-05's read view is `cap:rota.view`, not a token link (P1d-1, 2026-08-10).** Every seeded
position holds `rota.view` by default — a resident reading which unit they rotate through next is
the whole point of MR-05 — while `rota.manage` (editing) defaults Administrator-only. Tokenized
share links remain **P3** as stated above; by the time P1d-1 shipped, Stage 1's own acceptance
criterion ("residents claimed accounts") means every reader already has a login, so a share link
would solve a problem the platform has already solved differently. Separately, and settled by
Munawib's own data model rather than by this project's preference: `masterRota/{periodId}` carries
**no `status` field at all**, unlike `schedules/{periodId}` (`'draft'|'published'|'archived'` plus
a `version`). P1d therefore ships **no** draft/publish state machine for the rota — §18's
publish/version/archive machinery (PU-01…03) is Stage 2 and is written entirely about the call
schedule, once, for both surfaces together.

**The publish gate is ANSWERED and CLOSED: there is none (owner decision, 2026-08-10, P1d-2).**
This paragraph previously ended *"an explicit 'not visible until I say so' gate remains a real, if
unbuilt, product option (§14 open item), additive if the owner wants it later"*. That option is no
longer open — the owner closed it when P1d-2 was planned, and §14's item 19 records the answer
rather than continuing to list the question. No `status` column, no `published_at`, no draft state,
no publish action, no "visible from" date: `/rota` always shows the current rota. It stayed a
decision rather than becoming an implicit default because the absence is asserted, not merely
unimplemented — `RotaReadViewTest::test_there_is_no_publish_state_on_the_read_view` scans the read
controller's own props for a publish-shaped key (deliberately excluding the five keys
`HandleInertiaRequests::share()` puts on **every** page, one of which is `flash.status`, or the scan
would fire on every request in the app regardless of the rota).

**`rota.manage` reverted to Administrator-only on the same date, reversing what P1d-1 shipped.**
The sentence above — *"while `rota.manage` (editing) defaults Administrator-only"* — is what the
seeder does today, and was **not** what P1d-1 actually seeded: it shipped the capability to Chief
Resident as well (Munawib's Scheduler persona maps to no role here, and Chief Resident is the
nearest fit). A department that wants it there grants it from Access Control, which is one screen
and no code change. An instance that already received the P1d-1 grant **keeps** it —
`AccessControlSeeder` applies each (position, capability) default once through `applied_role_defaults`
and never re-asserts, so this is an operator un-tick (`docs/RUNBOOK-DEPLOY.md`) and deliberately
**not** a data migration.

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

### 9.3 `people.manage` (P1c Task 1, 2026-08-09)

A new capability, distinct from the two it could be confused with: `users.manage` gates the
**account** console (approve, activate, issue an invitation); `structure.manage` gates the
department's **shape** (units, levels, the calendar); `people.manage` gates the **roster** — who
exists, their contact fields, their training level, whether they are external. Administrator-only
by default. A roster-only person (no `users` row) is invisible to `users.manage`'s screen by
construction, and is frequently the on-call consultant whose name is frozen onto signed
medico-legal evidence — a different blast radius from either of the other two, which is why it is
its own key rather than folded into one of them.

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
- **PHPUnit:** database-per-customer provisioning, capability enforcement,
  `App\Support\SignoffPickers` offer/validation parity **per field** (D9,
  `PickerParityTest` — every fixture × all four fields), **`people` carries no credential
  column (asserted by name)**, **the roster-only-cannot-authenticate matrix across all six
  credential paths** (`RosterOnlyCannotAuthenticateTest`, B1–B10), the password-reset broker's
  join-not-raw-column resolution proven end to end through the real HTTP kernel
  (`PasswordResetTest`), the roster-import/invitation email-match-not-duplicate path
  (`Person::matchByEmail()`, `ClaimLifecycleTest`), the no-PHI guard test, share-token
  expiry/revocation, audit-chain integrity.
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
| **P0 — Platform foundation** | Save Munawib Part B as `docs/munawib/SPEC.md`. Units and `UnitProfile` become configuration; **Ceiling-2 custom fields** (definitions, encrypted JSON, dynamic validation, generic print renderer, import mapping); `levels` + `person_levels` (shipped under this name, not `user_levels` — §6.1); `users` extended with §5.1 columns and CHECK constraints; **auth lifecycle reconciled into one state machine**; the password-reset and email-collision gates; `pickerRule()` rewritten per D9; provisioning script for database-per-customer. Endorsement stays green throughout. |
| **P1 — Munawib Stage 1** | People, invitations, roles on the merged identity; master rota (both period systems, splits, vacations, import/export, publish view); clinics; holidays. **Split into five sub-plans** (`docs/superpowers/plans/2026-08-08-p1-master-rota.md`) once reconnaissance found the operational layer above `Person`/`PersonLevel` entirely empty and P1 too large to plan as one unit: **P1a** the calendar module, per-department calendar settings, both period systems, holidays, and absorption of every existing date converter (no new route); **P1b** — **SHIPPED, 2026-08-09.** Units CRUD (UN-01…05: create/rename/recolour from an eight-entry `bar_class` allow-list — Decision B, no separate `color` column — reorder, reflag, alias, retire, merge) and the level ladder CRUD (LV-01: seeded `R1…R4, EXT`, `external` flag only — Owner Decision A dropped `terminal`/`Level::nextAfter()` outright), both behind a new `structure.manage` capability; then the three ST-02 settings surfaces — calendar settings (bounds-checked Hijri offset, month-alignment-validated period start, `period_type`/`academic_year_start` hard-locked once periods exist), periods (preview **and** generate-and-commit **and** delete-a-year — `PeriodGenerator` had zero production callers before this), and holidays CRUD — plus the production `Calendar::flush()` contract every one of those writes now honours. Adds **no** anonymous route (§9.1 holds); **P1c-1 — SHIPPED, 2026-08-09.** The People screen
(`people.manage`, §9.3) — PE-01's full field set, PE-02's contact-visibility projection
(`PersonPresenter` + `PersonPolicy`, §5.1's correction), PE-03's `external` flag made real,
`Person::levelsAt()`'s set-wise level resolver, LV-04's per-span history — LV-02's bulk set-level
/ set-status / export (the safe CSV writer, §5.1-adjacent, neutralises formula injection on
write and un-neutralises it on read), LV-03's annual promotion (operator-chosen target, never
inferred — §6.1's Owner Decision A restated as a screen), and ST-04's roster import (CSV/TSV,
column mapping, dry-run preview, commit — open item 16 records the xlsx question). Split at the
person/account seam into P1c-1 (this) and **P1c-2** (AC-02's configurable lifetime/resend/claim
status, AC-03 unbinding, AC-04 per-person roles — open item 13), planned once P1c-1 merged. Two
claims this plan's own P1c item made turned out false and are corrected at their own sections:
PE-02's projection is `PersonPresenter`, not `$hidden` (§5.1); LV-02's "resend invitations" is an
account action, not a roster one, and ships in P1c-2. **P1d-1 — SHIPPED, 2026-08-10.** The master rota's data and its editor: `rota.view` (every seeded position) and `rota.manage` capabilities — P1d-1 seeded the latter to Administrator **and Chief Resident**, which P1d-2 reversed to Administrator-only the same day on an owner decision (§9.1); the department's own week inside `Calendar` (§7); `master_rota_assignments` (one row per span, overlaps refused, gaps allowed and counted, §6.3) and its one writer `App\Support\Rota\RotaAssignment`; `vacations` (no `period_id`) and its one writer `App\Support\Rota\VacationBooking`; the `PeriodController::destroy()` hardening the first table makes necessary; the `PersonPresenter` `email` gating; `/admin/rota`'s grid — rows by level, columns by period, per-cell save, splits, vacations — at a measured, bounded query count. **P1d-2 — SHIPPED, 2026-08-10**, in two branches (2a read and summarise, 2b move), adding **no migration in either half**: MR-05's resident-facing read view (`/rota`, `cap:rota.view`, search, level filter, per-person period strip, and a router-level assertion that *every* route behind `cap:rota.view` is a GET — no publish gate exists to add one for); MR-07's `App\Support\Rota\AvailabilitySummary`, one pure, query-free fold over the grid feeding **both** screens, counting uncovered days and the people carrying them separately and reporting who is on leave each week — **the Stage 1 acceptance criterion**; the contact-free projection that closes a props-payload disclosure on the editor as well as the read view (§9.1's neighbour, `PersonPresenter::contactFree()`, and `RotaGrid` taking no viewer at all); and MR-06's bulk moves — `RotaFill` (four action keys, one shared `analyse()`, a digest-pinned confirm, one `rota_fill` audit row per operation on `AuditAnomalies`' watch list, and a split-carrying target skipped unless explicitly confirmed), the two-file CSV export carrying no contact field, and `RotaImport`, which invents no person, unit or period and whose unit of outcome is the (person, period) cell rather than the line. MR-06 is six words in Munawib and the most destructive surface in the rota; the bulk discipline this codebase already had (P1 finding 12, `AccessControlController::updateRoles()`; `RosterImport`'s preview/commit/digest) is not optional for it, which is why every clause above about validation, transactions and confirmation is stated rather than assumed. **P1e** clinics, the weekly clinic map, and the setup wizard threading every step above, plus the removable demo department seed. Each sub-plan is written when its predecessor merges, per the P0a–P0d convention. |
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
6. ~~**Reserved unit codes.**~~ **SHIPPED, P0d Task 5, 2026-08-08.** `Unit::RESERVED_CODES`
   (`TODAY`, `COMPLIANCE`, `ROWS`) is enforced on every write via a `saving` guard, and
   `ReservedUnitCodesTest::test_the_reserved_list_covers_every_literal_route_segment` derives the
   list from the registered routes rather than trusting the constant, so a new literal route
   under `/endorsement` that forgets to extend it fails the build instead of shipping a silent
   trap. The guard predates the admin UI it was written for, which is still item 1 of P1's scope
   (§13).
7. **`invitations` has no retention rule.** `member_email` accumulates on that table indefinitely
   — nothing ever prunes an old, accepted or revoked invitation (recon report 1 §R8). Needs a
   disposal policy, most likely folded into `data:retention` alongside the other operational rows
   it already prunes (abandoned registrations, expired one-time codes).
8. **Removing `pending_registrations` awaits a production count of zero.** The queue has **no
   writer at all** in this codebase (recon report 1 §2.3; `GET /register` binds to
   `RegisteredUserController::closed()`), but it is not dropped — only the owner can confirm the
   live production count is zero, and CLAUDE.md's migration rules put that confirmation before the
   drop, not this plan.
9. **`users.member_email` awaiting removal (P0c).** Dead since owner decision 2 (2026-08-08):
   `people.email` is the single authoritative address, and no live write path touches the raw
   `users.member_email` column any more (the one exception, `LegacyImport`'s one-time historical
   upsert, is deliberately left alone — see §5.1). The column still physically exists and still
   carries its original UNIQUE index. Dropping it is a future, separate, additive migration
   (CLAUDE.md: never retype/drop a column holding real data without its own reviewed migration),
   not done as part of P0c.
10. **Co-tenancy on the shared `coolify` network is an ACCEPTED risk with a named trigger
    (owner decision, P0d, 2026-08-08).** Every customer's `app` container is mutually reachable
    with every other on that network, and `TRUSTED_PROXIES` covers `172.16.0.0/12`, so a compromised neighbour
    could forge `X-Forwarded-For` — reviving the forgeable-audit-IP and bypassable-lockout risk
    the 2026-07-26 audit closed. Mitigating it means a separate host per customer, which the
    owner has declined for now rather than accepted as safe. Recorded in full, with the trigger
    that must be honoured verbatim, in `docs/OPEN-DECISIONS.md`, `docs/COMPLIANCE.md` and
    `docs/PDPL-PACK.md`: **revisit before a second customer carries real patient data.**
11. **The restore drill has no last-run record and no ageing alert.** `docs/RUNBOOK-BACKUP.md`
    records the first drill's date in prose; nothing machine-checks that a quarter has not
    passed since, per instance. At N=1 this is a discipline gap; at N customers it is N
    untracked obligations. Not addressed by P0d — the runbook carries a per-instance register
    (a place to write the date down), not an alert.
12. ~~**`institutions` still has no admin surface.**~~ **PARTIALLY CLOSED, P1b Task 10,
    2026-08-09.** The calendar columns (`hijri_enabled`, `hijri_offset_days`, `weekend_days`,
    `period_type`, `block_weeks`, `academic_year_start`) are editable at
    `/admin/structure/calendar`, bounds-checked server-side, audited by key name. `name` and
    `code` remain `INSTITUTION_CODE`/`INSTITUTION_NAME`, env-only (P0d Task 3), read once by
    `ReferenceSeeder` on `db:seed --force` — changing either still means a direct database edit
    outside the audit trail. Narrowed rather than closed: a rename/re-code screen is a separate,
    not-yet-scoped item.
13. ~~**AC-02 invitation lifetime: 7 days or 14?**~~ **SETTLED, owner decision, round 2,
    2026-08-08.** `Invitation::LIFETIME_DAYS = 7` today, deliberately shorter than Munawib
    AC-02's 14 — an invitation is a credential that reaches children's clinical records once
    redeemed, and a shorter window means a forwarded link is live for less time. The decision
    goes further than "keep 7": lifetime becomes **admin-configurable**, default 7, validated
    (a sane upper bound, an integer, no zero-or-negative) so the knob cannot be turned to
    something absurd. Recorded as a deliberate spec deviation from AC-02, not an oversight.
    Building the configurable setting is **P1c-2** scope (the P1c plan's own split between roster
    work and account work — this is an AC-02/account concern, not ST-04/roster) — not yet
    implemented as of P1c-1, which shipped 2026-08-09.
14. ~~**Does the missed-days denominator become weekend/holiday-aware?**~~ **SETTLED,
    UNCHANGED, owner decision, round 2, 2026-08-08.** Every calendar day still counts toward
    `MissedDays`' `total_days`, exactly as before P1a gave the system its first weekend and
    holiday knowledge. Making the denominator day-type-aware would silently alter every
    historical compliance figure the system has ever produced — a change in what the number
    *means*, not a refactor, and nothing records which definition produced an earlier figure.
    `MissedDays` never consults `Calendar::dayType()`/`isHoliday()`/`isWeekend()`; pinned by
    `HolidayTest::test_missed_days_denominator_is_unaffected_by_a_holiday` and
    `ConverterAbsorptionTest`'s weekend-day equivalent (P1a Tasks 5 and 7). If ever revisited,
    it must be a deliberate, dated change with the old figures preserved, not this one.
15. **`Calendar::flush()`'s production contract, and its one allow-listed non-flushing writer.**
    P1b Task 10 gave `flush()` its first production callers (the calendar-settings and holiday
    screens); `tests/Feature/Build/CalendarWritersFlushTest.php` enforces at source level that
    every file writing `institutions`' calendar columns or a `holidays` row also calls it
    somewhere in the same file. One legitimate exception: `database/seeders/ReferenceSeeder.php`
    writes `hijri_offset_days` on create (or when `HIJRI_OFFSET_DAYS` is explicitly configured)
    without flushing, because a seeder run is its own process that exits — nothing renders from
    the stale in-process memo afterward. Any future non-flushing writer needs the same kind of
    stated reason, or the guard will name it.
16. **xlsx roster import awaits a dependency decision (P1c Decision E, 2026-08-09).** ST-04 ships
    CSV/TSV only — `App\Support\Roster\RosterReader` is an interface with one adapter,
    `CsvRosterReader`, built on PHP core (`SplFileObject`); there is no spreadsheet package in
    `composer.lock`, and adding one to a system holding children's PHI is the owner's
    supply-chain decision, not a developer's. The cost of yes, stated rather than discovered
    later: one MIT, zero-runtime-dependency package (`openspout/openspout`), one
    `composer.json` line, one explicit `"ext-zip": "*"` (zip is already installed in the image,
    in CI and locally), and one new class, `XlsxRosterReader`, implementing the same interface —
    nothing in the preview, the validation report or the commit path changes either way. Default
    if unanswered: stay CSV-only; the screen states plainly what it accepts and how to produce it
    from Excel (File → Save As → CSV UTF-8).
17. **`Invitation::issue()`'s own email normalisation is a second definition of one fact.**
    `Person::normalizeEmail()` (mb-safe lowercase, trim, `''` → `null`) is the one definition
    P1c's roster importer uses; `Invitation::issue()` still normalises inline with
    `Str::lower(trim($email))` — equivalent for ASCII, not mb-safe, and it cannot produce `null`.
    Collapsing the second onto the first is a small, safe tidy, deferred to **P1c-2**, which owns
    `Invitation` anyway (finding 10, P1c plan).
18. **MR-04's eligibility derivation is unbuilt, and its hook is recorded rather than built.**
    *"The master rota drives on-call eligibility automatically"* is Stage 2 (§35, owner decision
    1, P1d): slots, call rosters, an `off_roster` unit flag and per-person include/exclude
    overrides do not exist anywhere in this codebase, and neither P1d-1 nor P1d-2 adds any of them.
    `tests/Feature/Rota/RotaAccessTest.php` now scans for the shape **twice**, and the two fail for
    different reasons: `test_nothing_in_the_rota_infers_on_call_eligibility` keeps the original four
    identifier needles over the whole of `app/`, and
    `test_nothing_in_the_rota_namespace_infers_on_call_eligibility` (P1d-2) runs eight needles,
    case-insensitively, over `app/Support/Rota/` in full plus the rota's controllers, form requests
    and Vue screens — because an availability summary is precisely the shape somebody would reach
    for to answer *"who can take call in Block 11?"*, a bulk fill is how they would write the answer
    across a year, and an importer is how they would load one from a spreadsheet. **The second scan
    strips comments before matching**, which is a deliberate departure from
    `CalendarIsTheOnlyConverterTest`'s prose-matching discipline: three of those files open with a
    paragraph stating that they must never become an eligibility computation, so a literal needle
    scan would fail the build on the rule's own statement and train people to delete the
    documentation. The stripper is itself pinned in both directions
    (`test_the_scan_strips_comments_and_still_sees_the_code`) — one that over-reached would silently
    disable the guard and look identical to a clean tree.
19. ~~**The master rota has no publish state, by decision — revisit if the owner wants a gate.**~~
    **CLOSED — ANSWERED, owner decision, 2026-08-10 (P1d-2): there is no gate, and the question is
    no longer open.** §9.1's Decision D already observed that Munawib's own `masterRota/{periodId}`
    document carries no `status` field (unlike `schedules/{periodId}`), so P1d shipped no
    draft/publish machinery and MR-05's read view is a logged-in, `cap:rota.view`-gated screen
    showing the current rota. This item existed because "we have not built it" and "we have decided
    against it" are different states and only the second one is safe to build on top of. The owner
    answered it before P1d-2 began: **no status column, no draft state, no publish action, no
    'visible from' date.** The absence is asserted rather than merely unimplemented
    (`RotaReadViewTest::test_there_is_no_publish_state_on_the_read_view`), and the whole
    `cap:rota.view` route group is asserted GET-only over the ROUTER, so a future publish endpoint
    cannot arrive there unnoticed. Should a later owner want a gate it remains additive — one
    nullable column, one controller branch — but it would be a new decision reversing this one, not
    the resumption of an open question.

---

## 15. Risks

| Risk | Mitigation |
|---|---|
| Engine and solver semantics drift | The CI cross-validation job (§4.3) with the §4.2 evaluation mode; divergence fails the build. This is the failure that killed the prototype and is designed against explicitly. |
| D13 makes P2 long and undemoable | Accepted by the owner (§1.3). Mitigate by ordering the 21 types so the prototype's proven nine land first and are demoable, even though all 21 ship before P3. |
| D3 (one `users` table for roster + accounts) weakens auth invariants | **REVERSED, 2026-08-08 (P0c).** `people` and `users` are now two tables; a roster-only person has no `users` row and cannot authenticate by construction (§5.1) — five of the original six §5.2 mitigations became unnecessary and the sixth (roster-import email matching) shipped as ordinary correctness logic rather than a risk mitigation. Residual risk from the split itself, not from D3: `$user->full_name`/`position`/`member_email` are read-through accessors that silently resolve to null if a narrowed `select()`/`with()` omits `person_id` — broke four live sites before test coverage existed (CLAUDE.md carries this as a standing rule now); and `users.member_email` survives as dead weight awaiting removal (§14 item 9). |
| PHI leaks into Rota | Named query services as the only crossing, plus the §9.2 guard test. |
| Share tokens forwarded outside the department | Expiry, revocation, audit, no contact data, `noindex`; policy documented in the PDPL pack. |
| N deployments once there is a second customer | **Backup is scripted and tested** (`BackupRunTest`, 11 tests; `docker/smoke.sh` exercises it against real MySQL). **Provisioning and restore are prose, not scripts** — `docs/RUNBOOK-PROVISION.md`, dress-rehearsed against two throwaway stacks rather than merely written (P0d Task 9). Two of the "linear cost" items were in fact defects that only exist once N ≥ 2 — the uptime monitor was incorrect (not just duplicated) and backup retention deleted across customers — both closed by P0d (Tasks 1 and 7) and disproved live in the rehearsal, not merely reasoned about. Drills remain a runbook obligation with no automated last-run record or ageing alert (§14 item 11). |
| The documented migration procedure can be aimed at the wrong customer's clinical database, and with default names succeeds silently | `docker/instance-env.sh` resolves one stack's containers by Coolify-assigned container identity and refuses on no match or an ambiguous match — replacing the image-ancestry selector that cannot distinguish two customers' identical `mysql:8.4` containers (P0d Task 6). Proven against two live throwaway stacks with identical database/user names in Task 9's rehearsal, including the ambiguous-match refusal path. |
| Solver too slow on the host | Queued generation; capacity is a P4 prerequisite (D12); measured against a real fixture rather than assumed. |
| D6 delays clinical value for months | Accepted and reaffirmed by the owner (§1.3). |
