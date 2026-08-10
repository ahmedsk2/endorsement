import { test, expect } from '@playwright/test';
import { assertLocalhost, login, readBack, RESIDENT } from './fixtures.js';

/**
 * MR-05, walked by the person it was written for: a RESIDENT signs in, reads the master rota,
 * narrows it, reloads, and finds the same thing on screen — and cannot change any of it.
 *
 * WHY A BROWSER AND NOT MORE PHPUnit. P1d-1's e2e spec is the reason this file exists in the shape
 * it does: ten tasks of unit and HTTP tests passed around the fact that there was no way to CLEAR a
 * rota cell from the UI at all, because every one of them called the endpoint directly and none of
 * them ever looked for a control. The three properties below are of the same kind — each is true of
 * the server in a test that already passes, and each could still be false of the screen:
 *
 *  1. A `cap:rota.view` holder can actually REACH the rota. `RotaAccessTest` proves the route
 *     answers 200; it cannot prove a resident has any way to arrive at it, and a screen reachable
 *     only by typing the URL is not published to anybody.
 *  2. The filters live in the URL. `RotaReadViewTest` proves `?q=` narrows the props; only a real
 *     reload can prove the reader gets the same screen back, with the CONTROL still showing what
 *     the server applied — a client-only filter passes the first half and fails the second.
 *  3. Nothing here writes. Asserted at the level a browser can settle it: the editor's URL is
 *     refused for this actor, the nav offers no route to it, the strip carries no control, and the
 *     browser is watched for the whole journey — not one non-GET request leaves the page.
 *
 * THE ACTOR IS A RESIDENT, DELIBERATELY. An administrator holds every capability in the catalogue,
 * so an administrator reaching `/rota` would prove nothing about MR-05. `RESIDENT` is position 4:
 * `rota.view` and no `rota.manage` (owner decision 2, 2026-08-10).
 *
 * FINDING 15 — EVERY PER-ROW LOOKUP IS SCOPED. `Rota.vue` renders BOTH a mobile card and a desktop
 * table row for each person, carrying identical `data-testid`s, with CSS hiding one. An unscoped
 * `page.getByTestId()` therefore hits Playwright's strict-mode "resolved to 2 elements". Every row
 * lookup below goes through `stripRow()`, which scopes by the row's own `data-row-id` inside the
 * desktop table (this project runs at Desktop Chrome, above the `lg` breakpoint).
 *
 * THE NUMBERS ARE E2eSeeder's, AND THE ARITHMETIC IS WRITTEN OUT where it is asserted, so a future
 * change to that fixture fails here with a diagnosable reason rather than a bare "expected 35".
 */
const YEAR = '2026-2027';
const ROTA = `/rota?year=${YEAR}`;

/** E2eSeeder's rota fixture — see its own comments for the full shape. */
const READER = 'E2E Rota Reader';        // R1 · PICU · a DELIBERATE 7-day gap in Block 1
const COLLEAGUE = 'E2E Rota Colleague';  // R2 · NICU · whole periods · a week's leave in Block 2

/* ---------------------------------------------------------------- helpers ---- */

/**
 * A row of the strip, scoped the only way finding 15 permits: the desktop table, then the row's own
 * `data-row-id`, then that row's own `td[data-col-key]`. Returns the column keys in the order the
 * server laid the periods out, so a period is addressed by POSITION discovered from the DOM rather
 * than by an id this spec would have to guess.
 */
async function stripRow(page, name) {
    const row = page.locator('table tbody tr[data-row-id]').filter({ hasText: name });
    await expect(row).toBeVisible();

    const rowId = await row.getAttribute('data-row-id');
    const columns = await row.locator('td[data-col-key]')
        .evaluateAll((tds) => tds.map((td) => td.getAttribute('data-col-key')));

    return {
        rowId,
        columns,
        cell: (colKey) => page.locator(`table tbody tr[data-row-id="${rowId}"] td[data-col-key="${colKey}"]`),
    };
}

/** MR-07's card for one period. `AvailabilityPanel` renders one markup at every width — no duplicate. */
const summaryCard = (page, colKey) => page.getByTestId(`summary-period-${colKey.replace('period-', '')}`);

/** One of the four headline figures, found by the LABEL a reader sees rather than by a testid. */
const stat = (card, label) => card.locator('dl > div').filter({ hasText: label }).locator('dd');

/**
 * A cell of the per-level/per-unit table, addressed by the level code and unit code ON SCREEN. The
 * ids that `summary-cell-*` is keyed on are not exposed anywhere a reader can see, and pinning the
 * screen to what it actually says is the point of asserting it here rather than in Vitest.
 */
async function levelUnitCell(card, levelCode, unitCode) {
    const table = card.locator('table');
    const heads = await table.locator('thead th').allInnerTexts();
    const column = heads.findIndex((head) => head.trim() === unitCode);

    expect(column, `no "${unitCode}" column in this period's availability table (${heads.join(', ')})`)
        .toBeGreaterThan(0);

    // The level row leads with a <th scope="row">, so the unit's <td> sits one place to its left.
    return table.locator('tbody tr').filter({ hasText: levelCode }).locator('td').nth(column - 1);
}

/**
 * Watch the browser for the rest of the test. A read screen that writes would show up here as a
 * POST/PATCH/DELETE leaving the page — including one fired by a control nobody thought to look for,
 * which is exactly the class of thing the component-level "no <form>" assertion cannot see.
 *
 * Attached BEFORE login on purpose, and that is a non-vacuity device rather than an oversight: an
 * empty list at the end of a test looks identical whether nothing wrote or the recorder never
 * fired. Signing in IS a POST, so {@see signInAndWatch} can prove the recorder works before the
 * journey it is watching begins, and each spec then asserts the list has not grown SINCE.
 */
function watchForWrites(page) {
    const writes = [];

    page.on('request', (request) => {
        if (request.method() !== 'GET') writes.push(`${request.method()} ${request.url()}`);
    });

    return writes;
}

/** Sign in as the resident with the recorder already running; returns `writes` and the mark to compare against. */
async function signInAndWatch(page) {
    const writes = watchForWrites(page);
    await login(page, RESIDENT);

    expect(writes, 'the write recorder never fired — signing in is itself a POST').not.toEqual([]);
    expect(writes.every((write) => write.includes('/login'))).toBe(true);

    return { writes, mark: writes.length };
}

/* ------------------------------------------------------------------ specs ---- */

test.describe('rota read view — a resident reads it, and cannot change it', () => {
    test.beforeAll(({ }, testInfo) => assertLocalhost(testInfo.project.use.baseURL));

    test('a resident reaches the rota with no capability to edit it, and no control that writes', async ({ page }) => {
        const { writes, mark } = await signInAndWatch(page);

        // NO `?year=`, deliberately: this is the landing-year behaviour Task 4 decided (the year
        // containing today, else the most recent generated). The fixture's only academic year is
        // 2026-2027, so both branches land here and the assertion does not rot with the calendar.
        await readBack(page, '/rota');
        await expect(page.getByTestId('rota-year')).toHaveValue(YEAR);

        // MR-05 is "publishable to residents", and a screen with no route to it is not published.
        // The nav is the route.
        const nav = page.locator('nav#primary-nav');
        await expect(nav.getByRole('link', { name: 'Rota', exact: true })).toBeVisible();

        // ...and the editor is not offered, because this actor does not hold `rota.manage`.
        await expect(nav.getByText('Administration')).toHaveCount(0);
        await expect(nav.locator('a[href="/admin/rota"]')).toHaveCount(0);

        // The strip carries the seeded assignment's unit code, and the gap beside it. Both figures
        // are the SERVER's: PICU 2026-08-01..2026-08-07 inside a 14-day Block 1 leaves 7 days.
        const reader = await stripRow(page, READER);
        expect(reader.columns).toHaveLength(2);
        await expect(reader.cell(reader.columns[0])).toContainText('PICU');
        await expect(reader.cell(reader.columns[0])).toContainText('7d not yet assigned');
        await expect(reader.cell(reader.columns[1])).toContainText('PICU');

        // NOTHING ON THIS PAGE WRITES — asserted three ways, because each misses what the others
        // catch. (a) No form and no button anywhere in the page's own landmark. Scoped to `main`:
        // the layout's "Sign out" is a button on every screen in the app and is not this screen's.
        // Each landmark is asserted PRESENT first: a count of zero inside a locator that matched
        // nothing is the same green as a count of zero inside the real thing.
        const main = page.locator('main#main-content');
        await expect(main).toBeVisible();
        await expect(main.locator('form')).toHaveCount(0);
        await expect(main.locator('button')).toHaveCount(0);

        // (b) The strip itself carries no control of any kind — no unit picker, no Split, no On
        // leave, no Clear. The two filter controls live OUTSIDE it, and they only navigate.
        const strip = page.getByTestId('rota-strip');
        await expect(strip).toBeVisible();
        await expect(strip.locator('select')).toHaveCount(0);
        await expect(strip.locator('input')).toHaveCount(0);
        await expect(strip.locator('button')).toHaveCount(0);

        // (c) The editor's own URL, typed by hand — the one route a determined reader would try.
        const refused = await page.goto('/admin/rota');
        expect(refused.status()).toBe(403);

        // And the browser itself: not one non-GET request left this journey, from the moment the
        // reader arrived. `mark` is where the recorder stood after the (legitimate) login POST.
        expect(writes.slice(mark)).toEqual([]);
    });

    test('search and the level filter narrow the rota and survive a reload', async ({ page }) => {
        const { writes, mark } = await signInAndWatch(page);

        await readBack(page, ROTA);

        const rows = page.locator('table tbody tr[data-row-id]');
        await expect(rows).toHaveCount(4);   // E2eSeeder's whole active roster

        // --- Search. ---------------------------------------------------------------------------
        await page.getByTestId('rota-search').fill('Colleague');
        await page.getByTestId('rota-search').press('Enter');
        await page.waitForURL(/[?&]q=Colleague/);

        await expect(rows).toHaveCount(1);
        await expect(rows.first()).toContainText(COLLEAGUE);
        await expect(page.getByTestId('rota-count')).toContainText('Showing 1 person');

        // A FILTERED ROTA IS STILL THE ROTA, and the sidebar has to keep saying so. This is the
        // assertion that found the AppLayout defect this task fixed: `page.url` carries the query
        // string, both nav helpers compared it whole, and so the "you are here" highlight and the
        // `aria-current="page"` a screen reader announces both vanished on the first filter a
        // resident applied. Asserted HERE, after a real navigation, because every unit-test mount
        // of the layout stubbed a bare path and none of them could see it.
        await expect(page.locator('nav#primary-nav a[href="/rota"]')).toHaveAttribute('aria-current', 'page');

        // THE RELOAD. Everything above is a fact about the client. This throws the DOM away and
        // asks the server for the same URL — and asserts the CONTROL's value, not only the row set,
        // because the input is seeded from the `filters` prop the server echoes back. A filter that
        // narrowed rows in the browser and lost the box's contents on reload would pass a row-only
        // assertion and be exactly the bug this reload exists to catch.
        await readBack(page, page.url());
        await expect(page.getByTestId('rota-search')).toHaveValue('Colleague');
        await expect(rows).toHaveCount(1);
        await expect(rows.first()).toContainText(COLLEAGUE);

        // --- Level filter, from a clean slate. -------------------------------------------------
        await readBack(page, ROTA);
        const level = page.getByTestId('rota-level');
        const r2 = await level.locator('option').filter({ hasText: 'R2' }).first().getAttribute('value');
        await level.selectOption(r2);
        await page.waitForURL(new RegExp(`[?&]level=${r2}`));

        await expect(rows).toHaveCount(1);
        await expect(rows.first()).toContainText(COLLEAGUE);

        await readBack(page, page.url());
        await expect(page.getByTestId('rota-level')).toHaveValue(r2);
        await expect(rows).toHaveCount(1);
        await expect(rows.first()).toContainText(COLLEAGUE);

        // Both filters travelled as query strings on GET requests. If either had been a form POST
        // this list would have grown — and the URL would not have survived the reload above.
        expect(writes.slice(mark)).toEqual([]);
    });

    test('the availability summary reports the same rota the strip shows, gap included', async ({ page }) => {
        await login(page, RESIDENT);
        await readBack(page, ROTA);

        const reader = await stripRow(page, READER);
        const [block1, block2] = reader.columns;

        // THE GAP IS ON SCREEN IN BOTH RENDERINGS, AND THEY AGREE. Stage 1's acceptance criterion
        // (Munawib §35) is that the summaries match reality, and the failure it forbids is a
        // half-planned period that reads as finished. E2eSeeder's Block 1:
        //
        //   E2E Rota Reader      PICU 08-01..08-07   7 assigned,  7 uncovered  <- the gap
        //   E2E Rota Colleague   NICU 08-01..08-14  14 assigned,  0 uncovered
        //   E2E Administrator    (none)              0 assigned, 14 uncovered  <- unassigned
        //   E2E Rota Resident    (none)              0 assigned, 14 uncovered  <- unassigned
        //
        // so 21 assigned, 35 not assigned, ONE person carrying a gap and TWO with nothing at all.
        // The two counts are disjoint on purpose (Task 2's amendment): "one with a gap, two
        // unassigned" means three people need attention, and a single lumped figure could not say
        // that. The 7 below is the same 7 the reader's own cell prints two assertions down.
        await expect(reader.cell(block1)).toContainText('7d not yet assigned');

        const first = summaryCard(page, block1);
        await expect(stat(first, 'Assigned days')).toHaveText('21');
        await expect(stat(first, 'Days not assigned')).toHaveText('35');
        await expect(stat(first, 'People with a gap')).toHaveText('1');
        await expect(stat(first, 'Unassigned people')).toHaveText('2');

        // Per level and per unit — MR-07's own words. One R1 on PICU for 7 of Block 1's days is the
        // gap again, counted from the other end; one R2 on NICU for all 14 is the period done.
        await expect(await levelUnitCell(first, 'R1', 'PICU')).toContainText('1');
        await expect(await levelUnitCell(first, 'R1', 'PICU')).toContainText('7 days');
        await expect(await levelUnitCell(first, 'R2', 'NICU')).toContainText('1');
        await expect(await levelUnitCell(first, 'R2', 'NICU')).toContainText('14 days');

        // Block 2 is fully covered for both of them, so its gap count is a real zero rather than an
        // absence — the distinction MR-07 exists to make.
        const second = summaryCard(page, block2);
        await expect(stat(second, 'Assigned days')).toHaveText('28');
        await expect(stat(second, 'People with a gap')).toHaveText('0');

        // "...including who is on vacation each week" (MR-07). E2eSeeder books the colleague a week
        // from 2026-08-16, which the department's own week (weekend [5,6]) is 08-16..08-22 — Block
        // 2's SECOND week, and no other spec's fixture touches it.
        const week = page.getByTestId(`summary-week-${block2.replace('period-', '')}-1`);
        await expect(week).toContainText('2026-08-16');
        await expect(week).toContainText('2026-08-22');
        await expect(week).toContainText('1 on leave');

        // DECISION D's ORDERING TRAP, IN A BROWSER. The summary is the DEPARTMENT's, computed from
        // the full grid before the rows are filtered. Narrow the list to one person and every
        // figure above must be unchanged — a summary that moved with the search box would be
        // telling each reader a different story about how covered the department is.
        await readBack(page, `${ROTA}&q=Colleague`);
        await expect(page.locator('table tbody tr[data-row-id]')).toHaveCount(1);

        const filtered = summaryCard(page, block1);
        await expect(stat(filtered, 'Assigned days')).toHaveText('21');
        await expect(stat(filtered, 'Days not assigned')).toHaveText('35');
        await expect(stat(filtered, 'People with a gap')).toHaveText('1');
        await expect(stat(filtered, 'Unassigned people')).toHaveText('2');
    });
});
