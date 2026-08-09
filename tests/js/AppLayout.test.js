import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// A mutable page store so each test can drive `auth.can` / `auth.user` before mounting — mirrors
// the mock pattern in tests/js/AccessControl.test.js (mock @inertiajs/vue3, read a hoisted store).
const store = vi.hoisted(() => ({
    page: { props: { auth: { can: [], user: null }, nav: { units: [] }, flash: {} }, url: '/dashboard' },
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
    store.page.props = { auth: { can: [], user: null }, nav: { units: [] }, flash: {} };
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
        // The unit list comes from the server (Unit::navList()) rather than a hardcoded array —
        // P1b closed CLAUDE.md's "a fifth department gets no nav entry or hue" exception.
        store.page.props.nav.units = [
            { code: 'picu', label: 'Pediatric Intensive Care Unit', bar: 'channel-bar-picu' },
            { code: 'nicu', label: 'Neonatal Intensive Care Unit', bar: 'channel-bar-nicu' },
            { code: 'scbu', label: 'Special Care Baby Unit', bar: 'channel-bar-scbu' },
            { code: 'ward', label: 'Pediatric Ward', bar: 'channel-bar-ward' },
        ];

        const text = navText(mountLayout());
        expect(text).toContain('All units');
        expect(text).toContain('Pediatric Intensive Care Unit');
        expect(text).toContain('Neonatal Intensive Care Unit');
        expect(text).toContain('Special Care Baby Unit');
        expect(text).toContain('Pediatric Ward');
        expect(text).toContain('Access Control');
        // users.manage is absent, so the Users entry is too.
        expect(text).not.toContain('Users');
    });

    // The exact defect CLAUDE.md named as a pending exception: a fifth department created
    // through Admin -> Structure -> Units must appear in the sidebar without a frontend change.
    it('renders a fifth unit the server sends, with no frontend change', () => {
        store.page.props.auth.can = ['endorsement.view'];
        store.page.props.auth.user = { id: 5, member_name: 'r', full_name: 'A Resident', position: 4 };
        store.page.props.nav.units = [
            { code: 'picu', label: 'PICU', bar: 'channel-bar-picu' },
            { code: 'rgh1', label: 'Riyadh General Ward 1', bar: 'channel-bar-amber' },
        ];

        const text = navText(mountLayout());
        expect(text).toContain('Riyadh General Ward 1');
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
    // leave that user with no way in at all. Task 8 adds a second structure.manage-gated
    // entry (Levels); Task 10 a third (Calendar); Task 11 a fourth (Periods); Task 12 a fifth
    // (Holidays) — all belong beside Units here.
    it('shows the admin section with structure.manage alone, with Units, Levels, Calendar, Periods and Holidays links', () => {
        store.page.props.auth.can = ['structure.manage'];
        store.page.props.auth.user = { id: 4, member_name: 'sm', full_name: 'Structure Manager', position: 0 };

        const text = navText(mountLayout());
        expect(text).toContain('Administration');
        expect(text).toContain('Units');
        expect(text).toContain('Levels');
        expect(text).toContain('Calendar');
        expect(text).toContain('Periods');
        expect(text).toContain('Holidays');
        // structure.manage alone does not grant the other admin links.
        expect(text).not.toContain('Access Control');
        expect(text).not.toContain('Settings');
    });

    // P1c: a user holding ONLY the new people.manage capability must still see the
    // Administration section with a People link — the same recon-frontend risk P1b's Decision A
    // named, now proven for the roster capability too.
    it('shows the admin section with people.manage alone, with a People link only', () => {
        store.page.props.auth.can = ['people.manage'];
        store.page.props.auth.user = { id: 6, member_name: 'pm', full_name: 'People Manager', position: 0 };

        const text = navText(mountLayout());
        expect(text).toContain('Administration');
        expect(text).toContain('People');
        // people.manage alone does not grant the other admin links.
        expect(text).not.toContain('Access Control');
        expect(text).not.toContain('Units');
        expect(text).not.toContain('Settings');
        expect(text).not.toContain('Users');
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
