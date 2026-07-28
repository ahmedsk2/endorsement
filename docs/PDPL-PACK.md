# PDPL governance pack — DRAFT

**Status: prepared 2026-07-27 for review. Not legal advice.**

Every technical claim here is drawn from the code and cross-referenced, so the parts that
describe *the system* should be accurate and checkable. The parts that name **people,
decisions and timeframes** are marked **`[DECIDE]`** and are yours — nobody can supply them
from the repository, and an auditor will ask who made each one.

Take this to whoever owns compliance at Qatif Central Hospital. Most of it will fold into
the hospital's existing programme rather than standing alone: this system processes hospital
data, so its lawful basis, retention rule and breach path should be the hospital's, not a
separate regime invented for one application.

Scope: the Paediatric Endorsement system at `endorse.towardpcc.com` — shift handover for
PICU, NICU, SCBU and WARD.

---

## 0. What makes this high-risk, in one paragraph

It processes **health data about children**: name, MRN, date of birth and clinical
narrative. Under PDPL that is *sensitive personal data*, and data subjects who cannot
consent for themselves raise the bar again. That combination is why a DPIA is not optional
here, and why "we're a small internal tool" is not an argument that survives contact with a
regulator.

---

## 1. Data protection officer

PDPL and its Implementing Regulations require a DPO where processing sensitive personal
data is a core activity. Handover of paediatric clinical records is exactly that.

**ANSWERED, 2026-07-28: the hospital's position is that a formal DPO is not required.**

That conclusion is recorded here rather than left as a silence, because a documented "we
assessed this and concluded no" is audit evidence and a blank is not. Two things follow from
it, and both should be checked with whoever gave that answer:

- **`[CONFIRM]` The basis.** PDPL's Implementing Regulations tie the requirement to
  processing sensitive personal data as a *core activity*. This system processes health data
  about children, so the conclusion is not self-evident from the data alone — it presumably
  rests on scale, or on the hospital treating this as one small part of a larger clinical
  function. Write down which, in one sentence. That sentence is what an auditor is asking for.
- **`[CONFIRM]` Who answers instead.** "No DPO" cannot mean "no one". Breach declaration
  already routes to hospital compliance (§6), so name that function here too, so the pack
  does not point at a role it has just said does not exist.

**Independence still matters even without the title.** One person is currently owner,
administrator and developer of this system. Whoever reviews it should not be that person —
not because of any doubt about them, but because a control nobody independent ever looks at
is indistinguishable from one that does not work.

---

## 2. Record of processing activities (ROPA)

Filled in from the code. Check it rather than retype it.

| Field | Value |
|---|---|
| Controller | **`[DECIDE]`** — Qatif Central Hospital, Department of Paediatrics (confirm the legal entity) |
| Processor(s) | Oracle Cloud Infrastructure (hosting, `me-riyadh-1`); Cloudflare (CDN/WAF); **`[DECIDE]`** SMTP provider once chosen |
| Purpose | Recording and handing over the clinical state of admitted children between shifts; a contemporaneous clinical record |
| Lawful basis | **`[DECIDE]`** — expected to be provision of healthcare under the hospital's existing basis, *not* consent. Consent is the wrong basis for a clinical record: it cannot meaningfully be withdrawn mid-admission |
| Categories of data subject | Admitted paediatric patients; clinical staff (users) |
| Personal data — patients | Name, MRN, date of birth or age, bed/room, unit, and four free-text clinical fields (problem list, clinical condition, plan of care, follow-ups) |
| Personal data — staff | Full name, username, email address, role, handwritten signature image, last sign-in, audit trail of actions and IP address |
| Sensitive data | Yes — health data concerning children |
| Recipients | Clinical staff holding `endorsement.view`; printed sheets leave the system on paper |
| Cross-border transfer | **None for the record itself** — hosted in-Kingdom (OCI Riyadh). Note: Cloudflare terminates TLS at an edge that may be outside the Kingdom, and any non-Saudi SMTP provider handles staff email addresses (never patient data). **`[DECIDE]`** whether the hospital treats those as transfers requiring an Art. 29 assessment |
| Retention | See §4 |
| Security measures | See `docs/COMPLIANCE.md` — the full technical list, kept current with the code |

---

## 3. Data protection impact assessment (DPIA)

### 3.1 Necessity and proportionality

The alternative to this system is the one it replaced: handover on paper and in memory. The
risk being mitigated — an unrecorded or lost handover — is a patient-safety risk, and the
data collected is the minimum a receiving clinician needs to take over care safely. No
field exists for analytics, billing or research.

**CONFIRMED by the owner, 2026-07-28: no data from this system is used for any secondary
purpose.** Not for research, not for audit statistics, not for teaching material, not for
performance measurement of individuals.

This is load-bearing for the assessment, so it is worth stating why rather than just
recording it. Purpose limitation is what makes the rest of the DPIA hold together: the
retention period can be the clinical one because there is no analytical copy outliving it;
the lawful basis can be provision of healthcare because nothing is being done that a patient
would not expect from their own care record; and no consent question arises for a secondary
use, because there is none.

**If that ever changes it is a NEW processing activity** — a new ROPA row, its own lawful
basis, and very likely its own DPIA. The obvious candidates to watch for are the ones that
arrive without anyone thinking of them as "data use": a service-improvement audit, a
teaching set of real handovers, or a report of who signs off late.

### 3.2 Risks identified, and what answers each

| Risk | Control | Residual |
|---|---|---|
| Unauthorised access to the record | Authentication + capability on every route; second factor required for privileged accounts; accounts created only by invitation from an Administrator or Chief Resident | Low |
| A clinician reading records they have no involvement with | **Accepted deviation — see §3.3.** Reads are audited and swept for anomalies, but not prevented | **Medium — the main accepted risk** |
| Data exposed at rest | MRN, name, DOB and all four clinical fields encrypted at rest with a key held outside the database; backups encrypted; volume encryption at the provider | Low |
| Data exposed in transit | TLS only; HSTS; origin reachable only via Cloudflare | Low |
| Loss of the record | Nightly encrypted backup, verified by decrypting; off-host copy to in-Kingdom object storage; restore drill **`[DECIDE]`** quarterly | Low |
| Tampering with the record | Clinical rows soft-deleted, never removed; every change written to an append-only, HMAC-chained audit log verified hourly; prior values retained | Low |
| A signature on a sheet asserting more than it should | Owner ruling — a handwritten signature is applied only by that clinician, or by an Administrator/Chief Resident acting for them; everyone else prints as a typed name; provenance recorded in the trail | **Medium — accepted, see `docs/COMPLIANCE.md`** |
| PHI escaping into logs, URLs or alerts | Enforced by rule and by tests: audit details, push payloads and operational alerts carry ids, field names and counts only | Low |
| An account left active after someone leaves | **`[DECIDE]`** — this is a *process* gap, not a technical one. The system can deactivate instantly; somebody has to be told to do it. See §6 |
| Paper leaving the ward | Out of the system's control. Sheets carry an attribution footer; printing is audited | **`[DECIDE]`** — hospital paper-handling policy applies |

### 3.3 Accepted deviations

Two, both recorded with reasoning in `docs/COMPLIANCE.md`:

1. **No unit scoping.** Every clinical account can read, write and sign off all four units,
   because the residents cover all four concurrently. Compensated by approval-gated account
   creation and audited reads.
2. **Signature by proxy for two roles.** As above.

**`[DECIDE]`** Both need a named person to own them at the next review.

### 3.4 Conclusion

**`[DECIDE]`** — the DPIA's conclusion is a judgement, not an output. Expected form: *the
residual risks are acceptable given the patient-safety benefit and the controls listed, and
will be reviewed [when]*. Sign and date it. An unsigned DPIA is a draft, and a draft is what
a regulator treats as "not done".

---

## 4. Retention schedule

The system already disposes of operational data automatically (`data:retention`, nightly).
What it does **not** do is delete clinical records — deliberately, because their retention
period is the hospital's medical-records rule, not this application's.

| Data | Retention | Enforced by |
|---|---|---|
| Handover rows (clinical) | **`[DECIDE]`** — per the MOH medical-records schedule the hospital already follows. Likely measured in years | Not automated. Soft-deleted only; nothing removes them |
| Audit log | ≥ 12 months hot, and never truncated in place — it is append-only and hash-chained | `data:retention` leaves it alone by design |
| Abandoned registrations / expired invitations | 30 days | `data:retention` |
| One-time sign-in codes | On use or expiry | `data:retention` |
| Idle sessions | 60 minutes | Session lifetime |
| Trusted devices ("don't ask for 7 days") | 7 days, and immediately on password change or disabling the factor | `TrustedDevice` |
| Backups | 14 archives locally, plus the off-host copy — **`[DECIDE]`** how long the off-host copy is kept, and whether an object-lock rule enforces it |

**`[DECIDE]`** The clinical retention number is the one gap that matters. Until it is
written down, "how long do you keep it?" has no answer.

---

## 5. Privacy notice

Two audiences, and they are not the same document.

- **Staff** — what the system records *about them*: their name and signature on handovers
  they sign, and an audit trail of what they viewed, printed, edited and signed, with the
  time and source address. **They should be told this plainly before they are invited**,
  because "activity on this system is recorded" on the sign-in page is a warning, not a
  notice. **`[DECIDE]`** — draft below, needs the hospital's wording and a delivery method.
- **Patients and guardians** — almost certainly covered by the hospital's existing notice,
  since this is part of the clinical record rather than a separate collection.
  **`[DECIDE]`** — confirm with compliance; do not write a second patient-facing notice
  unless they say one is needed.

> **Draft staff notice.** This system records the handover you write and the actions you
> take in it. Specifically: your name and, if you sign a handover, your signature appear on
> that record and on printed copies of it; and every action you take — viewing, printing,
> editing, signing and reopening a handover — is written to a tamper-evident log with the
> time and the network address you acted from. This exists so that a clinical record can be
> shown to be accurate and attributable, which protects you as much as the patient. The log
> is retained for at least 12 months. You can ask [**`[DECIDE]`** contact] what is recorded
> about you.

---

## 6. Breach procedure

PDPL requires notification to SDAIA **within 72 hours of becoming aware**, and notification
to affected data subjects where the breach is likely to cause them serious harm. Awareness
is the clock's start — which is why detection matters as much as response.

**What the system already does:** verifies the audit chain hourly, sweeps for anomalies
(bulk reads, bulk printing, repeated refusals, repeated failed second factors), and escalates
each failure to an operational alert. Reads are audited, so "what did this account see?" has
an answer.

**`[DECIDE]` — the parts no code can supply:**

1. **Who decides it is a breach — ANSWERED, 2026-07-28: hospital compliance.** The
   declaration, the SDAIA notification and any notification to families go through the
   hospital's existing incident process; the system's administrator raises it to them and
   supplies the evidence. This is the answer that scales: it does not depend on one clinician
   being reachable, and family notification is done by people who already do that work.
   **`[CONFIRM]`** the contact point and how it is reached out of hours — a process that only
   works on a weekday is not a process, and the 72 hours runs from awareness.
2. **Who notifies SDAIA**, and from which account.
3. **Who tells affected families**, and with the hospital's involvement — not the system's
   administrator acting alone.
4. **Where the incident log lives**, including breaches judged *not* notifiable and why. An
   auditor asks to see the ones you decided against.
5. **A dry run.** Walk it through once on a hypothetical — "an account was left active for
   a doctor who left, and it read 200 records" — and time it. A procedure nobody has
   rehearsed is a document, not a capability.

**The leaver process — ANSWERED, 2026-07-28: a periodic review of active accounts.**

The system cannot be told when someone leaves, so it is checked instead. This is the more
robust of the two options: a notification that is never sent is invisible, whereas a review
that is skipped is a missed diary entry somebody can notice.

**Supported in the product as of 2026-07-28**: Admin → Users now shows *Last signed in* for
every account, and flags anything dormant 90 days or more. That column existed in the
database since July and was displayed nowhere, which meant an account belonging to someone
who left six months ago looked exactly like one in daily use.

**`[CONFIRM]`** the cadence and who does it. Monthly, or at each rotation changeover, are
the two that fit a department like this — rotation changeover has the advantage of coinciding
with when people actually leave. Record the date of each review; the review is the control,
and an unrecorded control is one you cannot show anyone.

---

## 7. Data subject rights

| Right | How it is served today |
|---|---|
| To be informed | §5 |
| Access | Manual — a request is served by an Administrator. **`[DECIDE]`** who handles it and within what timeframe |
| Correction | Clinical corrections go through the normal record path: reopen a signed handover with a reason, which is audited. The prior value is retained rather than overwritten |
| Destruction | **Constrained by law, not by the system.** A clinical record cannot generally be erased on request while the hospital's retention obligation stands. **`[DECIDE]`** — record that position and its basis, so a refusal is a policy, not an improvisation |

---

## 8. Review

**`[DECIDE]`** — a date and an owner. Annually is the usual answer; also review on any of:
a change to who can see which units, a new category of data, a new processor, a breach, or
the system extending beyond the Department of Paediatrics.

The technical half of this pack is generated from a codebase that changes — treat
`docs/COMPLIANCE.md` as the live document and re-check this one against it at each review.
