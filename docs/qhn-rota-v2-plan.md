# QHN Pediatrics Duty Rota — v2 Project Documentation & Plan of Action

Status: PROPOSAL · Author: prepared for the rebuild of `hassanalsaif/qhn-block11-schedule`
Date: 2026-08-07

---

## 1. Introduction

The QHN Pediatrics Duty Rota is the tool the chief resident of the QHN pediatrics
department uses to build, edit, publish, and distribute the monthly on-call schedule
("block") for ~50 residents across four night-call posts (Senior Overall, NICU, PICU,
PMW) and two daytime-coverage posts (Ward day call, NICU day call). Residents and staff
view the live schedule in the browser; the chief exports a filled Word document (the
department's official format) and a self-contained HTML page for distribution.

Version 1 (the current site, served from GitHub Pages at
`hassanalsaif.github.io/qhn-block11-schedule/`) works and is in daily production use,
but it has reached the limits of its architecture: a single 6,337-line HTML file with
in-browser Babel transpilation, three hand-synchronised copies of the source, no tests,
no build pipeline, whole-document database writes, and a data model that still carries
its "Block 11 only" origins in its bones.

This document is the complete blueprint for **v2**: a rebuilt, properly engineered
version of the same product. It contains:

- a full description of what v1 does and how (Section 2) — the behavioural spec;
- an authoritative catalogue of every scheduling rule the app enforces (Section 5) —
  the domain spec that must survive the rewrite intact;
- the proposed v2 architecture, data model, and security model (Section 6);
- a phase-by-phase plan of action with tasks, deliverables, and acceptance criteria
  (Section 9), plus migration/cutover (Section 10) and risks (Section 11).

**Guiding principle**: v1's *behaviour* is the specification. The residents and the
chief already trust how it works. v2 changes the engineering, not the domain logic —
except where a change is explicitly listed as an improvement in Section 3.

---

## 2. Background: the existing system (v1)

### 2.1 What it does — feature inventory

Seven tabs (the last is admin-only):

| Tab | Purpose |
|---|---|
| **Schedule** (`Block N`) | The live rota. Single-day view (defaults to today) or full 28-day grid. Admins move residents (click badge → click target cell), add via searchable per-cell picker, delete, undo (in-memory, 30 steps), see a move log. Every edit runs the constraint checker and shows advisory warnings. Colour-coded badges by rotation; filter chips (NICU/PICU/GP team, R1–R4); a per-resident side panel with all their calls, gap indicators, post-call flags, and day-call suggestions; an availability panel showing next-day unit coverage; DOCX + HTML export buttons. |
| **Daytime Coverage** | For each day, splits every team (NICU / PICU / GP-PMW / OPD / Subspecialty pull-pool) into available / post-call / vacation / unavailable, grouped by level. Auto-computed from the schedule + vacation calendar; the chief can manually override any resident's status per day (override always wins and is visually flagged). Can also read the next block's draft to spot shortages in advance. |
| **Subspecialty Consultants** | Editable per-day grids for 10 consultant specialties (Pulmonology, Neurology, Hematology, Nephrology, GI, ID, Genetics, Admitting, PICU-ICU, NICU-ICU), each with its own column schema and free-text notes. Cell states: assigned(text) / explicitly-none / no-data. |
| **Residents** | The resident database: ~53 people with level, rotation, type, phone, email, vacation weeks, unwanted days, master rota, constraints, notes. Inline admin editing writes to Firestore per resident. |
| **Master Rota** | Read-only browser of the 13-block year-long rotation for all long-term residents, with per-block week date ranges and colour-coded rotation cells. |
| **Unwanted Days** | Per-block, per-resident "please not these dates" requests; admin-editable; feeds the warning engine for both the live block and drafts. |
| **New Block** (admin) | The drafting workshop: pick block number → dates auto-seed from the year calendar; generate a 28-day skeleton (with computed Gregorian + Hijri dates); roster auto-derived from the Master Rota (excluding V/ELECTIVE/ER/PHC/OUTSIDE rotations); edit via day view or full grid with **drag-and-drop**; per-resident auto-fill and a whole-block **ward day-call allocator**; draft-specific warning engine; consultant rota entry; home-call entry; external-rotator entry; export draft as DOCX/HTML; **Publish** — archives the outgoing live block to `schedules/archive_block{N}` then promotes the draft into the live slot. |

Cross-cutting: Firebase email/password login for admins (custom claim `admin: true`);
everyone else is a read-only viewer with no login; all data updates live on every open
browser via Firestore listeners; mobile layout (day cards, collapsible panels).

### 2.2 Architecture (v1)

- **One HTML file** (`app/index.html`, 6,337 lines): React 18 UMD **development**
  builds + Babel Standalone from CDN, JSZip vendored. All JSX transpiled in the
  browser on every page load. All styling is inline style objects.
- **Three copies of the source**: `block11_schedule.jsx` (stale), `app/index.html`
  (edited), `deploy/index.html` (manually copied, actually served). The GitHub Pages
  workflow only triggers on `deploy/**` paths — an edit committed only to `app/`
  silently never deploys.
- **Firebase** project `qhn-block11`: Firestore (data) + Auth (admin gate). Server-side
  enforcement in `firestore.rules`: public read, `admin==true` custom-claim write, on
  five collections. Admin claim granted by a local Node script with a service-account
  key.
- **Local dev**: Python static server + Firebase emulators (auto-detected on
  localhost), with an import/export wrapper script so emulator state persists.
- **State pattern**: React state is hydrated by `onSnapshot` listeners; six
  module-level mutable globals (`rotationMap`, `levelMap`, `vacationWeeks`,
  `unwantedDays`, `residentProfiles`, `MASTER_ROTA`) are reassigned by the residents
  listener so that non-React helper functions can read them synchronously. Embedded
  `DEFAULT_*` constants serve as fallback when Firestore is empty.
- **Writes are whole-document**: every schedule edit rewrites the entire 28-day
  array; every consultant-cell edit rewrites the entire values map. Last writer wins.

### 2.3 Data model (v1 Firestore)

| Doc / collection | Contents |
|---|---|
| `schedules/block11` | The **live slot** (not literally Block 11): `{days:[28 rows], blockNumber, startDate, actingChief, consultants, homeCall, externals, updatedAt, updatedBy}` |
| `schedules/draftNextBlock` | The single working draft, same shape + `roster` |
| `schedules/archive_block{N}` | Snapshot of each block at the moment it was replaced |
| `manualUnavail/block11` | `{overrides: {"date\|name": status}}` daytime-coverage overrides |
| `consultantSchedules/block11` | `{values: {"specKey\|date\|colKey": {status,text}}}` |
| `residents/{shortName}` | One doc per resident; doc ID **is** the display short name |
| `unwantedDaysByBlock/{n}` | `{days: {shortName: [dateLabels]}}` per block |

Day row shape: `{date:"Jul 5", greg:"05/07", hijri:"20/01", day:"Sun", type:"WD"|"WE",
SO:[], NICU:[], PICU:[], PMW:[], DC_NICU:[], DC_PMW:[]}` — names stored as short-name
strings, dates as display labels.

### 2.4 What v1 gets right (must be preserved)

1. **Live sync, no publish step.** Firestore listeners mean every open browser shows
   the current schedule the instant the chief saves an edit. This replaced a
   static-publish mechanism that caused chronic "my edit isn't showing" bugs.
2. **Server-side write protection.** The admin gate is enforced in Firestore rules,
   not just hidden UI.
3. **Advisory (not blocking) warnings.** The chief can always override a rule; the app
   informs, it doesn't refuse. This matches how the rota is actually negotiated.
4. **The constraint engine itself** — the accumulated rule set (Section 5) encodes
   months of departmental rulings and is the most valuable asset in the codebase.
5. **The day-call allocator design**: allocation is computed from the pool + vacation
   calendar (never from current draft contents), assigns most-constrained-day-first,
   and treats already-placed days as fixed — so per-resident auto-fill composes in any
   order.
6. **Zero-ops, zero-cost hosting** (static page + Firebase free tier).
7. **Publish-with-archive**: the outgoing block is snapshotted before being replaced;
   the live doc is a *slot* whose block number the whole UI follows.
8. **Local dev cannot touch production** (emulators are a different process, not a
   flag).

### 2.5 Limitations and technical debt (what v2 fixes)

1. **No build step / single file.** 6,337 lines of mixed data, domain logic, and UI in
   one file; in-browser Babel; React *development* builds in production (large payload,
   slow first paint, console warnings).
2. **Three-way manual source sync** and the silent-non-deploy trap.
3. **No tests of any kind.** Every change to the constraint engine or the DOCX XML
   surgery is verified by hand.
4. **Whole-document writes → lost updates.** Two admins editing simultaneously (or one
   admin on two devices) silently clobber each other. No transactions, no versioning.
5. **In-memory undo only**; no persistent audit trail beyond a single `updatedBy` on
   the last write. A disputed change cannot be reconstructed.
6. **Stringly-typed domain.** Dates are display labels ("Jul 5") used as keys; slot
   membership is name-string arrays; resident doc IDs are display names (a rename
   breaks every reference); composite keys are `"a|b|c"` strings.
7. **Mutable module globals** rebuilt by listeners — implicit, race-prone, and the
   reason helper functions can't be pure.
8. **Hardcoded per-year data**: `BLOCK_DATES`, `BLOCK_VACATIONS`, the Block 11
   constraint name-sets (`noSaturdayRes` etc.), and the seed constants all live in the
   source. Each new academic year requires code edits.
9. **Legacy naming**: the live doc is called `block11` forever; `manualUnavail` and
   `consultantSchedules` are also `/block11` even when the live block is 12+ — the
   coverage overrides and consultant data don't roll over cleanly between blocks.
10. **Residual one-time code**: a production seed constant + button
    (`SEED_icuAdmittingValues`) that the code's own comments say must be deleted.
11. **PII in the page source**: 47 residents' full names + personal phone numbers are
    embedded in the public HTML and in the public export. (Matches how the paper rota
    circulated, but v2 should make this a deliberate, access-controlled choice.)
12. **Hijri dates** via a tabular approximation with a hand-calibrated −1 day offset —
    works, but fragile and undocumented outside code comments.
13. **Accessibility/UX debt**: no keyboard interaction model, tiny touch targets,
    colour-only status signalling in places, inline styles everywhere.
14. **Single admin role** — no distinction between the chief (full control) and, say,
    a co-chief who may edit the draft but not publish.

---

## 3. Vision for v2

### 3.1 Goals

- G1 — **Same product, engineered properly**: identical or better UX for every v1
  feature, with a typed, tested, modular codebase.
- G2 — **One source of truth**: single `src/` tree, compiled build, automatic deploy.
  Deleting a line of code that's still needed should fail CI, not production.
- G3 — **Concurrent-safe editing**: two admins can work at once without losing writes;
  every change is attributable and reversible (persistent history/audit).
- G4 — **Block-generic from day one**: no "block11" anywhere in code or schema; a new
  block (or a new academic year) is data entry, not a code change.
- G5 — **The constraint engine as a pure, exhaustively unit-tested library** — the
  rules in Section 5 become executable specification.
- G6 — **Keep the operating cost at ~zero** and the ops burden at ~zero.
- G7 — **First-class mobile** (most residents check the rota on phones) and a real
  print/export story.

### 3.2 Non-goals (v2.0)

- No patient data, ever. This is a staff rota; nothing clinical enters the system.
- No shift-swap marketplace / resident self-service requests (backlog, Section 12).
- No automatic full night-call solver in v2.0 (the day-call allocator is ported;
  a whole-block night-call optimiser is a v2.1+ candidate — see Section 12).
- No native mobile apps; the web app (installable PWA) is the mobile story.
- No change to the official DOCX output format (the department requires it as-is).

### 3.3 Success criteria

- Feature parity checklist (Appendix A) fully green, verified against v1 side-by-side.
- 100% of Section 5 rules covered by unit tests; DOCX export byte-validated against
  reference outputs; Firestore rules covered by emulator tests.
- Two admins editing concurrently never lose an edit (demonstrated by an integration
  test and a manual two-browser drill).
- Lighthouse: performance ≥ 90 mobile on the schedule view; a11y ≥ 90.
- Cutover completed with zero data loss and a documented rollback path.

---

## 4. Requirements

### 4.1 Roles & permissions

| Role | Granted via | Can |
|---|---|---|
| **Viewer** (anyone, no login) | — | Read every tab except New Block; export nothing or export view-only HTML (decision D3, §6.9) |
| **Editor** | user doc `role: "editor"` | Everything an admin can except Publish block, manage users, and delete/reset |
| **Admin** (chief) | user doc `role: "admin"` | Everything: edit all data, publish blocks, manage editors, run imports |

(v1 has only admin/viewer; the editor tier is new but cheap once rules are data-driven.)

### 4.2 Functional requirements

**FR-SCH (Schedule tab)**
- FR-SCH-1: Render the live block as day view (default: today) and full 28-day grid.
- FR-SCH-2: Move/add/remove a resident in any night or day-call slot (admin/editor),
  with the constraint engine evaluating the *resulting* schedule and returning
  advisory warnings; the write proceeds regardless.
- FR-SCH-3: Per-resident panel: all calls with inter-call gap badges, day calls with
  post-call flags, profile chips (vacation, unwanted, constraints, notes), day-call
  suggestions, remove buttons.
- FR-SCH-4: Call tracker sidebar: per-resident count vs target (target parsed from
  profile), progress bars, day-call chips, grouped by level.
- FR-SCH-5: Next-day availability panel per unit/level (post-call + vacation aware).
- FR-SCH-6: Filters (teams, levels) and colour modes; spotlight dims non-matching.
- FR-SCH-7: Undo (persistent, per-block, at least 30 steps) + human-readable change
  log with author and timestamp.
- FR-SCH-8: DOCX and HTML export of the live block (see FR-EXP).

**FR-COV (Daytime Coverage)**
- FR-COV-1: Per-day team/level breakdown: available / post-call / vacation /
  unavailable, computed from schedule + vacation calendar + overrides.
- FR-COV-2: Manual per-day per-resident status override (admin/editor), flagged as
  manual, deletable individually and clearable en masse; override beats computed.
- FR-COV-3: Overrides are **scoped to a block** (fixes v1's `/block11` rollover bug).
- FR-COV-4: Can display any block including the draft (shortage forecasting).

**FR-CON (Subspecialty Consultants)**
- FR-CON-1: Per-specialty day-grid with the specialty's own column schema (schema is
  config data, editable by admin, versioned per block).
- FR-CON-2: Cell tri-state: assigned(text) / explicit-none / no-data; free-text notes
  per specialty.
- FR-CON-3: Scoped per block; copy-forward tool when a new block starts.

**FR-RES (Residents)**
- FR-RES-1: CRUD for residents with **stable internal IDs**; short display name is a
  field, renameable without breaking references.
- FR-RES-2: Fields: short name, full name, level (R1–R4/EXT), type (QCH/external),
  rotation (per live block, derived), phone, email, master rota (13 codes or null),
  active/excluded flags per block, constraints (structured, see §6.3), notes.
- FR-RES-3: Phone/email visible only to logged-in editors/admins (decision D2, §6.9).

**FR-ROTA (Master Rota)**
- FR-ROTA-1: 13-block × resident grid, colour-coded, searchable, with week date
  ranges per block; block calendar (dates, numbers) is admin-editable data.

**FR-UNW (Unwanted days)** — per block, per resident, date-level; admin/editor
editable; feeds warnings for live and draft blocks.

**FR-DRAFT (New Block)**
- FR-DRAFT-1: Create a draft for block N: dates seeded from the block calendar,
  28-day skeleton generated (Gregorian + Hijri per day, Thu/Fri/Sat = WE).
- FR-DRAFT-2: Roster derived from Master Rota codes (exclusions per §5.3), manually
  adjustable (exclude members, add external rotators).
- FR-DRAFT-3: Full editing parity with the Schedule tab **plus** drag-and-drop, the
  ward day-call allocator (per-resident fill, whole-block fill, clear), per-resident
  night-call auto-fill respecting level slot rules, draft warning engine, ignorable
  warnings (tracked, per draft).
- FR-DRAFT-4: Consultant rota entry, home-call (week × senior/junior), external lists.
- FR-DRAFT-5: Export draft (DOCX/HTML) marked as draft.
- FR-DRAFT-6: **Publish** (admin only): transactionally archive live block → promote
  draft → carry consultants/homeCall/externals; draft retained afterwards; the whole
  UI (header, dates, exports, coverage) follows the new live block. Multiple drafts
  MAY be supported (one per future block) — decision D4, §6.9.

**FR-EXP (Exports)**
- FR-EXP-1: DOCX: fill the department's template — rota table (weekday vs Fri/Sat
  merged-cell layouts), consultant table (with the Night-Call column removal), roster
  sections, subspecialty table, home call, header patch (block #, date ranges,
  chief) — byte-compatible with v1 output for identical input.
- FR-EXP-2: Self-contained HTML export (inline logo/CSS, print-friendly).
- FR-EXP-3: Print stylesheet for the schedule view itself (bonus over v1).

**FR-AUD (History & audit)**
- FR-AUD-1: Every write records an append-only audit entry: who, when, what
  (machine-readable diff), on which block. Admin-visible history view per block.
- FR-AUD-2: Undo/redo built on the same history (revert = new forward write).

**FR-AUTH** — email/password login; roles from a `users` collection (custom-claims
optional optimisation); session persists; login/logout UI; viewer needs no login.

### 4.3 Non-functional requirements

- NFR-1 **Performance**: first contentful paint < 1.5 s on mid-range mobile; schedule
  edits reflected locally < 100 ms (optimistic) and on other clients < 2 s.
- NFR-2 **Availability**: static hosting + Firebase; no server to crash. Read-only
  degradation if Firestore is unreachable (last-known cache).
- NFR-3 **Cost**: within Firebase free tier at current usage (≪ 50k reads/day).
- NFR-4 **Security**: all writes role-checked server-side (rules); no secrets in the
  repo; contact PII behind auth (D2); dependencies pinned and CI-audited.
- NFR-5 **Maintainability**: typed end-to-end (TypeScript strict); domain layer has
  zero UI/Firebase imports; every rule change requires a test change.
- NFR-6 **Accessibility**: keyboard operable editing, WCAG AA contrast, ARIA on the
  grid, focus management in pickers/panels.
- NFR-7 **i18n-readiness**: date/label formatting centralised (the app is English;
  Hijri + Arabic name support must not be hardcoded into components).

---

## 5. Domain reference — the authoritative rules catalogue

> This section is the contract. Every rule below exists in v1 and must be ported with
> a unit test. "Live" = the live-block checker (v1 `checkWarnings`); "Draft" = the
> block-aware draft checker (v1 `draftWarnings`). Divergences between the two are
> deliberate v1 behaviour — keep them unless the chief rules otherwise (D1, §6.9).

### 5.1 Calendar

- A **block** is exactly 28 days. Block 11 of 2025–26 starts Sun Jul 5 2026. The
  academic year has 13 blocks (B1 = 2025-09-28 … B13 = 2026-08-30).
- **Weeks** W1–W4 are days 0–6, 7–13, 14–20, 21–27 of the block.
- **Weekend** (`type:"WE"`) = Thu, Fri, Sat. Weekdays = Sun–Wed.
- Each day carries a Gregorian date and a **Hijri** date. Hijri is computed by the
  tabular (Kuwaiti) algorithm **with a constant −1 day offset**, calibrated
  day-by-day against the department's published calendar across a month boundary.
  The offset is a first-class named constant with its own test fixture.

### 5.2 Posts (slots) and capacities

| Slot | Kind | Hours | Capacity | Runs on |
|---|---|---|---|---|
| SO — Senior Overall | night | 15:00–07:30 | 2 | every day |
| NICU | night | 15:00–07:30 | 2 | every day |
| PICU | night | 15:00–07:30 | 2 | every day |
| PMW | night | 15:00–07:30 | 3 | every day |
| DC_PMW — Ward day call | day | 07:30–15:00 | 2 (**1 senior + 1 junior**) | Sun–Thu (never Fri/Sat) → 20 days/block |
| DC_NICU — NICU day call | day | 07:30–15:00 | 1 | Sun–Thu grid-wise; auto-suggestions target Thu; not on Fri/Sat |

**Post-call rule** (single definition, used everywhere): a resident in any *night*
slot on day D−1 is post-call on day D — they hand over at 07:30 and go home, so they
cannot hold a *day* call on D. Enforced in live warnings, draft warnings, the
allocator's eligibility test, auto-suggestions, and surfaced as markers/banners in
grids and panels.

### 5.3 Residents, levels, rotations

- Levels: R1–R4 (QCH residents) and EXT (external rotators, not in the master rota).
- Each long-term resident has 13 rotation codes (one per block), e.g. `NICU`,
  `GP/V`, `ANESSIM`, `RESEA/V`. Normalisation: strip `/V`, take the segment before
  `/`, then apply aliases `RESEA→RESEARCH`, `RES→RESEARCH`, `DEVLOP→DEVELOP`,
  `ANESSIM→ANES`. (v1 data contains these real typos; keep the alias map.)
- **Block roster derivation**: a resident is *on the on-call roster* for block N
  unless their block-N base code is one of `V, ELECTIVE, ER, PHC, OUTSIDE`, or they
  carry a manual per-block exclusion flag (v1: `excludedFromBlock11`, e.g. Maab,
  A.Marzooq). Excluded residents still appear in the Residents tab and Master Rota.
- **Ward day-call pool** for block N: roster members whose base code is NOT in
  `{PICU, OPD, RESEARCH, NICU}`. Tiers: juniors = R1; seniors = R2; **backup
  seniors** = R3, consulted only for days no R2 can cover. R4 never takes ward day
  call. NICU-rotation residents are reserved for DC_NICU instead.
- **Vacations**: per block, per resident, as week codes W1–W4 (source: the
  distribution spreadsheets; admin-editable data in v2).
- **Unwanted days**: per block, per resident, exact dates. Free-text requests that
  can't be dated (e.g. "no last weekend") are handled as structured constraints
  (§6.3), not silently dropped.

### 5.4 Warning rules

Advisory only — every rule yields a warning string; nothing blocks a write.

**Both live and draft:**
| # | Rule |
|---|---|
| W1 | Slot capacity exceeded (per §5.2 capacities). |
| W2 | Resident is on vacation that week (any slot, incl. day calls). |
| W3 | Date is in the resident's unwanted days. |
| W4 | Post-call resident placed in a day-call slot (§5.2). |
| W5 | Same-unit conflict, NICU slot: two NICU-rotation residents together in the NICU slot the same day. |
| W6 | Same-unit conflict, PICU slot: two PICU-rotation residents together in the PICU slot the same day — **exempt on Thu and Fri**. |
| W7 | Minimum gap between a resident's night calls: **4 days (live)** / **3 days (draft)** — flag every adjacent pair below minimum. |
| W8 | DC_PMW tier conflict: two juniors or two seniors together (needs one of each). |
| W9 | DC_PMW on Fri/Sat: the ward day call doesn't run those days. |
| W10 | DC pool: resident not eligible for ward day calls this block (per §5.3, with the human-readable reason: rotation / R4 / not on roster). |

**Live-block only (Block-11-era name lists — port as data, see note):**
| # | Rule |
|---|---|
| W11 | No-Saturday list: NICU team + PICU team + named individuals (v1: H.Saif). |
| W12 | No-Monday list (v1: Dina, Mustafa, H.Saif — daytime unit conflicts). |
| W13 | No-Sunday list (v1: Reem). |
| W14 | R1 residents must not cover the block's **first 3 days**. |

**Draft only (block-aware, rotation-driven — these superseded W11/W14 for drafts):**
| # | Rule |
|---|---|
| W15 | PICU or NICU rotation this block → no Saturday. |
| W16 | PULMO rotation → no Sunday. |
| W17 | CARDIO rotation → no Saturday and no Monday. |
| W18 | GI rotation → no Sunday and no Wednesday. |
| W19 | Same-rotation same-day conflict across **all** night slots: two PICU-rotation (or two NICU-rotation) residents on call the same day — exempt Thu/Fri. |
| W20 | Level→slot rule: R4 → NICU/SO; R3 → SO/NICU; R2 → PICU/NICU; R1 → PMW/PICU. Placement outside the level's allowed slots is flagged. |

> **v2 note**: W11–W14 exist because Block 11 predates the rotation-driven rules.
> v2 stores all of these as **structured per-resident/per-rotation constraint data**
> (§6.3) evaluated by one engine, with the v1 name lists imported as seed data. The
> live/draft split disappears; what remains is "which constraint set applies to which
> block", which is data. Behavioural parity is proven by running both engines over
> the archived Block 11/12 data and diffing the warnings (Phase 1 acceptance).

### 5.5 Ward day-call allocation algorithm (port as-is)

1. **Eligibility of (resident, day)** — one shared predicate used by allocator,
   filler, and warnings alike: day is Sun–Thu; not on vacation that week; not an
   unwanted date; not on a night call that same day; not post-call.
2. **Week cap**: max 2 ward day calls per resident per block-week.
3. **Seeding**: day-call cells already filled in the draft are fixed; they seed the
   per-resident totals and week loads.
4. **Assignment** (per tier — juniors first, then seniors): among unassigned
   day-call days, repeatedly pick the day with the **fewest remaining candidates**
   (backup-senior tier consulted only when no primary candidate exists), then assign
   the candidate with the lowest running total (ties: alphabetical). A day with zero
   candidates is left empty and surfaced as a gap.
5. Week-by-week balancing emerges from the cap + least-loaded choice; rounding
   shortfall lands on residents with vacations (chief's ruling).

Unit tests must include the "Aug 20 contention" scenario class: a day coverable by
only k residents must be assigned before those k residents are spent elsewhere.

### 5.6 Consultant rota (DOCX table 2) slots

`admit` (Consultant On Call/Admitting, col 3), `nicu` (col 4), `picu1` (PICU On
Call, col 5), `picu2` (PICU Out/Consultation, col 6), `specAM` (Specialist Morning
7AM–3PM, col 8), `specPM` (Specialist OnCall 3PM–7AM, col 9). Template column 7
("Night Call") is obsolete — the export removes that physical column (grid width
redistributed to the PICU columns) whenever it rewrites the table.

### 5.7 DOCX export rules (the fiddly parts that must not regress)

- Rota table rows: weekday rows use cells 4–9 = DC_PMW, DC_NICU, SO, NICU, PICU,
  PMW; **Fri/Sat rows have a merged day-call cell**: cells 4–8 = DC_PMW(merged),
  SO, NICU, PICU, PMW. Thursday is a full weekday layout.
- Pre-filled template cells (dates, consultant table, roster sections) must be
  **replaced**, not appended (append silently no-ops on non-empty cells — the
  historical "exports keep showing old dates" bug).
- Resident name colouring by that block's rotation: PICU→blue #1565C0, NICU→red
  #C62828, GP→green #2E7D32, else black (colour scheme per chief 2026-07-31).
- Template-block optimisation: exporting the template's own block with no extra
  data leaves pre-filled tables untouched (they're already correct).
- Header patch targets `word/header1.xml` (block number, both date ranges, acting
  chief name).
- Roster sections (ward team seniors/juniors/outside, unit coverage, subspecialty
  table, home call) are derived from one shared function so DOCX and HTML can never
  disagree.
- Cell/paragraph splicing must preserve `<w:tcPr>`/closing tags (dropping a
  trailing `</w:tc>` collapses the row) — keep the v1-hardened patterns, now with
  golden-file tests.

---

## 6. Proposed v2 architecture

### 6.1 Stack

| Layer | Choice | Why |
|---|---|---|
| Language | **TypeScript (strict)** | The domain is exactly the kind of stringly-typed rule soup types were made for. |
| UI | **React 18 + Vite** | Direct port path from v1's mental model; instant dev server; tree-shaken production build. |
| Styling | **Tailwind CSS** | Replaces thousands of inline style objects with a design-token system; trivial print/dark handling. |
| Data/auth | **Firebase (Firestore + Auth)** — keep | Live sync is the product's soul; free tier fits; rules give server-side enforcement without a server. A new Firebase project (`qhn-rota`) so v1 production is untouched during the build. |
| Server logic | **None by default; Cloud Functions only if needed** for (a) audit-log integrity, (b) publish transaction. Start client-side + rules; escalate only on demonstrated need. |
| State | **TanStack Query + thin Firestore hooks** (or plain listeners + context) | Kill the mutable module globals; all reads flow through typed hooks. |
| DOCX | **JSZip + the ported XML-surgery module** (not docxtemplater) | The template isn't tagged for templating; v1's surgical approach is proven — port it as a pure, golden-file-tested module. |
| Tests | **Vitest** (domain + components), **@firebase/rules-unit-testing** (rules), **Playwright** (E2E against emulators) | |
| Hosting/CI | **GitHub Actions → Firebase Hosting** (preview channels per PR) | Auto-deploy on main; kills the deploy/** trap; preview URLs for the chief to review. GitHub Pages remains a fallback option. |

Alternatives considered and rejected for v2.0: **Next.js/SSR** (no SEO need, adds a
server), **Supabase/Postgres** (real RLS+SQL wins, but migration risk + loses the
battle-tested Firestore live-sync patterns; revisit if relational reporting needs
grow), **Laravel + Livewire** (fits the author's other stack, but requires paid
hosting and re-solving live multi-client sync).

### 6.2 Repository layout

```
qhn-rota/
├── src/
│   ├── domain/            # PURE TypeScript. No React, no Firebase imports.
│   │   ├── calendar.ts        # block skeleton, weeks, weekend typing, Hijri (+offset)
│   │   ├── types.ts           # Block, Day, Slot, Resident, Assignment, Constraint…
│   │   ├── roster.ts          # rotation normalisation, roster/pool derivation
│   │   ├── warnings.ts        # THE rule engine (Section 5.4), data-driven
│   │   ├── daycall.ts         # allocator (Section 5.5)
│   │   ├── availability.ts    # coverage/availability breakdowns
│   │   └── __tests__/         # one spec file per rule group + property tests
│   ├── export/
│   │   ├── docx/              # template surgery, per-table modules, golden tests
│   │   └── html.ts
│   ├── data/              # Firestore schema, converters, repositories, audit
│   ├── features/          # schedule/ coverage/ consultants/ residents/
│   │                      # masterRota/ unwanted/ draft/ auth/ audit/
│   ├── components/        # shared UI (Badge, Grid, Picker, Panel, Tabs…)
│   └── app/               # routing, shell, providers
├── firestore.rules  · firestore.indexes.json  · firebase.json
├── functions/             # only if Phase 4 decides they're needed
├── scripts/               # migrate-v1.ts, set-role.ts, backup.ts
├── e2e/                   # Playwright specs (run against emulators, seeded)
└── .github/workflows/     # ci.yml (test+build), deploy.yml (hosting+rules)
```

The **domain layer purity rule** is enforced by lint (no `firebase`/`react` imports
under `src/domain`): it's what makes the rule catalogue exhaustively testable.

### 6.3 Firestore data model (v2)

Design moves: stable IDs everywhere; ISO dates; **per-day documents** for the
schedule (concurrency granularity); everything block-scoped; constraints as data.

```
residents/{residentId}                # residentId = stable slug, e.g. "r_hsaif"
  shortName, fullName, level, type    # display name is DATA, renameable
  phone?, email?                      # readable only by signed-in staff (rules)
  masterRota: string[13] | null
  notes, active

blocks/{blockId}                      # blockId = "b2026-11" (year+number)
  number, startDate (ISO), status: "draft"|"live"|"archived"
  actingChief, homeCall, externals
  rosterOverrides: {residentId: "include"|"exclude"}
  vacations:   {residentId: ["W1",...]}       # block-scoped
  unwanted:    {residentId: ["2026-07-05",...]}
  constraints: Constraint[]                    # see below
  publishedAt?, publishedBy?

blocks/{blockId}/days/{dayIndex}      # "00".."27" — ONE DOC PER DAY
  date (ISO), hijri, weekday, isWeekend
  slots: { SO:[residentId], NICU:[...], PICU:[...], PMW:[...],
           DC_PMW:[...], DC_NICU:[...] }

blocks/{blockId}/coverageOverrides/{dayIndex}
  overrides: {residentId: "available"|"postcall"|"unavailable"}

blocks/{blockId}/consultants/{specKey}
  columns (schema snapshot), notes[]
  cells: {"{dayIndex}|{colKey}": {status,text}}

blocks/{blockId}/audit/{autoId}       # append-only (rules: create-only)
  at, byUid, byName, action, dayIndex?, slot?, residentId?,
  before, after, note?                # machine diff → drives history UI + undo

config/blockCalendar                  # the 13-block year calendar (editable)
config/app                            # pointer: liveBlockId, draftBlockIds[]
users/{uid}                           # role: "admin"|"editor", displayName
```

**Constraint** (replaces v1's name-sets + free-text `constraints` strings):

```ts
type Constraint =
  | {kind:"noWeekday";   scope:Scope; weekdays:Weekday[]}        // W11–W13, W15–W18
  | {kind:"firstNDays";  scope:Scope; n:number}                  // W14
  | {kind:"notWith";     scope:Scope; otherResidentId:string}    // "no same day as X"
  | {kind:"maxCalls";    scope:Scope; n:number}                  // reduced-call rulings
  | {kind:"dateBan";     scope:Scope; dates:ISODate[]}           // dated ad-hoc bans
  | {kind:"note";        scope:Scope; text:string};              // undatable free text
type Scope = {residentId?:string; level?:Level; rotationBase?:string;
              blocks?: "all"|"thisBlock"};
```

One engine evaluates all of them; v1's live/draft divergence becomes seed data
(Block-11 name-list constraints attached to that block only).

**Why per-day docs**: a schedule edit becomes a single-doc update (or a 2-doc
transaction for a cross-day move) instead of rewriting 28 days — concurrent admins
editing different days can no longer collide at all, and same-day collisions are
resolved by a `runTransaction` read-check-write. Reading a block = 1 + 28 small doc
reads via one collection listener (well within free tier).

### 6.4 Security rules sketch

```
match /blocks/{b}            { allow read: true;  allow write: isEditor(); }
match /blocks/{b}/days/{d}   { allow read: true;  allow write: isEditor(); }
match /blocks/{b}/audit/{a}  { allow read: isEditor(); allow create: isEditor();
                               allow update, delete: false; }   // append-only
match /residents/{r}         { allow read: !privateField || isSignedInStaff();
                               allow write: isEditor(); }        // split doc: see below
match /users/{u}             { allow read: isSelf(u) || isAdmin(); allow write: isAdmin(); }
match /config/{c}            { allow read: true; allow write: isAdmin(); }
// publish (status flips + live pointer) admin-only via rules on the specific fields,
// or via a callable Function if field-level rules get unwieldy.
```

Contact PII: Firestore rules can't hide fields, so phones/emails live in a
subdocument `residents/{id}/private/contact` readable only by signed-in staff.
Public roster shows names/levels/rotations only (decision D2).

### 6.5 Concurrency, history, undo

- Every mutation: `runTransaction` (read fresh day doc(s) → apply → write) + one
  audit doc in the same batch.
- Undo = replay the audit entry's `before` state as a new forward transaction
  (auditable itself). Redo likewise. History view = the audit stream per block.
- The v1 "move log" becomes a projection of real audit data, shared by all admins.

### 6.6 Publish flow

Transaction/batch: verify caller is admin → set current live block `status:
"archived"` → set draft block `status:"live"` → update `config/app.liveBlockId` →
audit entry. Nothing is copied or deleted; "archive" is a status, and every block's
full history stays queryable. (v1's copy-to-`archive_blockN` disappears.)

### 6.7 Deployment pipeline

- `ci.yml` (every PR): typecheck, lint, domain+component tests, rules tests
  (emulator), build, Playwright smoke, deploy a Hosting **preview channel**, comment
  the URL.
- `deploy.yml` (main): all of the above + deploy Hosting + deploy Firestore rules
  and indexes. One command, no manual copies, impossible to "forget the deploy dir".
- Weekly scheduled Firestore export (backup) via a tiny script + service account in
  GitHub secrets (or Firebase's scheduled backups if the plan allows).

### 6.8 UI/UX plan

- Keep v1's proven interaction grammar (badge-select → cell-click to move; per-cell
  "+" pickers; spotlight panels; advisory warning banners) — residents know it.
- Upgrades: drag-and-drop on the live schedule too (v1 has it only in drafts);
  keyboard support (arrow/enter to move focus and place); consistent design tokens;
  larger touch targets; explicit save/conflict toasts; skeleton loading; a visible
  "history" drawer; a print stylesheet; installable PWA with offline read-only cache
  of the live block.
- Component inventory to build (Phase 3): `ScheduleGrid`, `DayCard`, `ResidentBadge`,
  `SlotCell`, `AddPicker`, `ResidentPanel`, `TrackerSidebar`, `AvailabilityPanel`,
  `WarningBanner`, `TabShell`, `LoginDialog`, `HistoryDrawer`, `CoverageBoard`,
  `ConsultantGrid`, `MasterRotaGrid`, `DraftWorkbench`, `PublishDialog`.

### 6.9 Open decisions (need the chief's ruling before the affected phase)

| # | Decision | Default recommendation |
|---|---|---|
| D1 | Unify live/draft rule divergences (gap 4 vs 3; W11–W14 vs W15–W20)? | Keep per-block constraint data; live block imports its legacy sets. Ask whether gap-4 should apply to future blocks or 3 is the real rule. |
| D2 | Contact PII behind login? | Yes (staff-only subdoc). Confirm the department accepts that the public page no longer lists phones. |
| D3 | Should viewers get export buttons? | Yes for HTML/print; DOCX admin-only (it's the official artifact). |
| D4 | Multiple simultaneous drafts? | Support it (schema already does); UI shows one "active draft" selector. |
| D5 | New Firebase project vs reuse `qhn-block11`? | New project + data migration (clean rules/indexes, zero risk to prod during build). |
| D6 | Keep GitHub Pages URL or move to Firebase Hosting URL? | Firebase Hosting; leave a redirect page at the old Pages URL for one block. |

---

## 7. Testing strategy

1. **Domain unit tests** (Vitest): one spec per rule W1–W20 with positive/negative
   cases; allocator scenario suite (incl. contention, vacation-heavy weeks, seeded
   composition in arbitrary order); calendar/Hijri fixtures (all 28 Block-11 dates
   must match the department's published values exactly); roster derivation against
   the real Master Rota dataset.
2. **Parity harness** (Phase 1 gate): run the v2 engine over the archived v1 Block 11
   + Block 12 draft data; diff warnings against the v1 engine's output (v1 functions
   extracted verbatim into the harness). Zero unexplained differences.
3. **Golden-file DOCX tests**: for fixed inputs (Block 11 live data; a Block 12 draft
   fixture), unzip the produced DOCX and assert `document.xml`/`header1.xml` against
   committed goldens; plus invariants (row count preserved, no dangling `</w:tc>`,
   Night-Call column gone when expected).
4. **Rules tests**: emulator-based — viewer cannot write anywhere; editor can write
   days but not publish/users; audit docs immutable; PII subdoc unreadable signed-out.
5. **E2E (Playwright, emulator-seeded)**: view schedule as anonymous; login; move a
   resident and see the warning; two-context concurrent edit (both edits survive);
   coverage override; draft → allocate day calls → publish → UI follows new block;
   export downloads.
6. **Manual drills** before cutover: chief walks the full monthly workflow on staging
   with real (migrated) data; two-admin simultaneous editing session.

---

## 8. Migration tooling

`scripts/migrate-v1.ts` (idempotent, re-runnable, reads v1 prod via service account
or from a JSON export):

1. Residents: mint stable IDs, map short names → IDs (mapping table kept for all
   later steps), split contact PII into the private subdoc, convert `constraints`
   strings to structured `Constraint`s where parseable (log the rest as `note`).
2. Blocks: `schedules/block11` (live) → `blocks/b2026-{n}` with per-day docs;
   `archive_block*` → archived blocks; `draftNextBlock` → draft block.
   Display dates ("Jul 5") → ISO; names → residentIds.
3. `manualUnavail/block11` → the live block's `coverageOverrides` day docs.
4. `consultantSchedules/block11` → per-spec docs under the live block.
5. `unwantedDaysByBlock/*` → each block's `unwanted` map (creating stub block docs
   for past blocks if needed).
6. Config: block calendar from v1 `BLOCK_DATES`; `BLOCK_VACATIONS` → per-block
   `vacations`; v1 name-set constraints (W11–W14) attached to the Block-11 block.
7. Verification report: counts per collection, unmapped names, per-day checksum
   (sorted slot membership) of v1 vs v2 for the live block — must be identical.

---

## 9. Plan of action

Estimates assume one developer working with AI assistance; a phase's tests are part
of the phase, not an afterthought. Total: **~30–43 working days** to cutover.

### Phase 0 — Foundations (1–2 days)
- Create repo (`qhn-rota`), Vite+React+TS strict, Tailwind, ESLint (incl. the
  domain-purity import rule), Vitest, Playwright, Firebase project (per D5),
  emulators config + persistent-data wrapper script, seed script for dev data.
- CI: typecheck+lint+test+build on PR; Hosting preview channels; deploy on main.
- **Deliverable**: empty app deployed by CI to a live URL; a failing-test PR is red.
- **Acceptance**: clone → `npm i` → `npm run dev` gives a working local stack with
  emulators in < 5 minutes, documented in README.

### Phase 1 — Domain core (5–7 days) ← the keystone
- `types.ts`, `calendar.ts` (skeleton generator, weeks, Hijri + offset),
  `roster.ts` (normalisation, aliases, roster + day-call pool derivation),
  `warnings.ts` (data-driven engine, all W1–W20), `daycall.ts` (allocator §5.5),
  `availability.ts` (coverage + next-day availability).
- Port v1 datasets as typed fixtures (master rota, block calendar, Block-11/12
  vacations & unwanted & constraint sets) — these are also the migration seeds.
- Build the **parity harness** (§7.2) and run it to zero diffs.
- **Deliverable**: `src/domain` at 100% rule coverage; parity report committed.
- **Acceptance**: every Section-5 rule has a named test; harness green; no
  react/firebase imports under domain (lint-enforced).

### Phase 2 — Data layer & rules (3–4 days)
- Firestore converters/repositories for the §6.3 schema; typed hooks
  (`useBlock`, `useDays`, `useResidents`, `useLiveBlockId`…); transactional
  mutation helpers that pair every write with an audit entry.
- `firestore.rules` + full rules test-suite; indexes file.
- `scripts/set-role.ts` (successor of set-admin-claim), `scripts/migrate-v1.ts`
  skeleton + verification report.
- **Acceptance**: rules tests green; a scripted two-client concurrent-write test
  shows both edits surviving on different days and correct last-write semantics
  within a transaction on the same day.

### Phase 3 — Read-only viewer MVP (4–5 days)
- App shell, tabs, routing; Schedule tab day view + full grid; resident panel
  (read-only), tracker, availability panel, filters/colour modes; Master Rota tab;
  Residents tab (read-only, no PII when signed out); mobile layouts; PWA manifest +
  offline read cache; print stylesheet.
- Seed staging with migrated real data (first real run of migrate-v1 against a
  v1 export).
- **Deliverable**: staging URL the chief can browse on a phone next to v1.
- **Acceptance**: side-by-side visual parity check on live Block data;
  Lighthouse ≥ 90/90 (perf/a11y) on the schedule view.

### Phase 4 — Editing & auth (5–7 days)
- Login, roles, session; move/add/remove on night + day-call slots (transaction +
  audit); warnings surfaced per edit; per-cell add pickers; delete; drag-and-drop;
  persistent undo/redo from audit; history drawer; day-call suggestions in the
  resident panel; recommend-day-calls action.
- Coverage tab with overrides (block-scoped); Unwanted Days tab; Consultants tab
  (schema-driven grids, tri-state cells, notes, copy-forward).
- Residents tab editing (rename-safe), constraint editor for structured constraints.
- **Acceptance**: E2E suite for all edit paths green; the two-browser concurrent
  drill passes; every edit visible in history with author.

### Phase 5 — Exports (3–5 days)
- Port the DOCX surgery into `src/export/docx/` as small per-table modules; golden
  tests (§7.3) built from v1's actual output for identical input; HTML export from
  the shared roster-sections deriver; draft-marked variants.
- **Acceptance**: byte-parity (or documented-and-approved diffs) with v1 output for
  the live block; goldens for a non-template block exercise header patch, date
  replacement, column removal, Fri/Sat merged layout.

### Phase 6 — Drafting & publish (4–6 days)
- New Block workbench: create draft (calendar-seeded), roster management incl.
  externals, full editing parity + allocator (per-resident fill / fill-all /
  clear), night-call per-resident auto-fill (level slot rules), ignorable
  warnings, consultant/home-call entry, draft exports.
- Publish dialog + transaction (§6.6); whole UI follows `liveBlockId`.
- **Acceptance**: E2E: build a Block-12 draft from scratch to published, on
  emulators, with the allocator matching the fixture expectations; post-publish,
  coverage/consultants/exports all address the new block.

### Phase 7 — Hardening & polish (3–4 days)
- Accessibility pass (keyboard model, ARIA, focus traps, contrast audit);
  error/empty/loading states; Firestore listener resilience (offline banner);
  performance profiling of the full grid; backup script + restore drill; docs:
  README, ADMIN-GUIDE (chief-facing, with screenshots), RUNBOOK (deploy, backup,
  restore, add-editor, new-academic-year data entry).
- **Acceptance**: NFR checklist signed off; restore drill executed once.

### Phase 8 — Parallel run & cutover (1 block, ~1–2 days of work)
- Freeze v1 feature changes. Final migration run; chief uses **v2 as primary** for
  one block while v1 stays read-only-available; daily automated checksum compare of
  v2 data vs expectations; collect chief feedback list and burn it down.
- Cutover: redirect old Pages URL → v2 (D6); announce; keep v1 repo archived
  read-only; revoke v1 write access; final v1 Firestore export archived.
- **Rollback path** (pre-agreed): v1 is still deployed and its Firestore untouched;
  reverting = removing the redirect. Any edits made in v2 during the window are
  re-entered manually (bounded: one block's edits).

### Cross-phase working rules
- TDD for domain and export code (red → green), matching the parity/golden gates.
- Every PR deploys a preview; anything touching visible UI gets a chief screenshot
  review before merge.
- No secrets in the repo; service-account keys only in CI secrets and the owner's
  machine.
- Conventional commits + CHANGELOG; the block-cutover phases each end in a tagged
  release.

---

## 10. Risk register

| Risk | L | Impact | Mitigation |
|---|---|---|---|
| Rule regression (a warning silently disappears) | M | Chief mis-schedules trusting the app | Parity harness (Phase 1 gate) + 100% rule test coverage + one-block parallel run |
| DOCX output drifts from the official format | M | Department rejects the export | Golden-file tests from v1's real output; chief signs off a printed sample in Phase 5 |
| Migration mangles names/IDs | M | Wrong people on the published rota | Deterministic mapping table + per-day checksum report + manual chief review of the migrated live block |
| Concurrent-edit design flaw | L | Lost edits return | Transactions + rules tests + the two-browser drill; audit trail makes any loss detectable |
| Firebase free-tier overrun | L | Cost | Per-day docs keep reads small; usage alerts at 50% budget |
| Single-maintainer bus factor | M | Project stalls | This document + ADMIN-GUIDE + RUNBOOK; boring, standard stack; CI does the deploys |
| Chief availability for D1–D6 decisions | M | Phase delays | Decisions batched in §6.9 with defaults; defaults proceed if no ruling by phase start |
| Hijri calendar disagreement | L | Wrong dates on official doc | Offset fixture tested against the published calendar; header dates chief-reviewed each publish |
| PII exposure regression | L | Privacy complaint | Rules test asserting contact subdoc is unreadable signed-out; CI-blocking |

---

## 11. Backlog (post-v2.0 candidates)

- Whole-block **night-call auto-draft** (constraint solver over W1–W20 + targets),
  presented as a suggestion layer the chief accepts per-week.
- Resident self-service: submit unwanted days / vacation requests → chief approves
  (kills the CSV/WhatsApp intake).
- Swap requests between residents with chief approval.
- Notifications (push/email) on publish and on changes to *your* calls.
- Per-resident iCal feed ("subscribe to my calls").
- Fairness dashboards: weekend/holiday distribution across blocks.
- Multi-department tenancy (the model generalises: departments → blocks).
- Arabic UI toggle.

---

## Appendix A — Feature-parity checklist (cutover gate)

Schedule: day view · full grid · today highlight · move · add-picker · delete ·
undo · move log · warnings (all W-rules) · tracker w/ targets · availability panel ·
filters · colour modes · spotlight panel · gap badges · post-call markers · DC
suggestions · recommend-day-calls · DOCX export · HTML export.
Coverage: computed statuses · manual overrides · clear-all · draft-block view.
Consultants: 10 specialties · tri-state cells · notes · admin edit.
Residents: full DB view · admin inline edit · vacation/unwanted display strings.
Master Rota: 13-block grid · search · week dates · block selector.
Unwanted Days: per-block view/edit.
New Block: skeleton gen (Greg+Hijri) · roster derivation · exclusions · externals ·
day view · full grid · drag-and-drop · per-resident autofill · day-call allocator
(fill one/all/clear) · draft warnings · ignorable warnings · draft tracker · draft
availability · consultant entry · home call · draft exports · publish w/ archive.
Auth: login · logout · admin gating · (new) editor role.
Platform: mobile layouts · live multi-client sync · emulator-only local dev.

## Appendix B — Glossary

**Block** 28-day scheduling period (13/academic year) · **WE** weekend (Thu–Sat) ·
**SO** Senior Overall night post · **PMW** Pediatric Medical Ward night post ·
**DC** day call (07:30–15:00) · **Post-call** the day after a night call ·
**Master Rota** the year-long 13-block rotation table · **Roster** residents
eligible for on-call in a given block · **Pool** roster subset eligible for ward
day calls · **Unwanted day** resident-requested date to avoid · **Publish**
promoting a draft to the live block · **Live slot** the block currently shown to
everyone · **EXT** external rotator (not in Master Rota).
