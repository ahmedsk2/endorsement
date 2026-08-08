# 3. Unit model

`units` is the source of unit identity and of every per-unit difference. `Unit::codes()`
returns the ACTIVE codes in `display_order`; unknown codes and units with `active = false`
404 (lowercase URL codes keep resolving — codes are normalized to upper case on write, and
`Unit::findByCode()` normalizes the lookup).

Per-unit variation is **configuration on the `units` row** — `extra_row_fields`, `bed_label`,
`consultant_pair`, `consultant_by_label`, `bar_class`, `print_plan_label`,
`print_narrative_label`. `App\Support\UnitProfile` is the value object carrying that shape to
PHP and, via Inertia, to Vue (`$unit->profile()`); it holds no values of its own.

Units are **opt-in active**: `active` defaults to `false`, so a half-configured department is
inert rather than routable. `extra_row_fields` is allow-listed by `App\Casts\ExtraRowFields`
against the identity columns that actually exist on `handovers` — it feeds validation rules
whose output reaches an update, so an unexpected key there would be a data-integrity hazard.

The table below is the SEEDED configuration for the QCH paediatric institution, not a
description of the code.

| | PICU | NICU | SCBU | WARD |
|---|---|---|---|---|
| Row identity columns | bed, mrn, patient_name | + dob | + dob | age, bed (labelled "Room"), ward_unit ("Unit/Speciality"), mrn, patient_name (no dob) |
| Consultant sign-off fields | by + to | by + to | by + to | single field labelled **"Consultant Oncall"**, stored in `consultant_by_*`; consultant-receiving hidden **[RULING]** |
| Print column 4 label | Plan Of Care | Plan Of Care | Plan Of Care | Management |
| Print column 5 label | New events | To be followed | To be followed | To be followed |
| Hue token | `--color-unit-picu` (existing value) | `--color-unit-nicu` (minted) | `--color-unit-scbu` (minted) | `--color-unit-ward` (minted) |

Adding a department means inserting a `units` row and configuring these columns; the chooser,
day index, sheet, print and validation follow immediately. Two surfaces are **not** yet
data-driven: the sidebar nav (`resources/js/Layouts/AppLayout.vue`) and the hue classes
(`resources/css/app.css`) are still hardcoded for the four paediatric units, so a fifth
department is reachable from the chooser but has no nav entry or hue until those move to
configuration.

## Two tiers of per-row field

A handover row's fields come from two independent mechanisms; they are never merged.

1. **Named identity columns**, via `extra_row_fields` (above). Each is its own encrypted
   column on `handovers` (`dob`, `age`, `ward_unit`), individually validated and individually
   degradable — a wrong-key `dob` blanks only `dob`; the rest of the row is unaffected.
2. **Custom fields** (design §6.2, "Ceiling 2"), via `unit_field_definitions` — per-unit
   `{key, label, type (text|date|select), options, required, display_order, active}` rows —
   whose VALUES all live together in one column, `handovers.extra_fields`, behind
   `App\Casts\EncryptedJson`. There is no admin UI yet (P0b); definitions are seeded or
   inserted directly, which is still zero *code* for a new field. The management screen
   belongs with a future units settings screen.

Consequences of custom fields sharing one column, all load-bearing:

- **Not searchable or indexable.** No SQL range query, sort, or `LIKE` reaches inside
  `extra_fields`. Nothing in this system does either today.
- **Degradation is all-or-nothing per row**, unlike the named columns above: if the column
  fails to decrypt (a restored row, a hand-inserted fixture, a rotated `APP_KEY`), the WHOLE
  map is unreadable, not just one field. `EncryptedJson::get()` returns the `__unreadable`
  sentinel for that case rather than an empty map (which would be indistinguishable from "no
  custom fields were ever entered"); the client renders it as a visible row-level warning in
  both the sheet and print rather than silently dropping it.
- **Retiring a definition hides values, never deletes them.** `UnitFieldDefinition`'s
  `active()` scope simply stops a retired definition from being offered by
  `Unit::fieldDefinitions()`; the historical value under its `key` stays in `extra_fields`
  untouched and starts rendering again if the definition is reactivated. `EncryptedJson`
  therefore deliberately carries no `unit_field_definitions`-keyed allow-list — unlike
  `ExtraRowFields`, whose allow-list exists for an unrelated reason (its output becomes model
  attribute names reaching `update()`, a mass-assignment vector that does not apply here).
- **Values are plain text and are never purified** — unlike the four rich-text fields
  (`disease`/`details`/`plan`/`nevent`), which go through `SanitizedHtml`. Every consumer
  escapes on render (`{{ }}` interpolation / `:value` binding in Vue, never `v-html`).
- **Autosave saves one key at a time** through the existing row-PATCH endpoint, shaped
  `{extra_fields: {[key]: value}}`; the server merges it into the stored map
  (`EndorsementController::updateRow()`, `array_replace`) rather than replacing the column, so
  a single-field save can never wipe its siblings.

- `/endorsement` renders a **four-unit chooser**: one card per unit with its hue bar, today's census count, and today's status (signed / in progress / no sheet), plus a banner when any unit is unfilled past handover time. No unit is privileged. **[RULING]**
- All handover reads and writes are unit-partitioned: every query scopes by `unit_id`, and bare-row-ID endpoints verify the row's unit is an enabled unit (generalising the reference's `assertPicuRow`).

---
