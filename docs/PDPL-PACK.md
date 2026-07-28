# PDPL governance pack — DRAFT

**Status: SIGNED 2026-07-28 by Ahmed Al Khalifah, system owner (§3.4).**

Outstanding: a small number of confirmations with the hospital, each marked `[CONFIRM]`, and
the first restore drill. Neither blocks the assessment — the confirmations record reasoning
the hospital already holds, and the untested restore is stated as a Medium residual risk in
§3.2 rather than glossed.

Not legal advice.

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
| Controller | **Qatif Central Hospital** (owner, 2026-07-28). This system is one application within it, not a separate controller |
| Processor(s) | Oracle Cloud Infrastructure (hosting + backup object storage, `me-riyadh-1`, **in-Kingdom**); Cloudflare (CDN/WAF, edge outside the Kingdom); mail relay at `mail.towardpicu.com` — the owner's own Google Cloud VM in **`me-central2`, Dammam, also in-Kingdom** (confirmed 2026-07-28; rDNS `243.69.212.35.bc.googleusercontent.com`, serving a valid `*.towardpicu.com` certificate). Not a third-party provider: no external party reads this mail |
| Purpose | Recording and handing over the clinical state of admitted children between shifts; a contemporaneous clinical record |
| Lawful basis | **Provision of healthcare, under the hospital's existing basis** (owner, 2026-07-28). Explicitly NOT consent: a clinical record a patient could withdraw mid-admission is not a clinical record, and a consent that would not be honoured is not valid consent |
| Categories of data subject | Admitted paediatric patients; clinical staff (users) |
| Personal data — patients | Name, MRN, date of birth or age, bed/room, unit, and four free-text clinical fields (problem list, clinical condition, plan of care, follow-ups) |
| Personal data — staff | Full name, username, email address, role, handwritten signature image, last sign-in, audit trail of actions and IP address |
| Sensitive data | Yes — health data concerning children |
| Recipients | Clinical staff holding `endorsement.view`; printed sheets leave the system on paper |
| Cross-border transfer | **None.** Every store of personal data is in-Kingdom: the clinical record in OCI Riyadh, the backups in OCI object storage, and the mail relay on the owner's own VM in Dammam. The only element outside the Kingdom is **Cloudflare's edge**, which terminates TLS in transit and stores nothing — no personal data at rest leaves Saudi Arabia |
| Retention | See §4 |
| Security measures | See `docs/COMPLIANCE.md` — the full technical list, kept current with the code |

### 2.1 What the mail path actually carries — verified, not asserted

The SMTP ruling above rests on a factual claim, so it is checked rather than taken on trust.
Everything this system can send by email:

| Message | Contents |
|---|---|
| `InvitationMail` | A one-time link and its expiry. No role, no inviter, no unit |
| `LoginOtpCode` | A six-digit code |
| `VerifyEmailAddress` | A signed confirmation link |
| `OpsAlertMail` | A job name, a timestamp and the host — and it says so on its face |
| `TestMail` | A timestamp and the host |

Plus the recipient's own address. A repo-wide search of `app/Mail/`, `app/Notifications/`
and the mail views for any patient field (`patient_name`, `mrn`, `dob`, and the four
clinical fields) returns **no reference in any outbound message** — the single match is a
comment in `OpsAlertMail` stating that it never carries them.

**What that means, now that the relay is located:** the question is moot rather than
answered. The owner's earlier ruling — that a mail provider outside the Kingdom would be
acceptable because it never handles patient data — turns out not to be needed: the relay is
his own VM in Dammam (`me-central2`), so nothing crosses a border and no third party reads
it. The verification above is kept anyway, because it is the thing that would matter if the
relay were ever moved or replaced, and that is a change nobody would think to re-assess. Re-check this table if a new message type is ever added, or if the relay is ever moved
outside the Kingdom — either change would quietly invalidate the position above without
anyone noticing, because neither looks like a data-protection decision at the time.

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
| Loss of the record | Nightly encrypted backup, verified by decrypting; off-host copy to in-Kingdom object storage (7 objects, verified 2026-07-28); **quarterly restore drill by the system owner** | **Medium until the first drill runs.** As of 2026-07-28 no archive has ever been restored, so what is proven is that backups are *written and decryptable*, not that they can be turned back into a working database. The gap is deliberate and time-boxed, not overlooked |
| Tampering with the record | Clinical rows soft-deleted, never removed; every change written to an append-only, HMAC-chained audit log verified hourly; prior values retained | Low |
| A signature on a sheet asserting more than it should | Owner ruling — a handwritten signature is applied only by that clinician, or by an Administrator/Chief Resident acting for them; everyone else prints as a typed name; provenance recorded in the trail | **Medium — accepted, see `docs/COMPLIANCE.md`** |
| PHI escaping into logs, URLs or alerts | Enforced by rule and by tests: audit details, push payloads and operational alerts carry ids, field names and counts only | Low |
| **A backup silently stops running** | **ACCEPTED, 2026-07-28.** The monitoring service in use offers HTTP monitors only, not heartbeats, so nothing alarms if the nightly backup simply stops. The site being up says nothing about whether a backup ran. Compensated by the quarterly restore drill and by `backup:run` escalating a FAILURE to an operational alert — what is not covered is the backup that never starts at all | **Medium — accepted, revisit if a heartbeat becomes available** |
| An account left active after someone leaves | **Periodic review of active accounts** (§6), supported by a *Last signed in* column on Admin → Users that flags anything dormant 90 days or more | **Medium — depends on the review actually happening** |
| Paper leaving the ward | Out of the system's control. Sheets carry an attribution footer; printing is audited | **The hospital's existing paper-handling policy applies** — this system does not create a separate regime for printed clinical records |

### 3.3 Accepted deviations

Two, both recorded with reasoning in `docs/COMPLIANCE.md`:

1. **No unit scoping.** Every clinical account can read, write and sign off all four units,
   because the residents cover all four concurrently. Compensated by approval-gated account
   creation and audited reads.
2. **Signature by proxy for two roles.** As above.

Both are owned by the system owner, who signs this assessment (§3.4), and are reviewed at the annual review in §8.

### 3.4 Conclusion and sign-off

**Conclusion.** The residual risks identified above are acceptable given the patient-safety
benefit of a recorded, attributable shift handover and the controls listed in §3.2 and in
`docs/COMPLIANCE.md`. Three risks are accepted rather than eliminated and are named as such:
unrestricted access across the four units, signature-by-proxy for two roles, and the absence
of monitoring for a backup that stops running. Each has a stated compensating control and a
trigger that reopens it.

**Signed:**

| | |
|---|---|
| Name | **Ahmed Al Khalifah** |
| Role | System owner, Paediatric Endorsement |
| Date | **28 July 2026** |
| Signature | Recorded in version control — see the commit that added this block |

The signature is the commit rather than an image. That is deliberate and it is stronger
here: a scanned signature is stapled to a document and says nothing about what the document
said at the time, whereas the commit fixes this exact text, to this name, at this date, in a
history that cannot be altered without leaving a trace. If the hospital's process requires a
wet signature as well, print this section and sign it; the two do not conflict.

**One thing to note beside that signature**, because an auditor will: the signatory is also
the system's owner, administrator and the person who commissioned it. That is normal for a
departmental application of this size and it does not invalidate the assessment — but it
does mean no independent party has reviewed these risks. If the hospital's compliance
function is willing to countersign, that closes the only structural weakness in this
document. It is recorded here rather than left for someone else to notice.

---

## 4. Retention schedule

The system already disposes of operational data automatically (`data:retention`, nightly).
What it does **not** do is delete clinical records — deliberately, because their retention
period is the hospital's medical-records rule, not this application's.

| Data | Retention | Enforced by |
|---|---|---|
| Handover rows (clinical) | **4 years — the hospital's own policy for these records** (owner, 2026-07-28) | Not automated. Soft-deleted only; nothing removes them |
| Audit log | ≥ 12 months hot, and never truncated in place — it is append-only and hash-chained | `data:retention` leaves it alone by design |
| Abandoned registrations / expired invitations | 30 days | `data:retention` |
| One-time sign-in codes | On use or expiry | `data:retention` |
| Idle sessions | 60 minutes | Session lifetime |
| Trusted devices ("don't ask for 7 days") | 7 days, and immediately on password change or disabling the factor | `TrustedDevice` |
| Backups | 14 archives locally, plus the off-host copy — **verified working 2026-07-28: 7 objects in `oci:endorsement-backups`, synced nightly at 02:05**. A **30-day retention rule** is in force on that bucket (`endorsement-30d-no-delete`, applied 2026-07-28): an archive cannot be deleted or overwritten for 30 days after it is written, so a mistake or a compromised credential cannot erase the recovery window. The rule is deliberately **UNLOCKED** — see §4.2 |

### 4.2 Object lock — why it is unlocked

The rule prevents deletion for 30 days. It does not prevent *removal of the rule* by someone
holding permissions on the bucket, because it was created unlocked.

That is a deliberate stopping point rather than an oversight. **Locking a retention rule in
OCI is irreversible** — once locked, neither an attacker nor the account owner can remove it
until it expires, and a mistake in the duration becomes permanent. Unlocked protects against
the two things that actually happen (an accidental delete, a script gone wrong, ransomware
that finds the storage credentials and tries to erase the copies); locked additionally
protects against a determined attacker who has both the credentials AND the patience to
delete the rule first.

**`[CONFIRM]`** whether to lock it. Worth doing eventually, worth doing on purpose, and not
worth doing as a side effect of a conversation about something else.

### 4.1 On the retention figure

**4 years, inherited from the hospital's existing policy for these records** — not a number
invented for this system. That distinction is the whole of it: a handover is part of the
clinical record, so its retention should be whatever the hospital already applies, and a
system-specific figure that quietly differed from the hospital's would be the actual problem.

**Nothing acts on it today.** Clinical rows are soft-deleted and no job removes them, so the
figure is currently a documented policy rather than an enforced one. If automated disposal
is ever built, it should be built against this figure and audited when it runs — and given
what it destroys, it should be reviewed once more before it is switched on.

---

## 5. Privacy notice

Two audiences, and they are not the same document.

- **Staff — DONE IN THE PRODUCT, 2026-07-28.** The notice is shown on the
  invitation-acceptance page (*before* the account exists) and again in first-login setup.
  It states what is recorded — the name and signature on handovers they sign, and which
  actions are logged — for how long, that the log cannot be altered even by an
  administrator, and who to ask.

  Delivered BY THE SYSTEM rather than circulated, on purpose: a notice that depends on
  somebody remembering to send it is a notice some people never received, and the person
  who never received it is the one who later disputes what was recorded. It is a single
  component used in both places, because a notice maintained in two copies eventually says
  two different things. `resources/js/Components/StaffPrivacyNotice.vue`.

  **Approved as written by the system owner, 2026-07-28.** The text below is what is live.
- **Patients and guardians** — almost certainly covered by the hospital's existing notice,
  since this is part of the clinical record rather than a separate collection.
  **Position taken, 2026-07-28: covered by the hospital's existing patient notice.** This
  system is part of the clinical record rather than a separate collection, so a second
  patient-facing notice would duplicate — and eventually contradict — the hospital's.
  **`[CONFIRM]`** with compliance.

> **Draft staff notice.** This system records the handover you write and the actions you
> take in it. Specifically: your name and, if you sign a handover, your signature appear on
> that record and on printed copies of it; and every action you take — viewing, printing,
> editing, signing and reopening a handover — is written to a tamper-evident log with the
> time and the network address you acted from. This exists so that a clinical record can be
> shown to be accurate and attributable, which protects you as much as the patient. The log
> is retained for at least 12 months. You can ask an administrator of this system what is
> recorded about you.

---

## 6. Breach procedure

PDPL requires notification to SDAIA **within 72 hours of becoming aware**, and notification
to affected data subjects where the breach is likely to cause them serious harm. Awareness
is the clock's start — which is why detection matters as much as response.

**What the system already does:** verifies the audit chain hourly, sweeps for anomalies
(bulk reads, bulk printing, repeated refusals, repeated failed second factors), and escalates
each failure to an operational alert. Reads are audited, so "what did this account see?" has
an answer.

**Decided 2026-07-28; the remainder are `[CONFIRM]` with the hospital:**

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

**Cadence: annually, by the system owner** (2026-07-28), alongside the review in §8.

**A weekly automated check backs it up**, because annually alone is a long time for a
departed clinician's account to stay live — up to twelve months, in a department with
rotating residents. `users:dormant` runs every Monday at 08:00 and raises an operational
alert for any ACTIVE account not signed into for 90 days, or never signed into at all more
than 90 days after it was created.

It prompts; it never deactivates. A doctor back from long leave finding their account
disabled is a worse failure than the one being prevented, and that is a judgement for a
person. The alert carries **ids and day counts only** — a list of who is absent from a
children's hospital does not belong in an external mailbox.

Record the date of each annual review: the review is the control, and an unrecorded control
is one you cannot show anyone.

---

## 7. Data subject rights

| Right | How it is served today |
|---|---|
| To be informed | §5 |
| Access | **Through the hospital's existing process** (owner, 2026-07-28); an Administrator supplies what this system holds. Consistent with the breach path in §6 — one route in, not two. **`[CONFIRM]`** the response timeframe the hospital already works to |
| Correction | Clinical corrections go through the normal record path: reopen a signed handover with a reason, which is audited. The prior value is retained rather than overwritten |
| Destruction | **Refused while the retention obligation stands** (owner, 2026-07-28). PDPL's erasure right does not override a legal retention duty, and a clinical record is held under one. Recorded in advance deliberately: this is the one right where the answer is usually "no", and a refusal improvised under pressure reads very differently from a stated policy. Requests still go to the hospital (above), which decides whether this case is the exception |

---

## 8. Review

**Annually in OCTOBER, by the system owner** (2026-07-28). Put it in a calendar; a review
that lives in an intention is one that happens in the year somebody remembers.

Worth knowing what an annual-only cycle accepts: a material change made in month two is not
reviewed until month twelve. The changes that would matter most are a change to who can see
which units, a new category of data, a new processor, a breach, or this system extending
beyond the Department of Paediatrics. None of those is required to trigger a review under
the choice made — but if one happens, this is the document to reopen early.

The technical half of this pack is generated from a codebase that changes — treat
`docs/COMPLIANCE.md` as the live document and re-check this one against it at each review.
