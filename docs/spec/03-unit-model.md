# 3. Unit model

`units` table seeded with all four units. `EndorsementController::UNIT_CODES = ['PICU', 'NICU', 'SCBU', 'WARD']`; unknown or retired unit codes 404 (lowercase URL codes keep resolving, as in the reference).

A single **UnitProfile** value object (PHP, exposed to Vue via Inertia props) is the sole source of per-unit variation:

| | PICU | NICU | SCBU | WARD |
|---|---|---|---|---|
| Row identity columns | bed, mrn, patient_name | + dob | + dob | age, bed (labelled "Room"), ward_unit ("Unit/Speciality"), mrn, patient_name (no dob) |
| Consultant sign-off fields | by + to | by + to | by + to | single field labelled **"Consultant Oncall"**, stored in `consultant_by_*`; consultant-receiving hidden **[RULING]** |
| Print column 4 label | Plan Of Care | Plan Of Care | Plan Of Care | Management |
| Print column 5 label | New events | To be followed | To be followed | To be followed |
| Hue token | `--color-unit-picu` (existing value) | `--color-unit-nicu` (minted) | `--color-unit-scbu` (minted) | `--color-unit-ward` (minted) |

- `/endorsement` renders a **four-unit chooser**: one card per unit with its hue bar, today's census count, and today's status (signed / in progress / no sheet), plus a banner when any unit is unfilled past handover time. No unit is privileged. **[RULING]**
- Navigation has four unit entries plus the chooser.
- All handover reads and writes are unit-partitioned: every query scopes by `unit_id`, and bare-row-ID endpoints verify the row's unit is an enabled unit (generalising the reference's `assertPicuRow`).

---
