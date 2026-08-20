# The conditions-engine fixture corpus

*Fixed at P2 Task 7, with the CG-10 contract. Every case added by Tasks 10–20 takes this format.*

## The format — one JSON file per case

```jsonc
{
  "name": "...",                  // the file's own name, so a case cannot be renamed by moving it
  "why": "...",                   // MANDATORY. See below.
  "context": { ... },             // EvaluationContext
  "schedule": { ... },            // Schedule: the horizon, and the duties in it
  "conditions": [ ... ],          // Condition[]
  "expected": [ ... ],            // Violation[], in ANY order — the suite sorts before comparing
  "expectedCoverage": [ ... ]     // CoverageReport[], optional
}
```

Every one of those keys is a `$defs` entry in `src/contract/schema.ts`, and the `Fixture` def ties
them together with `additionalProperties: false`. A case with a typo'd key therefore fails loudly
instead of being half-read — which is the whole reason the schema exists at a boundary TypeScript
cannot see.

## `why` is mandatory, and it is not decoration

A fixture whose purpose nobody wrote down is a fixture nobody dares change. It ossifies: the next
author cannot tell whether the number in it is the point of the case or an arbitrary value that
happened to make the assertion pass, so it survives every refactor unexamined and eventually
asserts something nobody intended.

Say what the case would catch if the code were wrong. *"A weekly-cadence slot occupies seven dates,
not eight"* is a `why`. *"Tests min_gap"* is not.

## The corpus is SYNTHETIC, permanently

No real staff list — no name, email or phone number of any actual QCH person — belongs in this
repository at any time, in a fixture or anywhere else. That is a standing project invariant, and it
binds here exactly as it binds `tests/fixtures/roster/`.

Person keys are `p-<something>`, unit keys are unit codes, and the months are invented. The corpus
is built to exercise specific failure SHAPES — the abutting split day/night pair, the horizon edge
on the 1st, a partial window at the left of the tail — not to resemble a real department. A case
that resembles a real rota is a case somebody will eventually try to reconcile with a real rota.

## Two properties every case is expected to have

**It is fixtured at the seam where the type can be wrong.** Every window-measured and pairwise type
measures a relationship that crosses the boundary of what is being evaluated, so a corpus of
mid-month cases proves nothing about the case a scheduler hits first, on the 1st. `context`'s
`priorDuties` and `followingDuties` are there to be used.

**It is observed failing.** A type that is green because its inputs are empty is indistinguishable,
on a green suite, from a type that works. Plant the defect the case exists to catch, watch the case
go red, revert — and if it does not go red, the case is not yet a case.

## The one case that ships with Task 7

`contract-shapes.json` asserts the SERIALISER and the ORDER, not a rule. It constructs one
violation of each `Location` member — placement, window, cohort — round-trips them through
`validate()`, and asserts that `sortViolations()` puts a deliberately scrambled list into its
canonical order. It exists because P2-1 authors the window and cohort members before P2-2's types
consume them, and an unused contract field with no assertion behind it is precisely the objection
that early authoring invites.
