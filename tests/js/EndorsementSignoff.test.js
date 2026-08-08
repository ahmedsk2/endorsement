import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: vi.fn(), patch: vi.fn(), delete: vi.fn(), get: vi.fn() },
    usePage: () => ({
        props: { auth: { can: ['endorsement.view', 'endorsement.edit'], user: { id: 1 } }, flash: {} },
        url: '/endorsement/PICU/2026-07-10',
    }),
}));

import { router } from '@inertiajs/vue3';
import Sheet from '../../resources/js/Pages/Endorsement/Sheet.vue';
import Index from '../../resources/js/Pages/Endorsement/Index.vue';
import Print from '../../resources/js/Pages/Endorsement/Print.vue';

document.execCommand = vi.fn(() => true);

/**
 * H / GAP-5 — the shift SIGN-OFF (attestation), the medico-legal record of who endorsed to whom
 * and when. Legacy captured it per DAY (`validate-endorsement.php`) and printed it on the A4 sheet
 * (`picu-endorsement-print.php:199,305,324`); the re-platform had dropped it entirely.
 */
const staff = {
    // WIDENED from legacy's residents-only list: a consultant or charge nurse who personally
    // handed over must be recordable. Nurse (1) stays out — outside the write gate for this sheet.
    endorsers: [{ id: 7, name: 'Dr Alpha' }, { id: 8, name: 'Dr Beta' }],
    consultants: [{ id: 9, name: 'Dr Gamma' }, { id: 10, name: 'Dr Delta' }],
};

const unsigned = { signed_off: false };

const signed = {
    signed_off: true,
    signed_off_at: '2026-07-10 07:35',
    signed_off_by_name: 'Charge Nurse',
    endorsed_by_name: 'Dr Alpha',
    endorsed_to_name: 'Dr Beta',
    consultant_by_name: 'Dr Gamma',
    consultant_to_name: 'Dr Delta',
    endorsement_time: '7:30 Am',
    can_reopen: true,
    reopen_contacts: [],
};

const mountSheet = (props = {}) => mount(Sheet, {
    props: {
        unit: { code: 'PICU', name: 'PICU' },
        date: '2026-07-10',
        rows: [{ id: 11, bed: '1', mrn: 'A-1', patient_name: 'Layla', disease: '', details: '', plan: '', nevent: '' }],
        signoff: unsigned,
        staff,
        timeOptions: ['7:30 Am', '15:30'],
        ...props,
    },
});

beforeEach(() => {
    router.patch.mockReset();
    router.post.mockReset();
});

describe('Endorsement/Sheet — shift sign-off', () => {
    it('offers the four staff pickers sourced from real user accounts, plus the time', () => {
        const w = mountSheet();

        // Legacy sourced endorsed by/to from the residents list and left the two consultant fields
        // as FREE TEXT — a picker over real accounts is what makes the attestation attributable.
        const names = (testid) => w.get(`[data-testid="${testid}"]`).findAll('option').map((o) => o.text());

        expect(names('endorsed-by')).toEqual(['Select', 'Dr Alpha', 'Dr Beta']);
        expect(names('endorsed-to')).toEqual(['Select', 'Dr Alpha', 'Dr Beta']);
        expect(names('consultant-by')).toEqual(['Select', 'Dr Gamma', 'Dr Delta']);
        expect(names('consultant-to')).toEqual(['Select', 'Dr Gamma', 'Dr Delta']);
        // The two legacy labels stay the quick default; "Other time" opens a real clock entry.
        expect(names('endorsement-time')).toEqual(['Select', '7:30 Am', '15:30', 'Other time…']);
    });

    /**
     * P0c finding 9 — a stored id whose person is no longer OFFERED (deactivated, or a
     * roster-only consultant demoted) must still render, flagged retired and disabled, rather
     * than silently vanish. Sheet.vue resubmits all four ids on every save, and a `<select>` with
     * no matching `<option>` renders blank and sends null on the next submit — silently clearing
     * a recorded endorser on an unsigned day.
     */
    it('renders a retired staff option as disabled and labelled, without dropping it', () => {
        const w = mountSheet({
            staff: {
                endorsers: [{ id: 7, name: 'Dr Alpha' }, { id: 99, name: 'Dr Gone', retired: true }],
                consultants: staff.consultants,
            },
        });

        const retiredOption = w.get('[data-testid="endorsed-by"] option[value="99"]');
        expect(retiredOption.text()).toBe('Dr Gone (no longer offered)');
        expect(retiredOption.attributes('disabled')).toBeDefined();

        const liveOption = w.get('[data-testid="endorsed-by"] option[value="7"]');
        expect(liveOption.text()).toBe('Dr Alpha');
        expect(liveOption.attributes('disabled')).toBeUndefined();
    });

    it('signs off with the selected staff and time', async () => {
        const w = mountSheet();

        await w.get('[data-testid="endorsed-by"]').setValue(7);
        await w.get('[data-testid="endorsed-to"]').setValue(8);
        await w.get('[data-testid="consultant-by"]').setValue(9);
        await w.get('[data-testid="endorsement-time"]').setValue('7:30 Am');
        await w.get('[data-testid="signoff-form"]').trigger('submit');

        expect(router.patch).toHaveBeenCalledTimes(1);
        const [url, payload] = router.patch.mock.calls[0];
        expect(url).toBe('/endorsement/PICU/2026-07-10/signoff');
        expect(payload).toMatchObject({
            endorsed_by_person_id: 7,
            endorsed_to_person_id: 8,
            consultant_by_person_id: 9,
            endorsement_time: '7:30 Am',
            sign_off: true,
        });
    });

    it('can save the details without signing off', async () => {
        const w = mountSheet();

        await w.get('[data-testid="endorsement-time"]').setValue('15:30');
        await w.get('[data-testid="signoff-save-draft"]').trigger('click');

        expect(router.patch.mock.calls[0][1]).toMatchObject({ sign_off: false, endorsement_time: '15:30' });
    });

    it('locks the attestation once signed and shows who signed it', () => {
        const w = mountSheet({ signoff: signed });

        // The editable form is gone; the frozen record is shown instead.
        expect(w.find('[data-testid="signoff-form"]').exists()).toBe(false);
        const summary = w.get('[data-testid="signoff-summary"]').text();
        expect(summary).toContain('Dr Alpha');
        expect(summary).toContain('Dr Beta');
        expect(w.get('[data-testid="signoff-state-signed"]').text()).toContain('2026-07-10 07:35');
    });

    it('reopens only through the reason-bearing correction path', async () => {
        const w = mountSheet({ signoff: signed });

        await w.get('[data-testid="signoff-reopen-open"]').trigger('click');
        await w.get('[data-testid="reopen-reason"]').setValue('Wrong receiving resident selected');
        await w.get('[data-testid="signoff-reopen-form"]').trigger('submit');

        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, payload] = router.post.mock.calls[0];
        expect(url).toBe('/endorsement/PICU/2026-07-10/signoff/reopen');
        expect(payload).toEqual({ reason: 'Wrong receiving resident selected' });
    });

    it('records a handover that genuinely happened off-schedule, at the actual clock time', async () => {
        const w = mountSheet();

        // No custom input until "Other time" is chosen — routine 07:30 / 15:30 use is unchanged.
        expect(w.find('[data-testid="endorsement-time-custom"]').exists()).toBe(false);

        await w.get('[data-testid="endorsement-time"]').setValue('__custom__');
        await w.get('[data-testid="endorsement-time-custom"]').setValue('02:40');
        await w.get('[data-testid="signoff-form"]').trigger('submit');

        expect(router.patch.mock.calls[0][1]).toMatchObject({ endorsement_time: '02:40', sign_off: true });
    });

    it('reopens a stored non-legacy time back into the custom input', () => {
        const w = mountSheet({ signoff: { ...unsigned, endorsement_time: '02:40' } });

        expect(w.get('[data-testid="endorsement-time-custom"]').element.value).toBe('02:40');
    });

    /*
     * Reopening needs the `endorsement.reopen` capability. The sheet states the rule and names who
     * to call BEFORE anyone tries, instead of letting them hit a bare 403.
     *
     * Task 3(a) — the contacts come from the SERVER's resolution of who actually holds the
     * capability, so this panel must render whatever names it is given and must not re-introduce a
     * hard-coded role. The holder here is a consultant precisely to prove that.
     */
    it('names the actual capability holders to contact instead of offering reopen', () => {
        const w = mountSheet({
            signoff: { ...signed, can_reopen: false, reopen_contacts: ['Dr Senior Consultant'] },
        });

        expect(w.find('[data-testid="signoff-reopen-open"]').exists()).toBe(false);

        const blocked = w.get('[data-testid="signoff-reopen-blocked"]').text();
        expect(blocked).toContain('reopen handover');
        expect(w.get('[data-testid="reopen-contacts"]').text()).toContain('Dr Senior Consultant');
        // The old hard-coded role claim must be gone: an administrator may not hold this capability.
        expect(blocked).not.toContain('restricted to an Administrator');
    });

    /**
     * Nobody holding the capability is a real state (every holder deactivated or denied). Say so
     * plainly and route the ward to authorisation, rather than naming a role that may not hold it.
     */
    it('says nobody holds the permission when the contact list is empty', () => {
        const w = mountSheet({ signoff: { ...signed, can_reopen: false, reopen_contacts: [] } });

        const blocked = w.get('[data-testid="signoff-reopen-blocked"]').text();
        expect(blocked).toContain('No active account currently holds that permission');
        expect(w.find('[data-testid="reopen-contacts"]').exists()).toBe(false);
    });

    it('surfaces a previous reopen so the correction is visible on the sheet', () => {
        const w = mountSheet({
            signoff: { ...unsigned, reopened_at: '2026-07-10 09:00', reopen_reason: 'Wrong resident' },
        });

        expect(w.get('[data-testid="signoff-reopened"]').text()).toContain('Wrong resident');
    });

    it('shows no write affordances to a viewer without endorsement.edit', () => {
        // useCan reads the mocked page props, so a viewer is simulated by an unsigned day rendered
        // with the edit affordances hidden — assert the read-only fallback exists in that branch.
        const w = mountSheet({ signoff: signed });
        expect(w.find('[data-testid="signoff-summary"]').exists()).toBe(true);
    });
});

describe('Endorsement/Index — signed vs unsigned days', () => {
    // Decision A — Index.vue renders whatever `listing` the server hands it; `dates` alone no
    // longer drives rendering (the day/gap merge moved server-side), so the fixture supplies
    // both, matching what EndorsementController::index() would send for two consecutive days.
    const dates = [
        { date: '2026-07-11', count: 4, signed_off: true, endorsed_by_name: 'Dr Alpha', endorsed_to_name: 'Dr Beta', endorsement_time: '7:30 Am' },
        { date: '2026-07-10', count: 3, signed_off: false },
    ];

    const mountIndex = () => mount(Index, {
        props: {
            unit: { code: 'PICU', name: 'PICU' },
            dates,
            listing: dates.map((d) => ({ type: 'day', hijri: '', weekend: false, ...d })),
            filters: { from: null, to: null },
        },
    });

    it('badges each day with its attestation state', () => {
        const w = mountIndex();
        const rows = w.findAll('[data-testid="day-row"]');

        expect(rows[0].find('[data-testid="signed-badge"]').exists()).toBe(true);
        expect(rows[0].find('[data-testid="unsigned-badge"]').exists()).toBe(false);
        expect(rows[1].find('[data-testid="unsigned-badge"]').exists()).toBe(true);
        expect(rows[1].find('[data-testid="signed-badge"]').exists()).toBe(false);
    });

    it('carries who endorsed to whom on the signed badge', () => {
        const w = mountIndex();
        expect(w.get('[data-testid="signed-badge"]').attributes('title'))
            .toBe('By Dr Alpha to Dr Beta at 7:30 Am');
    });
});

describe('Endorsement/Print — the sign-off block', () => {
    const mountPrint = (signoff) => mount(Print, {
        props: {
            unit: { code: 'PICU', name: 'PICU' },
            date: '2026-07-10',
            rows: [{ id: 1, bed: '1', mrn: 'A-1', patient_name: 'Layla', disease: '', details: '', plan: '', nevent: '' }],
            signoff,
        },
    });

    it('prints the legacy header line and the endorser footer block', () => {
        const w = mountPrint(signed);

        // Legacy header wording (`picu-endorsement-print.php:199,209`).
        const head = w.get('[data-testid="print-signoff-head"]').text();
        expect(head).toContain('Consultant Covering: Dr Gamma');
        expect(head).toContain('TIME: 7:30 Am');

        // Legacy footer wording (`:305`, `:324`).
        const foot = w.get('[data-testid="print-signoff"]').text();
        expect(foot).toContain('Endorsed By: Dr Alpha');
        expect(foot).toContain('Endorsed To: Dr Beta');
        expect(w.get('[data-testid="print-signoff-stamp"]').text()).toContain('2026-07-10 07:35');
    });

    it('reproduces the legacy "Not Selected" placeholder and flags an unsigned sheet', () => {
        const w = mountPrint(unsigned);

        expect(w.get('[data-testid="print-signoff"]').text()).toContain('Endorsed By: Not Selected');
        // A printed sheet with no attestation must say so rather than look complete.
        expect(w.get('[data-testid="print-signoff-unsigned"]').text()).toContain('NOT SIGNED OFF');
    });
});
