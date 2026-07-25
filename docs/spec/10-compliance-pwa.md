# 10. Compliance and PWA (the "forgetting" problem)

The owner's stated pain: residents forget to fill the endorsement and whole days are missed. Levers chosen: reminders, one-tap access, and visibility of gaps. Explicitly rejected: hard enforcement (breeds garbage-text workarounds), email digests, offline editing.

### 10.1 PWA

Web manifest + service worker caching the **app shell only — never patient data; no offline editing** (an offline queue is this domain's documented failure mode). Offline shows a clear "you're offline" screen. Installed app opens at `/endorsement/today` → redirects to the current date's sheet for the user's remembered unit (chooser if none).

### 10.2 Reminders — in-app + web push [RULING]

- Scheduled job a few minutes after each handover time (07:30/13:30 Asia/Riyadh, config-driven): for each unit whose today-sheet is missing or unsigned, push to users opted in for that unit.
- Payload strictly `unit + date + status` — never patient data.
- Opt-in per unit on the profile page; VAPID subscriptions in `push_subscriptions`. Works for installed PWAs on iOS 16.4+ and Android; deployment is public HTTPS.
- In-app equivalents: chooser cards show today's per-unit status; a banner appears when a unit is unfilled past handover time.

### 10.3 Missed-days view (the only KPI) [RULING]

One page behind `cap:endorsement.compliance`: a date-range picker (default last 30 days) and one row per unit showing **days without endorsement / total days in range**, expandable to the list of missing dates, each linking to create/open that day. **Definition:** a day is missed when the unit has **no signed sign-off** for that date; the expanded list distinguishes "no sheet at all" from "sheet created but never signed". Counts and dates only — no other metrics, no patient data.

### 10.4 Day-index gap markers

Missing dates between existing sheets render inline in the day index with one-tap backfill; backfill uses the carry dialog (§5), which will offer carry-from-last-sheet or blank.

---
