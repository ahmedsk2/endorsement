# Munawib Spec v1.0 (frozen, 8 August 2026)

`SPEC.md` in this directory is **Part B** of
`munawib-claude-code-build-prompt-v1.md` (the user's Downloads folder copy),
saved verbatim per Munawib §A0. It is the frozen functional and technical
specification for Munawib, a separate duty-rota/scheduling product — not this
endorsement system.

It is saved here, versioned, because this repo's own plans and design doc
(`docs/superpowers/plans/`, `docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`)
bind to it **by requirement ID** (e.g. MR-03, CG-07, AU-06, SL-01). Those IDs
need a durable, in-repo anchor; a file sitting only in Downloads is not one.

Part A of the source document (operating instructions for building Munawib
from scratch) is deliberately **not** included here. It described a build
that has since diverged in stack, identity model, and process (solo work here
vs. the reviewed team process it assumed); keeping it alongside SPEC.md would
misrepresent it as current guidance.

## What SPEC.md is — and is not

SPEC.md describes what Munawib **was specified** to be. It is not a record of
what this repo actually built, and it is not current for any point where this
repo's integration design deliberately departs from it.

The **integration design doc is the authority on divergence**:
`docs/superpowers/specs/2026-08-08-munawib-endorsement-integration-design.md`,
§1.2, lists every clause this repo adapts or overrides — and D3 was reversed
outright.

When a requirement ID from SPEC.md is cited anywhere in this repo's plans or
docs, check §1.2 of the integration design doc before assuming SPEC.md's
wording is what was actually implemented.
