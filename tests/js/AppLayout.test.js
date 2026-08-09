import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// A mutable page store so each test can drive `auth.can` / `auth.user` before mounting — mirrors
// the mock pattern in tests/js/AccessControl.test.js (mock @inertiajs/vue3, read a hoisted store).
const store = vi.hoisted(() => ({
    page: { props: { auth: { can: [], user: null }, flash: {} }, url: '/dashboard' },
    routerPost: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: store.routerPost },
    usePage: () => store.page,
}));

import AppLayout from '../../resources/js/Layouts/AppLayout.vue';
import { useCan } from '../../resources/js/Composables/useCan.js';

const mountLayout = () => mount(AppLayout, { slots: { default: '<p>page body here</p>' } });

const resetPage = () => {
    store.page.props = { auth: { can: [], user: null }, flash: {} };
    store.page.url = '/dashboard';
};

describe('AppLayout — role-gated navigation', () => {
    beforeEach(() => {
        resetPage();
        store.routerPost.mockClear();
    });

    // Assert against the nav region only — the brand + footer chrome deliberately contain
    // "Registry"/"Endorsement", so a whole-page text scan would give false positives.
    const navText = (w) => w.get('nav[aria-label="Primary"]').text();

    it('renders no endorsement or admin nav without the capabilities', () => {
        store.page.props.auth.can = ['profile.manage'];
        store.page.props.auth.user = { id: 1, member_name: 'jdoe', full_name: 'Jane Doe', position: 1 };

        const text = navText(mountLayout());
        // A Nurse (profile only) sees no endorsement entries and no admin section.
        expect(text).not.toContain('Endorsement');
        expect(text).not.toContain('All units');
        expect(text).not.toContain('Access Control');
        expect(text).not.toContain('Users');
    });

    it('renders the chooser, all four unit entries and admin nav when the caps are present', () => {
        store.page.props.auth.can = ['endorsement.view', 'access.manage'];
        store.page.props.auth.user = { id: 2, member_name: 'admin', full_name: 'The Admin', position: 0 };

        const text = navText(mountLayout());
        expect(text).toContain('All units');
        expect(text).toContain('PICU Endorsement');
        expect(text).toContain('NICU Endorsement');
        expect(text).toContain('SCBU Endorsement');
        expect(text).toContain('Ward Endorsement');
        expect(text).toContain('Access Control');
        // users.manage is absent, so the Users entry is too.
        expect(text).not.toContain('Users');
    });

    it('shows the admin section with users.manage alone, without Access Control', () => {
        store.page.props.auth.can = ['users.manage'];
        store.page.props.auth.user = { id: 3, member_name: 'um', full_name: 'User Manager', position: 0 };

        const text = navText(mountLayout());
        expect(text).toContain('Administration');
        expect(text).toContain('Users');
        // users.manage alone does not grant the access-control manager link.
        expect(text).not.toContain('Access Control');
    });

    // The recon frontend risk P1b names: a user holding ONLY the new structure.manage
    // capability must still see the Administration section — omitting it from canAdmin would
    // leave that user with no way in at all.
    it('shows the admin section with structure.manage alone, with a Units link', () => {
        store.page.props.auth.can = ['structure.manage'];
        store.page.props.auth.user = { id: 4, member_name: 'sm', full_name: 'Structure Manager', position: 0 };

        const text = navText(mountLayout());
        expect(text).toContain('Administration');
        expect(text).toContain('Units');
        // structure.manage alone does not grant the other admin links.
        expect(text).not.toContain('Access Control');
        expect(text).not.toContain('Settings');
    });

    it('shows the signed-in user name and logs out via router.post', async () => {
        store.page.props.auth.can = ['profile.manage'];
        store.page.props.auth.user = { id: 1, member_name: 'jdoe', full_name: 'Jane Doe', position: 1 };

        const w = mountLayout();
        expect(w.text()).toContain('Jane Doe');

        await w.get('[data-testid="logout"]').trigger('click');
        expect(store.routerPost).toHaveBeenCalledWith('/logout');
    });

    it('exposes a skip link and a main landmark wrapping the page slot', () => {
        const w = mountLayout();
        expect(w.get('a.skip-link').attributes('href')).toBe('#main-content');
        expect(w.get('main#main-content').text()).toContain('page body here');
    });
});

describe('useCan composable', () => {
    beforeEach(resetPage);

    it('can() reflects membership in auth.can', () => {
        store.page.props.auth.can = ['endorsement.view', 'profile.manage'];
        const { can } = useCan();
        expect(can('endorsement.view')).toBe(true);
        expect(can('profile.manage')).toBe(true);
        expect(can('endorsement.reopen')).toBe(false);
    });

    it('exposes the current user', () => {
        store.page.props.auth.user = { id: 7, member_name: 'x', full_name: 'X Y', position: 2 };
        const { user } = useCan();
        expect(user.value).toMatchObject({ id: 7, full_name: 'X Y' });
    });

    it('is defensive when the auth props are missing entirely', () => {
        store.page.props = {};
        const { can, user } = useCan();
        expect(can('anything')).toBe(false);
        expect(user.value).toBe(null);
    });
});
