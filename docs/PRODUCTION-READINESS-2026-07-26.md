# Production readiness review — 2026-07-26

Target: the Paediatric Endorsement system, live at https://endorse.towardpcc.com.
18 categories assessed by three independent auditors against the `prod-ready` methodology.

## Verdict: 🔴 BLOCKED — on two owner actions, not on code

Both blocking Criticals are already items 1 and 6 of `docs/OWNER-CHECKLIST.md`. Nothing in
the application code blocks go-live. Clear those two and this becomes 🟡 NEEDS FIXES with a
clear path to green.

| # | Category | Score | Note |
| --- | --- | --- | --- |
| 01 | Reliability, SLOs & error budgets | 0 | No SLO exists — mostly *intake*, not failure |
| 02 | Observability | 36 | No APM, no metrics, no uptime monitor |
| 03 | Security | 68 | 🔴 one unwaived Critical (unrotated tokens) |
| 04 | Testing & quality | 67 | Test *content* exemplary; missing CI gates cost the points |
| 05 | CI/CD & release | 19 | No immutable artifact; rollback rebuilds |
| 06 | Performance & scalability | 35 | No limits, no load evidence |
| 07 | Resilience & fault tolerance | 17 | Single VM/container/DB, no failover |
| 08 | Data, backups, DR | 49 | 🔴 Critical: backups only on the VM they back up |
| 09 | Config, secrets & environments | 85 | 🟢 Strong |
| 10 | Infra & containers | 0 | Small denominator — 5 of 10 checks apply |
| 11 | Compliance & privacy | 43 | 100% of the deduction is PDPL paperwork, not code |
| 12 | Cost & sustainability | 100 | 🟢 |
| 13 | Docs & runbooks | 55 | Runbooks strong; on-call/incident absent |
| 14 | Ops, on-call & incident | 22 | No rota, no escalation target |
| 15 | Visual & accessibility | 48 | Two Highs — contrast |
| 16 | AI/LLM | **N/A** | No LLM dependencies anywhere in the tree |
| 17 | API contract & lifecycle | **N/A** | No `routes/api.php`, no `api:` routing, sole client compiled into the same image |
| 18 | i18n & text | 77 | Not N/A — Arabic patient names in an LTR UI |

## The two blockers

**DATA-02 (Critical) — the only backup lives on the machine it backs up.** One instance
loss, boot-volume corruption or a stray `docker volume prune` destroys the paediatric
clinical record *and* all 14 archives in the same event. The backup itself is genuinely
good — encrypted, self-verifying, drill-tested — it just has no second home.
→ `docs/OWNER-CHECKLIST.md` §6.

**SEC-01 (Critical) — the two exposed Coolify tokens are not yet rotated.** One is
deploy-capable and both read the Coolify environment store, which holds `APP_KEY` — so they
decrypt every child's PHI and every backup. The repository and git history are otherwise
clean. This reverts to a pass the moment rotation is confirmed.
→ `docs/OWNER-CHECKLIST.md` §1.

## Highest-value fixes after that

1. **An escalation target that a human reads.** `routes/console.php` escalates audit-chain
   tampering and backup failure to `Log::critical`, but `LOG_CHANNEL=stderr` with no mail,
   webhook or on-call destination configured. The system can detect that it produced no
   recoverable backup and tell nobody. Cheapest real fix: a mail channel to a monitored
   address once SMTP is configured, plus an uptime monitor that alerts if the 07:30 window
   passes with the site down.
2. **Text contrast fails WCAG AA in the primary label mechanism.** `--color-muted` measures
   3.68:1 on white and 3.30:1 on ground against a 4.5:1 requirement, and it drives
   `.channel-tag` (92 uses) and `text-muted` (63 uses); `text-ok` and `text-caution` on
   their soft tints measure 3.66:1 and 3.23:1. `Login.vue` already diagnoses this exact
   pattern and fixes one instance — six others were left. This matters for clinicians
   reading a census at speed on a ward screen. **Needs a design decision** (darkening the
   token changes the visual identity), so it is proposed rather than applied. Independently
   re-measured; candidates, keeping the same hue:

   | value | ratio on white | |
   | --- | --- | --- |
   | `#6b8b8e` (current) | 3.68:1 | fails AA |
   | `#5f7d80` | 4.44:1 | still fails — just short |
   | `#5a777a` | 4.82:1 | passes, smallest visual change |
   | `#567376` | 5.11:1 | passes, comfortable margin |

   `#5a777a` is the smallest change that clears AA. The same treatment is needed for
   `text-ok` (3.66:1) and `text-caution` (3.23:1) on their soft tints.
3. **No immutable deployment artifact.** Coolify's "redeploy last good" *rebuilds* from
   source rather than restoring known bits, so a rollback can ship an image that never
   existed and was never tested. A registry with digest-pinned images fixes this.
4. **The production VM is also the build plane.** `docker build` runs as root on the same
   daemon as the patient database, from unpinned base tags.
5. **No `USER` in the Dockerfile** — PID 1, supervisord and the nginx/php-fpm masters run
   as root; only the workers drop to `app`. Defence-in-depth, but flagged independently by
   two auditors.

## What is genuinely strong

- **PHI-in-logs hygiene is exemplary** — verified across all 48 log and audit call sites.
  `QueryException` redaction, `dontFlash` on every clinical field, audit details restricted
  to ids and counts. This is the control most systems get wrong and it holds throughout.
- **Test content is excellent** where it exists: sign-off locking, capability enforcement,
  PHI-encryption-at-rest read against raw columns, and an autosave doctrine that asserts
  persistence after reload rather than trusting the indicator.
- **`BackupRun` verifies its archive is a real dump of *this* database**, not merely that a
  file decompressed.
- **`docker/smoke.sh` and `scripts/verify-live.sh` are real, incident-derived harnesses** —
  every assertion in them exists because something actually broke.
- **Idempotency is correct by construction** everywhere it matters.
- **Config and secret handling scored 85** with nothing sensitive in the repo or its history.

## Method and limits

Static analysis of code and configuration, plus read-only HTTP against the live site. No
load test, no Lighthouse run, no live SLO backend — so performance and reliability scores
reflect *absence of evidence*, not measured failure. 13 items across the three groups are
recorded as **intake** (a process or artifact that cannot exist in a repository — on-call
rota, incident procedure, SLO agreement) rather than being failed. Category 10's score of 0
comes from a 5-check denominator and should be read alongside the fact that network
isolation and image-build hygiene were both assessed as genuinely good.
