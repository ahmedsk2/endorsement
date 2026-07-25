# 12. Legacy import

`legacy:import` artisan command, modelled on the reference's `LegacyImport`, generalised to four units:

- **Read-only** `legacy` DB connection (SELECT-only grant), one-way; the owner runs it against production — never Claude.
- Source map: `patintsendorcement` → PICU; `nicu_patintsendorcement` → NICU (+dob); `scbu_patintsendorcement` → SCBU (+dob); `ward_patintsendorcement` → WARD (age, unit → ward_unit). Day headers: `endorsement`, `nicu_endorsement`, `scbu_endorsement` (consultant by/to), `ward_endorsement` (**consultantoncall → consultant_by_name**).
- Sections (reference → users → handovers ×4 → signoffs ×4) in per-section transactions, chunked reads ordered by legacy ID.
- **Idempotent on provenance:** upsert keyed (legacy_source_table, legacy_id) — lossless, no natural-key collapse (~2.5k duplicate (date,mrn,bed) rows import intact) **[RULING]**. Blank template rows import as-is.
- Users: bcrypt hashes copied verbatim, never rehashed. Endorser member_ids resolved to users; junk/unresolvable ids → null FK (name snapshot only when resolvable).
- Rich text sanitised by the model cast on import (historical rows predate legacy's sanitiser).
- Data rules: `0000-00-00` and `1970-01-01` dates and zero-datetime dobs → null, counted per unit in reconciliation; WARD's free-text sub-unit imports verbatim into ward_unit; imported signed days get `signed_off_at = date 00:00` with null signer (historically final, therefore locked).
- Output: counts-only `docs/RECONCILIATION.md`; companion `legacy:reconcile` recomputes and exits non-zero on unexplained drift, with modelled expected divergences (skipped zero-dates etc.) per unit. Audited; no PHI in any output.

---
