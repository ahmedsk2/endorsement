import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Admin → Somewhere to practise (Munawib ST-05), the screen half of P1e Task 14.
 *
 * THE PAYLOAD IS THE SUBJECT, NOT THE DOM. Both actions are pinned to the state the page was
 * rendered from, and a form carrying the wrong pin — or no pin — renders byte-identical markup to
 * one carrying the right one. So the spies below record what each control SENDS, which is where
 * this feature lives, and `put`/`post`/`delete` are written as `function`s rather than arrows for
 * exactly that reason: `this` is the form.
 */
const store = vi.hoisted(() => ({
    post: vi.fn(function (url, options) {
        store.submitted = { verb: 'post', url, options, pin: this.pin };
    }),
    destroy: vi.fn(function (url, options) {
        store.submitted = { verb: 'delete', url, options, pin: this.pin, confirm_demo: this.confirm_demo };
    }),
    flash: {},
    submitted: null,
}));

// `reactive`, not a plain object: `v-model` on the confirmation box must notify Vue, or the typed
// word would reach the payload only by accident and these assertions would pass for the wrong
// reason (Clinics.test.js records the same trap).
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        Head: { name: 'Head', template: '<div><slot /></div>' },
        Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
        router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() },
        useForm: (initial) => reactive({
            ...initial,
            errors: {},
            processing: false,
            recentlySuccessful: false,
            clearErrors: vi.fn(),
            reset: vi.fn(),
            transform() { return this; },
            post: store.post,
            patch: vi.fn(),
            put: vi.fn(),
            delete: store.destroy,
        }),
        usePage: () => ({
            props: {
                auth: {
                    can: ['structure.manage'],
                    user: { id: 1, member_name: 'adm', full_name: 'The Admin', position: 0 },
                },
                nav: { units: [] },
                flash: store.flash,
            },
            url: '/admin/structure/demo',
        }),
    };
});

import DemoDepartment from '../../resources/js/Pages/Admin/DemoDepartment.vue';

const SOURCE = readFileSync(resolve(__dirname, '../../resources/js/Pages/Admin/DemoDepartment.vue'), 'utf8');

const base = {
    plan: { refusals: [], skipped: [], pin: 'plan-pin' },
    demo: null,
    confirm_word: 'DEMO',
    unit_code: 'DEMO',
    label_prefix: 'Demo ',
    address_domain: 'demo.invalid',
};

const mountScreen = (props = {}) => mount(DemoDepartment, {
    props: { ...base, ...props },
    global: {
        stubs: {
            AppLayout: { name: 'AppLayout', template: '<div><slot /></div>' },
        },
    },
});

const existing = (overrides = {}) => ({
    batch: 'b-1',
    total: 15,
    counts: [
        { table: 'clinics', count: 1 },
        { table: 'people', count: 5 },
        { table: 'units', count: 1 },
    ],
    blocked: [],
    swept: [],
    pin: 'remove-pin',
    ...overrides,
});

beforeEach(() => {
    store.submitted = null;
    store.flash = {};
    store.post.mockClear();
    store.destroy.mockClear();
});

describe('Admin/DemoDepartment', () => {
    it('says what a demo department is before it offers either control', () => {
        const w = mountScreen();
        const text = w.get('[data-testid="what-it-is"]').text();

        expect(text).toContain('DEMO');
        expect(text).toContain('Demo ');
        expect(text).toContain('demo.invalid');
        // The property that makes pressing this on a live instance safe, stated on the screen and
        // not only in a docblock nobody opens.
        expect(text).toContain('creates no account');
    });

    it('sends the pin the page was rendered with when creating', async () => {
        const w = mountScreen();

        await w.get('[data-testid="create-demo"]').trigger('click');

        expect(store.submitted.verb).toBe('post');
        expect(store.submitted.url).toBe('/admin/structure/demo');
        expect(store.submitted.pin).toBe('plan-pin');
    });

    it('states what a demo will not do here, and refuses to offer the button when it cannot be created', () => {
        const withSkips = mountScreen({
            plan: { refusals: [], skipped: ['This department already has periods.'], pin: 'p' },
        });

        expect(withSkips.get('[data-testid="create-skipped"]').text()).toContain('already has periods');
        expect(withSkips.get('[data-testid="create-demo"]').attributes('disabled')).toBeUndefined();

        const refused = mountScreen({
            plan: { refusals: ['A unit with the code [DEMO] already exists.'], skipped: [], pin: 'p' },
        });

        expect(refused.get('[data-testid="create-refusals"]').text()).toContain('already exists');
        expect(refused.get('[data-testid="create-demo"]').attributes('disabled')).toBeDefined();
        expect(refused.find('[data-testid="create-skipped"]').exists()).toBe(false);
    });

    it('reports how many rows were created and what was skipped', () => {
        store.flash = { demo_result: { rows: 15, skipped: ['This department already has periods.'] } };

        const w = mountScreen();
        const result = w.get('[data-testid="create-result"]');

        expect(result.text()).toContain('15');
        expect(result.text()).toContain('already has periods');
        expect(result.attributes('aria-live')).toBe('polite');
    });

    it('shows what would be removed, then takes the pin and the typed word', async () => {
        const w = mountScreen({ demo: existing() });

        const counts = w.get('[data-testid="remove-counts"]').text();
        expect(counts).toContain('people');
        expect(counts).toContain('5');
        expect(w.get('[data-testid="remove-panel"]').text()).toContain('15');

        // The create panel is gone: there is nothing to create while one exists.
        expect(w.find('[data-testid="create-panel"]').exists()).toBe(false);

        await w.get('[data-testid="confirm-demo"]').setValue('DEMO');
        await w.get('[data-testid="remove-demo"]').trigger('click');

        expect(store.submitted.verb).toBe('delete');
        expect(store.submitted.url).toBe('/admin/structure/demo');
        expect(store.submitted.pin).toBe('remove-pin');
        expect(store.submitted.confirm_demo).toBe('DEMO');
    });

    /**
     * The difference between an actionable refusal and a dead end, and the reason this component
     * renders the pre-flight rather than only an error string: WHAT is holding it, with the remedy
     * named — and no confirmation control at all while it is held.
     */
    it('shows what is holding a blocked removal and offers no way to press through it', () => {
        const w = mountScreen({
            demo: existing({
                blocked: [
                    { table: 'handovers', count: 2, remedy: 'handovers: merge the demo unit.' },
                    { table: 'users', count: 1, remedy: 'users: UNBIND it (Administration -> Accounts).' },
                ],
            }),
        });

        const held = w.get('[data-testid="remove-blocked"]').text();

        expect(held).toContain('handovers');
        expect(held).toContain('2');
        expect(held).toContain('users');
        expect(held).toContain('Deal with these first');

        // THE REMEDY IS PER TABLE, and it is rendered. Accounts are deactivated and never deleted
        // here, so the one sentence this panel used to show every blocker ("delete them, or point
        // them somewhere real") named a control the product does not have for half of them.
        expect(held).toContain('UNBIND');
        expect(held).toContain('merge the demo unit');

        expect(w.find('[data-testid="remove-demo"]').exists()).toBe(false);
        expect(w.find('[data-testid="confirm-demo"]').exists()).toBe(false);
    });

    /**
     * Removal hard-deletes an invitation addressed to a demo person — a row the demo never created.
     * Silence there would be a cleanup button reaching outside its own ledger with nobody told, so
     * the panel is rendered BEFORE the word is typed, not reported afterwards.
     */
    it('says what removal will delete that the demo did not create', () => {
        const clean = mountScreen({ demo: existing() });

        expect(clean.find('[data-testid="remove-swept"]').exists()).toBe(false);

        const w = mountScreen({
            demo: existing({ swept: [{ table: 'invitations', count: 1 }] }),
        });

        const swept = w.get('[data-testid="remove-swept"]').text();

        expect(swept).toContain('invitations');
        expect(swept).toContain('1');

        // …and it is still offered: a swept row is not a blocker.
        expect(w.find('[data-testid="confirm-demo"]').exists()).toBe(true);
    });

    it('renders both refusal keys where the control that caused them is', async () => {
        const w = mountScreen({ demo: existing() });

        w.vm.removeForm.errors.confirm_demo = 'Type DEMO exactly to confirm.';
        w.vm.removeForm.errors.remove_pin = 'This page was describing a different department.';
        await w.vm.$nextTick();

        expect(w.get('[data-testid="remove-error"]').text()).toContain('Type DEMO exactly');
        expect(w.get('[data-testid="remove-pin-error"]').text()).toContain('different department');
    });

    /**
     * Every string on this page is server-built prose or a plain table name, so escaping is this
     * component's job. Asserted on the DOM and on the source: a component that renders one field
     * safely and another with the raw-markup directive passes a DOM-only check.
     */
    it('escapes what it renders and keeps the house theme rules', async () => {
        const w = mountScreen({
            plan: { refusals: ['A unit <b>DEMO</b> exists & is not ours.'], skipped: [], pin: 'p' },
        });

        expect(w.get('[data-testid="create-refusals"]').text()).toContain('<b>DEMO</b>');
        expect(w.html()).not.toContain('<b>DEMO</b>');

        expect(SOURCE).not.toContain('v-html');
        expect(SOURCE).not.toContain('dark:');
        expect(SOURCE).not.toContain('bg-panel-soft');
    });
});
