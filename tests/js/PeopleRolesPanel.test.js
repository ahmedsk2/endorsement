import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * AC-04's roles panel on Admin -> People (P1c-2 Task 6, Decision F).
 *
 * The server-side half is `tests/Feature/Admin/PersonRolesTest.php` — this file covers the two
 * things only a render can answer:
 *
 *  1. The panel is behind `access.manage`, NOT behind the `people.manage` that opened the screen.
 *     Two independent signals have to agree: the prop the server omitted, and `auth.can`. Neither
 *     is the gate (the `cap:access.manage` route group is) but a control offered where the
 *     endpoint would refuse it is its own defect.
 *  2. A person with no account gets a SENTENCE, not a disabled control. A control that silently
 *     does nothing is the shape this programme refused for bulk resend, and the same reasoning
 *     applies here: a roster-only person has no `users` row and cannot authenticate by
 *     construction, so there is nothing for a capability to attach to.
 */
const store = vi.hoisted(() => ({
    page: { props: { auth: { can: [], user: null }, nav: { units: [] }, flash: {} }, url: '/admin/people' },
    formPut: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
    usePage: () => store.page,
    useForm: (initial) => {
        const form = {
            ...initial,
            errors: {},
            processing: false,
            recentlySuccessful: false,
            clearErrors: vi.fn(),
            transform() { return this; },
            put: store.formPut,
            post: vi.fn(),
            patch: vi.fn(),
            reset: vi.fn(),
        };

        return form;
    },
}));

import People from '../../resources/js/Pages/Admin/People.vue';

const person = (overrides = {}) => ({
    id: 7,
    full_name: 'Dr Alpha',
    short_name: 'Alpha',
    position: 4,
    email: 'alpha@example.org',
    active: true,
    external: false,
    has_account: true,
    level: null,
    invitation: null,
    ...overrides,
});

const capabilityGrants = (overrides = {}) => ({
    capabilities: [
        { id: 3, key: 'endorsement.reopen', label: 'Reopen a signed day', description: null },
    ],
    overrides: {},
    ...overrides,
});

const mountPeople = (props) => mount(People, {
    props: { people: [person()], positions: [{ id: 4, name: 'Resident' }], ...props },
    global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
});

describe('Admin -> People — AC-04 roles panel', () => {
    beforeEach(() => {
        store.page.props = { auth: { can: [], user: null }, nav: { units: [] }, flash: {} };
        store.formPut.mockClear();
    });

    it('offers no roles control to a viewer holding only people.manage', () => {
        store.page.props.auth.can = ['people.manage'];

        // The server withheld the prop entirely for this viewer — absent, not empty.
        const wrapper = mountPeople({ capability_grants: null });

        expect(wrapper.find('[data-testid="roles-7"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Roles belong to the account');
    });

    it('offers no roles control when the prop arrives but the shared capability set does not agree', () => {
        store.page.props.auth.can = ['people.manage'];

        const wrapper = mountPeople({ capability_grants: capabilityGrants() });

        expect(wrapper.find('[data-testid="roles-7"]').exists()).toBe(false);
    });

    it('offers the panel to an access.manage holder and states the consequence beside it', async () => {
        store.page.props.auth.can = ['people.manage', 'access.manage'];

        const wrapper = mountPeople({
            capability_grants: capabilityGrants({ overrides: { 7: { 3: 'deny' } } }),
        });

        await wrapper.get('[data-testid="roles-7"]').trigger('click');

        const select = wrapper.get('[data-testid="person-7-cap-3"]');
        expect(select.element.value).toBe('deny');
        expect(wrapper.text()).toContain('Roles belong to the account, not the person.');
        expect(wrapper.text()).toContain('not restored automatically');
        expect(wrapper.find('[data-testid="roles-no-account"]').exists()).toBe(false);
    });

    it('tells the operator plainly when a person has no account, rather than offering a dead control', async () => {
        store.page.props.auth.can = ['people.manage', 'access.manage'];

        const wrapper = mountPeople({
            people: [person({ has_account: false })],
            capability_grants: capabilityGrants(),
        });

        await wrapper.get('[data-testid="roles-7"]').trigger('click');

        expect(wrapper.get('[data-testid="roles-no-account"]').text())
            .toContain('This person has no account. Roles are granted to an account — invite them first.');
        expect(wrapper.find('[data-testid="person-7-cap-3"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="save-roles-7"]').exists()).toBe(false);
    });

    it('saves through the access-control endpoint, never through /admin/people', async () => {
        store.page.props.auth.can = ['people.manage', 'access.manage'];

        const wrapper = mountPeople({ capability_grants: capabilityGrants() });

        await wrapper.get('[data-testid="roles-7"]').trigger('click');
        await wrapper.get('[data-testid="save-roles-7"]').trigger('click');

        expect(store.formPut).toHaveBeenCalledTimes(1);
        expect(store.formPut.mock.calls[0][0]).toBe('/admin/access-control/person');
    });
});
