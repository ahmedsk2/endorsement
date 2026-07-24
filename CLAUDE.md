# Paediatric Endorsement System

A standalone shift-handover (endorsement) system for four paediatric units — PICU, NICU,
SCBU, WARD. Endorsement ONLY: no registry, no scoring, no KPI dashboards (beyond the
missed-days counter), no nursing sheets.

## Canonical documents

- Spec (approved, rulings index in §15): `docs/superpowers/specs/2026-07-24-endorsement-system-design.md`
- Implementation plan: `docs/superpowers/plans/2026-07-24-endorsement-system.md`
- Design tokens: `docs/DESIGN-TOKENS.md` — "Monitor, in daylight", LIGHT THEME ONLY

## Reference codebases (READ-ONLY — never modify)

- `C:\Users\ahmed\Documents\PICU Registry and Endorsement` — legacy procedural PHP
  (deployed production; the behavioural specification; row table misspelled
  `patintsendorcement` ×4; PICU files lowercase)
- same repo, `laravel\` — the hardened Laravel re-platform this project clones from
  (deliberately PICU-only there; four units here)

## Non-negotiable rules

- **No PHI** (patient name, MRN, DOB) in URLs, query strings, logs, audit_log details,
  exception messages, or push payloads — ids/field-names/counts only.
- **TDD**: failing test first, watch it go red, then implement. Tree deployable after
  every commit. `php artisan test` + `npm run build` green before any commit.
- Never concatenate SQL — Eloquent/bindings only. Every route behind auth + a `cap:`
  capability. Writes are POST/PATCH/DELETE + CSRF.
- Additive, nullable migrations; soft deletes; never retype a column holding real data;
  clinical rows never hard-deleted; accounts deactivated, never deleted.
- Rich text sanitised ON WRITE (`SanitizedHtml` cast → `RichTextSanitizer`); the editor
  sets `styleWithCSS` per-command (ON only for foreColor/hiliteColor). Never copy the
  legacy toolbar JS — its global styleWithCSS caused silent production data loss.
- LIGHT THEME ONLY: any `dark:` utility is a bug (guarded by
  `tests/Feature/Build/CompiledCssIsLightOnlyTest.php`). Semantic classes only
  (`.readout`, `.channel-tag`, `.channel-bar*`) — no raw Tailwind palette classes or
  hex in markup.
- Autosave is never fire-and-forget: per-field save-on-blur, UI reflects the server
  response, e2e asserts persistence after reload — never the indicator alone.
- Secrets are owner-managed: never ask for, print, or commit DB/SMTP/VAPID secrets.
  Production migrations and live-DB changes: prepare + document, the owner runs them.
- The legacy import is one-way, read-only against its source, idempotent (provenance
  keyed), audited — and only the owner runs it against production.

## Toolchain (this machine)

- PHP 8.4 at `%LOCALAPPDATA%\php84`, Composer shim at `%LOCALAPPDATA%\composer-bin`
  (both on user PATH; prepend to PATH in fresh shells if not picked up)
- `php artisan test` (PHPUnit, sqlite :memory:) · `npm run build` (Vite) ·
  `npm test` (Vitest, once configured) · Playwright e2e under `tests/e2e`

## Domain vocabulary

- The four rich-text fields: `disease` ("Problem List"), `details` ("Clinical
  Condition"), `plan` ("Plan of Care"), `nevent` ("To be followed"; PICU print header
  says "New events"). nevent CARRIES FORWARD on new day (owner ruling).
- Day identity: (unit_id, handover_date). Sign-off is a per-day header row
  (`handover_signoffs`, UNIQUE on that pair); `signed_off_at` = locked.
- Endorsed by/to pickers: active Residents (position 4) only. Consultants: position 3.
  WARD has a single "Consultant Oncall" stored in `consultant_by_*`.
- Unit variation lives in ONE place: `App\Support\UnitProfile`.
