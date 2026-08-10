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
    // Administration section with People AND Promotion links — the same recon-frontend risk
    // P1b's Decision A named, now proven for the roster capability too. Task 10 adds Promotion
    // beside People, both gated on the same capability.
    it('shows the admin section with people.manage alone, with People and Promotion links only', () => {
        store.page.props.auth.can = ['people.manage'];
        store.page.props.auth.user = { id: 6, member_name: 'pm', full_name: 'People Manager', position: 0 };

        const text = navText(mountLayout());
        expect(text).toContain('Administration');
        expect(text).toContain('People');
        expect(text).toContain('Promotion');
        // people.manage alone does not grant the other admin links.
        expect(text).not.toContain('Access Control');
        expect(text).not.toContain('Units');
        expect(text).not.toContain('Settings');
        expect(text).not.toContain('Users');
    });

    // The negative symmetric case: a capability that grants neither People nor Promotion must
    // show neither — Promotion is not accidentally reachable through some OTHER admin key.
    it('does not show Promotion without people.manage', () => {
        store.page.props.auth.can = ['structure.manage'];
        store.page.props.auth.user = { id: 7, member_name: 'sm', full_name: 'Structure Manager', position: 0 };

        const text = navText(mountLayout());
        expect(text).not.toContain('Promotion');
    });

    // P1d: a user holding ONLY rota.manage must still see the Administration section, with a
    // Master Rota link and no other admin-surface link — the same recon-frontend risk P1b's
    // Decision A and P1c's people.manage case both named.
    it('shows the admin section with rota.manage alone, with a Master Rota link only', () => {
        store.page.props.auth.can = ['rota.manage'];
        store.page.props.auth.user = { id: 8, member_name: 'rm', full_name: 'Rota Manager', position: 0 };

        const text = navText(mountLayout());
        expect(text).toContain('Administration');
        expect(text).toContain('Master Rota');
        // rota.manage alone does not grant the other admin links.
        expect(text).not.toContain('Access Control');
        expect(text).not.toContain('Units');
        expect(text).not.toContain('Settings');
        expect(text).not.toContain('People');
    });

    // P1d-2 Decision A: MR-05's read view is a TOP-LEVEL entry, beside the unit channels, not an
    // admin one. `rota.view` is seeded for every authenticated position, so a resident holding
    // nothing else must still find the rota — and must NOT be shown an Administration section for
    // holding it.
    it('shows a top-level Rota link for rota.view, with no admin section', () => {
        store.page.props.auth.can = ['rota.view'];
        store.page.props.auth.user = { id: 9, member_name: 'res', full_name: 'A Resident', position: 4 };

        const text = navText(mountLayout());
        expect(text).toContain('Rota');
        expect(text).not.toContain('Administration');
        expect(text).not.toContain('Master Rota');
    });

    // An administrator legitimately sees BOTH: reading the department's rota and editing it are
    // two acts on two screens, and hiding the read view from the person most likely to want to
    // check what residents actually see would be a strange kindness (Decision A says so in the
    // layout's own comment, so it does not come back as a review question).
    it('shows both entries to somebody holding rota.view and rota.manage', () => {
        store.page.props.auth.can = ['rota.view', 'rota.manage'];
        store.page.props.auth.user = { id: 10, member_name: 'adm', full_name: 'The Admin', position: 0 };

        const w = mountLayout();
        expect(w.get('nav[aria-label="Primary"]').text()).toContain('Master Rota');
        expect(w.findAll('nav[aria-label="Primary"] a[href="/rota"]')).toHaveLength(1);
        expect(w.findAll('nav[aria-label="Primary"] a[href="/admin/rota"]')).toHaveLength(1);
    });

    /**
     * A QUERY STRING DOES NOT CHANGE WHICH SCREEN YOU ARE ON — and until P1d-2 Task 6 this layout
     * thought it did. Inertia's `page.url` carries the full path AND query, and both nav helpers
     * compared it whole, so every filterable screen in this app dropped its "you are here"
     * highlight — and its `aria-current="page"`, which is what a screen reader announces — the
     * instant a filter was applied.
     *
     * Found by walking the rota in a real browser, not by reading the code: `/rota`'s year picker,
     * search box and level filter all push `?year=`/`?q=`/`?level=`, so the read view lost its own
     * nav entry on the first click a resident made. Neither this file nor any PHPUnit case saw it,
     * because every mount here stubbed a bare path.
     *
     * It was never rota-specific. `/endorsement/{unit}?from=&to=` (Endorsement/Index.vue's date
     * filter) is the same bug on the app's most-used screen, and it predates the rota entirely —
     * which is why the fix is in the two helpers rather than in the Rota link's own expression.
     */
    it('keeps a nav entry current when the screen carries a query string', () => {
        store.page.props.auth.can = ['rota.view', 'endorsement.view'];
        store.page.props.auth.user = { id: 11, member_name: 'res', full_name: 'A Resident', position: 4 };
        store.page.props.nav.units = [
            { code: 'picu', label: 'Pediatric Intensive Care Unit', bar: 'channel-bar-picu' },
        ];

        // isExactly(): the rota read view, filtered.
        store.page.url = '/rota?year=2026-2027&q=ahmed';
        expect(mountLayout().get('nav[aria-label="Primary"] a[href="/rota"]').attributes('aria-current'))
            .toBe('page');

        // isActive(): a unit's own index, date-filtered — the same defect, on the screen the
        // department actually lives on.
        store.page.url = '/endorsement/picu?from=2026-08-01&to=2026-08-10';
        expect(mountLayout().get('nav[aria-label="Primary"] a[href="/endorsement/picu"]').attributes('aria-current'))
            .toBe('page');

        // And the chooser must NOT claim to be current just because a query string was stripped
        // off a deeper path — `isExactly` still means exactly.
        expect(mountLayout().get('nav[aria-label="Primary"] a[href="/endorsement"]').attributes('aria-current'))
            .toBeUndefined();
    });

    /**
     * A TRAILING SLASH DOES NOT CHANGE WHICH SCREEN YOU ARE ON EITHER (adversarial review,
     * finding 2). The query/hash fix above split on `[?#]` and stopped there, so `/rota/` still
     * failed every one of the four `isExactly` comparisons and the entry it names went dark — the
     * same defect the fix was for, one character along. Browsers, proxies and hand-typed URLs all
     * produce the form; `/endorsement/` is the one a ward is most likely to bookmark.
     *
     * Asserted as a sweep over the awkward forms rather than one representative, because each of
     * them is a separate branch of one small expression and "it works for the one I tried" is how
     * the hash case would have been missed too.
     */
    it('keeps a nav entry current through a trailing slash, a hash, or both with a query', () => {
        store.page.props.auth.can = ['rota.view', 'endorsement.view'];
        store.page.props.auth.user = { id: 12, member_name: 'res', full_name: 'A Resident', position: 4 };

        for (const url of ['/rota', '/rota/', '/rota#top', '/rota?year=2026-2027#top', '/rota/?q=ahmed']) {
            store.page.url = url;
            expect(
                mountLayout().get('nav[aria-label="Primary"] a[href="/rota"]').attributes('aria-current'),
                `expected /rota to be current on ${url}`,
            ).toBe('page');
        }

        // The chooser is `isExactly`, and a trailing slash must not defeat that either.
        store.page.url = '/endorsement/';
        expect(mountLayout().get('nav[aria-label="Primary"] a[href="/endorsement"]').attributes('aria-current'))
            .toBe('page');
    });

    /**
     * The root path is a PATH, not the empty string. Nothing in this nav sits at `/` today, so
     * normalising it away is currently unobservable — which is exactly why it is worth pinning
     * before something does. What IS observable now is that `/` must not make anything current:
     * a normalisation that produced `''` and an `isActive` written as a bare `startsWith` would
     * light up the whole sidebar at once.
     */
    it('claims nothing is current on the root path', () => {
        store.page.props.auth.can = ['rota.view', 'endorsement.view', 'structure.manage'];
        store.page.props.auth.user = { id: 13, member_name: 'res', full_name: 'A Resident', position: 4 };
        store.page.props.nav.units = [{ code: 'picu', label: 'PICU', bar: 'channel-bar-picu' }];
        store.page.url = '/';

        expect(mountLayout().findAll('nav[aria-label="Primary"] a[aria-current="page"]')).toHaveLength(0);
    });

    /**
     * `isActive` matches a prefix, and it must be a PATH-SEGMENT prefix. Unit codes are
     * administrator-created (P1b Task 4), so a department really can hold both `pic` and `picu` —
     * and a naive `startsWith(href)` would then light two channels for one screen, on the nav the
     * whole department reads.
     */
    it('does not mark a nav entry current because a deeper path merely starts with its href', () => {
        store.page.props.auth.can = ['endorsement.view'];
        store.page.props.auth.user = { id: 14, member_name: 'res', full_name: 'A Resident', position: 4 };
        store.page.props.nav.units = [
            { code: 'pic', label: 'Paediatric Investigations Clinic', bar: 'channel-bar-amber' },
            { code: 'picu', label: 'Paediatric Intensive Care Unit', bar: 'channel-bar-picu' },
        ];
        store.page.url = '/endorsement/picu?from=2026-08-01';

        const w = mountLayout();
        expect(w.get('nav[aria-label="Primary"] a[href="/endorsement/picu"]').attributes('aria-current')).toBe('page');
        expect(w.get('nav[aria-label="Primary"] a[href="/endorsement/pic"]').attributes('aria-current')).toBeUndefined();
    });

    /**
     * THE ADMINISTRATION SECTION ANNOUNCES NOTHING (adversarial review, finding 3, and
     * pre-existing on `main` rather than introduced by the read-view slice). All twelve links
     * below carried the visual `channel-bar` highlight and bound no `aria-current` at all, so a
     * screen reader was told where it was on the four top-level entries and nowhere else — the
     * whole admin surface, silent.
     *
     * Swept per link rather than sampled: the defect was twelve independent omissions, so one
     * representative would have proved one of them.
     */
    it('announces the current Administration entry on every one of its links', () => {
        const adminHrefs = [
            '/admin/users', '/admin/people', '/admin/promotion', '/admin/roster-import',
            '/admin/access-control', '/admin/structure/units', '/admin/structure/levels',
            '/admin/structure/calendar', '/admin/structure/periods', '/admin/structure/holidays',
            '/admin/rota', '/admin/settings',
        ];

        store.page.props.auth.can = ['users.manage', 'people.manage', 'access.manage',
            'structure.manage', 'rota.manage', 'settings.manage'];
        store.page.props.auth.user = { id: 15, member_name: 'adm', full_name: 'The Admin', position: 0 };

        for (const href of adminHrefs) {
            // The query string is on purpose: several of these screens push one from their own
            // controls, and the two defects compound — a filtered admin screen announced nothing
            // even before finding 3.
            store.page.url = `${href}?page=2`;
            const w = mountLayout();

            expect(
                w.get(`nav[aria-label="Primary"] a[href="${href}"]`).attributes('aria-current'),
                `expected ${href} to announce itself as current`,
            ).toBe('page');

            // Exactly one — an over-broad match would announce two places at once, which is worse
            // for a screen reader than announcing none.
            expect(
                w.findAll('nav[aria-label="Primary"] a[aria-current="page"]').length,
                `expected exactly one current entry on ${href}`,
            ).toBe(1);
        }
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
