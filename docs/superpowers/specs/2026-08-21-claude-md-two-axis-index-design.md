# `CLAUDE.md` as a two-axis index — design, 21 August 2026

## What this is

A restructure of `CLAUDE.md` from a 431-line stream into a short always-loaded core plus two
index tables that point into `docs/INVARIANTS.md`. **No rule's wording changes.** Rules move
wholesale; the core gains a one-line trigger for each.

## What this is not

- **Not a rewrite.** Compressing a rule risks destroying the mechanism that makes it bite. The
  `.env.example` rule works because it names the exact failure — a present-but-empty key
  resolving to `''` rather than `env()`'s default. "Keep `.env.example` correct" prevents nothing.
- **Not a cull.** Nothing is deleted. Everything either stays in the core or moves under a heading.
- **Not harness documentation.** How to drive agents belongs to the tool and will age with it.
  Only what is true of *this repository* is written down.

## The problem, measured

`CLAUDE.md` is 431 lines / 32KB, read in full at the start of every session. Its
`Non-negotiable rules` section is 190 lines carrying **22** bullets — each one an incident
narrative as much as a rule.

*(Corrected during self-review: this document first said 12. That was a grep counting only the
**bolded** bullets, reported without checking what the pattern excluded — the same species the
design exists to address, committed while writing the design.)*

Two costs, and the second is the expensive one:

1. **Reading.** 32KB of context before any work begins.
2. **Not finding.** The rules that cost the most time in the P2 phase were not in the file at
   all, and the ones that were could not be found by the question an agent was actually asking.
   "A docblock is scanned source" fired **eight times** across unrelated files. It is not a fact
   about clinics or the rota, so no area index would ever have surfaced it.

## The four decisions

| | Decision |
|---|---|
| **Shape** | A short always-loaded core; everything else behind pointers loaded on demand — the mechanism `docs/INVARIANTS.md` already proves works |
| **Core test** | **Cross-cutting AND non-obvious.** It binds whatever you are touching, and a competent agent would plausibly get it wrong unaided. Obvious things are cut; area-specific things move |
| **Parallelism** | Record only what is project-specific: **the seams** and **the union rule**. No tool mechanics |
| **Compression** | **Split, do not rewrite.** Move rules verbatim; leave a one-line trigger |

## Target structure of the core

1. What this project is (unchanged)
2. **How to work here** — the method. Kept, and extended with the falsifiability technique below
3. **Two index tables** — what am I touching; what am I about to do
4. **Universal rules** — only those that bind every task and that no table row can trigger:
   no PHI in URLs/logs/audit detail; secrets are owner-managed; D11's one-database-per-customer
   boundary; TDD and a deployable tree
5. Toolchain (unchanged — needed every session)
6. Domain vocabulary (unchanged — needed constantly)
7. Canonical documents and reference codebases (unchanged)

Target: **under 200 lines.**

## Table one — what am I touching

The existing table, extended so every moved rule has a trigger. Unchanged in mechanism.

## Table two — what am I about to do

New. Each row is one line and a pointer to a new activity section in `docs/INVARIANTS.md`.

| About to… | Read | Because |
|---|---|---|
| Write a test | §Falsifiability | ~31 deliberately-broken versions of correct code passed an 800-test suite in one phase |
| Add or extend a guard | §Guards | A guard extended into new territory reads identically to one that is not scanning |
| Pin a budget | §Budgets | A budget cannot see a defect that makes things *faster* |
| Run agents in parallel | §Parallelism | Whole-directory guards only see the union after a merge |
| Correct a document | §Documents | A decision record can cite an objection as though it were the decision |
| Add a migration | §Migrations | MySQL has no transactional DDL; the app user lacks `ALTER` |

### §Falsifiability — the content

The method already says *a passing test must be shown capable of failing*. What is missing is the
**species** and the **technique**:

- **The species: a claim asserted only where it MATCHES and never where it must not.** Every one
  of the phase's green plants was this. A scope filter no fixture exercised. A holiday filter with
  no unnamed holiday to reject. A preview matrix structurally blind to types with no parameters.
- **Plant the narrowing, not the happy path.** The corpus proves what a rule does; nothing proved
  what it refuses. Two kind-filters could both be emptied — discarding the operator's entire
  configuration — with the whole suite green.
- **Batch-plant before fixing.** Planting all candidates at once and counting the survivors found
  8 of 8 and 23 of 28. It also distinguishes a real hole from a coincidence cheaply.
- **A test that is not collected is indistinguishable from a passing one.** Confirm by name.
- **A fixture can be unable to catch the parameter it exists to test.** Check the expected value
  would not also be produced by the broken implementation.

### §Parallelism — the content

Two project facts, no tool mechanics:

- **The seams.** This repo splits cleanly at `app/` versus `packages/` (PHP versus TypeScript),
  and at the guard roots — `app_path()`, `database`, `routes`, `resources/js`, `packages`.
  A split along a seam produces no collisions; a split across one produces conflicts in shared
  files (the registry, the message table, a plan's appendix).
- **The union rule.** Whole-directory guards only see the union of both sets of files **after**
  the merge. Each branch can be green alone and the merged tree red. **A merged tree always gets
  its own full verification pass** — this is not optional and it has already caught one real
  failure that neither worktree could see.

## What moves

All 22 non-negotiables move verbatim into `docs/INVARIANTS.md` under an area or activity heading,
except the four listed as universal above. The `2026-07-26 audit` section moves under its areas.

**The triage, and why it settles the two-axis question.** Reading the 22 as they stand, they do not
sort onto one axis — roughly a third are about an ACT rather than a place:

| Axis | Bullets |
|---|---|
| **Universal** (stay in core) | PHI; TDD; secrets are owner-managed; D11 one-database-per-customer |
| **Activity** → new sections | single-writer guards audited by planting; the `$model->update([...])` needle; `.env.example` never neuters a default; a refusal flashed under a key the screen renders; a validation rule asked only of the mode's own input; Coolify secrets are alphanumeric |
| **Area** → existing sections | SQL/routes/CSRF; migrations; rich text; light theme; autosave; roster fixtures; custom fields; `EncryptedJson`; CSV; `APP_TIMEZONE`; `institution_id` indexes; `unit_id` and `UnitMerge` |

Six of the 22 are activity-scoped. Under a single area table each would have to be duplicated
across every area it touches, or dropped — and the dropped ones are precisely the guard rules that
were relearned eight times in one phase.

## Acceptance

- [ ] `CLAUDE.md` under 200 lines
- [ ] Every moved rule reachable from exactly one table row, and its wording **byte-identical** to
      what it was before the move (verified by diff, not by reading)
- [ ] The two new sections written from the phase's own findings, each citing the defect that
      earned it
- [ ] No rule deleted. A diff of the concatenation of both files loses nothing but ordering
- [ ] Every table row's target section exists (assert it, the way the guards do)

## Risks

- **A pointer is weaker than a rule in front of you.** Mitigation: the trigger line names the
  consequence, not just the topic, so an agent can tell whether it needs to follow the pointer.
- **Two axes means judging which one a rule sits on.** Mitigation: a rule may have a row in both.
  A duplicated one-line pointer is cheap; a missed rule is not.
- **The index can go stale as `INVARIANTS.md` grows.** Mitigation: a build guard asserting every
  table row resolves to a real heading — the same device `UnitMergeCoversEveryUnitReferenceTest`
  and the catalog-parity guard already use, and the reason both work is that they derive one side
  rather than declaring both.
