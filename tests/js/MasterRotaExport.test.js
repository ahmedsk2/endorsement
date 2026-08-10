import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';

// Same mock pattern as MasterRotaSplit.test.js / MasterRotaFill.test.js.
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), patch: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({
        props: { auth: { can: ['rota.manage'], user: { id: 1, member_name: 'admin', full_name: 'Admin', position: 0 } }, nav: { units: [] }, flash: {} },
        url: '/admin/rota?year=2026-2027',
    }),
}));

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

const row = {
    person: {
        id: 9, full_name: 'Sara Ahmed', short_name: 's.ahmed', position: 4,
        external: false, active: true, retired: false, has_account: true, joined_at: null,
    },
    group_level_id: 1,
    stale: false,
    cells: { 101: { spans: [], uncovered_days: 28, level_id: 1, vacations: [] } },
};

const mountScreen = (overrides = {}) => mount(MasterRota, {
    props: {
        academic_years: ['2026-2027'],
        year: '2026-2027',
        grid: { periods: [period], levels, units, rows: [row] },
        ...overrides,
    },
});

/**
 * MR-06's export, client half (P1d-2 Decision G, Task 10).
 *
 * The two links are asserted by their HREF, not merely by their presence: the whole point of two
 * routes rather than one with a `?file=` parameter is that each URL is independently bookmarkable
 * and independently audited, and a screen that pointed both buttons at the same file would look
 * completely correct.
 *
 * The warning is asserted because a prop nothing renders is the defect Task 9's amendment records
 * — a panel bound to a permanently-undefined prop kept `npm run build`, `npm test` and
 * `php artisan test` all green. The PHP side proves the COUNT; only a mount proves an operator
 * ever sees it.
 */
describe('Admin/MasterRota — MR-06 export', () => {
    it('offers two files on two URLs, each carrying the year on screen', () => {
        const wrapper = mountScreen();

        expect(wrapper.get('[data-testid="export-assignments"]').attributes('href'))
            .toBe('/admin/rota/export/assignments?year=2026-2027');
        expect(wrapper.get('[data-testid="export-vacations"]').attributes('href'))
            .toBe('/admin/rota/export/vacations?year=2026-2027');
    });

    /**
     * A file download is a stream with a Content-Disposition header, not an X-Inertia page object.
     * An Inertia `<Link>` (or `router.get`) would try to parse it as a page and the download would
     * simply never happen — so both controls must be plain anchors.
     */
    it('downloads through plain anchors, never the Inertia router', () => {
        const wrapper = mountScreen();

        ['export-assignments', 'export-vacations'].forEach((testid) => {
            const el = wrapper.get(`[data-testid="${testid}"]`);
            expect(el.element.tagName).toBe('A');
            expect(el.attributes('href')).toBeTruthy();
        });
    });

    it('says nothing about short names when everybody in the year has one', () => {
        const wrapper = mountScreen({ people_without_a_short_name: 0 });

        expect(wrapper.find('[data-testid="export-short-name-warning"]').exists()).toBe(false);
    });

    it('names the count of people who would export a blank handle, beside the buttons', () => {
        const wrapper = mountScreen({ people_without_a_short_name: 3 });

        const warning = wrapper.get('[data-testid="export-short-name-warning"]');

        expect(warning.text()).toContain('3');
        expect(warning.text()).toContain('no short name');
        // The remedy, not just the complaint — the count alone leaves an operator nowhere to go.
        expect(warning.get('a').attributes('href')).toBe('/admin/people');
        // In the same panel as the buttons: a warning elsewhere on a long page is a warning read
        // after the file has already been downloaded.
        expect(wrapper.get('[data-testid="rota-export"]').find('[data-testid="export-short-name-warning"]').exists())
            .toBe(true);
    });

    it('offers no export at all until a year is on screen', () => {
        const wrapper = mountScreen({ year: null, grid: null });

        expect(wrapper.find('[data-testid="rota-export"]').exists()).toBe(false);
    });
});
