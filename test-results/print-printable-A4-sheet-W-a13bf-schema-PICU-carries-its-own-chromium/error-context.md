# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: print.spec.js >> printable A4 sheet >> WARD print carries the ward schema; PICU carries its own
- Location: tests\e2e\print.spec.js:14:5

# Error details

```
Test timeout of 60000ms exceeded.
```

```
Error: page.waitForURL: Test timeout of 60000ms exceeded.
=========================== logs ===========================
waiting for navigation until "load"
  navigated to "http://127.0.0.1:8001/setup"
============================================================
```

# Page snapshot

```yaml
- main [ref=e3]:
  - generic [ref=e4]:
    - generic [ref=e5]:
      - paragraph [ref=e6]: Paediatric Endorsement
      - heading "Set up your account" [level=1] [ref=e7]
      - paragraph [ref=e8]: Two things before you start. This takes a minute and you only do it once.
    - generic [ref=e9]:
      - generic [ref=e10]:
        - generic [ref=e11]:
          - heading "1. How you sign in" [level=2] [ref=e12]
          - paragraph [ref=e13]: A password alone is not enough for a system holding children's records. Pick a second step.
        - generic [ref=e14]: Required
      - generic [ref=e15]:
        - generic [ref=e16]:
          - paragraph [ref=e17]: Authenticator app — recommended
          - paragraph [ref=e18]: A 6-digit code from an app on your phone. Works with no signal and no email.
          - link "Set up the app" [ref=e19] [cursor=pointer]:
            - /url: /user/two-factor
        - generic [ref=e20]:
          - paragraph [ref=e21]: Code by email
          - paragraph [ref=e22]: A one-time code sent to e2e-admin@example.org each time you sign in. Confirm your address first.
          - button "Send confirmation email" [ref=e23]
    - generic [ref=e24]:
      - generic [ref=e25]:
        - generic [ref=e26]:
          - heading "2. Handover reminders" [level=2] [ref=e27]
          - paragraph [ref=e28]: Which units should remind you if a handover has not been signed after the 07:30 or 15:30 changeover?
        - generic [ref=e29]: Optional
      - generic [ref=e30]:
        - generic [ref=e31] [cursor=pointer]:
          - checkbox "PICU Pediatric Intensive Care Unit" [ref=e32]
          - generic [ref=e33]:
            - text: PICU
            - generic [ref=e34]: Pediatric Intensive Care Unit
        - generic [ref=e35] [cursor=pointer]:
          - checkbox "NICU Neonatal Intensive Care Unit" [ref=e36]
          - generic [ref=e37]:
            - text: NICU
            - generic [ref=e38]: Neonatal Intensive Care Unit
        - generic [ref=e39] [cursor=pointer]:
          - checkbox "SCBU Special Care Baby Unit" [ref=e40]
          - generic [ref=e41]:
            - text: SCBU
            - generic [ref=e42]: Special Care Baby Unit
        - generic [ref=e43] [cursor=pointer]:
          - checkbox "WARD Pediatric Ward" [ref=e44]
          - generic [ref=e45]:
            - text: WARD
            - generic [ref=e46]: Pediatric Ward
      - generic [ref=e47]:
        - button "Save my units" [ref=e48]
        - button "Enable notifications on this device" [ref=e49]
      - paragraph [ref=e50]: On an iPhone, notifications only arrive if you add this site to your Home Screen first.
    - generic [ref=e51]:
      - button "Sign out" [ref=e53]
      - button "Finish and start" [disabled] [ref=e55]
```

# Test source

```ts
  1  | import { expect } from '@playwright/test';
  2  | 
  3  | /**
  4  |  * Shared harness for the browser end-to-end specs. Everything here drives the app the way
  5  |  * a clinician's browser does — real navigation, real clicks, real keystrokes — and asserts
  6  |  * against what the SERVER hands back after a reload. No spec is allowed to conclude
  7  |  * "saved" from an optimistic indicator; see readBack() below.
  8  |  */
  9  | 
  10 | /* ------------------------------------------------------------------ safety ---- */
  11 | 
  12 | const LOOPBACK = new Set(['localhost', '127.0.0.1', '[::1]', '::1']);
  13 | 
  14 | /**
  15 |  * Refuses to run against anything but loopback. This suite CREATES clinical-shaped rows;
  16 |  * pointed at production it would write into a live PHI system.
  17 |  */
  18 | export function assertLocalhost(baseURL) {
  19 |     const host = new URL(baseURL).hostname;
  20 |     if (!LOOPBACK.has(host)) {
  21 |         throw new Error(
  22 |             `E2E refuses to run against non-loopback host "${host}". Local testing only — this suite writes data.`,
  23 |         );
  24 |     }
  25 | }
  26 | 
  27 | /* ------------------------------------------------------------------ identity -- */
  28 | 
  29 | export const ADMIN = { username: 'admin', password: 'AdminPass123!' };
  30 | 
  31 | /* --------------------------------------------------------------------- auth --- */
  32 | 
  33 | export async function login(page, who = ADMIN) {
  34 |     await page.goto('/login');
  35 |     await page.getByLabel(/username/i).fill(who.username);
  36 |     await page.getByLabel(/^password/i).fill(who.password);
  37 |     await page.getByRole('button', { name: /log ?in|sign ?in/i }).click();
> 38 |     await page.waitForURL(/\/endorsement/);
     |                ^ Error: page.waitForURL: Test timeout of 60000ms exceeded.
  39 | }
  40 | 
  41 | /* ------------------------------------------------------------ persistence ----- */
  42 | 
  43 | /**
  44 |  * The core discipline of this suite: throw away the current DOM, re-fetch the page from
  45 |  * the server, and read the value back. Anything asserted BEFORE this ran is only evidence
  46 |  * about the client — the production bug this harness exists for looked correct on screen
  47 |  * and was gone after a reload.
  48 |  */
  49 | export async function readBack(page, url) {
  50 |     await page.goto(url, { waitUntil: 'networkidle' });
  51 | }
  52 | 
  53 | /* ----------------------------------------------------------------- misc ------- */
  54 | 
  55 | /** Today, as the Y-m-d string the endorsement routes are regex-pinned to (LOCAL date). */
  56 | export function today() {
  57 |     const d = new Date();
  58 | 
  59 |     return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  60 | }
  61 | 
```