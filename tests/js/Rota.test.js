import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// Same mock pattern as MasterRotaSplit/MasterRotaStaleRow/MasterRotaVacation. `patch`, `post` and
// `delete` are mocked NOT because this screen uses them, but so the read-only case can prove it
// never calls them — a spy that is never armed cannot fail.
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), patch: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({
        props: {
            auth: { can: ['rota.view'], user: { id: 1, member_name: 'dana', full_name: 'Dana Resident', position: 4 } },
            nav: { units: [] },
            flash: {},
        },
        url: '/rota',
    }),
}));

import { router } from '@inertiajs/vue3';
import Rota from '../../resources/js/Pages/Rota.vue';

// Every date on this screen arrives as a SERVER-BUILT label pair. The component may place these
// strings; it may not derive one (Decision A / ST-06, guarded at source by
// CalendarIsTheOnlyConverterTest's ten needles over resources/js).
const label = (date) => ({ date, hijri: `${date} AH`, weekend: false, holiday: null, day_type: 'WD' });

const week = (starts, ends) => ({
    starts_on: starts,
    ends_on: ends,
    clipped_starts_on: starts,
    clipped_ends_on: ends,
    starts_label: label(starts),
    ends_label: label(ends),
});

const periodOne = {
    id: 101, position: 1, label: 'Block 1', kind: 'week_block',
    starts_on: '2026-07-01', ends_on: '2026-07-28',
    starts_label: label('2026-07-01'), ends_label: label('2026-07-28'),
    weeks: [week('2026-07-01', '2026-07-07'), week('2026-07-08', '2026-07-14')],
};

const periodTwo = {
    id: 102, position: 2, label: 'Block 2', kind: 'week_block',
    starts_on: '2026-07-29', ends_on: '2026-08-25',
    starts_label: label('2026-07-29'), ends_label: label('2026-08-25'),
    weeks: [week('2026-07-29', '2026-08-04')],
};

const units = [
    { id: 10, code: 'PICU', name: 'Paediatric ICU', bar_class: 'channel-bar-picu' },
    { id: 11, code: 'NICU', name: 'Neonatal ICU', bar_class: 'channel-bar-nicu' },
];

const levels = [
    { id: 1, code: 'R1', name: 'Resident 1' },
    { id: 2, code: 'R2', name: 'Resident 2' },
];

const span = (id, unitId, starts, ends) => ({
    id, unit_id: unitId, unit_code: unitId === 10 ? 'PICU' : 'NICU',
    starts_on: starts, ends_on: ends, days: 1,
    starts_label: label(starts), ends_label: label(ends),
});

const person = (id, fullName, shortName) => ({
    id, full_name: fullName, short_name: shortName, position: 4,
    external: false, active: true, retired: false, has_account: true, joined_at: null,
});

const dana = {
    person: person(5, 'Dana Resident', 'dana'),
    group_level_id: 1,
    stale: false,
    cells: {
        101: { spans: [span(900, 10, '2026-07-01', '2026-07-28')], uncovered_days: 0, level_id: 1, vacations: [] },
        102: {
            spans: [span(901, 10, '2026-07-29', '2026-08-10'), span(902, 11, '2026-08-15', '2026-08-25')],
            uncovered_days: 4,
            level_id: 1,
            vacations: [],
        },
    },
};

const omar = {
    person: person(6, 'Omar Sample', 'omar'),
    group_level_id: 2,
    stale: false,
    cells: {
        101: {
            spans: [], uncovered_days: 28, level_id: 2,
            vacations: [{
                id: 77, starts_on: '2026-07-06', ends_on: '2026-07-10', granularity: 'week',
                starts_label: label('2026-07-06'), ends_label: label('2026-07-10'),
            }],
        },
        102: { spans: [span(903, 11, '2026-07-29', '2026-08-25')], uncovered_days: 0, level_id: 2, vacations: [] },
    },
};

const summary = {
    101: {
        by_level_unit: { 1: { 10: { people: 1, days: 28 } } },
        assigned_days: 28,
        uncovered_days: 28,
        people_with_a_gap: 0,
        unassigned_people: 1,
        weeks: [
            { starts_on: '2026-07-01', ends_on: '2026-07-07', clipped_starts_on: '2026-07-01', clipped_ends_on: '2026-07-07', on_vacation: 1, person_ids: [6] },
            { starts_on: '2026-07-08', ends_on: '2026-07-14', clipped_starts_on: '2026-07-08', clipped_ends_on: '2026-07-14', on_vacation: 1, person_ids: [6] },
        ],
        stale_people: 2,
    },
    102: {
        by_level_unit: { 1: { 10: { people: 1, days: 13 }, 11: { people: 1, days: 11 } }, 2: { 11: { people: 1, days: 28 } } },
        assigned_days: 52,
        uncovered_days: 4,
        people_with_a_gap: 1,
        unassigned_people: 0,
        weeks: [
            { starts_on: '2026-07-29', ends_on: '2026-08-04', clipped_starts_on: '2026-07-29', clipped_ends_on: '2026-08-04', on_vacation: 0, person_ids: [] },
        ],
        stale_people: 0,
    },
};

const mountRota = (overrides = {}) => mount(Rota, {
    props: {
        academic_years: ['2025-2026', '2026-2027'],
        year: '2026-2027',
        grid: { periods: [periodOne, periodTwo], levels, units, rows: [dana, omar] },
        summary,
        filters: { q: '', level: null },
        ...overrides,
    },
});

/**
 * Munawib MR-05 and MR-07's client half: the read view every member of the department can reach.
 *
 * Counts are asserted with findAll().toHaveLength(2) wherever a row is involved, because this
 * screen renders the mobile card list AND the desktop table in one pass (only CSS hides one, the
 * same shape MasterRota.vue uses). A control removed from one markup and left in the other would
 * pass a get()-based assertion while still being on screen at the other breakpoint — and on THIS
 * screen the thing being asserted is usually that a control is absent, which makes the trap worse.
 */
describe('Rota — the read view a resident lands on', () => {
    beforeEach(() => {
        router.get.mockClear();
        router.patch.mockClear();
        router.post.mockClear();
        router.delete.mockClear();
    });

    it('renders one strip row per person, with a chip per period carrying the unit code', () => {
        const w = mountRota();

        expect(w.findAll('[data-row-id="person-5"]')).toHaveLength(2);
        expect(w.findAll('[data-row-id="person-6"]')).toHaveLength(2);

        expect(w.findAll('[data-testid="cell-5-101"]')).toHaveLength(2);
        expect(w.findAll('[data-testid="cell-5-102"]')).toHaveLength(2);

        expect(w.get('[data-testid="cell-5-101"]').text()).toContain('PICU');
        expect(w.get('[data-testid="cell-6-102"]').text()).toContain('NICU');
        expect(w.text()).toContain('Dana Resident');
        expect(w.text()).toContain('Block 1');
    });

    it('renders both spans of a split cell with the server-supplied labels, and no editing control', () => {
        const w = mountRota();

        const cell = w.get('[data-testid="cell-5-102"]');
        expect(cell.text()).toContain('PICU');
        expect(cell.text()).toContain('NICU');
        expect(cell.text()).toContain('2026-07-29');
        expect(cell.text()).toContain('2026-08-25');

        // The editor's affordances must not exist here at either breakpoint.
        expect(w.findAll('[data-testid="split-open-5-102"]')).toHaveLength(0);
        expect(w.findAll('[data-testid="clear-cell-5-102"]')).toHaveLength(0);
        expect(w.findAll('[data-testid="vacation-open-5-102"]')).toHaveLength(0);
    });

    it('renders the uncovered-day count on a partly-covered cell and not on a full one', () => {
        const w = mountRota();

        expect(w.findAll('[data-testid="uncovered-5-102"]')).toHaveLength(2);
        expect(w.get('[data-testid="uncovered-5-102"]').text()).toContain('4');

        expect(w.findAll('[data-testid="uncovered-5-101"]')).toHaveLength(0);

        // A cell with NO span at all is not a "28 day gap" reading — it is simply unassigned, and
        // saying so in words is what tells a reader nothing was planned rather than half-planned.
        expect(w.findAll('[data-testid="uncovered-6-101"]')).toHaveLength(0);
        expect(w.get('[data-testid="cell-6-101"]').text()).toContain('Unassigned');
    });

    it('marks the cells a vacation touches, with the dates the server formatted', () => {
        const w = mountRota();

        expect(w.findAll('[data-testid="leave-6-101"]')).toHaveLength(2);
        expect(w.get('[data-testid="leave-6-101"]').text()).toContain('2026-07-06');
        expect(w.get('[data-testid="leave-6-101"]').text()).toContain('2026-07-10');

        expect(w.findAll('[data-testid="leave-5-101"]')).toHaveLength(0);
    });

    it('renders the availability summary per level and unit, and the per-week vacation counts', () => {
        const w = mountRota();

        const block2 = w.get('[data-testid="summary-period-102"]');
        // by_level_unit[1][10] === { people: 1, days: 13 } — people AND person-days, because
        // "two people at R1 on PICU" and "one person for twice as long" are different facts.
        expect(w.get('[data-testid="summary-cell-102-1-10"]').text()).toContain('1');
        expect(w.get('[data-testid="summary-cell-102-1-10"]').text()).toContain('13');
        expect(w.get('[data-testid="summary-cell-102-2-11"]').text()).toContain('28');
        expect(block2.text()).toContain('R1');
        expect(block2.text()).toContain('PICU');

        // The gap figures, which are what make this an AVAILABILITY summary rather than an
        // assignment count (Decision B).
        expect(block2.text()).toContain('4');
        expect(w.get('[data-testid="summary-period-101"]').text()).toContain('Unassigned');

        // MR-07's "including who is on vacation each week".
        expect(w.get('[data-testid="summary-week-101-0"]').text()).toContain('1');
        expect(w.get('[data-testid="summary-week-101-0"]').text()).toContain('2026-07-01');

        // Decision D: a stale cell is nobody's coverage, and it is not silently zero either.
        expect(w.get('[data-testid="summary-stale-101"]').text()).toContain('2');
        expect(w.findAll('[data-testid="summary-stale-102"]')).toHaveLength(0);
    });

    it('writes nothing: no form, no write request, and no control inside the strip', async () => {
        const w = mountRota();

        expect(w.findAll('form')).toHaveLength(0);

        // The strip itself carries no control of any kind — the filters live outside it, and they
        // only navigate.
        const strip = w.get('[data-testid="rota-strip"]');
        expect(strip.findAll('select')).toHaveLength(0);
        expect(strip.findAll('button')).toHaveLength(0);
        expect(strip.findAll('input')).toHaveLength(0);

        // Click everything the PAGE offers; none of it may reach a write verb. Scoped to the
        // layout's main landmark on purpose — AppLayout's own "Sign out" is a POST, it belongs to
        // every screen in the app, and sweeping it in here would assert something about the
        // layout rather than about the rota.
        for (const button of w.get('main#main-content').findAll('button')) {
            await button.trigger('click');
        }

        expect(router.patch).not.toHaveBeenCalled();
        expect(router.post).not.toHaveBeenCalled();
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('pushes the search to the server, keeping the year, because the filter is server-side', async () => {
        const w = mountRota();

        const search = w.get('[data-testid="rota-search"]');
        await search.setValue('dana');
        await search.trigger('change');

        expect(router.get).toHaveBeenCalledWith(
            '/rota',
            expect.objectContaining({ year: '2026-2027', q: 'dana' }),
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('pushes the level filter the same way, and offers every level the grid carries', async () => {
        const w = mountRota();

        const select = w.get('[data-testid="rota-level"]');
        expect(select.findAll('option')).toHaveLength(levels.length + 1);

        await select.setValue('2');

        expect(router.get).toHaveBeenCalledWith(
            '/rota',
            expect.objectContaining({ year: '2026-2027', level: '2' }),
            expect.anything(),
        );
    });

    it('reflects the filters the SERVER applied rather than re-deriving them', () => {
        const w = mountRota({ filters: { q: 'omar', level: 2 } });

        expect(w.get('[data-testid="rota-search"]').element.value).toBe('omar');
        expect(w.get('[data-testid="rota-level"]').element.value).toBe('2');
    });

    it('says so when a search matches nobody, rather than rendering an empty table', () => {
        const w = mountRota({
            grid: { periods: [periodOne, periodTwo], levels, units, rows: [] },
            filters: { q: 'nobody', level: null },
        });

        expect(w.get('[data-testid="rota-no-matches"]').text()).toContain('nobody');
        expect(w.findAll('[data-testid="rota-strip"]')).toHaveLength(0);
        // The summary is the DEPARTMENT's, not the filtered set's — it stays.
        expect(w.findAll('[data-testid="summary-period-101"]')).toHaveLength(1);
    });

    it('shows the two empty states: no rota at all, and a year with no periods', () => {
        const nothing = mountRota({ academic_years: [], year: null, grid: null, summary: null });
        expect(nothing.text()).toContain('No rota yet');

        const noYear = mountRota({ year: null, grid: null, summary: null });
        expect(noYear.text()).toContain('Choose an academic year');
    });
});
