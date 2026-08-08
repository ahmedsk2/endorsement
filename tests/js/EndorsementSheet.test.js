import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Mock @inertiajs/vue3 (mirrors the other page tests): a real <a> for Link, captured router
// calls, and a usePage that grants the endorsement capabilities.
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

// jsdom implements neither execCommand nor a selection model; the toolbar contract itself is
// covered by RichTextEditor.test.js.
document.execCommand = vi.fn(() => true);

const rows = [
    { id: 11, bed: '1', mrn: 'A-1', patient_name: 'Layla',
      disease: '<p>Sepsis</p>', details: '<b>stable</b>', plan: 'abx', nevent: '', draft: false },
    { id: 22, bed: '2', mrn: 'A-2', patient_name: 'Omar',
      disease: 'DKA', details: 'improving', plan: 'insulin', nevent: '', draft: false },
];

const mountSheet = (props = {}) => mount(Sheet, {
    props: {
        unit: { code: 'PICU', name: 'PICU', profile: { code: 'PICU', extra_row_fields: [], bed_label: 'Bed', consultant_pair: true, consultant_by_label: 'Consultant covering', bar_class: 'channel-bar-picu', plan_label: 'Plan Of Care', narrative_label: 'New events' } },
        date: '2026-07-10',
        rows,
        ...props,
    },
});

describe('Endorsement/Sheet', () => {
    it('renders one row per handover from props', () => {
        const w = mountSheet();
        const rowEls = w.findAll('[data-testid="handover-row"]');
        expect(rowEls).toHaveLength(2);
        // The census cells are editable inputs — their values (not text nodes) carry the data.
        const mrnValues = w.findAll('[data-testid="handover-row"] input')
            .map((i) => i.element.value);
        expect(mrnValues).toContain('A-1');
        expect(mrnValues).toContain('Omar');
        // The rich-text cell renders its (server-sanitized) markup.
        expect(w.get('[data-testid="cell-disease"]').html()).toContain('Sepsis');
    });

    // G1 — the registry is PICU-only: the columns that only the other units used (the neonatal
    // DOB, the ward age + sub-unit) are gone from the sheet entirely.
    it('renders no unit-specific columns', () => {
        const w = mountSheet();
        expect(w.find('[data-testid="col-dob"]').exists()).toBe(false);
        expect(w.find('[data-testid="col-age"]').exists()).toBe(false);
        expect(w.find('[data-testid="col-ward-unit"]').exists()).toBe(false);
    });

    // The channel bar collapses to the single PICU variant.
    it('carries the PICU channel bar', () => {
        expect(mountSheet().html()).toContain('channel-bar-picu');
    });

    it('renders an empty state when there are no rows', () => {
        const w = mountSheet({ rows: [] });
        expect(w.findAll('[data-testid="handover-row"]')).toHaveLength(0);
        expect(w.text().toLowerCase()).toContain('no ');
    });

    // G3 — every one of the four rich-text fields gets the SAME editor (and therefore the same
    // toolbar), rather than a bare contenteditable with no affordances.
    it('gives all four rich-text fields a formatting toolbar', () => {
        const w = mountSheet();

        for (const field of ['disease', 'details', 'plan', 'nevent']) {
            const cell = w.get(`[data-testid="cell-${field}"]`);
            expect(cell.find('[data-testid="rte-toolbar"]').exists()).toBe(true);
            expect(cell.find('[data-testid="rte-bold"]').exists()).toBe(true);
        }

        // Two rows × four fields × two layouts (mobile cards + desktop table) — the editor
        // is shared, not duplicated per-field ad hoc.
        expect(w.findAll('[data-testid="rte-editor"]')).toHaveLength(16);
    });

    // Ruling 1 — nevent carries forward (legacy parity); the sheet SAYS so.
    it('notes that the narrative carries forward with the census', () => {
        expect(mountSheet().text().toLowerCase()).toContain('carries forward');
    });
});

// G3 — legacy flashed a green underline on the saved element. We keep save-on-blur (safer under
// concurrency) but restore a per-FIELD confirmation, plus the failure state legacy never had.
describe('Endorsement/Sheet — per-field save feedback', () => {
    beforeEach(() => {
        router.patch.mockReset();
    });

    it('marks the field saved when the write succeeds', async () => {
        router.patch.mockImplementation((url, data, opts) => opts.onSuccess?.());

        const w = mountSheet();
        const cell = w.get('[data-testid="cell-plan"]');
        await cell.get('[data-testid="rte-editor"]').trigger('blur');
        await nextTick();

        expect(router.patch).toHaveBeenCalled();
        expect(cell.get('[data-testid="rte-status"]').text().toLowerCase()).toContain('saved');
    });

    it('marks the field not-saved when the write fails', async () => {
        router.patch.mockImplementation((url, data, opts) => opts.onError?.({ plan: 'nope' }));

        const w = mountSheet();
        const cell = w.get('[data-testid="cell-plan"]');
        await cell.get('[data-testid="rte-editor"]').trigger('blur');
        await nextTick();

        expect(cell.get('[data-testid="rte-status"]').text().toLowerCase()).toContain('not saved');
    });

    it('confirms a plain census cell save too', async () => {
        router.patch.mockImplementation((url, data, opts) => opts.onSuccess?.());

        const w = mountSheet();
        await w.get('[data-testid="cell-bed"]').trigger('change');
        await nextTick();

        expect(w.get('[data-testid="status-bed"]').text().toLowerCase()).toContain('saved');
    });
});

/*
 * P0b (design §6.2, "Ceiling 2") — bounded custom fields. `unit.profile.field_definitions` is
 * a list of {key, label, type, options, required}, active-only, already ordered server-side
 * (EndorsementController::unitPayload()). This is a SECOND, separate list from `extraFields`
 * (the named dob/age/ward_unit identity columns) — never merged with it — rendered after them
 * in BOTH the mobile cards and the desktop table, since Sheet.vue renders the census twice.
 */
describe('Endorsement/Sheet — custom fields (P0b)', () => {
    const fieldDefinitions = [
        { key: 'weight_kg', label: 'Weight (kg)', type: 'text', options: null, required: false },
        { key: 'allergy_status', label: 'Allergy status', type: 'select', options: ['none', 'known'], required: true },
        { key: 'admission_date', label: 'Admission date', type: 'date', options: null, required: false },
    ];

    const rowsWithExtra = [
        { id: 11, bed: '1', mrn: 'A-1', patient_name: 'Layla',
          disease: '<p>Sepsis</p>', details: '<b>stable</b>', plan: 'abx', nevent: '', draft: false,
          extra_fields: { weight_kg: '3.2', allergy_status: 'known', admission_date: '2026-07-01' } },
        { id: 22, bed: '2', mrn: 'A-2', patient_name: 'Omar',
          disease: 'DKA', details: 'improving', plan: 'insulin', nevent: '', draft: false,
          extra_fields: {} },
    ];

    const mountWithDefinitions = (props = {}) => mountSheet({
        unit: { code: 'PICU', name: 'PICU', profile: {
            code: 'PICU', extra_row_fields: [], bed_label: 'Bed', consultant_pair: true,
            consultant_by_label: 'Consultant covering', bar_class: 'channel-bar-picu',
            plan_label: 'Plan Of Care', narrative_label: 'New events',
            field_definitions: fieldDefinitions,
        } },
        rows: rowsWithExtra,
        ...props,
    });

    it('renders one input per definition in the mobile cards', () => {
        const w = mountWithDefinitions();
        const card = w.findAll('[data-testid="handover-card"]')[0];

        expect(card.find('[data-testid="m-cf-weight_kg"] input').exists()).toBe(true);
        expect(card.find('[data-testid="m-cf-allergy_status"] select').exists()).toBe(true);
        expect(card.find('[data-testid="m-cf-admission_date"] input').attributes('type')).toBe('date');
    });

    it('renders one input per definition in the desktop table', () => {
        const w = mountWithDefinitions();
        const row = w.findAll('[data-testid="handover-row"]')[0];

        expect(row.find('[data-testid="cell-cf-weight_kg"] input').exists()).toBe(true);
        expect(row.find('[data-testid="cell-cf-allergy_status"] select').exists()).toBe(true);
        expect(row.find('[data-testid="cell-cf-admission_date"] input').attributes('type')).toBe('date');
    });

    it('binds values from row.extra_fields[key] in both layouts', () => {
        const w = mountWithDefinitions();

        const cardInput = w.findAll('[data-testid="handover-card"]')[0]
            .get('[data-testid="m-cf-weight_kg"] input');
        expect(cardInput.element.value).toBe('3.2');

        const cellInput = w.findAll('[data-testid="handover-row"]')[0]
            .get('[data-testid="cell-cf-weight_kg"] input');
        expect(cellInput.element.value).toBe('3.2');
    });

    it('a select field renders its options', () => {
        const w = mountWithDefinitions();
        const select = w.findAll('[data-testid="handover-row"]')[0]
            .get('[data-testid="cell-cf-allergy_status"] select');

        const values = select.findAll('option').map((o) => o.element.value);
        expect(values).toEqual(expect.arrayContaining(['none', 'known']));
    });

    it('a row with no custom values yet still renders empty inputs, not errors', () => {
        const w = mountWithDefinitions();
        const secondRow = w.findAll('[data-testid="handover-row"]')[1];

        expect(secondRow.get('[data-testid="cell-cf-weight_kg"] input').element.value).toBe('');
    });

    it('changing one custom field PATCHes only that field, shaped {extra_fields: {[key]: value}}', async () => {
        router.patch.mockReset();
        router.patch.mockImplementation((url, data, opts) => opts.onSuccess?.());

        const w = mountWithDefinitions();
        const input = w.findAll('[data-testid="handover-row"]')[0]
            .get('[data-testid="cell-cf-weight_kg"] input');

        await input.setValue('3.5');
        await nextTick();

        expect(router.patch).toHaveBeenCalledTimes(1);
        const [url, payload] = router.patch.mock.calls[0];
        expect(url).toBe('/endorsement/rows/11');
        expect(payload).toEqual({ extra_fields: { weight_kg: '3.5' } });
    });

    it('does not send the whole extra_fields map, only the one key that changed', async () => {
        router.patch.mockReset();
        router.patch.mockImplementation((url, data, opts) => opts.onSuccess?.());

        const w = mountWithDefinitions();
        const select = w.findAll('[data-testid="handover-row"]')[0]
            .get('[data-testid="cell-cf-allergy_status"] select');

        await select.setValue('none');
        await nextTick();

        const [, payload] = router.patch.mock.calls[0];
        expect(Object.keys(payload.extra_fields)).toEqual(['allergy_status']);
    });

    // The sentinel: `extra_fields.__unreadable` means the row's whole custom-field map failed to
    // decrypt (foreign APP_KEY). A renderer keyed strictly on definitions would drop it and show
    // a clean but silently incomplete clinical sheet — this is the entire reason it exists.
    it('renders the __unreadable sentinel as a visible row-level warning in both layouts', () => {
        const unreadableRows = [
            { id: 33, bed: '3', mrn: 'A-3', patient_name: 'Zaid',
              disease: 'x', details: 'y', plan: 'z', nevent: '', draft: false,
              extra_fields: { __unreadable: '[unreadable — encrypted with a different key]' } },
        ];
        const w = mountWithDefinitions({ rows: unreadableRows });

        expect(w.get('[data-testid="m-extra-fields-unreadable"]').text()).toContain('unreadable');
        expect(w.get('[data-testid="row-extra-fields-unreadable"]').text()).toContain('unreadable');
    });

    it('does not render a warning for a row whose custom fields decrypted fine', () => {
        const w = mountWithDefinitions();
        expect(w.find('[data-testid="m-extra-fields-unreadable"]').exists()).toBe(false);
        expect(w.find('[data-testid="row-extra-fields-unreadable"]').exists()).toBe(false);
    });

    // Values are plain text and are NEVER sanitised server-side (App\Casts\EncryptedJson's own
    // docblock). Rendering them with v-html would be an XSS hole; interpolation/`:value` binding
    // is the only safe path. Pin it two ways: no v-html anywhere in the file, and a value
    // containing markup is never parsed as an element.
    it('never renders custom field values through v-html', () => {
        const source = readFileSync(
            resolve(__dirname, '../../resources/js/Pages/Endorsement/Sheet.vue'),
            'utf-8',
        );
        const template = source.match(/<template>([\s\S]*)<\/template>/)[1];

        // Sheet.vue has NO safe use of v-html anywhere (unlike Print.vue, whose four rich-text
        // columns are server-sanitized) — the directive must never appear in this file's markup.
        expect(template).not.toMatch(/\bv-html\s*=/);
    });

    it('a custom field value containing markup stays inert text, never parsed as an element', () => {
        const rowsWithMarkup = [
            { id: 44, bed: '4', mrn: 'A-4', patient_name: 'Sara',
              disease: 'x', details: 'y', plan: 'z', nevent: '', draft: false,
              extra_fields: { weight_kg: '<img src=x onerror=alert(1)>' } },
        ];
        const w = mountWithDefinitions({ rows: rowsWithMarkup });

        expect(w.find('img').exists()).toBe(false);
        const input = w.findAll('[data-testid="handover-row"]')[0]
            .get('[data-testid="cell-cf-weight_kg"] input');
        expect(input.element.value).toBe('<img src=x onerror=alert(1)>');
    });
});
