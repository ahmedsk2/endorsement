# PART B — SPECIFICATION v1.0 (FINAL — save verbatim as docs/SPEC.md)

# Munawib — Departmental Duty Rota & Scheduling Platform, Specification v1.0

Frozen 8 August 2026. Supersedes Drafts 1–4. Requirement IDs (e.g., MR-03) are binding at the stage where their module ships (§35).

## 1. Purpose

A department-level scheduling platform for hospitals, beginning with medical training departments. It manages the annual master rota (which resident trains in which unit each month or block), clinics, vacations, and the monthly on-call schedule — built manually with intelligent, ranked hints or generated automatically by a constraint solver — plus morning unit coverage, a live who's-on-call board, resident self-service with approvals, fairness and duty-hour reporting, and, in its final stage, true shift-grid rosters for emergency and nursing units. Each department runs an isolated instance built from one shared codebase; every department difference is configuration, never code.

## 2. Background and prototype lessons

The product succeeds a production prototype built for the QHN Pediatrics residency. Proven and carried forward: the live schedule as single source of truth with no publish-to-static step; a draft workbench publishing in one click with archive-before-publish; an advisory rules engine whose warnings the chief can deliberately override, recorded in an ignored-warnings ledger; auto-fill that only places rule-clean assignments; daily coverage derived from rotations minus post-call, vacations, and manual overrides, with manual overrides always winning; dual Gregorian–Hijri dating; mobile-first viewing. Encoded as requirements from its failures: no personal data on unauthenticated surfaces; exactly one conditions engine; one repository with an automated pipeline; everything department- or period-specific is data.

## 3. Product principles

The engine warns and ranks; the human decides — overrides are recorded, never silenced. The published schedule is live for every viewer. Configuration, not code. Privacy by default. Excellent user experience is a requirement with numbers attached (§31–§32). Regional calendar realities are first-class: Hijri display, configurable weekend, Hijri-aware holidays.

## 4. Frozen decisions

Delivery in five stages, shifts last. One isolated instance per department from one codebase. Accounts by email invitation link; residents self-submit requests through approvals. Conditions are typed, parameterized, hard or rank-ordered soft. **English-only launch** with translation-ready architecture (§30 AR-07). **Swaps: same-level residents only, mandatory counterpart acceptance then chief resident / program director approval, email at every step.** **Hard conditions block publishing by default (never editing/saving).** **Clinic conflict default: post-call variant on; same-day-overlap variant off.** Working codename Munawib; the display name is configuration.

## 5. Users, roles, and permissions

Personas: program director / chief resident (Scheduler) — owns the master rota and the monthly schedule, approves, publishes; department administrator (Admin) — configures structure, roster, roles; resident (Resident) — views own everything, self-submits, syncs calendar; everyone else (Viewer) — reads the published schedule and boards. Roles are additive per person; enforcement is server-side.

| Capability | Viewer | Resident | Scheduler | Admin |
|---|---|---|---|---|
| View published schedule & boards | ✓* | ✓ | ✓ | ✓ |
| View contacts | — | policy | ✓ | ✓ |
| View own tallies/requests | — | own | ✓ | ✓ |
| Submit requests & swaps | — | own | ✓ | ✓ |
| Edit drafts, run auto-fill | — | — | ✓ | ✓ |
| Approve requests/swaps, publish | — | — | ✓ | ✓ |
| Post-publish corrections | — | — | ✓ | ✓ |
| Manage master rota & clinics | — | — | ✓ | ✓ |
| Configure units/levels/slots/conditions | — | — | — | ✓ |
| Manage people, invitations, roles | — | — | — | ✓ |
| Audit browser, contact-bearing exports | — | — | ✓ | ✓ |

*Viewer access is link-public or login-only per department setting; when link-public, only the published schedule, boards, and clinic map are exposed — never contacts, requests, or tallies.

## 6. Competitive context (informative)

Amion (https://www.amion.com) — the hospital-wide who's-on-call standard: department schedules feeding a live master list, switchboard corrections, swaps with optional final approval, requests, balance checks, tallies, calendar sync. MedRez (https://www.medrez.net) — the residency-native relative: annual rotations feeding call availability via auto-include, staffing and workload requirements, continuous duty-hour checking, eligible-highlighting during manual edits, an "On Now" view, calendar feeds; born from clinic-versus-call conflicts. QGenda (https://www.qgenda.com) — the enterprise ceiling (scheduling, on-call, time, credentialing, capacity, analytics; nurse self-scheduling and swaps by acquisition). PerfectServe Lightning Bolt (https://www.lightning-bolt.com) — the automation benchmark and this product's direct design validation: hard and soft constraints where soft violations incur penalties scaled by an assigned priority level, optimized combinatorially; hundreds of rules per real deployment. Nursing platforms (symplr Smart Square, Shiftboard, Deputy, Snap Schedule) — Stage 5 mechanics: self-scheduling windows, open-shift bidding, fatigue rules, equalization. Commodity HMS scripts (https://codecanyon.net) bundle shallow rosters inside broad suites — depth is this product's edge. Solver prior art: OR-Tools CP-SAT (e.g., https://github.com/TaroAndMulan/HospitalDoctorScheduling-OR-tools-). Differentiators: GCC-native calendar and holiday equity, master-rota-integrated eligibility, explainable ranked automation, per-department isolation and price, WhatsApp-friendly sharing.

## 7. Domain overview and glossary

A **Department** (one per instance) contains **Units/Specialties**, a **Level ladder**, **People**, **Holidays**, and configuration. The **Master Rota** assigns each person a unit per **Period** (month or week-block) and records **Vacations**. **Clinics** belong to units on a weekday session. **Slot definitions** describe duties; **Coverage templates** state required headcount by level per day type. A **Schedule** per period holds **Assignments** and moves draft → published → archived. **Conditions** govern hints and automation. **Requests** flow through approvals into constraints. Tallies, reports, notifications, audit events, and feeds complete the model.

Glossary (EN/AR, documentation aid): on-call — مناوبة; on-call schedule — جدول المناوبات; post-call — ما بعد المناوبة; rotation — تناوب تدريبي; unit — وحدة; clinic — عيادة; vacation — إجازة; unwanted day — يوم غير مرغوب; swap — تبادل; backup — احتياطي.

## 8. Instance setup and configuration

ST-01 — A setup wizard takes an empty instance to a working department: profile and branding; calendar (period type, academic-year start, weekend days, Hijri display, **timezone** — default Asia/Riyadh, and **hijriOffsetDays** — a small signed calibration applied to algorithmic Hijri conversion, verified against the department's official calendar; the prototype required −1); level ladder; units; slots and coverage templates from a preset; conditions from a preset; holidays; roster import; invitations. ST-02 — Every step revisitable in Settings. ST-03 — Launch presets: "Residency on-call (split day/night)" and "Residency on-call (24-hour)"; Stage 5 adds "Shift roster". ST-04 — Roster import: xlsx/csv with column mapping, validation report, dry-run preview. ST-05 — A one-click, clearly-labeled, removable **demo department** seed exists for training and for development fixtures. ST-06 — All day-boundary computations use the instance timezone.

## 9. Units and specialties

UN-01 — Admins create, rename, color, order, deactivate, and merge units (NICU, PICU, General Ward, subspecialties…). UN-02 — Three independent capability flags per unit: training rotation; on-call coverage target; clinic owner. UN-03 — Alias names normalize imports (typo tolerance) while preserving source data. UN-04 — Deactivation hides forward, never deletes history. UN-05 — Optional secondary display name stored for future translations; unused at launch.

## 10. Levels and bulk operations

LV-01 — Department-defined ladder (name, code, order, external flag); seed PGY-1…PGY-4 + External; names cosmetic and editable. LV-02 — People screens support multi-select bulk actions: set level, set status, resend invitations, deactivate, export. LV-03 — One-action **annual promotion** advances a cohort one level with full preview, single-transaction commit, audit entry; graduates become alumni/inactive, never deleted. LV-04 — Level changes are effective-dated; history renders with the level held at the time.

## 11. People and accounts

PE-01 — Person: display/short name (app-wide handle), full legal name, email, phone?, level (effective-dated), status, joined date, notes, structured constraints. PE-02 — Contact visibility per policy toggles, logged-in members only. PE-03 — Ad-hoc external people supported, flagged everywhere. AC-01 — **Email invitation link** creates accounts: scheduler adds/imports entry → invitation → person claims profile and sets sign-in (email link; password optional). AC-02 — Invitations expire (default 14 days), resendable singly or in bulk; claim status visible. AC-03 — One account ↔ one person; unbinding on turnover is an admin action preserving history. AC-04 — Roles granted per person by Admin, enforced via server-side claims.

## 12. Master rota

MR-01 — The academic year divides by the chosen **period system**: calendar months, or week-measured blocks; block lengths may vary within a year; start dates department-set. MR-02 — Grid of people (rows, by level) × periods (columns), one unit per person per period; **split periods** supported via date-bounded sub-assignments. MR-03 — **Vacations** live on the master rota at week or exact-date granularity. MR-04 — The master rota **drives on-call eligibility automatically**: a person enters a period's call roster per their rotation, with configurable off-roster rotations and per-person manual include/exclude overrides. MR-05 — Publishable to residents independently of any call schedule (search, level filter, per-person period strip, per-period availability summaries). MR-06 — Fill-down/across, copy period, import/export. MR-07 — Per-period availability summary per level and unit, including who is on vacation each week.

## 13. Clinics

CL-01 — Clinic: owning unit, name, weekday, session AM/PM, optional location/note, active. CL-02 — Rotators on a unit attach to its clinics by default; per-clinic refinement by level or named people. CL-03 — Clinics feed conditions: **no post-call on a day with a clinic of the resident's current specialty** (default on); stricter same-day-overlap variant available (default off). CL-04 — Clinics appear on personal schedules, feeds, the on-now board, and morning coverage (clinic session subtracts availability). CL-05 — A weekly clinic map (unit × weekday × session) for viewers.

## 14. Slots, call windows, and coverage templates

SL-01 — Slot: name; kind (night_call, day_call, full_24h_call, weekly_duty, backup, shift[Stage 5]); time window (may cross midnight); cadence daily|weekly; days it runs with day-type overrides; covered unit (optional); counts-toward-hours flag; tally key. SL-02 — **Call structure is configuration**: single 24-hour call, or split day/night with department-set boundaries; post-duty semantics follow slot windows automatically. SL-03 — **Coverage template** per slot per day type (weekday/weekend/holiday): ordered level requirements with min and target and optional composition — e.g., NICU night = one senior (PGY-3/4) + one junior (PGY-1/2); Ward night = three across PGY-1–3, at least one PGY-2+. This implements per-level, per-unit nightly counts. SL-04 — Weekly-cadence slots (home call: senior + junior per week; backup) share the model. SL-05 — Slot/template edits affect future drafts, never published history.

## 15. The conditions engine and the gate

One engine definition powers manual hints, the solver, and compliance reports.

CG-01 — The gate screen lists all conditions: type, plain-language parameters, scope, class (Hard | soft rank), on/off, source. CG-02 — Soft conditions **rank-ordered by drag**; hard conditions sit above. CG-03 — Changes audited; effective on drafts immediately; never retroactive on published schedules. CG-04 — Plain-language preview text auto-generated from parameters. CG-05 — **Hard**: solver never violates; highest hint severity; blocks publishing (default on; a department may relax to warn-only) — never blocks editing/saving. CG-06 — **Soft**: solver minimizes rank-weighted violations; hints grade by rank; committed violations sit in the tracker until resolved or **ignored** — the ignored-warnings ledger records who ignored what, per person per period. CG-07 — Shipped catalog (all parameters department-editable):

| Type key | Meaning | Key parameters |
|---|---|---|
| min_gap | Minimum spacing between duties | duty kinds; days or hours; value |
| max_gap | Maximum spacing (regular exposure) | duty kinds; days |
| count_max / count_min | Duty caps/floors per window | kinds; levels; count; window (period/week) |
| target_per_period | Per-level targets with modifiers | level→target; modifiers (e.g., ≥2 vacation weeks) |
| composition | Weekday/weekend mix per person | level→{WD,WE} |
| we_pairing | Weekend pairing convention | preferred pairs; fallbacks |
| fairness_distribution | Even spread across colleagues | quantity (weekends/nights/holidays); tolerance |
| vacation_block | Never during vacation | — (Hard default) |
| unwanted_day_block | Avoid registered unwanted days | — (top soft default) |
| clinic_conflict | Clinic vs post-call (and optionally same-day) | variant |
| eligibility | Allowed levels/rotations per slot; auto-fill order | slot→levels/rotations |
| same_unit_conflict | Pairs never together | unit pairs; day exceptions |
| dow_restriction | Day-of-week bans | rotation or person; days |
| post_duty_exclusion | After kind A, blocked from kinds B for H hours | from; to; hours (generalized post-call) |
| overlap_block | One duty per overlapping window | — (Hard, built-in) |
| consecutive_max | Max consecutive duty days/nights | kinds; count |
| rolling_hours_max | Max hours per rolling window | hours; window; averaging weeks |
| free_day_min | One fully free day in N | N; averaged weeks |
| call_frequency_max | In-house call ≤ one night in N | N; averaging window |
| onboarding_grace | No duties in first N days | levels; days |
| holiday_equity | Spread named holidays across people & years | holidays; lookback years |
| forbidden_transition | Shift A never followed by shift B | from/to kinds (Stage 5) |

**Footnote on the CG-07 table above (added 2026-08-20, P2 Task 1 — arithmetic, not a change of
scope).** The table is **22 data rows** (lines 89–110) carrying **23 distinct type keys**:
`count_max / count_min` is one row with two keys, and every other row carries one. Exactly one row —
`forbidden_transition`, line 110 — is marked `(Stage 5)` **inside its own parameters cell**; it is
also named verbatim in §35's Stage 5 deliverable list (line 276, *"forbidden transitions"*) and is
covered by §36's non-goal *"Shift features before Stage 5."* (line 280). So **22 rows − 1 = 21 rows**
— which is exactly D13's number in
`docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md:36` (*"All 21 types in
P2"*) — and **23 keys − 1 = 22 implemented type keys.**

**D13's "21" is therefore self-consistent, and it means the whole shipped catalog except the Stage-5
row.** It is not a count that matches nothing in the catalog; an earlier P2 planning draft asserted
that it was, and that assertion is wrong and is withdrawn here. P2 implements those 22 keys and
registers `forbidden_transition` with `implemented: false` carrying the three citations above, so its
absence reads as a decision rather than an omission — the same device `UnitMerge::REFERENCES` uses
for a table a merge deliberately leaves alone. **An entry is a decision, not documentation.**

*(Line numbers corrected 2026-08-20, P2 Task 8: this footnote originally cited lines 252 and 256 for
§35 and §36, which were their positions BEFORE the footnote itself was inserted — its own two dozen
lines had already pushed them down. `packages/engine/test/registry.test.ts` therefore asserts the
citation TEXT rather than the line numbers, and `catalog-parity.test.ts` locates the table below by
its header row for the same reason. A citation that no longer resolves reads as evidence and is
not.)*

A competing arithmetic reaches 21 by a different route — 23 keys minus `forbidden_transition` minus
`overlap_block`, reading *"Hard, built-in"* as meaning `overlap_block` is not department-configurable
— and it is rejected on the record: `overlap_block` is a row in a table this section calls the
shipped catalog, it carries a type key and a stated class, and the engine implements it either way.
**Both readings produce the same build set**, so the disputed number is a label and the enumeration
is the contract.

CG-08 — Presets: residency defaults seeded from the prototype's proven values; **Duty-hours (ACGME-style)** bundle — 80 h/week averaged over 4 weeks, call ≤ 1-in-3 averaged, 1-in-7 free averaged, 10 h between duties, 24 h continuous cap; an empty **SCFHS/local** preset slot to be encoded when the official numeric policy arrives. CG-09 — **Condition builder** (Stage 4): compose custom conditions from WHO (levels/rotations/units/people) × WHEN (day types/date ranges/relative to assignments) × WHAT (cannot assign; min gap; count ≤/≥ in window); auto preview text; rankable; same engine. No free-form scripting. CG-10 — Engine contract is stable: pure function (schedule, config, conditions) → violations [{conditionId, severity, rank?, location, explanation}]; new types are additive.

## 16. Draft workbench (manual scheduling with hints)

WB-01 — Each period's schedule is created ahead of time by a Scheduler; skeleton generated from period definition: Gregorian+Hijri labels, day types, eligible roster from the master rota, empty slots from templates; concurrent schedulers supported (§29). WB-02 — Views: **Day** (defaults to today or first unfilled), **Full grid** (drag-and-drop desktop; tap-to-arm-move mobile), **Unfilled lens**; weekly-slot panel for home call/backup. WB-03 — **Live hints**: any prospective add/move shows every condition it would break, graded by rank, before commit; committed violations badge the cell and the tracker. WB-04 — **Candidate assistance**: pickers exclude hard-ineligible people, then order by fitness (rank-weighted violations, then load vs target, then gap quality) with reason chips ("2 below target", "post-call", "clinic Tue AM", "unwanted day"); per-person suggestion mode proposes best remaining dates. WB-05 — Trackers: per-person (current vs target, WD/WE mix, status: needs review / incomplete / ignored / complete) and per-day availability. WB-06 — Verbs: add, remove, move, swap-in-place, clear day/person; **undo/redo ≥ 30**; move-history strip with warning flags. WB-07 — Ignoring a warning targets the specific warning, records actor+time. WB-08 — Draft coverage preview renders §19 from the draft on demand.

## 17. Auto-scheduler

AU-01 — Server-side solver job over the draft: inputs = skeleton, templates, conditions, approved constraints, clinics, and any pre-placed assignments (fixed — manual and automatic compose). AU-02 — **OR-Tools CP-SAT**; booleans x[person,date,slot]; templates + hard conditions as hard constraints; each soft condition adds penalty terms weighted monotonically by rank; minimize total penalty; configurable time limit (default 30 s); deterministic via stored seed. **JSON contract** (stable): request {periodSkeleton, roster, slots, templates, conditions, constraints, fixedAssignments, seed, timeLimitSec} → response {assignments, softViolations:[{conditionId, rank, count, locations}], explanations, infeasible?, conflictSet?}. AU-03 — Result report ordered by rank — "what was sacrificed and why"; per-cell explanation retrievable. AU-04 — Partial modes: selected slots, one person to target, weekends only, unfilled remainder. AU-05 — Fallback: client-side greedy (most-constrained-day first, farthest-point spacing, least-loaded), labeled heuristic. AU-06 — Binding acceptance: regenerate a past pilot month from archived real inputs — 100% hard satisfaction, all coverage minima, chief-acceptable with minor edits; automated where objective; rerun on engine changes. AU-07 — **Infeasibility**: when hard constraints + minima cannot all hold, return a scheduler-readable conflict report (the tightest constraints and where), never a silent under-fill. AU-08 — Generation writes only to drafts; every run audited with inputs digest and seed.

## 18. Publishing, versions, and change management

PU-01 — Publish archives the outgoing schedule first (mandatory), promotes the draft, updates every viewer live; the draft persists for edits/republish. PU-02 — Published schedules are **versioned**: each post-publish edit (correction, swap execution, sick replacement) creates a change entry (who/what/when/why-note) and notifies affected people (§25); a version browser views and restores prior states. PU-03 — Publish dialog summarizes outstanding warnings and ignored items; hard-violation blocking per CG-05.

## 19. Morning unit coverage

MC-01 — Per date, derive who is physically present per unit: master-rota assignment − post-duty exclusions − vacations − clinic sessions that session − approved leave − manual overrides; **manual overrides always win**, visibly marked. MC-02 — Per-unit and per-level chips graded green/amber/red (defaults 75%/50%, configurable); status glyphs (post-call, vacation, clinic, unavailable, manual); the **pull pool** of non-ward rotators recommended to cover short teams. MC-03 — Scheduler per-person per-day overrides with bulk clear; shared across live and draft views. MC-04 — Week strip trend.

## 20. Who's-on-call now

WO-01 — A board answering "who is on call right now": current slots and people, active clinics, who just handed over, who starts next; tap-to-call/message for authorized viewers per contact policy. WO-02 — Excellent on a wall display and a phone; link-public or login-only per department policy.

## 21. Personal schedules and calendar feeds

PS-01 — My Schedule: upcoming calls, clinics, rotation, vacations, requests, tallies vs targets. PS-02 — Tokenized **ICS feed** per person (calls, clinics, rotations); revocable; contains no other people's contact data.

## 22. Requests and approvals

RQ-01 — Types: unwanted day(s), leave/vacation, sick notice, swap (§23); pending → approved/declined with actor, time, note; approved requests immediately become engine constraints. RQ-02 — Per-period **request deadline** before drafting; automatic reminders at configurable offsets; late requests accepted but flagged. RQ-03 — Approval queue shows **coverage impact** before decision (e.g., "approving drops PICU below minimum on the 14th–15th"). RQ-04 — Residents see full request history; nothing disappears silently.

## 23. Swaps

SW-01 — A resident may propose exchanging a duty only with a colleague **of the same level** (duty ↔ duty, or a one-way hand-over). Same-level eligibility is the shipped rule, expressed as configuration so a department could later widen it without code changes. SW-02 — **Mandatory two-step approval**: counterpart accepts/declines; on acceptance, the chief resident / program director (Scheduler) approves/declines. Nothing changes until both approve; either decline ends it. The engine revalidates both placements at each step, showing violations to the counterpart before accepting and to the approver before approving. SW-03 — On approval, execution edits the published schedule as a versioned change naming the swap; **every lifecycle step emails the involved parties** (§25). SW-04 — Offer-to-many: a duty offered to multiple same-level eligible colleagues; first acceptance proceeds to scheduler approval.

## 24. Backup and sick replacement

BK-01 — Optional backup (jeopardy) slot per night, filled and conditioned like any slot. BK-02 — **Sick-replacement flow**: marking a person sick for a duty opens a candidate list ranked by the engine (violations, load, gap); one tap notifies the chosen or offers to several; the change publishes as a versioned edit with notifications. Phone-first; target under three minutes end to end.

## 25. Notifications

NT-01 — **Email is the required channel** for workflow actions, mirrored by an in-app notification center; email infrastructure ships in Stage 1 (invitations); each event activates with its feature's stage; future channels (e.g., WhatsApp via §28 webhooks) add without changing the event model. NT-02 — Required matrix:

| Event | Emailed parties |
|---|---|
| Invitation sent · account claimed | The person; admins claim digest |
| Request submitted (unwanted/leave/sick) | Schedulers |
| Request approved/declined | The requesting resident |
| Request deadline approaching | Residents not yet submitted (configurable) |
| Swap proposed | Counterpart resident |
| Swap accepted/declined by counterpart | Proposer; on acceptance also schedulers |
| Swap approved/declined by chief/PD | Both residents |
| Swap executed | Both residents, with the change entry |
| Sick replacement assigned | Replacement, sick resident, schedulers |
| Published-schedule change | Everyone whose assignments changed |
| New period published | All members (digest; configurable) |

NT-03 — Transactional emails under the department's display name, deep-linking to the exact item, never containing others' contact details. NT-04 — Queued with retries; failures surface in an admin view; every notification audited. NT-05 — Preferences may mute digests, never approval-chain emails where the person is a required actor. NT-06 — **Dev transport**: without provider credentials, queued mail renders in a development-only Outbox screen and emulator logs so every flow is testable end to end; production uses the configured provider (Trigger-Email extension or SMTP/SendGrid).

## 26. Tallies, equity, and compliance

TL-01 — Per-person counters per period and YTD: duties by kind, WD/WE split, nights, holidays, day calls; schedulers see all; residents see own plus anonymized distributions (policy). TL-02 — Equity dashboard (Stage 4): distributions with outlier flags; **holiday equity** across years for named holidays (Eid al-Fitr, Eid al-Adha, National Day, custom) — surfaced as the holiday_equity condition and as reports. TL-03 — Duty-hour compliance reports per person per month over the published schedule; ACGME-style preset ships; SCFHS/local encoded when received; exportable packet. TL-04 — Vacation and leave reports per year.

## 27. Exports

EX-01 — Print-optimized month grid (A4/A3, both orientations) with branding; PDF; **standalone self-contained HTML** of the published schedule for chat-app sharing; xlsx of any grid; generated Word/PDF documents from the platform's own layout. No legacy-template string surgery (a template filler may follow demand later). EX-02 — Contact-bearing exports are login-gated.

## 28. Reference rotas, search, audit, integration

RR-01 — Read-mostly grids for other teams' coverage (e.g., subspecialty consultants): per-day columns with three-state cells — assigned / explicit "No service" / "no data" (absence of data must not display as absence of coverage); inline admin editing; block notes. SE-01 — Global search over people, units, clinics, dates. AD-01 — Every write records actor, server time, entity, before/after summary; an audit browser filters by person/entity/range; retention §31. IN-01 — Tokenized read-only JSON feed (today's on-call; date-range schedule; clinic map) and ICS feeds; outbound webhooks on publish and on published change — the contract for the existing WhatsApp bot and future hospital-wide aggregation. No inbound write API in v1.

## 29. Security, privacy, and concurrency

SC-01 — Server-side authorization only (claims + per-collection rules); client flags cosmetic. SC-02 — No personal data in unauthenticated page source, storage, or reads. SC-03 — Transactions or per-key documents for shared mutable data — no whole-doc last-write-wins; concurrent editors see each other live; per-assignment writes make conflicts near-impossible; audit resolves disputes. SC-04 — Invitation and feed tokens single-purpose and revocable; never write-granting. SC-05 — Automated daily database export to versioned storage (90-day retention + yearly archives); restore is a tested runbook. SC-06 — Hosting region selected at provisioning (prefer GCC if supported); recorded per instance.

## 30. Architecture and data model

AR-01 — Per-department isolated instances from one codebase; each instance its own Firebase project (Auth email-link, Firestore, Hosting, Functions); region per SC-06. AR-02 — TypeScript, React 18 + Vite production builds; every mutation writes to Firestore; listeners are the only view updaters. AR-03 — One pure TS conditions engine (client hints, function validation/reports) with the solver's constraint mapping cross-validated by a shared **golden fixture suite** in CI. AR-04 — Solver: Python CP-SAT service (2nd-gen Function or Cloud Run); stateless JSON per §17. AR-05 — Data model (semantics binding; names adjustable):

```ts
config/profile          { name, logo, colors, calendar:{ periodType:'months'|'weekBlocks',
                          periods:[{id,label,start,end}], weekendDays:[..], hijri:boolean,
                          hijriOffsetDays:number, timezone:'Asia/Riyadh', academicYearStart },
                          policies:{ viewerPublic, contactVisibility, hardBlocksPublish:true,
                          requestDeadlineDays, clinicConflictVariant:'postcall' }, version }
units/{unitId}          { name, name2?, color, order, roles:{rotation,callTarget,clinicOwner},
                          aliases:[..], offRoster:boolean, active }
levels/{levelId}        { name, code, order, external:boolean }
people/{personId}       { shortName, fullName, email, phone?, levelId,
                          levelHistory:[{levelId,from}], status, joined, notes?,
                          constraints:[..], accountUid?, active }
invites/{inviteId}      { personId, email, sentAt, expiresAt, claimedAt? }
masterRota/{periodId}   { assignments:{ [personId]:{unitId, spans?:[{from,to,unitId}]} } }
vacations/{vacId}       { personId, from, to, granularity:'week'|'date', source }
clinics/{clinicId}      { unitId, name, weekday, session:'AM'|'PM', location?, attendees?, active }
slots/{slotId}          { name, kind, window:{start,end}, cadence, days:[..], unitId?,
                          countsHours:boolean, tallyKey, active }
templates/{slotId}      { byDayType:{ WD|WE|HOL:[{levels:[..], min, target}] } }
conditions/{condId}     { typeKey, params, scope, class:'hard'|'soft', rank?, active, source, note? }
schedules/{periodId}    { status:'draft'|'published'|'archived', publishedAt?, version,
                          dayIndex:{ [date]:{ [slotId]:[personId..] } } }        // render index
assignments/{aId}       { periodId, date, slotId, personId, createdBy, createdAt } // source of truth
ignored/{periodId}      { [personId]:[warningKey..] }
overrides/{periodId}    { [date_personId]:'available'|'postduty'|'unavailable', by, at }
requests/{reqId}        { type:'unwanted'|'leave'|'sick'|'swap', personId, payload, status,
                          decidedBy?, decidedAt?, note?, createdAt }
                        // swap payload: { withPersonId | offerTo:[..], mine:{date,slotId},
                        //                theirs?:{date,slotId}, counterpartAt?, approvedAt? }
holidays/{holId}        { name, rule:{greg?|hijri?}, equityTracked:boolean }
changes/{periodId}/{n}  { at, by, kind, diff, note?, notified:[personId..] }
notifications/{uid}/{n} { at, event, refs, readAt? }
mailQueue/{id}          { to, template, data, state:'queued'|'sent'|'failed', attempts, lastError? }
audit/{eventId}         { at, uid, action, entity, ref, summary }
feeds/{token}           { personId|'department', scope, createdAt, revokedAt? }
archives/{periodId}     { snapshot at publish-over }
```

AR-06 — Integration surface per IN-01. AR-07 — **English-only launch**; binding practices: externalized strings (no hard-coded UI text), i18n library for dates/numbers/plurals, logical CSS properties (start/end) so a future RTL locale is translation work, not a rewrite. AR-08 — All date logic flows through one internal calendar module applying timezone + hijriOffsetDays.

## 31. Non-functional requirements

NF-01 — Hint evaluation for a full month grid: < 100 ms p95 (laptop), < 250 ms (mid phone). NF-02 — Auto-generation within its limit (default 30 s) for 60 people × 31 days × 8 slots. NF-03 — First meaningful paint of the published schedule < 2.5 s p75 on 4G; production builds and code-splitting mandatory. NF-04 — Viewing availability 99.5% monthly. NF-05 — Evergreen browsers (last 2) + iOS Safari 16+; functional from 360 px; touch targets ≥ 44 px. NF-06 — WCAG 2.1 AA; severity never by color alone. NF-07 — Audit retention ≥ 3 years; backups per SC-05. NF-08 — Engine fully test-covered; golden fixtures gate every release. NF-09 — Server timestamps for all provenance.

## 32. UX specification

UX-01 — Navigation: Schedule (day view defaulting to today ⇄ full month), Coverage, Clinics, People, Master Rota, Requests, Reports; +Draft and Approvals for Schedulers; +Settings for Admins. Mobile: bottom nav (top four destinations per role), drawers/bottom sheets for pickers. UX-02 — Severity ladder: S3 hard (red), S2 top soft (orange), S1 mid (amber), S0 info (neutral); icon+text always accompany color; consistent across hints, badges, trackers, publish dialog, reports. UX-03 — Interactions: tap-badge → side panel + armed move with highlighted drop targets; drag-and-drop desktop; searchable level-grouped pickers with fitness order and reason chips; optimistic UI with rollback; undo/redo; skeleton loaders; teaching empty states. UX-04 — Dual calendar everywhere Hijri is enabled; weekend/holiday day-types visually distinct; headers carry both ranges. UX-05 — Hints never block on network (engine runs client-side on listener-fresh data). UX-06 — Plain clinical language; every automated outcome explains itself in one sentence with a "why" affordance. UX-07 — A clickable prototype of workbench + hints + picker flows is a Stage-2 gate before full build-out.

## 33. Fleet operations and migration

FL-01 — One repository; semantic releases; CI builds once, deploys to every instance; per-instance version pinning allowed. FL-02 — Provisioning is a script: create project, enable auth, deploy rules/indexes/functions/hosting, write seed profile, create first admin via custom-claims script, register in the fleet inventory. FL-03 — **No per-instance code changes, ever.** FL-04 — Environments: local emulators with import/export persistence; a staging instance with demo data; production instances. FL-05 — Prototype migration (pilot): residents → people (+level history); master rota → masterRota; unwanted-days registry → approved requests; vacations → vacations; live + archived blocks → schedules/assignments/archives; manual availability → overrides; consultant grids → reference rotas; runnable dry-run-first script with a validation report; acceptance: the migrated pilot renders its current month identically to the prototype.

## 34. Testing and quality

QA-01 — Engine: exhaustive unit tests per condition type + golden fixtures (seed-department months until real pilot archives arrive, then both) asserting known warnings reproduce. QA-02 — Solver: property tests (hard never violated; minima always met when feasible; infeasibility reported per AU-07) + the AU-06 regeneration test. QA-03 — E2E journeys automated against emulators: invitation → claim → request → approve → draft → auto-fill → manual fix → publish → swap → sick replacement. QA-04 — Load: 200 concurrent viewers; 3 concurrent draft editors. QA-05 — Security review (threat model + rules audit + PII scan of built output + token handling) before any real names enter the system.

## 35. Stages and acceptance

**Stage 1 — Structure & master rota** (setup, units, levels+bulk+promotion, people/invitations/roles, master rota with both period systems + splits + vacations + import/export + publish view, clinics, holidays). *Accepted:* the pilot's real master rota and clinics live; residents claimed accounts; availability summaries match reality.
**Stage 2 — On-call & morning coverage** (slots incl. 24-h calls, templates, the gate with full catalog and drag ranking, workbench with hints/pickers/trackers/undo/unfilled lens, publish+archive+hard-block, morning coverage with overrides and pull pool, who's-on-call board, personal pages, basic tallies, exports, UX-07 prototype gate, QA-05). *Accepted:* one full real month scheduled manually at prototype parity; a week-long coverage reality audit matches.
**Stage 3 — Automation & self-service** (CP-SAT with report/explanations/partials/fallback/infeasibility; requests with deadlines, reminders, queue, impact preview; change notifications + versioned log; ICS feeds). *Accepted:* AU-06 passes; a real month auto-generated and chief-accepted with minor edits; ≥ 60% of requests via self-service.
**Stage 4 — Fairness, resilience, compliance** (equity + holiday equity; duty-hour reports with ACGME preset and encoded local policy; backup + sick replacement; swaps incl. offer-to-many; audit/version browser; feed + webhooks; condition builder). *Accepted:* live sick-replacement drill < 3 min on a phone; compliance report reproduces hand-computed results; a second department onboards with zero code changes within one working day.
**Stage 5 — Shift mode** (shift slots, hour accounting, coverage-first solver profile, forbidden transitions, progressive patterns, self-scheduling windows, open-shift bidding, shift swaps; pilot one ER or nursing unit for a full cycle including swaps). Starts only on explicit go-ahead.

## 36. Not doing (and why)

Free-form rule scripting (the catalog + builder stays testable and rankable). Payroll/HR/credentialing/EHR (enterprise ground; reports and exports feed those systems). Patient appointment booking. Rebuilding the WhatsApp bot (webhooks/feeds are the contract). Merging departments into one database (aggregate read-only feeds instead). Legacy Word-template filling early (most fragile code category; generated documents deliver the value). Shift features before Stage 5. Native mobile apps in v1 (responsive web + ICS + email covers it; revisit post-Stage-4 with usage data).

## 37. Remaining human inputs

Firebase project + region + deploy approvals; email provider credentials; the SCFHS/local duty-hour policy in numeric form; the pilot data export; the final product name; the subscription price (business item, not a build blocker).

## 38. Key assumptions to validate

- [ ] The condition catalog covers departments beyond pediatrics — walk one internal-medicine and one surgical rule set through it on paper before Stage 2 build-out.
- [ ] The SCFHS/local duty-hour policy exists in numeric form and maps onto the catalog — request now; map condition by condition.
- [ ] CP-SAT produces chief-acceptable schedules on real data — run AU-06 early in Stage 3, not at its end.
- [ ] The TS engine and the solver mapping stay semantically identical — golden fixtures in CI in the first weeks of Stage 2.
- [ ] Residents claim accounts and self-submit — measure in the pilot; if adoption lags, prioritize the WhatsApp path.
- [ ] Prototype data migrates cleanly — dry-run against a production copy during Stage 2.
- [ ] Provisioning is fully scriptable end to end — prove with a throwaway instance in week one.
- [ ] A second department can truly self-configure — schedule the zero-code onboarding drill as the Stage-4 gate.

## 39. Success criteria

Zero-code department onboarding to a first published rota within one working day. Pilot: a full manual month at prototype parity (Stage 2) and an accepted auto-generated month (Stage 3). Sick replacement on a phone in under three minutes (Stage 4). No personal data reachable unauthenticated, verified by inspection. Every instance updated from one pipeline, versions visible. Golden fixtures green on every release. The chief describes month-end scheduling as an hour of work rather than a week of dread.

## Appendix A — References

Amion https://www.amion.com · MedRez https://www.medrez.net · QGenda https://www.qgenda.com · PerfectServe Lightning Bolt https://www.lightning-bolt.com and https://www.perfectserve.com/physician-scheduling-software/ · symplr Smart Square https://www.symplr.com/products/smart-square · Shiftboard https://www.shiftboard.com · Snap Schedule https://www.bmscentral.com · CodeCanyon https://codecanyon.net (e.g., https://codecanyon.net/item/saturn-hospital-management-system/26479442) · OR-Tools hospital scheduling example https://github.com/TaroAndMulan/HospitalDoctorScheduling-OR-tools- · ACGME-style duty-hour reference values as published by US programs: 80 h/week averaged over 4 weeks; in-house call ≤ every third night averaged; one day in seven free averaged; 10 h between duty periods; 24 h continuous maximum with limited transition time; in-house time during home call counts. Optional UI accelerators (likely unnecessary given installed design tooling): Envato Elements admin templates — Konrix https://elements.envato.com/konrix-react-tailwind-css-admin-dashboard-XFJXYXM · Tailwick https://elements.envato.com/tailwick-15-in-1-tailwind-css-admin-dashboard-8UZCM3G · Darkone https://elements.envato.com/react-dashboard-and-ui-kit-template-darkone-3A4399E.

## Appendix B — Stakeholder-requirement traceability

Morning unit coverage + 24-hour on-call first → §14 SL-02, §19, Stage 2. Departments create units/specialties assignable across functions → §9. Master rota by months or week-blocks, assigning specialties and holding vacations → §12. Periods created ahead; chief/PD arranges the month → §16 WB-01. Automatic arrangement under modifiable, importance-ranked conditions (spacing, monthly caps, weekday/weekend distribution, vacations, unwanted days, clinic–post-call) → §15, §17. Manual mode with hints from the same conditions → §16 WB-03/04. Extreme future extensibility → CG-09/10. Best possible UI/UX → §32, NF-01/03. Clinics per specialty, weekday, AM/PM → §13. Levels PGY-1–4, modifiable, bulk-editable → §10. Email-invitation accounts → §11 AC-01. Per-level, per-unit nightly counts → §14 SL-03. English-only with future translations → AR-07. Same-level swaps with counterpart + chief/PD approval → §23. Email to involved parties on each such action → §25. Shifts last → Stage 5/§35. Competitive review with URLs incl. CodeCanyon/Envato → §6, Appendix A.

---

*End of Specification v1.0. Claude Code: return to Part A §A0 and begin.*
