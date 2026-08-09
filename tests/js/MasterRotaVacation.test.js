import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mirrors MasterRotaSplit.test.js's mock — MasterRota.vue is wrapped in AppLayout, which reads
// usePage() for its own nav capability gates.
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

// A period whose 4-week span does NOT start on a Sunday, so its `weeks` list (as
// Calendar::weeksIn() would build it, QCH weekend Fri+Sat) carries a genuine partial week at
// each end — the same shape a real server response has, never hand-simplified.
const period = {
    id: 101,
    position: 1,
    label: 'Block 1',
    kind: 'week_block',
    starts_on: '2026-08-12',
    ends_on: '2026-09-08',
    starts_label: { date: '2026-08-12', hijri: '', weekend: false, holiday: null, day_type: 'WD' },
    ends_label: { date: '2026-09-08', hijri: '', weekend: false, holiday: null, day_type: 'WD' },
    weeks: [
        { starts_on: '2026-08-09', ends_on: '2026-08-15', clipped_starts_on: '2026-08-12', clipped_ends_on: '2026-08-15',
          starts_label: { date: '2026-08-09', hijri: '' }, ends_label: { date: '2026-08-15', hijri: '' } },
        { starts_on: '2026-08-16', ends_on: '2026-08-22', clipped_starts_on: '2026-08-16', clipped_ends_on: '2026-08-22',
          starts_label: { date: '2026-08-16', hijri: '' }, ends_label: { date: '2026-08-22', hijri: '' } },
        { starts_on: '2026-08-23', ends_on: '2026-08-29', clipped_starts_on: '2026-08-23', clipped_ends_on: '2026-08-29',
          starts_label: { date: '2026-08-23', hijri: '' }, ends_label: { date: '2026-08-29', hijri: '' } },
        { starts_on: '2026-08-30', ends_on: '2026-09-05', clipped_starts_on: '2026-08-30', clipped_ends_on: '2026-09-05',
          starts_label: { date: '2026-08-30', hijri: '' }, ends_label: { date: '2026-09-05', hijri: '' } },
        { starts_on: '2026-09-06', ends_on: '2026-09-12', clipped_starts_on: '2026-09-06', clipped_ends_on: '2026-09-08',
          starts_label: { date: '2026-09-06', hijri: '' }, ends_label: { date: '2026-09-12', hijri: '' } },
    ],
};

const units = [{ id: 10, code: 'PICU', name: 'PICU', bar_class: 'channel-bar-picu' }];
const levels = [{ id: 1, code: 'R1', name: 'Resident 1' }];

const personRow = (vacations = []) => ({
    person: {
        id: 5, full_name: 'Dana Resident', short_name: null, position: 4,
        external: false, active: true, retired: false, has_account: true, joined_at: null,
    },
    group_level_id: 1,
    cells: {
        101: {
            spans: [
                { id: 900, unit_id: 10, unit_code: 'PICU', starts_on: '2026-08-12', ends_on: '2026-09-08',
                  starts_label: { date: '2026-08-12', hijri: '' }, ends_label: { date: '2026-09-08', hijri: '' } },
            ],
            uncovered_days: 0,
            level_id: 1,
            vacations,
        },
    },
});

const buildGrid = (vacations = []) => ({ periods: [period], levels, units, rows: [personRow(vacations)] });

const mountGrid = (grid = buildGrid()) => mount(MasterRota, {
    props: { academic_years: ['2026-2027'], year: '2026-2027', grid },
});

describe('Admin/MasterRota — booking and cancelling leave (Task 10)', () => {
    beforeEach(() => {
        router.post.mockClear();
        router.delete.mockClear();
    });

    it('previews the departments own snapped week from periods[].weeks, with no client date construction', async () => {
        const w = mountGrid();

        await w.get('[data-testid="vacation-open-5-101"]').trigger('click');
        await w.get('[data-testid="vacation-granularity-week"]').setValue('week');

        await w.get('[data-testid="vacation-starts-on"]').setValue('2026-08-19');
        await w.get('[data-testid="vacation-ends-on"]').setValue('2026-08-19');

        // 2026-08-19 falls inside the second listed week (2026-08-16..2026-08-22) — the preview
        // must show THAT week's true bounds, found by matching against the server-supplied
        // `weeks` list, not by constructing a date.
        expect(w.get('[data-testid="vacation-week-preview"]').text()).toContain('2026-08-16');
        expect(w.get('[data-testid="vacation-week-preview"]').text()).toContain('2026-08-22');
    });

    it('shows existing leave read-only in the cell with a Cancel control, and posts a DELETE to /admin/rota/vacations/{id}', async () => {
        const w = mountGrid(buildGrid([
            { id: 77, starts_on: '2026-08-12', ends_on: '2026-08-14', granularity: 'date',
              starts_label: { date: '2026-08-12', hijri: '' }, ends_label: { date: '2026-08-14', hijri: '' } },
        ]));

        expect(w.text()).toContain('2026-08-12');
        expect(w.text()).toContain('2026-08-14');

        await w.get('[data-testid="vacation-cancel-77"]').trigger('click');

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/admin/rota/vacations/77',
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );
    });

    it('posts person_id/starts_on/ends_on/granularity to /admin/rota/vacations on booking', async () => {
        const w = mountGrid();

        await w.get('[data-testid="vacation-open-5-101"]').trigger('click');
        await w.get('[data-testid="vacation-starts-on"]').setValue('2026-08-20');
        await w.get('[data-testid="vacation-ends-on"]').setValue('2026-08-21');
        await w.get('[data-testid="vacation-save"]').trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/admin/rota/vacations',
            {
                person_id: 5, starts_on: '2026-08-20', ends_on: '2026-08-21', granularity: 'date',
            },
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );
    });
});
