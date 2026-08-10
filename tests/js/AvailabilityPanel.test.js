import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// One mock for BOTH pages: the editor and the read view are mounted side by side in this file, so
// `can` carries both capabilities. It never reaches the panel — AppLayout reads it for the nav's
// own gates, and nothing extracted below comes from the layout.
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), patch: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({
        props: {
            auth: {
                can: ['rota.view', 'rota.manage'],
                user: { id: 1, member_name: 'admin', full_name: 'Admin', position: 0 },
            },
            nav: { units: [] },
            flash: {},
        },
        url: '/rota',
    }),
}));

import { router } from '@inertiajs/vue3';
import Rota from '../../resources/js/Pages/Rota.vue';
import MasterRota from '../../resources/js/Pages/Admin/MasterRota.vue';

// Every date arrives as a SERVER-BUILT label pair — the panel places these strings and derives
// none of its own (Decision A / ST-06, guarded at source by CalendarIsTheOnlyConverterTest).
const label = (date) => ({ date, hijri: `${date} AH`, weekend: false, holiday: null, day_type: 'WD' });

const week = (starts, ends, clippedStarts, clippedEnds) => ({
    starts_on: starts,
    ends_on: ends,
    clipped_starts_on: clippedStarts ?? starts,
    clipped_ends_on: clippedEnds ?? ends,
    starts_label: label(starts),
    ends_label: label(ends),
});

const periodOne = {
    id: 101, position: 1, label: 'Block 1', kind: 'week_block',
    starts_on: '2026-07-01', ends_on: '2026-07-28',
    starts_label: label('2026-07-01'), ends_label: label('2026-07-28'),
    // The first week starts BEFORE the block does, so the clipped bounds differ from the real
    // ones and the panel's "(part week)" branch is exercised rather than merely present.
    weeks: [week('2026-06-28', '2026-07-04'), week('2026-07-05', '2026-07-11')],
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
    starts_on: starts, ends_on: ends, days: 14,
    starts_label: label(starts), ends_label: label(ends),
});

const row = (id, fullName, groupLevelId, cells, stale = false) => ({
    person: {
        id, full_name: fullName, short_name: fullName.split(' ')[0].toLowerCase(), position: 4,
        external: false, active: !stale, retired: false, has_account: true, joined_at: null,
    },
    group_level_id: groupLevelId,
    stale,
    cells,
});

const rows = [
    row(5, 'Dana Resident', 1, {
        101: { spans: [span(900, 10, '2026-07-01', '2026-07-14')], uncovered_days: 14, level_id: 1, vacations: [] },
        102: { spans: [span(901, 11, '2026-07-29', '2026-08-25')], uncovered_days: 0, level_id: 2, vacations: [] },
    }),
    row(6, 'Omar Sample', 2, {
        101: { spans: [], uncovered_days: 28, level_id: 2, vacations: [] },
        102: { spans: [span(902, 10, '2026-07-29', '2026-08-25')], uncovered_days: 0, level_id: 2, vacations: [] },
    }),
];

/**
 * Every figure MR-07 defines, non-zero, including the two buckets a lazier fixture would miss:
 * `AvailabilitySummary::NO_LEVEL` (`0` — a person whose level history says nothing about this
 * period) and a unit id that is NOT in `units` (a ward retired since the rota was planned).
 */
const summary = {
    101: {
        by_level_unit: {
            0: { 10: { people: 1, days: 28 } },
            1: { 10: { people: 2, days: 42 }, 11: { people: 1, days: 14 } },
            2: { 99: { people: 1, days: 28 } },
        },
        assigned_days: 112,
        uncovered_days: 14,
        people_with_a_gap: 1,
        unassigned_people: 1,
        weeks: [
            { starts_on: '2026-06-28', ends_on: '2026-07-04', clipped_starts_on: '2026-07-01', clipped_ends_on: '2026-07-04', on_vacation: 2, person_ids: [5, 6] },
            { starts_on: '2026-07-05', ends_on: '2026-07-11', clipped_starts_on: '2026-07-05', clipped_ends_on: '2026-07-11', on_vacation: 0, person_ids: [] },
        ],
        stale_assignments: 3,
    },
    102: {
        by_level_unit: { 2: { 10: { people: 1, days: 28 }, 11: { people: 1, days: 28 } } },
        assigned_days: 56,
        uncovered_days: 0,
        people_with_a_gap: 0,
        unassigned_people: 0,
        weeks: [
            { starts_on: '2026-07-29', ends_on: '2026-08-04', clipped_starts_on: '2026-07-29', clipped_ends_on: '2026-08-04', on_vacation: 0, person_ids: [] },
        ],
        stale_assignments: 0,
    },
};

const grid = { periods: [periodOne, periodTwo], levels, units, rows };

const mountRead = (overrides = {}) => mount(Rota, {
    props: {
        academic_years: ['2026-2027'],
        year: '2026-2027',
        grid,
        summary,
        filters: { q: '', level: null },
        ...overrides,
    },
});

const mountEditor = (overrides = {}) => mount(MasterRota, {
    props: {
        academic_years: ['2026-2027'],
        year: '2026-2027',
        grid,
        summary,
        ...overrides,
    },
});

const panelOf = (wrapper) => wrapper.get('[data-testid="availability-panel"]');

/**
 * MR-07's client half. Decision B made the COMPUTATION single (`AvailabilitySummary`, one pure
 * fold, asserted across two live responses by `AvailabilitySummaryParityTest`); this file is what
 * makes the RENDERING single too.
 *
 * IT COMPARES REAL RENDERED OUTPUT FROM TWO REAL PAGE MOUNTS. Not two calls to one helper, not two
 * mounts of the component on its own: the read view and the editor are each mounted whole, and the
 * `availability-panel` subtree is pulled out of each and compared as markup. That is the only form
 * that can fail for the reason this test exists — a page feeding the shared component a different
 * slice of the grid, or growing a second copy of the panel — and it was verified to fail by
 * deliberately diverging each surface in turn and watching it go red (see this task's Amendments
 * entry). A parity test that cannot fail is worse than none, and this codebase has shipped two of
 * those already in this plan.
 *
 * The fixture is deliberately awkward: a NO_LEVEL bucket, a unit id absent from `grid.units`, a
 * first week clipped by the block boundary, and a non-zero stale count. Two panels rendering
 * nothing are identical, so every branch the panel has must be on screen before "identical" says
 * anything.
 */
describe('AvailabilityPanel — MR-07, one component, two surfaces', () => {
    beforeEach(() => {
        router.get.mockClear();
        router.patch.mockClear();
        router.post.mockClear();
        router.delete.mockClear();
    });

    it('renders byte-identical markup on the read view and on the editor', () => {
        const read = panelOf(mountRead()).html();
        const editor = panelOf(mountEditor()).html();

        expect(editor).toBe(read);
    });

    it('is on each page exactly once — one panel, never a page-local second copy', () => {
        expect(mountRead().findAll('[data-testid="availability-panel"]')).toHaveLength(1);
        expect(mountEditor().findAll('[data-testid="availability-panel"]')).toHaveLength(1);
    });

    /**
     * Non-vacuity for the case above: two panels that rendered nothing would also be identical.
     * Asserted on BOTH mounts rather than one, because "the editor renders an empty panel and the
     * read view a full one" must not be able to hide behind a single-surface assertion.
     */
    it('renders every figure MR-07 defines, on both surfaces', () => {
        for (const panel of [panelOf(mountRead()), panelOf(mountEditor())]) {
            // by_level_unit, keyed on the CELL's level: people AND person-days, because "two
            // people at R1 on PICU" and "one person for twice as long" are different facts.
            expect(panel.get('[data-testid="summary-cell-101-1-10"]').text()).toContain('2');
            expect(panel.get('[data-testid="summary-cell-101-1-10"]').text()).toContain('42');
            expect(panel.text()).toContain('R1');
            expect(panel.text()).toContain('PICU');

            // A person whose level history says nothing about the period is bucketed, not dropped
            // (AvailabilitySummary::NO_LEVEL) — dropping them would break the property that the
            // buckets sum to assigned_days.
            expect(panel.text()).toContain('No level');
            expect(panel.get('[data-testid="summary-cell-101-0-10"]').text()).toContain('28');

            // The gap figures, which are what make this an AVAILABILITY summary rather than an
            // assignment count.
            expect(panel.get('[data-testid="summary-period-101"]').text()).toContain('112');
            expect(panel.get('[data-testid="summary-period-101"]').text()).toContain('14');

            // MR-07's "including who is on vacation each week". The dates are the FULL week's
            // server-built labels (`Calendar::weekOf()` labels the true bounds, and the clipped
            // ones carry no label pair of their own), with a part-week marker when the block does
            // not contain the whole week — which is the state this fixture's first week is in.
            expect(panel.get('[data-testid="summary-week-101-0"]').text()).toContain('2026-06-28');
            expect(panel.get('[data-testid="summary-week-101-0"]').text()).toContain('part week');
            expect(panel.get('[data-testid="summary-week-101-0"]').text()).toContain('2 on leave');
            expect(panel.get('[data-testid="summary-week-101-1"]').text()).not.toContain('part week');

            // Decision D: a departed person's cells are nobody's coverage, and not silently zero.
            expect(panel.get('[data-testid="summary-stale-101"]').text()).toContain('3');
            expect(panel.findAll('[data-testid="summary-stale-102"]')).toHaveLength(0);
        }
    });

    /**
     * The shared component must not carry a write control into the read view. `/rota` is GET-only
     * at the router, so a control here would 403 rather than save — and the whole reason the panel
     * can be shared at all is that it renders numbers and offers nothing.
     */
    it('carries no control of any kind, on either surface', async () => {
        for (const wrapper of [mountRead(), mountEditor()]) {
            const panel = panelOf(wrapper);

            expect(panel.findAll('button')).toHaveLength(0);
            expect(panel.findAll('select')).toHaveLength(0);
            expect(panel.findAll('input')).toHaveLength(0);
            expect(panel.findAll('form')).toHaveLength(0);
            expect(panel.findAll('a')).toHaveLength(0);
        }

        expect(router.patch).not.toHaveBeenCalled();
        expect(router.post).not.toHaveBeenCalled();
        expect(router.delete).not.toHaveBeenCalled();
    });

    /**
     * A retired unit is not in `grid.units` (the grid offers active units only) and its spans carry
     * a null `unit_code` for the same reason, so there is no code anywhere to render for it. The
     * column is marked rather than invented — an id in a column head would read as a ward name.
     */
    it('marks a unit it has no code for instead of inventing one', () => {
        const panel = panelOf(mountRead());
        const heads = panel.get('[data-testid="summary-period-101"]').findAll('th');

        expect(heads.some((th) => th.text() === '—')).toBe(true);
        expect(panel.text()).not.toContain('99');
    });

    /** No summary at all (a year with no periods) renders no panel rather than an empty one. */
    it('renders nothing when there is no summary', () => {
        expect(mountRead({ grid: null, summary: null }).findAll('[data-testid="availability-panel"]')).toHaveLength(0);
        expect(mountEditor({ grid: null, summary: null }).findAll('[data-testid="availability-panel"]')).toHaveLength(0);
    });
});
