# What is outstanding — snapshot, 19 August 2026

> **⚠ SUPERSEDED IN ITS HEADLINE, 2026-08-19 (later the same day).** Section 1 — "the release is
> built, tested and unshipped" — **was acted on and is DONE.** P1 deployed: 44 of 44 migrations,
> audit chain intact before and after, every screen 200/302. The table below still reads
> 22-of-44 and an image from 30 July; that was true when this was written and is not now.
>
> Sections 2 through 7 were NOT all acted on — the owner checklist was corrected, but alerting
> still has no destination, the Coolify deploy token is revoked-not-replaced in the ledger, and
> the PDPL items stand. **Read those as live; read section 1 as history.**

A dated work list, not a living document. Produced by sweeping the plans, the owner/compliance
docs, the code, the test suite and the operational surface, then verifying the load-bearing
claims against production directly. Delete it when it is worked through.

**Verified against production on 19 Aug**, not inferred:

| | |
|---|---|
| Production image | `8886f8d`, built 2026-07-30 20:57 |
| Repo HEAD | `ba20193`, 2026-08-19 |
| Migrations | 44 in repo, **22 applied**; last applied `2026_07_27_180001_create_trusted_devices_table` |
| Absent from the container | `Person.php`, `Calendar.php`, `RotaFill.php`, `ClinicWriter.php`, `docs/INVARIANTS.md` |
| Clinical use | 2 users, 2 handover rows, **0 sign-offs ever**, 0 signatures |
| Health | healthy; 0 crits/500s; audit chain intact (132 rows); backups nightly + off-host; uptime green |

---

## 1. THE ONE THING — the release is built, tested and unshipped

Three weeks of work — P0c (identity split into `people` + `users`), P0d (tenancy), P1a
(calendar), P1b (structure admin), P1c (roster + invitations), P1d (rota), P1e (clinics) —
exists only in git. `docs/DEPLOY-P1-2026-08-12.md` is a written runbook for exactly this and
records that **nothing in it has been executed**.

Everything else here is small by comparison. Three properties make it serious rather than
merely large:

- **It is a one-way door.** Migration `2026_08_10_120003` drops `users.full_name` and
  `users.position`. Past that point, redeploying the previous image yields a site that is up,
  reports healthy, and refuses every user — the failure mode that looks like success.
- **It takes a write lock on `handovers`** measured at ~147 seconds, and `lock_wait_timeout`
  defaults to a year: a save attempted during it queues rather than fails. Not at 07:30 or 15:30.
- **Rollback rebuilds from source on the production host.** No image is pushed to a registry,
  so recovery under incident conditions re-runs `composer install` and `npm ci` on the same VM
  as the clinical database.

**The mitigating fact: there is no clinical data.** 0 sign-offs, 2 throwaway rows. This is the
cheapest moment this deploy will ever have, and it gets dearer with every real handover written.

## 2. The owner-facing docs will actively block go-live

Not cosmetic. `docs/OWNER-CHECKLIST.md` section 8 still instructs the owner to have staff
**register themselves at `/register`** — closed in code on 2026-07-27. Following it, go-live
stalls on a step that cannot be performed: staff get a redirect, and nobody discovers that
Admin -> Invitations is the path. Section 7 sends the owner to close an exposure that no longer
exists, and the top banner still names the July one-column migration as the thing to run —
the wrong instruction for a release that crosses an irreversible line.

The deploy runbook's post-deploy check asks you to register a throwaway account to prove SMTP
works. That check cannot run, so SMTP goes unverified after the deploy that most needs it.

## 3. Nothing pages a human

The external monitor appends a line to a log file on the host and exits non-zero. There is no
notification channel. The 2026-07-27 outage is the precedent: 504 on every request, container
healthy throughout, discovered by a person.

- In-app escalation is log-only unless SMTP **and** `alert_email` are both live.
- The backup dead-man's switch cannot detect a *stale* backup: the freshness check only
  asserts the destination is non-empty.
- The handover reminder — a clinical-safety nudge — is the one scheduled job with no failure
  escalation, and it burns its per-slot idempotency marker *before* attempting delivery.

## 4. Verified corrections

- **The backup bucket retention rule EXISTS.** `endorsement-30d-no-delete`, 30 days, unlocked,
  created 2026-07-28 on `endorsement-backups` — confirmed via the OCI API on 19 Aug. Documents
  saying otherwise are wrong. What is genuinely inconsistent is the bucket NAME the docs cite
  (`state.env` says `coolify-backups`; the sync writes `endorsement-backups`).
- **The restore drill did run**, 2026-07-25 — but before patient data, before the signature
  archive existed, and before the `.meta.json` key file. The SQL half is proven; the signature
  half is not.

## 5. Code and schema leftovers

| Item | Consequence if never done |
|---|---|
| `pending_registrations` alive with no writer — model, table, 2 routed endpoints, admin panel | `approve()` is a second, non-invitation path that mints an ACTIVE account at any position |
| `users.member_email` dead but present, still holding its UNIQUE index | A future reader treats it as authoritative |
| `invitations` has no retention rule; `data:retention` never touches it | Staff email addresses accumulate forever — and the signed DPIA claims they are pruned at 30 days |
| Per-unit custom fields built end-to-end except the write path | The feature silently never arrives |
| `DemoSeeder`/`E2eSeeder` gated on `APP_ENV` only, rows unmarked | A DR rehearsal against restored patient data accepts them |

## 6. Test-suite gaps worth closing

Integrity is high — the clinical spine is covered, all 133 routes referenced, the eleven
single-writer guards audited by planting. Two real gaps:

- **No browser test signs off or reopens a day.** A route change applied to `routes/web.php`
  and to `HandoverSignoffTest` but not to the Vue component leaves both halves green.
- **CI is SQLite-only.** MySQL 8.4 has been exercised once, by hand — and that single run found
  a real migration defect and a test that had never asserted anything. `lockForUpdate()`
  compiles to an empty string in all ~1,680 tests.

## 7. Owner actions, unchanged

Rotate the leaked Coolify deploy token — still not done, and Coolify builds images **on the
production host**, so that token is arbitrary code execution beside the clinical database.
Reassess what the 2026-08-11 repository publication exposed (the docs publish the host IP and
the Coolify app UUID). Confirm the out-of-hours breach contact. Three PDPL `[CONFIRM]`s. The
DPIA needs re-signing for P0c, P0d and P1c-1 — and two later personal-data surfaces are missing
from that backlog: the rota CSV export of staff names, and the clinic screens.

---

## Suggested order

1. **Fix the owner checklist** (minutes) — it governs the next step and is currently wrong in
   a way that stops go-live.
2. **Deploy P1** per `docs/DEPLOY-P1-2026-08-12.md`, at a quiet hour, with the one-way-door and
   lock-window facts understood. Cheapest now; never cheaper again.
3. **Give alerting a destination** — an external monitor that pages, and confirm SMTP after the
   deploy by issuing a real invitation rather than the removed self-registration check.
4. **Then** the leftovers in sections 5 and 6, none of which are urgent.
