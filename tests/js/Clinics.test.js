import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// The same mock pattern as Rota/MasterRota*: `post`, `patch` and `put` are all mocked so a test can
// prove which one a control reaches for — and the destructive verb is mocked too, NOT because this
// screen uses it, but so the test asserting it is never called has something to assert against. A
// spy that is never armed cannot fail.
const store = vi.hoisted(() => ({
    post: vi.fn(),

    /**
     * `patch` can be told to REFUSE. Set `store.refuseWith` to an error bag and the next patch
     * populates the form's `errors` and fires `onError`, which is the only way to exercise a
     * refusal path from this side of the boundary — an assertion that a control merely *calls*
     * the endpoint cannot tell a rendered refusal from a silent one.
     */
    patch: vi.fn(function (url, options) {
        store.patched = { url, options };

        if (store.refuseWith) {
            this.errors = store.refuseWith;
            options?.onError?.(store.refuseWith);
        }
    }),

    destroy: vi.fn(),
    refuseWith: null,
    patched: null,

    /**
     * `put` RECORDS THE FORM'S OWN STATE, not only the arguments it was called with. Inertia's
     * `useForm` sends `form.data()` — which never appears in `put(url, options)` — so a spy that
     * captures arguments alone can assert which endpoint a control reaches and nothing about what
     * it sends. The lockout this file's two payload tests exist to catch lives entirely in that
     * payload: the endpoint, the verb and the rendered DOM were all correct while the form carried
     * ids no checkbox on the screen could ever untick.
     *
     * Written as a `function` rather than an arrow on purpose — `this` is the form object.
     */
    put: vi.fn(function (url, options) {
        store.submitted = {
            url,
            options,
            mode: this.mode,
            level_ids: [...this.level_ids],
            person_ids: [...this.person_ids],
        };
    }),

    submitted: null,
}));

// `reactive`, not a plain object: `v-model` on a plain object mutates it without notifying Vue, so
// a mode switch would change the form and re-render nothing — the picker tests below would then be
// asserting the initial render twice and passing for the wrong reason.
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
            patch: store.patch,
            put: store.put,
            delete: store.destroy,
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
            url: '/admin/structure/clinics',
        }),
    };
});

import Clinics from '../../resources/js/Pages/Admin/Clinics.vue';

const SOURCE = readFileSync(resolve(__dirname, '../../resources/js/Pages/Admin/Clinics.vue'), 'utf8');

/**
 * DELIBERATELY NOT ENGLISH DAY NAMES. `Calendar::weekdayColumns()` sends this array already labelled
 * and already rotated into the department's own week order, and the point of these fixtures is that
 * a component which hardcoded `['Sun', 'Mon', …]` would render the wrong seven strings in the wrong
 * order and every assertion below would fail. Real labels would have made a hardcoded list
 * indistinguishable from a consumed prop.
 */
const weekdays = [
    { iso: 7, label: 'Day-Seven', short: 'D7', weekend: false },
    { iso: 1, label: 'Day-One', short: 'D1', weekend: false },
    { iso: 2, label: 'Day-Two', short: 'D2', weekend: false },
    { iso: 3, label: 'Day-Three', short: 'D3', weekend: false },
    { iso: 4, label: 'Day-Four', short: 'D4', weekend: false },
    { iso: 5, label: 'Day-Five', short: 'D5', weekend: true },
    { iso: 6, label: 'Day-Six', short: 'D6', weekend: true },
];

const person = (id, fullName) => ({
    id, full_name: fullName, short_name: null, position: 4,
    external: false, active: true, retired: false, has_account: true, joined_at: null,
});

const clinic = (overrides = {}) => ({
    id: 500,
    unit_id: 10,
    name: 'General Paediatrics',
    weekday: 2,
    weekday_label: 'Day-Two',
    weekday_short: 'D2',
    session: 'AM',
    session_label: 'Morning',
    location: 'Clinic B',
    note: null,
    active: true,
    attendee_mode: 'rotators',
    mode_label: 'Everyone rotating on this unit',
    level_ids: [],
    person_ids: [],
    unlisted: [],
    resolved_today: [],
    ...overrides,
});

const mountScreen = (overrides = {}) => mount(Clinics, {
    props: {
        clinics: [{
            unit: { id: 10, code: 'WARD', name: 'Pediatric Ward', active: true, clinic_owner: true, bar_class: 'channel-bar-ward' },
            clinics: [clinic()],
        }],
        units: [{ id: 10, code: 'WARD', name: 'Pediatric Ward', bar_class: 'channel-bar-ward' }],
        weekdays,
        sessions: { AM: 'Morning', PM: 'Afternoon' },
        modes: {
            rotators: 'Everyone rotating on this unit',
            levels: 'Rotators at these training levels',
            named: 'These people only',
        },
        levels: [{ id: 1, code: 'R1', name: 'Resident 1' }],
        people: [person(5, 'Dana Resident')],
        today: { date: '2026-08-11', hijri: '1448-02-18', weekend: false, holiday: null, day_type: 'WD' },
        ...overrides,
    },
    global: {
        stubs: {
            AppLayout: { name: 'AppLayout', template: '<div><slot /></div>' },
        },
    },
});

describe('Admin → Structure → Clinics', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        // `clearAllMocks` clears calls, not the captured payload, and a leaked one from the
        // previous test would let a screen that sends nothing at all pass.
        store.submitted = null;
        store.patched = null;
        store.refuseWith = null;
    });

    /**
     * The attendees form, found by the one id that is unique to it. Triggering `submit` on the
     * form rather than clicking its button, because `@submit.prevent` is what the component binds
     * and jsdom does not synthesise a submit from a button click.
     */
    const submitAttendees = (w, clinicId) => w
        .findAll('form')
        .find((f) => f.html().includes(`mode-${clinicId}`))
        .trigger('submit');

    const groupWith = (theClinic) => [{
        unit: { id: 10, code: 'WARD', name: 'Pediatric Ward', active: true, clinic_owner: true, bar_class: 'channel-bar-ward' },
        clinics: [theClinic],
    }];

    it('renders a clinic with its server-built labels', () => {
        const text = mountScreen().text();

        expect(text).toContain('General Paediatrics');
        expect(text).toContain('Day-Two');
        expect(text).toContain('Morning');
        expect(text).toContain('Clinic B');
    });

    /**
     * THE WEEKDAY PICKER IS THE PROP, IN THE PROP'S ORDER — behaviour, not source text. The
     * SOURCE-level half lives in `CalendarIsTheOnlyConverterTest::
     * test_no_hardcoded_weekday_vocabulary_appears_under_resources_js`, repo-wide over `resources/js`
     * rather than duplicated here, so the next clinic surface is guarded without anybody remembering
     * to copy an assertion. The two are not the same test: that one catches seven day names written
     * anywhere in any file, this one catches a picker built from the wrong list or in the wrong
     * order — a component could consume the labels honestly and still sort them itself.
     */
    it('builds the day picker from the calendar prop, in the department order', async () => {
        const w = mountScreen();
        await w.findAll('button').find((b) => b.text().includes('New clinic')).trigger('click');
        const options = w.findAll('#new-weekday option');

        expect(options.map((o) => o.text())).toEqual(weekdays.map((d) => d.label));
        expect(options.map((o) => o.attributes('value'))).toEqual(weekdays.map((d) => String(d.iso)));
    });

    it('takes its units from the prop rather than a literal list', async () => {
        const w = mountScreen({
            units: [{ id: 77, code: 'RGH1', name: 'Fifth Department', bar_class: 'channel-bar-slate' }],
            clinics: [],
        });

        await w.findAll('button').find((b) => b.text().includes('New clinic')).trigger('click');

        expect(w.findAll('#new-unit option').map((o) => o.text())).toEqual(['RGH1 — Fifth Department']);
    });

    it('escapes a clinic name rather than rendering it as markup', () => {
        const w = mountScreen({
            clinics: [{
                unit: { id: 10, code: 'WARD', name: 'Pediatric Ward', active: true, clinic_owner: true, bar_class: 'channel-bar-ward' },
                clinics: [clinic({ name: '<img src=x onerror=boom>', location: '<b>Room 9</b>' })],
            }],
        });

        expect(w.html()).not.toContain('<img src=x');
        expect(w.html()).not.toContain('<b>Room 9</b>');
        expect(w.html()).toContain('&lt;img src=x onerror=boom&gt;');
    });

    it('never uses v-html and never a dark-mode utility', () => {
        expect(SOURCE).not.toContain('v-html');
        expect(SOURCE).not.toMatch(/\bdark:/);
        // There is no such token — it compiles to nothing, and two uses of it shipped invisibly once.
        expect(SOURCE).not.toContain('bg-panel-soft');
        // No hex in markup: unit colour is a `Unit::BAR_CLASSES` name and nothing else.
        expect(SOURCE).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
    });

    it('offers no delete control and never calls a delete', () => {
        const w = mountScreen();

        expect(w.text()).not.toContain('Delete');
        expect(w.text()).toContain('Stop');

        w.findAll('button').forEach((b) => b.trigger('click'));

        expect(store.destroy).not.toHaveBeenCalled();
    });

    it('stops a clinic through the active endpoint, preserving scroll', async () => {
        const w = mountScreen();

        await w.findAll('button').find((b) => b.text() === 'Stop').trigger('click');

        expect(store.patch).toHaveBeenCalledWith(
            '/admin/structure/clinics/500/active',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    /**
     * A REFUSED STOP OR RESTART IS VISIBLE, AND VISIBLE ON THE ROW IT WAS PRESSED ON (P1e-1
     * adversarial review finding 3).
     *
     * `ClinicController::setActive()` flashes `ClinicWriter`'s refusal — a clinic cannot be
     * revived onto a unit that has since been retired or stopped owning clinics — under the key
     * `active`, and nothing on this screen read it. The button therefore appeared to do nothing:
     * no message, no state change, no error. That is the third instance of this shape in two
     * slices (ruling 41's single resend; finding 1's attendee lists), which is why its render site
     * is asserted here rather than left to inspection.
     *
     * THE SECOND ASSERTION IS THE LOAD-BEARING ONE. `activeForm` is ONE `useForm` shared by every
     * row's Stop/Restart button, so an unguarded `v-if="activeForm.errors.active"` renders the
     * same refusal under every clinic on the page — the message would be present, and pointing at
     * three clinics that were never touched. Two clinics are mounted for exactly that reason.
     */
    it('renders a refused stop or restart against the clinic it was pressed on', async () => {
        store.refuseWith = { active: 'Unit [WARD] is retired.' };

        const w = mountScreen({
            clinics: [{
                unit: { id: 10, code: 'WARD', name: 'Pediatric Ward', active: false, clinic_owner: true, bar_class: 'channel-bar-ward' },
                clinics: [
                    clinic({ id: 500, name: 'First Clinic', active: false }),
                    clinic({ id: 501, name: 'Second Clinic', active: false }),
                ],
            }],
        });

        const restarts = w.findAll('button').filter((b) => b.text() === 'Restart');
        // Two clinics × (phone card + desktop row) — the button exists on both breakpoints.
        expect(restarts.length).toBe(4);

        await restarts[restarts.length - 1].trigger('click');

        expect(store.patched.url).toBe('/admin/structure/clinics/501/active');
        expect(w.text()).toContain('Unit [WARD] is retired.');

        const shown = w.findAll('[data-testid="clinic-active-error"]');

        expect(shown.length).toBeGreaterThan(0);
        shown.forEach((node) => {
            expect(node.text()).toContain('Unit [WARD] is retired.');
        });
        // The other clinic was not touched and must not be wearing the refusal.
        expect(w.findAll('[data-testid="clinic-active-error-500"]').length).toBe(0);
    });

    it('shows the level picker only in levels mode and the people picker only in named mode', async () => {
        const w = mountScreen();

        await w.findAll('button').find((b) => b.text() === 'Who attends').trigger('click');

        const modeSelect = w.get('#mode-500');

        await modeSelect.setValue('levels');
        expect(w.text()).toContain('Resident 1');
        expect(w.text()).not.toContain('Dana Resident');

        await modeSelect.setValue('named');
        expect(w.text()).toContain('Dana Resident');
    });

    it('names an attached subject the pickers no longer offer', () => {
        const w = mountScreen({
            clinics: [{
                unit: { id: 10, code: 'WARD', name: 'Pediatric Ward', active: true, clinic_owner: true, bar_class: 'channel-bar-ward' },
                clinics: [clinic({
                    attendee_mode: 'named',
                    mode_label: 'These people only',
                    person_ids: [99],
                    unlisted: [{ kind: 'person', id: 99, label: 'Departed Colleague' }],
                })],
            }],
        });

        expect(w.text()).toContain('Departed Colleague');
    });

    /**
     * THE BANNER'S PROMISE, MADE TRUE (P1e-1 adversarial review finding 1).
     *
     * The panel says "No longer offered, and saving will drop them". It seeded the form with the
     * clinic's WHOLE stored id list, so the departed colleague was sent back on every save —
     * nothing dropped them, and no control on the screen could: their checkbox is not rendered,
     * because the picker is built from the offered list. The server then refused the whole request
     * under `person_ids.N`, a key this screen renders no element for, so `onSuccess` never fired
     * and the panel sat open reporting nothing.
     *
     * Asserted on the PAYLOAD rather than the DOM, and that is the point: a form holding [5, 99]
     * and a form holding [5] render byte-identical markup when 99 has no checkbox. Every visible
     * surface was correct while the submitted body was not.
     */
    it('submits only the attendee ids the pickers still offer', async () => {
        const w = mountScreen({
            clinics: groupWith(clinic({
                attendee_mode: 'named',
                mode_label: 'These people only',
                person_ids: [5, 99],
                unlisted: [{ kind: 'person', id: 99, label: 'Departed Colleague' }],
            })),
        });

        await w.findAll('button').find((b) => b.text() === 'Who attends').trigger('click');

        expect(w.text()).toContain('saving will drop them');
        expect(w.text()).toContain('Departed Colleague');

        await submitAttendees(w, 500);

        expect(store.submitted.url).toBe('/admin/structure/clinics/500/attendees');
        expect(store.submitted.mode).toBe('named');
        // 5 is offered and stays ticked; 99 is what the banner just promised to drop.
        expect(store.submitted.person_ids).toEqual([5]);
    });

    it('submits only the level ids the pickers still offer', async () => {
        const w = mountScreen({
            clinics: groupWith(clinic({
                attendee_mode: 'levels',
                mode_label: 'Rotators at these training levels',
                level_ids: [1, 42],
                unlisted: [{ kind: 'level', id: 42, label: 'XR9 — Retired Level' }],
            })),
        });

        await w.findAll('button').find((b) => b.text() === 'Who attends').trigger('click');
        await submitAttendees(w, 500);

        expect(store.submitted.mode).toBe('levels');
        expect(store.submitted.level_ids).toEqual([1]);
    });

    /**
     * THE ESCAPE, from the client side. `rotators` needs no rules at all, so it is the state a
     * clinic whose whole rule set has aged out must be able to reach — and it was the state the
     * unconditional server rules refused, which is what made the lockout permanent rather than
     * transient. Its server half is
     * `ClinicScreenTest::test_a_list_the_chosen_mode_ignores_cannot_refuse_the_save`.
     */
    it('can switch a clinic whose whole rule set has aged out back to rotators', async () => {
        const w = mountScreen({
            clinics: groupWith(clinic({
                attendee_mode: 'named',
                mode_label: 'These people only',
                person_ids: [99],
                unlisted: [{ kind: 'person', id: 99, label: 'Departed Colleague' }],
            })),
        });

        await w.findAll('button').find((b) => b.text() === 'Who attends').trigger('click');
        await w.get('#mode-500').setValue('rotators');
        await submitAttendees(w, 500);

        expect(store.submitted.mode).toBe('rotators');
        expect(store.submitted.person_ids).toEqual([]);
        expect(store.submitted.level_ids).toEqual([]);
    });

    it('marks a resolved person who is no longer on the roster', () => {
        const w = mountScreen({
            clinics: [{
                unit: { id: 10, code: 'WARD', name: 'Pediatric Ward', active: true, clinic_owner: true, bar_class: 'channel-bar-ward' },
                clinics: [clinic({
                    resolved_today: [{ ...person(5, 'Dana Resident'), via: 'rotation', stale: true }],
                })],
            }],
        });

        expect(w.text()).toContain('Dana Resident');
        expect(w.text()).toContain('off the roster');
    });
});
