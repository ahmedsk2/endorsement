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

## Post-launch rulings (2026-07-25)

| # | Decision | Ruling |
|---|---|---|
| 16 | Nurse role (position 1) | RETIRED entirely: no catalog row, no defaults, not registerable, legacy nurse accounts not imported |
| 17 | Chief Resident (position 5) | Registers as Resident, promoted by an Administrator; holds `users.manage_residents` (approve/activate/deactivate RESIDENT accounts only — no role changes, no profiles, no non-residents); remains in the endorser pickers |
| 18 | Runtime settings | Admin → Settings (`settings.manage`, Admin-only default): SMTP, VAPID, reminder times stored in `app_settings`, secrets encrypted + write-only, changes audited by key name only, values override .env at boot |
| 19 | Registration password policy | `Password::min(8)->mixedCase()->numbers()->symbols()`, mirrored 1:1 by the page's live checklist |

## P1c-1 rulings (2026-08-09)

| # | Decision | Ruling |
|---|---|---|
| 20 | Annual promotion target | Chosen by the operator, explicit, every time — never inferred. There is no `levels.terminal` column and no `Level::nextAfter()` method; P1b Owner Decision A restated as the screen it was written for |
| 21 | Contact visibility | `email` AND `phone` together behind a two-valued department setting (`institutions.contact_visibility`: `admins` default, `members`) — one decision (`PersonPolicy::viewContact()` / `ContactVisibility::membersMaySeeContact()`), never one field governed and the other forgotten; `notes` behind neither value — always `people.manage`-only. Amended 2026-08-10: P1d Task 7 put `email` behind the gate when the rota grid became the first `viewContact` holder narrower than `people.manage`, and this row still named `phone` alone |

## P1d-1 rulings (2026-08-10)

| # | Decision | Ruling |
|---|---|---|
| 22 | Master-rota assignment shape | One table, one row shape: `starts_on`/`ends_on` NOT NULL on every row, both bounds inclusive. No nullable "whole period" range, no parent/child span pair. Overlaps for one person in one period are refused by the model; gaps are allowed and counted, never silently invisible |
| 23 | `vacations` keying | Own table, keyed on `person_id` plus a date range — deliberately NO `period_id`. A vacation is an overlay, crosses period boundaries, and must survive a department regenerating or switching its period system |
| 24 | Soft delete on rota tables | Neither `master_rota_assignments` nor `vacations` soft-deletes. Both are schedule structure, not clinical rows; the hash-chained `audit_log` is the history. A mistaken clear has no UI undo in P1d-1 |
| 25 | MR-04 on-call eligibility | Stage 2, not P1d. Nothing in the rota infers eligibility — no `off_roster` flag, no call-roster derivation, no per-person include/exclude override. P1d ships the rota's data and screens and records the hook only |
| 26 | Master-rota publish state | None, by decision (Decision D). Munawib's own `masterRota` document carries no `status` field, unlike `schedules`; MR-05's read view (P1d-2) is a `cap:rota.view` screen showing the current rota, not a draft/publish state machine. Revisit if the owner wants an explicit gate — additive, not a rework. **Superseded by ruling 27**: the revisit was offered and declined, so this is no longer "not built yet, revisit" but "decided against" |

## P1d-2 rulings (2026-08-10)

| # | Decision | Ruling |
|---|---|---|
| 27 | Publish gate on the master rota | **None, and the question is CLOSED** — not deferred. Ruling 26 and design §14 item 19 left an explicit "not visible until I say so" gate listed as a real, unbuilt product option; the owner answered it before P1d-2 began. No `status` column, no `published_at`, no draft state, no publish action, no "visible from" date; `/rota` always shows the current rota. Asserted, not merely unimplemented (`RotaReadViewTest::test_there_is_no_publish_state_on_the_read_view`), and the whole `cap:rota.view` group is asserted GET-only over the router. A future gate would be a new decision reversing this one |
| 28 | `rota.manage` role default | **Administrator-only — REVERSING what P1d-1 shipped on 2026-08-09.** P1d-1 seeded it to Chief Resident as well (Munawib's Scheduler persona maps to no role here, and Chief Resident is the nearest fit); a department that wants it there now grants it from Access Control, one screen and no code change. `rota.view` is unchanged: seeded for every authenticated member, which is exactly why no rota surface may project a contact field. **An already-seeded instance KEEPS the old grant** — `AccessControlSeeder` applies each (position, capability) default once via `applied_role_defaults` and never re-asserts, so the remedy is an operator un-tick (`docs/RUNBOOK-DEPLOY.md`) and there is deliberately **no migration**: revoking a capability an administrator may since have kept on purpose is what that seeder's design exists to refuse |
| 29 | `week`-granularity leave on import | Snapped to whole weeks on import **exactly as on the booking screen**, and by the **same code path** — `App\Support\Rota\VacationBooking::snap()`, extracted from `book()` and called by both, never a parallel rule re-typed in the importer. The file's `granularity` column is authoritative and the preview reports the adjustment, so a snap is never silent. (P1d-1 ruling restated for the plan it was written for) |
