# 15. Rulings index

| # | Decision | Ruling |
|---|---|---|
| 1 | nevent on new day | Copy forward verbatim (legacy) |
| 2 | Carry source after a gap | Dialog: "Last endorsement was {date}" → carry or start blank; consecutive days carry silently |
| 3 | `/endorsement` landing | Four-unit chooser |
| 4 | Print target | Print.vue style for all four units |
| 5 | WARD consultant | Single "Consultant Oncall" → `consultant_by_*`; receiving field hidden |
| 6 | Endorsed by/to pickers | Active Residents only (position 4) |
| 7 | Import identity | Lossless via legacy_source_table + legacy_id |
| 8 | Draft flag | Dropped |
| 9 | Framework | Laravel 13.x (match reference) |
| 10 | Account creation | Self-register + admin approval |
| 11 | Capability scope | Global |
| 12 | Tenancy | Keep institutions table |
| 13 | Notifications | In-app + web push (no email) |
| 14 | Compliance metric | Missed-days per unit only, date-range selectable, expandable to dates; missed = no signed sign-off |
| 15 | Consultant pickers | Active Consultants (position 3), replacing legacy free text |
