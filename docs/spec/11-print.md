# 11. Print

**Print.vue style for all four units [RULING]** — one parameterised print page, not four templates:

- A4 landscape (`@page { size: A4 landscape; margin: 8mm }`), chrome-free layout, Arial 11px, auto `window.print()` shortly after mount when rows exist.
- Flat columns from UnitProfile. PICU: Bed | MRN | Name | Diagnosis List | Clinical Condition | Plan Of Care | New events. NICU/SCBU: Bed | MRN | Name | DOB | Diagnosis List | Clinical Condition | Plan Of Care | To be followed. WARD: Room | Unit | MRN | Name | Age | Diagnosis List | Clinical Condition | Management | To be followed (no DOB).
- Consultant line label per unit ("Consultant Covering" / WARD "Consultant Oncall") + TIME; footer prints all four endorser/consultant names ("Not Selected" fallback), keeping the reference's corrections: Consultant Receiving is printed, and the legacy "Endorsed By/To" label bug stays fixed. Signed stamp or "NOT SIGNED OFF" line.
- `v-html` is safe here because sanitisation happened on write; `:deep(ul/ol)` longhand `list-style-type` rules restore markers inside `v-html` (the minifier mangles the shorthand).
- Print fidelity is a contract: once approved, the page is not restyled.

---
