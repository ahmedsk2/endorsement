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
        unit: { code: 'PICU', name: 'PICU' },
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
});
