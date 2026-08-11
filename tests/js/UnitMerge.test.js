import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * Admin → Structure → Units → Merge (Munawib UN-01), the screen half of design §14 item 23's fix.
 *
 * WHAT THIS FILE IS FOR, and it is not "the counts render". A merge is irreversible and the preview
 * is the only thing an operator reads before pressing it, so a table the merge now moves but the
 * preview does not mention is a silent change to seven tables described as four. Three of the four
 * cases below are therefore about what the screen SAYS, and the fourth about a control it must
 * refuse to enable.
 *
 * IT IS ALSO THE RENDER HALF OF A REFUSAL PAIR (CLAUDE.md's flash-key invariant, rulings 41/49).
 * `UnitMergeController` throws the clinic-ownership refusal under `target_unit_id`; `UnitMergeTest`
 * asserts the key server-side and `renders the target_unit_id refusal` below asserts the site that
 * displays it. Either alone stays green while the other rots — the exact shape that shipped three
 * invisible controls across two slices.
 */
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
            post: vi.fn(),
            patch: vi.fn(),
            put: vi.fn(),
            delete: vi.fn(),
        }),
        usePage: () => ({
            props: {
                auth: {
                    can: ['structure.manage'],
                    user: { id: 1, member_name: 'adm', full_name: 'The Admin', position: 0 },
                },
                nav: { units: [] },
                flash: {},
            },
            url: '/admin/structure/units/merge',
        }),
    };
});

import UnitMerge from '../../resources/js/Pages/Admin/UnitMerge.vue';

const UNITS = [
    { id: 1, code: 'PICU', name: 'Paediatric ICU', active: true },
    { id: 2, code: 'NICU', name: 'Neonatal ICU', active: true },
];

const plan = (overrides = {}) => ({
    handovers: 3,
    signoffs: 2,
    field_definitions: 0,
    preferred_unit_users: 1,
    rota_assignments: 4,
    clinics: 2,
    reminders: 5,
    reminders_dropped: 0,
    collisions: [],
    ...overrides,
});

const mountScreen = (props = {}) => mount(UnitMerge, {
    props: {
        units: UNITS,
        source_unit_id: 2,
        target_unit_id: 1,
        plan: plan(),
        field_definition_conflicts: [],
        clinics_the_target_cannot_own: 0,
        ...props,
    },
    global: {
        stubs: {
            AppLayout: { name: 'AppLayout', template: '<div><slot /></div>' },
        },
    },
});

beforeEach(() => {
    vi.clearAllMocks();
});

describe('Admin/UnitMerge', () => {
    it('counts all three tables the merge used to strand', () => {
        const text = mountScreen().text();

        expect(text).toContain('4');
        expect(text).toContain('master rota span(s)');
        expect(text).toContain('clinic(s) move');
        expect(text).toContain('reminder opt-in(s)');
    });

    /**
     * The dropped duplicate is a DELETE, and a preview that folded it into the moved figure would
     * be a merge quietly removing a row. It appears only when there is one to report.
     */
    it('says plainly that a duplicate reminder opt-in is dropped rather than moved', () => {
        expect(mountScreen().text()).not.toContain('dropped');

        const text = mountScreen({ plan: plan({ reminders: 4, reminders_dropped: 1 }) }).text();

        expect(text).toContain('dropped');
        expect(text).toContain('already ask for');
    });

    /** A clinic cannot land on a unit that does not own clinics — refused, not warned about. */
    it('blocks the merge when the target cannot own the source clinics, naming the tick-box', () => {
        const w = mountScreen({ clinics_the_target_cannot_own: 2 });
        const button = w.findAll('button').find((b) => b.text().includes('Confirm merge'));

        expect(w.text()).toContain('does not own clinics');
        expect(w.text()).toContain('owns clinics');
        expect(button.attributes('disabled')).toBeDefined();
    });

    /** The render half of the pair: the key the controller actually flashes. */
    it('renders the target_unit_id refusal the controller flashes', async () => {
        const w = mountScreen();
        const button = w.findAll('button').find((b) => b.text().includes('Confirm merge'));

        expect(w.text()).not.toContain('Zzrefusalsentinel');

        await button.trigger('click');
        w.vm.mergeForm.errors.target_unit_id = 'Zzrefusalsentinel';
        await w.vm.$nextTick();

        expect(w.text()).toContain('Zzrefusalsentinel');
    });
});
