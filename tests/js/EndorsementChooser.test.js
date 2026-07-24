import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';

const store = vi.hoisted(() => ({
    page: { props: { auth: { can: ['endorsement.view'], user: { id: 1, member_name: 'x', full_name: 'X', position: 0 } }, flash: {} }, url: '/endorsement' },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn() },
    usePage: () => store.page,
}));

import Chooser from '../../resources/js/Pages/Endorsement/Chooser.vue';

const units = [
    { code: 'PICU', name: 'Pediatric Intensive Care Unit', bar_class: 'channel-bar-picu', today: { date: '2026-07-24', has_sheet: true, rows: 8, signed_off: true } },
    { code: 'NICU', name: 'Neonatal Intensive Care Unit', bar_class: 'channel-bar-nicu', today: { date: '2026-07-24', has_sheet: true, rows: 5, signed_off: false } },
    { code: 'SCBU', name: 'Special Care Baby Unit', bar_class: 'channel-bar-scbu', today: { date: '2026-07-24', has_sheet: false, rows: 0, signed_off: false } },
    { code: 'WARD', name: 'Pediatric Ward', bar_class: 'channel-bar-ward', today: { date: '2026-07-24', has_sheet: true, rows: 14, signed_off: true } },
];

describe('Endorsement/Chooser', () => {
    const mountChooser = () => mount(Chooser, { props: { units } });

    it('renders one card per unit, each linking to its index with its hue', () => {
        const w = mountChooser();
        const cards = w.findAll('[data-testid="unit-card"]');

        expect(cards).toHaveLength(4);
        expect(cards[0].attributes('href')).toBe('/endorsement/picu');
        expect(cards[0].classes()).toContain('channel-bar-picu');
        expect(cards[3].classes()).toContain('channel-bar-ward');
    });

    it('shows signed / in-progress / missing status per unit', () => {
        const statuses = mountChooser().findAll('[data-testid="unit-status"]').map((s) => s.text());

        expect(statuses[0]).toContain('Signed off');
        expect(statuses[1]).toContain('not signed');
        expect(statuses[2]).toContain('No sheet today');
    });

    it('banners the units with no sheet today', () => {
        const banner = mountChooser().get('[data-testid="unfilled-banner"]');

        expect(banner.text()).toContain('SCBU');
        expect(banner.text()).not.toContain('NICU');
    });
});
