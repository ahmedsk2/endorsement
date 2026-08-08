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

Adding a department means inserting a `units` row and configuring these columns. No code changes.

- `/endorsement` renders a **four-unit chooser**: one card per unit with its hue bar, today's census count, and today's status (signed / in progress / no sheet), plus a banner when any unit is unfilled past handover time. No unit is privileged. **[RULING]**
- Navigation has four unit entries plus the chooser.
- All handover reads and writes are unit-partitioned: every query scopes by `unit_id`, and bare-row-ID endpoints verify the row's unit is an enabled unit (generalising the reference's `assertPicuRow`).

---
