# 1. Purpose and scope

A standalone shift-handover ("endorsement") system for a hospital's paediatric services, covering four units — **PICU, NICU, SCBU, WARD** — each a first-class unit with its own day index, editable handover sheet, per-day sign-off, and printable A4 sheet.

**In scope:** endorsement/handover only, plus the authentication, access-control, audit, and design-system foundation it needs; a one-way legacy data import; mobile-first UI, PWA install, handover-time reminders, and a missed-days compliance view.

**Out of scope (permanently, for this project):** patient registry, scoring, KPI dashboards (beyond the missed-days counter specified in §10.3), nursing sheets, offline editing, hard enforcement of data entry (no blocking, no mandatory clinical fields), email digests.

### Reference codebases (read-only; never modified)

| Reference | Location | Role |
|---|---|---|
| Legacy procedural PHP | `C:\Users\ahmed\Documents\PICU Registry and Endorsement` (repo root) | Deployed production system; **behavioural specification** for all four units. Row tables carry the real misspelling `patintsendorcement`. PICU file names are lowercase. |
| Laravel re-platform | same repo, `laravel/` | Hardened modern implementation, deliberately PICU-only (decision "G1"). **Clone source** for foundation and endorsement module. |

Where the two disagree, the rulings in this spec are final (they were decided explicitly by the owner; each is marked **[RULING]** below).

---
