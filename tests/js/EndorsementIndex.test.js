import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn(), patch: vi.fn(), delete: vi.fn(), get: vi.fn() },
    usePage: () => ({
        props: { auth: { can: ['endorsement.view', 'endorsement.edit'], user: { id: 1 } }, flash: {} },
        url: '/endorsement/PICU',
    }),
}));

import { router } from '@inertiajs/vue3';
import Index from '../../resources/js/Pages/Endorsement/Index.vue';

const mountIndex = (props = {}) => mount(Index, {
    props: {
        unit: { code: 'PICU', name: 'PICU', profile: { code: 'PICU', extra_row_fields: [], bed_label: 'Bed', consultant_pair: true, consultant_by_label: 'Consultant covering', bar_class: 'channel-bar-picu', plan_label: 'Plan Of Care', narrative_label: 'New events' } },
        dates: [{ date: '2026-07-11', count: 4 }, { date: '2026-07-10', count: 3 }],
        filters: { from: null, to: null },
        ...props,
    },
});

beforeEach(() => {
    router.get.mockReset();
    router.post.mockReset();
});

// G3 — the legacy day index had a start/end date filter (`PICU-Endorsement.php:120-131`).
describe('Endorsement/Index — date-range filter', () => {
    it('renders labelled start and end date inputs', () => {
        const w = mountIndex();
        const from = w.get('[data-testid="filter-from"]');
        const to = w.get('[data-testid="filter-to"]');

        expect(from.attributes('type')).toBe('date');
        expect(to.attributes('type')).toBe('date');
        // Both are reachable by an accessible name.
        expect(from.attributes('aria-label') || w.find(`label[for="${from.attributes('id')}"]`).exists()).toBeTruthy();
        expect(to.attributes('aria-label') || w.find(`label[for="${to.attributes('id')}"]`).exists()).toBeTruthy();
    });

    it('submits the range as a GET query', async () => {
        const w = mountIndex();

        await w.get('[data-testid="filter-from"]').setValue('2026-07-05');
        await w.get('[data-testid="filter-to"]').setValue('2026-07-15');
        await w.get('[data-testid="filter-form"]').trigger('submit');

        expect(router.get).toHaveBeenCalled();
        const [url, params] = router.get.mock.calls[0];
        expect(url).toBe('/endorsement/PICU');
        expect(params).toMatchObject({ from: '2026-07-05', to: '2026-07-15' });
    });

    it('pre-fills the inputs from the server filters and offers a clear action', () => {
        const w = mountIndex({ filters: { from: '2026-07-05', to: '2026-07-15' } });

        expect(w.get('[data-testid="filter-from"]').element.value).toBe('2026-07-05');
        expect(w.get('[data-testid="filter-to"]').element.value).toBe('2026-07-15');
        expect(w.find('[data-testid="filter-clear"]').exists()).toBe(true);
    });
});

// G3 — the legacy "Create for previous date" modal (`PICU-Endorsement.php:172-199`). The Laravel
// `newDay` action already accepted a date; no UI supplied one.
describe('Endorsement/Index — create a sheet for a chosen date', () => {
    it('posts the chosen date to the new-day action', async () => {
        const w = mountIndex();

        await w.get('[data-testid="new-day-date"]').setValue('2026-07-02');
        await w.get('[data-testid="new-day-for-date"]').trigger('submit');

        expect(router.post).toHaveBeenCalled();
        const [url, payload] = router.post.mock.calls[0];
        expect(url).toBe('/endorsement/PICU/new-day');
        expect(payload).toMatchObject({ date: '2026-07-02' });
    });

    it('still offers the plain today new-day action', async () => {
        const w = mountIndex();
        await w.get('[data-testid="new-day"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith('/endorsement/PICU/new-day', {}, expect.anything());
    });

    // Spec §10.4 — gap markers: a missed day is visible exactly where residents look. Decision
    // A moved the merge that finds these gaps server-side (EndorsementController::buildListing());
    // this component only renders whatever `listing` it is handed, so the fixtures below build
    // the same shape the controller would, rather than the raw `dates` array the old client-side
    // computation used to derive gaps from.
    it('renders an inline gap row for each missing date between sheets', () => {
        const w = mountIndex({
            dates: [{ date: '2026-07-14', count: 2 }, { date: '2026-07-10', count: 3 }],
            listing: [
                { type: 'day', date: '2026-07-14', hijri: '', weekend: false, count: 2, signed_off: false },
                { type: 'gap', date: '2026-07-13', hijri: '', weekend: false },
                { type: 'gap', date: '2026-07-12', hijri: '', weekend: false },
                { type: 'gap', date: '2026-07-11', hijri: '', weekend: false },
                { type: 'day', date: '2026-07-10', hijri: '', weekend: false, count: 3, signed_off: false },
            ],
        });

        const gaps = w.findAll('[data-testid="gap-row"]');
        expect(gaps).toHaveLength(3);
        expect(gaps[0].text()).toContain('2026-07-13');
        expect(gaps[2].text()).toContain('2026-07-11');
        expect(gaps[0].text().toLowerCase()).toContain('no endorsement');
    });

    it('one-tap creates a sheet for a gap date', async () => {
        const w = mountIndex({
            dates: [{ date: '2026-07-12', count: 2 }, { date: '2026-07-10', count: 3 }],
            listing: [
                { type: 'day', date: '2026-07-12', hijri: '', weekend: false, count: 2, signed_off: false },
                { type: 'gap', date: '2026-07-11', hijri: '', weekend: false },
                { type: 'day', date: '2026-07-10', hijri: '', weekend: false, count: 3, signed_off: false },
            ],
        });

        await w.get('[data-testid="gap-create"]').trigger('click');
        expect(router.post).toHaveBeenCalledWith('/endorsement/PICU/new-day', { date: '2026-07-11' }, expect.anything());
    });

    it('collapses a long gap into a single summary row', () => {
        const w = mountIndex({
            dates: [{ date: '2026-07-20', count: 1 }, { date: '2026-07-01', count: 1 }],
            listing: [
                { type: 'day', date: '2026-07-20', hijri: '', weekend: false, count: 1, signed_off: false },
                { type: 'gap-summary', count: 18, from: '2026-07-01', to: '2026-07-20' },
                { type: 'day', date: '2026-07-01', hijri: '', weekend: false, count: 1, signed_off: false },
            ],
        });

        expect(w.findAll('[data-testid="gap-row"]')).toHaveLength(0);
        const summary = w.get('[data-testid="gap-summary-row"]');
        expect(summary.text()).toContain('18');
    });
});

// UX-04 dual dating + NF-06/UX-02 (day type is never colour alone) — both server-supplied,
// no client computation.
describe('Endorsement/Index — dual dating', () => {
    it('shows the Hijri date beside the Gregorian for a day row', () => {
        const w = mountIndex({
            dates: [{ date: '2026-08-08', count: 1, hijri: '24 Safar 1448', weekend: true }],
            listing: [
                { type: 'day', date: '2026-08-08', hijri: '24 Safar 1448', weekend: true, count: 1, signed_off: false },
            ],
        });

        expect(w.get('[data-testid="day-hijri"]').text()).toContain('24 Safar 1448');
        expect(w.get('[data-testid="day-weekend"]').text().toLowerCase()).toContain('weekend');
    });

    it('renders no Hijri span when the server sends none (Hijri display off)', () => {
        const w = mountIndex({
            dates: [{ date: '2026-08-08', count: 1, hijri: '', weekend: false }],
            listing: [
                { type: 'day', date: '2026-08-08', hijri: '', weekend: false, count: 1, signed_off: false },
            ],
        });

        expect(w.find('[data-testid="day-hijri"]').exists()).toBe(false);
        expect(w.find('[data-testid="day-weekend"]').exists()).toBe(false);
    });
});
