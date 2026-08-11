import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// The same mock pattern as Rota/MasterRota*: `post`, `patch` and `put` are all mocked so a test can
// prove which one a control reaches for — and the destructive verb is mocked too, NOT because this
// screen uses it, but so the test asserting it is never called has something to assert against. A
// spy that is never armed cannot fail.
const store = vi.hoisted(() => ({
    post: vi.fn(), patch: vi.fn(), put: vi.fn(), destroy: vi.fn(),
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
    });

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
