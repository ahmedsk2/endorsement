import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mirrors the mock pattern used by AppLayout.test.js / EndorsementSheet.test.js: a real <a> for
// Link, captured router calls, and a usePage stub granting `rota.manage` — MasterRota.vue is
// wrapped in AppLayout, which reads `usePage()` for the nav's own capability gates.
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), patch: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({
        props: { auth: { can: ['rota.manage'], user: { id: 1, member_name: 'admin', full_name: 'Admin', position: 0 } }, nav: { units: [] }, flash: {} },
        url: '/admin/rota',
    }),
}));

import { router } from '@inertiajs/vue3';
import MasterRota from '../../resources/js/Pages/Admin/MasterRota.vue';

const period = {
    id: 101,
    position: 1,
    label: 'Block 1',
    kind: 'week_block',
    starts_on: '2026-07-01',
    ends_on: '2026-07-28',
    starts_label: { date: '2026-07-01', hijri: '', weekend: false, holiday: null, day_type: 'WD' },
    ends_label: { date: '2026-07-28', hijri: '', weekend: false, holiday: null, day_type: 'WD' },
    weeks: [],
};

const units = [
    { id: 10, code: 'PICU', name: 'PICU', bar_class: 'channel-bar-picu' },
    { id: 11, code: 'NICU', name: 'NICU', bar_class: 'channel-bar-nicu' },
];

const levels = [{ id: 1, code: 'R1', name: 'Resident 1' }];

const personRow = (uncoveredDays) => ({
    person: {
        id: 5, full_name: 'Dana Resident', short_name: null, position: 4,
        external: false, active: true, retired: false, has_account: true, joined_at: null,
    },
    group_level_id: 1,
    cells: {
        101: {
            spans: [
                {
                    id: 900, unit_id: 10, unit_code: 'PICU', starts_on: '2026-07-01', ends_on: '2026-07-14',
                    starts_label: { date: '2026-07-01', hijri: '' }, ends_label: { date: '2026-07-14', hijri: '' },
                },
                {
                    id: 901, unit_id: 11, unit_code: 'NICU', starts_on: '2026-07-21', ends_on: '2026-07-28',
                    starts_label: { date: '2026-07-21', hijri: '' }, ends_label: { date: '2026-07-28', hijri: '' },
                },
            ],
            uncovered_days: uncoveredDays,
            level_id: 1,
            vacations: [],
        },
    },
});

const buildGrid = (uncoveredDays = 6) => ({
    periods: [period],
    levels,
    units,
    rows: [personRow(uncoveredDays)],
});

const mountGrid = (grid = buildGrid()) => mount(MasterRota, {
    props: { academic_years: ['2026-2027'], year: '2026-2027', grid },
});

describe('Admin/MasterRota — the split editor (Task 9)', () => {
    beforeEach(() => {
        router.post.mockClear();
    });

    it('renders one row per span, with the period\'s own bounds as min/max on each date input', async () => {
        const w = mountGrid();

        await w.get('[data-testid="split-open-5-101"]').trigger('click');

        const rows = w.findAll('[data-testid="split-span-row"]');
        expect(rows).toHaveLength(2);

        const starts = w.findAll('[data-testid="split-span-start"]');
        const ends = w.findAll('[data-testid="split-span-end"]');

        starts.forEach((input) => {
            expect(input.attributes('min')).toBe('2026-07-01');
            expect(input.attributes('max')).toBe('2026-07-28');
        });
        ends.forEach((input) => {
            expect(input.attributes('min')).toBe('2026-07-01');
            expect(input.attributes('max')).toBe('2026-07-28');
        });

        // The rows carry the EXISTING spans' own dates — server-supplied strings, never
        // re-derived or reformatted client-side.
        expect(starts[0].element.value).toBe('2026-07-01');
        expect(ends[0].element.value).toBe('2026-07-14');
        expect(starts[1].element.value).toBe('2026-07-21');
        expect(ends[1].element.value).toBe('2026-07-28');
    });

    it('shows the uncovered-day count from props, and updates it from a fresh prop after a save — never recomputed from the unsaved inputs', async () => {
        const w = mountGrid(buildGrid(6));

        await w.get('[data-testid="split-open-5-101"]').trigger('click');
        expect(w.get('[data-testid="split-uncovered"]').text()).toContain('6 day(s)');

        // Simulate what a successful Inertia visit does: the server re-renders `grid` with a
        // fresh count for the SAME cell. The panel must pick this up by re-reading props, not by
        // computing anything from the (still open, still unsaved) date inputs above.
        await w.setProps({ grid: buildGrid(0) });

        expect(w.get('[data-testid="split-uncovered"]').text()).toContain('0 day(s)');
    });

    it('appends a row whose dates start empty when "Add span" is clicked, rather than guessing', async () => {
        const w = mountGrid();

        await w.get('[data-testid="split-open-5-101"]').trigger('click');
        expect(w.findAll('[data-testid="split-span-row"]')).toHaveLength(2);

        await w.get('[data-testid="split-add-span"]').trigger('click');

        const rows = w.findAll('[data-testid="split-span-row"]');
        expect(rows).toHaveLength(3);

        const lastStart = rows[2].get('[data-testid="split-span-start"]');
        const lastEnd = rows[2].get('[data-testid="split-span-end"]');
        const lastUnit = rows[2].get('[data-testid="split-span-unit"]');

        expect(lastStart.element.value).toBe('');
        expect(lastEnd.element.value).toBe('');
        expect(lastUnit.element.value).toBe('');
    });

    it('disables removing the last remaining span', async () => {
        const w = mountGrid();

        await w.get('[data-testid="split-open-5-101"]').trigger('click');
        const removeButtons = w.findAll('[data-testid="split-remove-span"]');
        expect(removeButtons).toHaveLength(2);

        await removeButtons[0].trigger('click');
        expect(w.findAll('[data-testid="split-span-row"]')).toHaveLength(1);

        const lastRemove = w.get('[data-testid="split-remove-span"]');
        expect(lastRemove.attributes('disabled')).toBeDefined();
    });

    it('posts the whole span set to /admin/rota/cell/split on save', async () => {
        const w = mountGrid();

        await w.get('[data-testid="split-open-5-101"]').trigger('click');
        await w.get('[data-testid="split-save"]').trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/admin/rota/cell/split',
            expect.objectContaining({
                person_id: 5,
                period_id: 101,
                spans: expect.arrayContaining([
                    expect.objectContaining({ unit_id: 10, starts_on: '2026-07-01', ends_on: '2026-07-14' }),
                    expect.objectContaining({ unit_id: 11, starts_on: '2026-07-21', ends_on: '2026-07-28' }),
                ]),
            }),
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );
    });
});

/**
 * The Clear affordance. Neither Task 8 nor Task 9 specified building this control despite the
 * backend route existing from Task 8 and Task 9's own "Remove span" tooltip naming Clear as the
 * way to empty a cell — found empirically while writing Task 11's e2e journey, which needs a real
 * UI control to drive (see this task's Amendments entry). `DELETE /admin/rota/cell` itself is
 * proven at the HTTP layer by `RotaClearEndpointTest`; this only proves the button sends the
 * right identity pair and appears/disappears with the cell's own contents.
 */
describe('Admin/MasterRota — the Clear affordance', () => {
    beforeEach(() => {
        router.delete.mockClear();
    });

    const emptyCellGrid = () => ({
        periods: [period],
        levels,
        units,
        rows: [{
            person: {
                id: 6, full_name: 'Riley Empty', short_name: null, position: 4,
                external: false, active: true, retired: false, has_account: true, joined_at: null,
            },
            group_level_id: 1,
            cells: { 101: { spans: [], uncovered_days: 28, level_id: 1, vacations: [] } },
        }],
    });

    it('renders no Clear control on an empty cell — there is nothing to clear', () => {
        const w = mountGrid(emptyCellGrid());

        expect(w.find('[data-testid="clear-cell-6-101"]').exists()).toBe(false);
    });

    it('renders a Clear control on a simple, whole-period assignment', () => {
        const w = mount(MasterRota, {
            props: {
                academic_years: ['2026-2027'],
                year: '2026-2027',
                grid: {
                    periods: [period],
                    levels,
                    units,
                    rows: [{
                        person: {
                            id: 7, full_name: 'Sam Simple', short_name: null, position: 4,
                            external: false, active: true, retired: false, has_account: true, joined_at: null,
                        },
                        group_level_id: 1,
                        cells: {
                            101: {
                                spans: [{
                                    id: 950, unit_id: 10, unit_code: 'PICU', starts_on: '2026-07-01', ends_on: '2026-07-28',
                                    starts_label: { date: '2026-07-01', hijri: '' }, ends_label: { date: '2026-07-28', hijri: '' },
                                }],
                                uncovered_days: 0,
                                level_id: 1,
                                vacations: [],
                            },
                        },
                    }],
                },
            },
        });

        expect(w.find('[data-testid="clear-cell-7-101"]').exists()).toBe(true);
    });

    it('renders a Clear control on an already-split cell too', () => {
        const w = mountGrid();

        expect(w.find('[data-testid="clear-cell-5-101"]').exists()).toBe(true);
    });

    it('sends only the person/period identity pair to DELETE /admin/rota/cell', async () => {
        const w = mountGrid();

        await w.get('[data-testid="clear-cell-5-101"]').trigger('click');

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/admin/rota/cell',
            expect.objectContaining({
                data: { person_id: 5, period_id: 101 },
                preserveScroll: true,
                preserveState: true,
            }),
        );
    });
});
