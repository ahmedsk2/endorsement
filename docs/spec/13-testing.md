# 13. Testing strategy

TDD throughout: failing test first, red observed, then implementation. Ported reference suites plus new coverage:

- **Feature:** auth (login/lockout/timing/expiry/2FA/reset/registration), access control (deny-wins, cache bust, seeder revocation persistence, self-lockout), audit chain + `audit:verify`, security headers, endorsement (index/sheet/rows/new-day/carry-dialog rules/bed sort/unit 404s/per-unit columns), sign-off (pickers, snapshots, time parsing, lock, reopen capability + reason + audit), sanitiser round-trip + attack corpus, missed-days computation (range boundaries, unit partition, no-sheet vs unsigned), push (subscription CRUD, payload PHI-free, scheduler selection), import (fixtures ×4 units, idempotency, provenance, data rules, reconciliation).
- **Build guards:** compiled CSS is light-only; print sheet keeps list markers.
- **JS (Vitest):** RichTextEditor command/styleWithCSS behaviour, SaveStatus states, Sheet autosave wiring, chooser status logic.
- **E2E (Playwright, loopback-only fixtures):** rich-text formatting survives save + reload; sheet journey on a mobile viewport; print page renders; a11y pass. E2E asserts on persistence, never on UI indicators alone.

---
