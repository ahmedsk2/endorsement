import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// `router` is mocked with every verb so the "this screen writes nothing" assertion has something
// to assert against: a spy that is never armed cannot fail. `useForm` is deliberately NOT provided
// — if this component ever reaches for it, these tests blow up rather than quietly pass.
const router = vi.hoisted(() => ({
    get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router,
    usePage: () => ({
        props: {
            auth: { can: ['clinics.view'], user: { id: 1, member_name: 'res', full_name: 'A Resident', position: 4 } },
            nav: { units: [] },
            flash: {},
        },
        url: '/clinics',
    }),
}));

import Map from '../../resources/js/Pages/Clinics/Map.vue';

const SOURCE = readFileSync(resolve(__dirname, '../../resources/js/Pages/Clinics/Map.vue'), 'utf8');

/**
 * DELIBERATELY NOT ENGLISH DAY NAMES, and in a rotation that starts nowhere near the top of the
 * ISO week. `Calendar::weekdayColumns()` sends this array already labelled and already rotated
 * into the department's own order, so a component that hardcoded a list of seven day names would
 * render the wrong seven strings in the wrong order and every assertion below would fail. Real
 * labels would have made a hardcoded list indistinguishable from a consumed prop, and a
 * department-order rotation is exactly what a hardcoded list cannot reproduce.
 */
const weekdays = [
    { iso: 7, label: 'Day-Seven', short: 'D7', weekend: false },
    { iso: 1, label: 'Day-One', short: 'D1', weekend: false },
    { iso: 2, label: 'Day-Two', short: 'D2', weekend: false },
    { iso: 3, label: 'Day-Three', short: 'D3', weekend: false },
    { iso: 4, label: 'Day-Four', short: 'D4', weekend: false },
    { iso: 5, label: 'Day-Five', short: 'D5', weekend: true },
    { iso: 6, label: 'Day-Six', short: 'D6', weekend: true },
];

const emptyCells = () => {
    const cells = {};
    weekdays.forEach((day) => ['AM', 'PM'].forEach((session) => { cells[`${day.iso}-${session}`] = []; }));
    return cells;
};

const clinic = (overrides = {}) => ({
    id: 500,
    name: 'General Paediatrics',
    location: 'Clinic B',
    note: null,
    who: 'Everyone rotating on this unit',
    who_detail: null,
    ...overrides,
});

const row = (unit, placements = {}) => {
    const cells = emptyCells();
    Object.entries(placements).forEach(([key, list]) => { cells[key] = list; });

    return { unit, cells };
};

const WARD = { id: 10, code: 'WARD', name: 'Pediatric Ward', bar_class: 'channel-bar-ward' };
const NICU = { id: 11, code: 'NICU', name: 'Neonatal ICU', bar_class: 'channel-bar-nicu' };

const mountMap = (overrides = {}) => mount(Map, {
    props: {
        weekdays,
        sessions: { AM: 'Morning', PM: 'Afternoon' },
        rows: [row(WARD, { '2-AM': [clinic()] })],
        ...overrides,
    },
    global: {
        stubs: {
            AppLayout: { name: 'AppLayout', template: '<div><slot /></div>' },
        },
    },
});

describe('CL-05 — the weekly clinic map', () => {
    /**
     * THE COLUMNS ARE THE PROP, IN THE PROP'S ORDER — behaviour, not source text. The SOURCE-level
     * half lives in `CalendarIsTheOnlyConverterTest::
     * test_no_hardcoded_weekday_vocabulary_appears_under_resources_js`, repo-wide over
     * `resources/js` with no allow-list, so it already covers this file without anybody copying an
     * assertion. The two are not the same test: that one catches seven day names written anywhere,
     * this one catches a header built from the wrong list or sorted into the wrong order — a
     * component can consume the labels honestly and still re-sort them itself.
     */
    it('draws seven columns from the calendar prop, in the department order', () => {
        const headers = mountMap().findAll('[data-testid="map-table"] thead th');

        // Unit, then the department's seven days.
        expect(headers).toHaveLength(8);
        expect(headers.slice(1).map((h) => h.text())).toEqual(
            weekdays.map((d) => (d.weekend ? `${d.label} Weekend` : d.label)),
        );
        expect(headers.slice(1).map((h) => h.attributes('data-col-iso'))).toEqual(
            weekdays.map((d) => String(d.iso)),
        );
    });

    it('places a clinic in its own unit row and day column', () => {
        const w = mountMap({ rows: [row(WARD, { '2-AM': [clinic({ name: 'Renal Clinic' })] }), row(NICU)] });

        const cell = w.get('[data-testid="map-table"] [data-cell-key="10-2"]');

        expect(cell.text()).toContain('Morning');
        expect(cell.text()).toContain('Renal Clinic');
        expect(w.get('[data-testid="map-table"] [data-cell-key="11-2"]').text()).not.toContain('Renal Clinic');
    });

    it('shows both clinics when one cell holds two', () => {
        const w = mountMap({
            rows: [row(WARD, { '2-AM': [clinic({ id: 1, name: 'Asthma Clinic' }), clinic({ id: 2, name: 'Diabetes Clinic' })] })],
        });

        const cell = w.get('[data-testid="map-table"] [data-cell-key="10-2"]');

        expect(cell.findAll('[data-clinic-id]')).toHaveLength(2);
        expect(cell.text()).toContain('Asthma Clinic');
        expect(cell.text()).toContain('Diabetes Clinic');
    });

    it('shows a level refinement as codes and never as a colleague', () => {
        const w = mountMap({
            rows: [row(WARD, {
                '2-AM': [clinic({ who: 'Rotators at these training levels', who_detail: 'R1, R2' })],
            })],
        });

        expect(w.text()).toContain('Rotators at these training levels');
        expect(w.text()).toContain('R1, R2');
    });

    /**
     * MOBILE DEGRADATION: the phone gets the week grouped BY DAY, which is the question a reader
     * on a phone actually has, and every day is kept even when nothing is on it — a filtered-out
     * Thursday and a Thursday that failed to load look identical.
     */
    it('degrades to one card per day, keeping the empty days', () => {
        const w = mountMap({ rows: [row(WARD, { '2-AM': [clinic({ name: 'Renal Clinic' })] })] });

        const cards = w.findAll('[data-testid="map-cards"] article');

        expect(cards).toHaveLength(7);
        expect(cards.map((c) => c.attributes('data-day-iso'))).toEqual(weekdays.map((d) => String(d.iso)));

        const tuesday = cards.find((c) => c.attributes('data-day-iso') === '2');
        expect(tuesday.text()).toContain('Renal Clinic');
        expect(tuesday.text()).toContain('WARD');

        const clear = cards.find((c) => c.attributes('data-day-iso') === '3');
        expect(clear.text()).toContain('No clinics.');
    });

    it('says so when no unit runs clinics, and when none has been added', () => {
        expect(mountMap({ rows: [] }).find('[data-testid="map-no-units"]').exists()).toBe(true);
        expect(mountMap({ rows: [row(WARD)] }).find('[data-testid="map-no-clinics"]').exists()).toBe(true);
    });

    it('escapes a clinic name rather than rendering it as markup', () => {
        const w = mountMap({
            rows: [row(WARD, {
                '2-AM': [clinic({ name: '<img src=x onerror=boom>', location: '<b>Room 9</b>' })],
            })],
        });

        expect(w.html()).not.toContain('<img src=x');
        expect(w.html()).not.toContain('<b>Room 9</b>');
        expect(w.html()).toContain('&lt;img src=x onerror=boom&gt;');
    });

    /**
     * READ-ONLY AT THE COMPONENT, not only at the router. The server-side half is
     * `ClinicMapTest::test_every_route_behind_cap_clinics_view_is_a_get`; this half is that the
     * screen offers no control which could reach a write in the first place — a control that
     * appears and then 403s is worse than no control.
     */
    it('offers no form and reaches no write verb', () => {
        const w = mountMap();

        expect(w.findAll('form')).toHaveLength(0);
        expect(w.findAll('button')).toHaveLength(0);
        expect(w.findAll('input')).toHaveLength(0);
        expect(w.findAll('select')).toHaveLength(0);

        expect(router.post).not.toHaveBeenCalled();
        expect(router.patch).not.toHaveBeenCalled();
        expect(router.put).not.toHaveBeenCalled();
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('never uses the raw-markup directive and never a dark-mode utility', () => {
        expect(SOURCE).not.toContain('v-html');
        expect(SOURCE).not.toMatch(/\bdark:/);
        // There is no such token — it compiles to nothing, and two uses of it shipped invisibly once.
        expect(SOURCE).not.toContain('bg-panel-soft');
        // No hex in markup: a unit's colour is a `Unit::BAR_CLASSES` name and nothing else.
        expect(SOURCE).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
    });
});
