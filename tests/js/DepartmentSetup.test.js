import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Every verb is mocked so "this screen writes nothing" has something to assert against: a spy that
// is never armed cannot fail. `useForm` is deliberately NOT provided — if this component ever
// reaches for it, these tests blow up rather than quietly pass.
const router = vi.hoisted(() => ({
    get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router,
    usePage: () => ({
        props: {
            auth: { can: ['structure.manage'], user: { id: 1, member_name: 'adm', full_name: 'The Admin', position: 0 } },
            nav: { units: [] },
            flash: {},
        },
        url: '/admin/setup',
    }),
}));

import DepartmentSetup from '../../resources/js/Pages/Admin/DepartmentSetup.vue';

const SOURCE = readFileSync(resolve(__dirname, '../../resources/js/Pages/Admin/DepartmentSetup.vue'), 'utf8');

const required = (overrides = {}) => ({
    key: 'levels',
    title: 'Training levels',
    kind: 'required',
    route: 'admin.structure.levels',
    url: '/admin/structure/levels',
    done: true,
    blocked_by: null,
    summary: '5 active training levels.',
    ...overrides,
});

const review = (overrides = {}) => ({
    key: 'calendar',
    title: 'Calendar',
    kind: 'review',
    route: 'admin.structure.calendar',
    url: '/admin/structure/calendar',
    done: false,
    blocked_by: null,
    summary: 'Hijri dates shown, offset -1 · academic year · clocks on Asia/Riyadh.',
    ...overrides,
});

const later = (overrides = {}) => ({
    key: 'slots',
    title: 'Duty slots and coverage templates',
    stage: 'Stage 2',
    summary: 'The tables behind this do not exist yet.',
    ...overrides,
});

const mountScreen = (overrides = {}) => mount(DepartmentSetup, {
    props: {
        steps: [review(), required()],
        later: [later()],
        ...overrides,
    },
    global: {
        stubs: {
            AppLayout: { name: 'AppLayout', template: '<div><slot /></div>' },
        },
    },
});

describe('Admin/DepartmentSetup.vue', () => {
    it('renders a required step as a tickable state with a link to the screen that owns it', () => {
        const w = mountScreen({ steps: [required()] });

        const row = w.get('[data-testid="step-levels"]');

        expect(row.text()).toContain('Training levels');
        expect(row.text()).toContain('5 active training levels.');
        expect(row.get('a').attributes('href')).toBe('/admin/structure/levels');
        expect(row.attributes('data-done')).toBe('true');
    });

    it('shows an unfinished required step as outstanding and names what blocks it', () => {
        const w = mountScreen({
            steps: [required({
                key: 'periods', title: 'Rota periods', route: 'admin.structure.periods',
                url: '/admin/structure/periods', done: false,
                blocked_by: 'The academic year start is not set yet (Structure -> Calendar).',
                summary: 'No generated period covers today or any date after it.',
            })],
        });

        const row = w.get('[data-testid="step-periods"]');

        expect(row.attributes('data-done')).toBe('false');
        expect(row.text()).toContain('The academic year start is not set yet');
    });

    /**
     * A REVIEW step can never be honestly ticked — a default is indistinguishable from a reviewed
     * default — so it renders its CURRENT VALUES and offers review, and there is no state control
     * on it at all. A checkbox here would be a claim the data cannot support.
     */
    it('renders a review step with its current values, no state and no checkbox', () => {
        const w = mountScreen({ steps: [review()] });

        const card = w.get('[data-testid="step-calendar"]');

        expect(card.text()).toContain('Hijri dates shown, offset -1');
        expect(card.attributes('data-done')).toBeUndefined();
        expect(card.findAll('input[type="checkbox"]').length).toBe(0);
        expect(card.get('a').attributes('href')).toBe('/admin/structure/calendar');
    });

    /**
     * Finding 2's requirement, asserted where it can actually be seen. The P2/P3 items are STATED,
     * with a stage label — not rendered as two permanently unticked boxes an administrator will
     * try to click and then wonder why nothing happened.
     */
    it('renders the later items with no link and no checkbox', () => {
        const w = mountScreen({ later: [later(), later({ key: 'conditions', title: 'Duty-hour rules', stage: 'Stage 3' })] });

        const items = w.findAll('[data-testid^="later-"]');
        expect(items.length).toBe(2);

        items.forEach((item) => {
            expect(item.findAll('a').length).toBe(0);
            expect(item.findAll('input').length).toBe(0);
            expect(item.findAll('button').length).toBe(0);
        });

        expect(items[0].text()).toContain('Stage 2');
        expect(items[1].text()).toContain('Stage 3');
    });

    it('announces how many required steps are done, in a live region', () => {
        const w = mountScreen({
            steps: [review(), required(), required({ key: 'units', title: 'Units', url: '/admin/structure/units', done: false })],
        });

        const status = w.get('[data-testid="setup-progress"]');

        expect(status.attributes('role')).toBe('status');
        expect(status.text()).toContain('1 of 2');
    });

    /** Nothing on this screen writes. No form, no button that sends anything at all. */
    it('sends nothing anywhere', () => {
        mountScreen();

        Object.values(router).forEach((spy) => expect(spy).not.toHaveBeenCalled());
    });

    /**
     * A step summary is server-built free text and is escaped by interpolation, never rendered as
     * markup. Asserted on the DOM AND on the source, because a component that renders one field
     * safely and reaches for the raw-markup directive on the next passes the first check alone.
     */
    it('escapes free text and never renders raw markup', () => {
        const w = mountScreen({ steps: [required({ summary: 'ENT <> Audiology & <b>bold</b>' })] });

        expect(w.get('[data-testid="step-levels"]').text()).toContain('ENT <> Audiology & <b>bold</b>');
        expect(w.html()).not.toContain('<b>bold</b>');

        expect(SOURCE).not.toContain('v-html');
    });

    /** LIGHT THEME ONLY: any dark-variant utility is a bug, and there is no bg-panel-soft token. */
    it('uses no dark-mode utility and no token that compiles to nothing', () => {
        expect(SOURCE).not.toMatch(/\bdark:/);
        expect(SOURCE).not.toContain('bg-panel-soft');
    });
});
