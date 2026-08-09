import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// Same mock pattern as MasterRotaSplit.test.js / MasterRotaVacation.test.js.
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

const units = [{ id: 10, code: 'PICU', name: 'PICU', bar_class: 'channel-bar-picu' }];
const levels = [{ id: 1, code: 'R1', name: 'Resident 1' }];

const span = {
    id: 900, unit_id: 10, unit_code: 'PICU', starts_on: '2026-07-01', ends_on: '2026-07-28',
    starts_label: { date: '2026-07-01', hijri: '' }, ends_label: { date: '2026-07-28', hijri: '' },
};

const row = (id, name, stale, spans = [span]) => ({
    person: {
        id, full_name: name, short_name: null, position: 4,
        external: false, active: !stale, retired: false, has_account: true, joined_at: null,
    },
    group_level_id: 1,
    stale,
    cells: { 101: { spans, uncovered_days: spans.length ? 0 : 28, level_id: 1, vacations: [] } },
});

const mountGrid = (rows) => mount(MasterRota, {
    props: {
        academic_years: ['2026-2027'],
        year: '2026-2027',
        grid: { periods: [period], levels, units, rows },
    },
});

/**
 * Pre-merge finding 1's client half. A person who was assigned and then deactivated keeps a grid
 * row (`stale: true`) so the operator can EMPTY it — but the row is read-only otherwise: naming
 * an inactive person is what the server refuses, and offering a control that always 422s is worse
 * than not offering it.
 *
 * Every assertion counts elements across BOTH markups: MasterRota.vue renders the mobile cards and
 * the desktop table in one pass (only CSS hides one), so a control removed from one and left in
 * the other would pass a `get()`-based test while still being on screen at the other breakpoint.
 */
describe('Admin/MasterRota — a stale (deactivated) row is read-only except for Clear', () => {
    beforeEach(() => {
        router.patch.mockClear();
        router.delete.mockClear();
    });

    it('renders every editing control twice — mobile and desktop — for an active row', () => {
        const w = mountGrid([row(5, 'Dana Resident', false)]);

        expect(w.findAll('[data-row-id="person-5"] select')).toHaveLength(2);
        expect(w.findAll('[data-testid="split-open-5-101"]')).toHaveLength(2);
        expect(w.findAll('[data-testid="vacation-open-5-101"]')).toHaveLength(2);
        expect(w.findAll('[data-testid="clear-cell-5-101"]')).toHaveLength(2);
    });

    it('offers no unit picker, no split and no leave booking on a stale row, in either markup', () => {
        const w = mountGrid([row(6, 'Omar Departed', true)]);

        expect(w.findAll('[data-row-id="person-6"] select')).toHaveLength(0);
        expect(w.findAll('[data-testid="split-open-6-101"]')).toHaveLength(0);
        expect(w.findAll('[data-testid="vacation-open-6-101"]')).toHaveLength(0);
    });

    it('keeps the Clear control on a stale row — it is the only way to empty the cell', async () => {
        const w = mountGrid([row(6, 'Omar Departed', true)]);

        expect(w.findAll('[data-testid="clear-cell-6-101"]')).toHaveLength(2);

        await w.get('[data-testid="clear-cell-6-101"]').trigger('click');

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/admin/rota/cell',
            expect.objectContaining({ data: { person_id: 6, period_id: 101 } }),
        );
    });

    it('shows the stale rows spans read-only, and says why the row is there', () => {
        const w = mountGrid([row(6, 'Omar Departed', true)]);

        expect(w.text()).toContain('Omar Departed');
        expect(w.text()).toContain('PICU');
        expect(w.text()).toContain('Inactive');
    });

    it('offers no Clear on an already-empty stale cell — there is nothing to clear', () => {
        const w = mountGrid([row(6, 'Omar Departed', true, [])]);

        expect(w.findAll('[data-testid="clear-cell-6-101"]')).toHaveLength(0);
    });
});
