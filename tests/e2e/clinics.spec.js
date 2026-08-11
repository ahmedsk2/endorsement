import { test, expect } from '@playwright/test';
import { assertLocalhost, login, readBack, signInAndWatch, ADMIN, RESIDENT } from './fixtures.js';

/**
 * CL-01 and CL-05, walked end to end by the two people they were written for: an ADMINISTRATOR
 * defines a clinic, and a RESIDENT finds it on the department's week — after a real reload, in a
 * real browser, against what the SERVER hands back.
 *
 * WHY A BROWSER AND NOT MORE PHPUnit. `ClinicScreenTest` (19 cases) and `ClinicMapTest` (16) already
 * prove both surfaces at the HTTP level, and `Clinics.test.js` / `ClinicMap.test.js` prove both
 * components at the mount level. Every one of those calls the endpoint or mounts the component
 * directly; none of them can see whether an administrator has any WAY to reach the create form, or
 * whether the value they picked in a `<select>` is the value that arrives. P1d-1's e2e spec is the
 * standing reminder: ten tasks of green unit tests passed around the fact that there was no way to
 * clear a rota cell from the UI at all, and P1d-2a's found the nav dropping `aria-current` on six
 * screens. Both defects were invisible to every other layer.
 *
 * THE TWO JOURNEYS ARE DELIBERATELY INDEPENDENT. The reader's clinic is E2eSeeder's; the
 * administrator's is created here, on a different day and in a different session. Playwright's
 * `--grep` runs one test, and a reader journey that only passes when the administrator journey ran
 * first is green for a reason nobody chose. It also keeps this file's fixture disjoint from the two
 * rota specs': the seeded clinic is `rotators` mode, so it names no person at all and cannot disturb
 * a spec that addresses its people by name.
 *
 * THE ACTOR MATTERS IN THE SECOND HALF. An administrator holds every capability in the catalogue, so
 * an administrator reaching `/clinics` would say nothing about CL-05. `RESIDENT` is position 4:
 * `clinics.view` (seeded to every position, P1e Decision C) and NOT `structure.manage`.
 *
 * BOTH SCREENS SHIP TWO TREES. `Clinics.vue` and `Clinics/Map.vue` each render mobile cards AND a
 * desktop table, with CSS hiding one — both are in the DOM, so an unscoped `getByText` hits
 * Playwright's strict-mode "resolved to 2 elements". Every lookup below is scoped to the desktop
 * tree (this project runs at Desktop Chrome, above the `lg` breakpoint), exactly as
 * `rota-read.spec.js` scopes its own.
 *
 * FIXTURES ARE SYNTHETIC, PERMANENTLY (CLAUDE.md). No real clinic, no real room, no real colleague.
 */

/* ---------------------------------------------------------------- fixture ---- */

/** E2eSeeder's clinic — see `seedReadableClinic()` for why it is on this unit, day and mode. */
const SEEDED = {
    unit: 'WARD',
    name: 'E2E Asthma Clinic',
    location: 'E2E Clinic Room 3',
    iso: 2,                  // ISO-8601 Tuesday. `clinics.weekday` holds exactly this numbering.
    dayLabel: 'Tuesday',
    sessionLabel: 'Morning', // Clinic::SESSIONS['AM']
};

/** This spec's own clinic, created through the screen. Disjoint from SEEDED in day AND session. */
const CREATED = {
    name: 'E2E Renal Clinic',
    location: 'E2E Clinic Room 7',
    dayLabel: 'Wednesday',
    sessionLabel: 'Afternoon',
};

const ADMIN_CLINICS = '/admin/structure/clinics';
const MAP = '/clinics';

/* ---------------------------------------------------------------- helpers ---- */

/**
 * The Inertia page object the SERVER put in the response, taken from the RESPONSE BODY rather than
 * from the DOM.
 *
 * This is the check that catches a contact leak; reading the screen never will. P1d-2 Decision C was
 * exactly this shape — the rota grid passed the request's user to `PersonPresenter`, and every
 * colleague's reachable details shipped in props that nothing on the page rendered. A blunt "no `@`
 * anywhere in the payload" is cheap, needs no knowledge of which key a future consumer might add,
 * and would have failed on that defect the day it landed.
 *
 * FOUND IN A BROWSER, AND WORTH RECORDING, BECAUSE THE OBVIOUS TWO IMPLEMENTATIONS BOTH FAIL. The
 * first — read `#app`'s `data-page` attribute — returns null, because Inertia's Vue 3 adapter
 * removes that attribute once it has parsed it. The second — look for `id="app" data-page="…"` in
 * the HTML — finds nothing either, because this version of `inertia-laravel` does not put the
 * payload on the root div at all: it emits `<script data-page="app" type="application/json">` with
 * the JSON as the script's own body, and `data-page` there holds the element ID. Neither mistake
 * could pass silently (both throw on a null match), which is the only reason this helper is allowed
 * to be this specific about the markup — and it is why the assertions below are made against a real,
 * PARSED payload rather than against a raw-substring search of the whole page.
 */
async function serverPayload(response) {
    const html = await response.text();
    const match = html.match(/<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/);

    // Non-vacuity: a null match, or a body that is not this page at all, would make every claim
    // below true of nothing. `expect(null).not.toContain('@')` passes forever.
    expect(match, 'no Inertia payload in the response — the "no contact detail" assertion would be vacuous')
        .not.toBeNull();

    return { raw: match[1], page: JSON.parse(match[1]) };
}

/** One option's `value`, found by the text a human reads. `selectOption({ label })` needs an exact match; this does not. */
async function optionValue(select, text) {
    const option = select.locator('option').filter({ hasText: text }).first();
    await expect(option, `no option matching "${text}"`).toHaveCount(1);

    return option.getAttribute('value');
}

/** The desktop listing row for one clinic on the administrative screen (the mobile card is an <article>). */
const adminRow = (page, name) => page.locator('table tbody tr').filter({ hasText: name });

/* ------------------------------------------------------------------ specs ---- */

test.describe('clinics — an administrator defines one, a resident finds it', () => {
    test.beforeAll(({ }, testInfo) => assertLocalhost(testInfo.project.use.baseURL));

    test('an administrator creates a clinic and it is still there after a reload', async ({ page }) => {
        await login(page, ADMIN);
        await readBack(page, ADMIN_CLINICS);

        // The screen is REACHED, not typed at: a create form nobody can navigate to is not shipped.
        const nav = page.locator('nav#primary-nav');
        await expect(nav.locator(`a[href="${ADMIN_CLINICS}"]`)).toBeVisible();

        // Nothing is on this day and in this session yet — so the assertion after the reload is
        // about a row this test created, not one that was already there.
        await expect(adminRow(page, CREATED.name)).toHaveCount(0);

        await page.getByRole('button', { name: '+ New clinic' }).click();

        const unit = page.locator('#new-unit');
        await expect(unit).toBeVisible();

        // Every value is picked from what the SERVER offered, never typed as a constant. The unit
        // list is `clinic_owner = true AND active = true` from the units table (D2 — units are
        // configuration, never a hardcoded list), and the weekday options are
        // `Calendar::weekdayColumns()`, already labelled and already in this department's own order.
        await unit.selectOption(await optionValue(unit, SEEDED.unit));

        const weekday = page.locator('#new-weekday');
        await weekday.selectOption(await optionValue(weekday, CREATED.dayLabel));

        const session = page.locator('#new-session');
        await session.selectOption(await optionValue(session, CREATED.sessionLabel));

        await page.locator('#new-name').fill(CREATED.name);
        await page.locator('#new-location').fill(CREATED.location);

        await page.getByRole('button', { name: 'Add clinic' }).click();

        // THE RELOAD IS THE EVIDENCE, and everything before it is only a fact about the client —
        // CLAUDE.md's autosave rule generalised, and the reason `rota-read.spec.js` reloads too. The
        // row appearing here comes from a fresh GET, so it is the database answering.
        await readBack(page, ADMIN_CLINICS);

        const row = adminRow(page, CREATED.name);
        await expect(row).toHaveCount(1);
        await expect(row).toContainText(CREATED.dayLabel);
        await expect(row).toContainText(CREATED.sessionLabel);
        await expect(row).toContainText(CREATED.location);
        // CL-02's default, applied by the writer at creation: refining who attends is a second,
        // explicit act, and a clinic can never open with a mode and an attendee set disagreeing.
        await expect(row).toContainText('Everyone rotating on this unit');

        // It landed on the unit the administrator picked, under that unit's own group heading —
        // the group is keyed on the unit, so a clinic filed under the wrong one would show here.
        const group = page.locator('section').filter({ has: row });
        await expect(group).toContainText(SEEDED.unit);
    });

    test('a resident reads the department week, in the right cell, with no contact detail and nothing that writes', async ({ page }) => {
        const { writes, mark } = await signInAndWatch(page, RESIDENT);

        // The response is kept, not discarded: its BODY is the server's own payload, and the
        // contact-freedom assertion at the end of this test is made against that rather than against
        // anything the client has since done to the DOM.
        const response = await page.goto(MAP, { waitUntil: 'networkidle' });
        expect(response.status()).toBe(200);

        // CL-05 is published to the whole department, and a screen with no route to it is not
        // published to anybody. The nav is the route.
        const nav = page.locator('nav#primary-nav');
        await expect(nav.getByRole('link', { name: 'Clinics', exact: true })).toBeVisible();

        // ...and the administrative screen is not offered, because this actor holds `clinics.view`
        // and not `structure.manage` (Decision C: one new key, not two).
        await expect(nav.locator(`a[href="${ADMIN_CLINICS}"]`)).toHaveCount(0);

        // --- The right unit row. ---------------------------------------------------------------
        // The map lists ACTIVE clinic-owning units only, and owner Decision B (P1b) makes WARD the
        // sole clinic owner at QCH, so one row is the correct count rather than a coincidence —
        // asserted so that a future unit ticking the box fails here and gets read rather than
        // silently widening what "the right row" means.
        const table = page.getByTestId('map-table');
        await expect(table).toBeVisible();

        const rows = table.locator('tbody tr[data-row-id]');
        await expect(rows).toHaveCount(1);

        const row = rows.first();
        await expect(row).toContainText(SEEDED.unit);

        const unitId = (await row.getAttribute('data-row-id')).replace('unit-', '');

        // --- The right weekday column. ---------------------------------------------------------
        // The column header is the SERVER's label for that ISO day, from `Calendar::weekdayColumns()`
        // — the screen names no day of the week itself.
        await expect(table.locator(`thead th[data-col-iso="${SEEDED.iso}"]`)).toContainText(SEEDED.dayLabel);

        const cell = row.locator(`td[data-cell-key="${unitId}-${SEEDED.iso}"]`);
        await expect(cell).toContainText(SEEDED.name);
        await expect(cell).toContainText(SEEDED.location);
        await expect(cell).toContainText(SEEDED.sessionLabel);
        // Who attends arrives as the RULE, never a resolved roster: a clinic is a weekly recurrence
        // with no date of its own, so there is no honest day to resolve a map cell as of.
        await expect(cell).toContainText('Everyone rotating on this unit');

        // ONE cell, and it is that one. Sweeping all seven is what makes this an assertion about the
        // COLUMN rather than about the row: a clinic rendered into every cell, or into the cell one
        // place along, would pass a single positive check.
        const keys = await row.locator('td[data-cell-key]').evaluateAll(
            (tds) => tds.filter((td) => td.textContent.includes('E2E Asthma Clinic'))
                .map((td) => td.getAttribute('data-cell-key')),
        );
        expect(keys).toEqual([`${unitId}-${SEEDED.iso}`]);

        // --- No contact detail, for this viewer, anywhere in the payload. ----------------------
        const { raw, page: props } = await serverPayload(response);

        // The payload really is this page's, and it really does carry the clinic — without this the
        // "no @" claim below could be true of an empty or unrelated blob.
        expect(props.component).toBe('Clinics/Map');
        expect(raw).toContain(SEEDED.name);
        expect(raw, 'a contact address reached the clinic map — see P1d-2 Decision C').not.toContain('@');

        // --- Nothing here writes. --------------------------------------------------------------
        // (a) The page's own landmark carries no control at all. Asserted PRESENT first: a count of
        // zero inside a locator that matched nothing is the same green as a count of zero inside the
        // real thing. Scoped to `main` because the layout's "Sign out" is on every screen in the app.
        const main = page.locator('main#main-content');
        await expect(main).toBeVisible();
        await expect(main.locator('form')).toHaveCount(0);
        await expect(main.locator('button')).toHaveCount(0);
        await expect(main.locator('select')).toHaveCount(0);
        await expect(main.locator('input')).toHaveCount(0);

        // (b) The administrative screen's own URL, typed by hand — the one route a determined reader
        // would try. `structure.manage` is what defining a clinic costs, and this actor lacks it.
        const refused = await page.goto(ADMIN_CLINICS);
        expect(refused.status()).toBe(403);

        // (c) And the browser itself: not one non-GET request left this journey from the moment the
        // reader arrived. `mark` is where the recorder stood after the (legitimate) login POST, which
        // is also the proof that the recorder was working at all.
        expect(writes.slice(mark)).toEqual([]);
    });
});
