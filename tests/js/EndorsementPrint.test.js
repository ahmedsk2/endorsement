import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    // The print page carries its own "back to the sheet" link now that it is no longer
    // opened in a throwaway tab — a new tab from an installed PWA lands in the system
    // browser, with a different cookie jar and therefore a sign-in page.
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

import Print from '../../resources/js/Pages/Endorsement/Print.vue';

/**
 * G7 B2 — the PRINTED A4 handover sheet must keep list markers.
 *
 * Clinical stake: a nurse writes the overnight plan as a NUMBERED list ("1. wean FiO2,
 * 2. repeat gas at 06:00, 3. call PICU consultant if lactate rises"), prints the sheet and
 * hands it to the next shift. Tailwind's preflight resets `ol,ul,menu{list-style:none}` and
 * zeroes list padding globally, so without an explicit restore the printed page shows three
 * unnumbered lines — the ORDER, which is the clinical content of an ordered list, is gone.
 *
 * G3 fixed this on screen (`.prose-handover` in app.css) but the print sheet has its own
 * standalone scoped stylesheet and was never covered.
 *
 * The subtle part: the handover cells render via `v-html`, and Vue's `scoped` attribute is
 * NOT stamped onto nodes injected that way. A plain `.print-table ul {}` rule silently does
 * nothing here — the restore MUST go through `:deep()`. That is what the CSS assertions below
 * pin down, and it is why "the rule exists" is not on its own enough.
 */
const rowWithLists = {
    id: 1,
    bed: '3',
    mrn: 'MRN-001',
    patient_name: 'Test Patient',
    disease: '<p>Bronchiolitis</p>',
    details: '<ul><li>HFNC 8 L/min</li><li>FiO2 0.35</li></ul>',
    plan: '<ol><li>Wean FiO2</li><li>Repeat gas 06:00</li><li>Escalate if lactate rises</li></ol>',
    nevent: '<p>None</p>',
};

const styleBlock = (() => {
    const src = readFileSync(
        resolve(__dirname, '../../resources/js/Pages/Endorsement/Print.vue'),
        'utf-8'
    );
    const match = src.match(/<style[^>]*>([\s\S]*)<\/style>/);

    return match ? match[1] : '';
})();


const PROFILES = {
    PICU: { code: 'PICU', extra_row_fields: [], bed_label: 'Bed', consultant_pair: true, consultant_by_label: 'Consultant covering', bar_class: 'channel-bar-picu', plan_label: 'Plan Of Care', narrative_label: 'New events' },
    NICU: { code: 'NICU', extra_row_fields: ['dob'], bed_label: 'Bed', consultant_pair: true, consultant_by_label: 'Consultant covering', bar_class: 'channel-bar-nicu', plan_label: 'Plan Of Care', narrative_label: 'To be followed' },
    WARD: { code: 'WARD', extra_row_fields: ['age', 'ward_unit'], bed_label: 'Room', consultant_pair: false, consultant_by_label: 'Consultant Oncall', bar_class: 'channel-bar-ward', plan_label: 'Management', narrative_label: 'To be followed' },
};

describe('Endorsement print sheet — handover list markers', () => {
    beforeEach(() => {
        window.print = vi.fn();
    });

    it('renders the rich-text list markup into the printed cells', () => {
        const wrapper = mount(Print, {
            props: { unit: { code: 'PICU', name: 'PICU', profile: PROFILES.PICU }, date: '2026-07-19', rows: [rowWithLists] },
        });

        // The ordered list must survive into the DOM as a real <ol>/<li> tree — if the
        // sanitizer or the template flattened it, no amount of CSS could restore numbering.
        expect(wrapper.find('td ol').exists()).toBe(true);
        expect(wrapper.findAll('td ol li')).toHaveLength(3);
        expect(wrapper.find('td ul').exists()).toBe(true);
        expect(wrapper.findAll('td ul li')).toHaveLength(2);
    });

    it('restores decimal and disc markers through :deep(), which is what reaches v-html nodes', () => {
        const normalized = styleBlock.replace(/\s+/g, ' ');

        expect(normalized).toMatch(/:deep\(\s*ol\s*\)[^{]*\{[^}]*list-style-type:\s*decimal/);
        expect(normalized).toMatch(/:deep\(\s*ul\s*\)[^{]*\{[^}]*list-style-type:\s*disc/);
    });

    it('gives the markers room, so `outside` markers are not clipped off the page edge', () => {
        const normalized = styleBlock.replace(/\s+/g, ' ');

        // Preflight zeroes list padding; `list-style: outside` paints the marker in that
        // padding, so without restoring it the numbers render outside the cell and clip.
        expect(normalized).toMatch(/:deep\([^)]*[uo]l[^)]*\)[\s\S]{0,160}padding-left:/);
    });
});


describe('Endorsement print sheet — per-unit column schemas (spec §11)', () => {
    const mountFor = (code, row = {}) => mount(Print, {
        props: {
            unit: { code, name: code, profile: PROFILES[code] },
            date: '2026-07-19',
            rows: [{ id: 1, bed: '3', mrn: 'M-1', patient_name: 'Child', dob: '2026-07-01 03:20', age: '4 years', ward_unit: 'Hematology', disease: 'x', details: 'y', plan: 'z', nevent: 'n', ...row }],
            signoff: {},
        },
    });

    const headers = (w) => w.findAll('thead th').map((th) => th.text());

    it('PICU: Bed|MRN|Name + four rich columns with New events', () => {
        expect(headers(mountFor('PICU'))).toEqual([
            'Bed', 'MRN', 'Name', 'Diagnosis List', 'Clinical Condition', 'Plan Of Care', 'New events',
        ]);
    });

    it('NICU: DOB after Name, To be followed narrative', () => {
        expect(headers(mountFor('NICU'))).toEqual([
            'Bed', 'MRN', 'Name', 'DOB', 'Diagnosis List', 'Clinical Condition', 'Plan Of Care', 'To be followed',
        ]);
    });

    it('WARD: Room|Unit|MRN|Name|Age, Management, no receiving consultant in the footer', () => {
        const w = mountFor('WARD');

        expect(headers(w)).toEqual([
            'Room', 'Unit', 'MRN', 'Name', 'Age', 'Diagnosis List', 'Clinical Condition', 'Management', 'To be followed',
        ]);

        const footer = w.get('[data-testid="print-signoff"]').text();
        expect(footer).toContain('Consultant Oncall');
        expect(footer).not.toContain('Consultant Receiving');
    });

    it('the header consultant line uses the per-unit label', () => {
        expect(mountFor('WARD').get('[data-testid="print-signoff-head"]').text()).toContain('Consultant Oncall: Not Selected');
        expect(mountFor('PICU').get('[data-testid="print-signoff-head"]').text()).toContain('Consultant covering: Not Selected');
    });
});

/*
 * P0b (design §6.2, "Ceiling 2") — bounded custom fields on the printed A4 sheet. Same split
 * as Sheet.vue: a SECOND, separate column set driven by `unit.profile.field_definitions`,
 * rendered after the named identity columns. Print is a fixed page — an unbounded number of
 * definitions could overflow it — so print caps what it renders and says so.
 */
describe('Endorsement print sheet — custom fields (P0b)', () => {
    beforeEach(() => {
        window.print = vi.fn();
    });

    const profileWithFields = (definitions) => ({
        ...PROFILES.PICU,
        field_definitions: definitions,
    });

    const mountWith = (definitions, rows) => mount(Print, {
        props: {
            unit: { code: 'PICU', name: 'PICU', profile: profileWithFields(definitions) },
            date: '2026-07-19',
            rows,
            signoff: {},
        },
    });

    const twoDefs = [
        { key: 'weight_kg', label: 'Weight (kg)', type: 'text', options: null, required: false },
        { key: 'allergy_status', label: 'Allergy status', type: 'select', options: ['none', 'known'], required: true },
    ];

    it('adds one printed column per definition, after the identity columns and before the rich-text columns', () => {
        const w = mountWith(twoDefs, [
            { id: 1, bed: '3', mrn: 'M-1', patient_name: 'Child', disease: 'x', details: 'y', plan: 'z', nevent: 'n',
              extra_fields: { weight_kg: '3.2', allergy_status: 'known' } },
        ]);

        const headers = w.findAll('thead th').map((th) => th.text());
        expect(headers).toEqual([
            'Bed', 'MRN', 'Name', 'Weight (kg)', 'Allergy status',
            'Diagnosis List', 'Clinical Condition', 'Plan Of Care', 'New events',
        ]);
    });

    it('renders the custom field value for each row', () => {
        const w = mountWith(twoDefs, [
            { id: 1, bed: '3', mrn: 'M-1', patient_name: 'Child', disease: 'x', details: 'y', plan: 'z', nevent: 'n',
              extra_fields: { weight_kg: '3.2', allergy_status: 'known' } },
        ]);

        const rowText = w.get('[data-testid="print-row"]').text();
        expect(rowText).toContain('3.2');
        expect(rowText).toContain('known');
    });

    it('renders an empty cell rather than erroring when a row has no extra_fields at all', () => {
        const w = mountWith(twoDefs, [
            { id: 1, bed: '3', mrn: 'M-1', patient_name: 'Child', disease: 'x', details: 'y', plan: 'z', nevent: 'n' },
        ]);

        expect(w.get('[data-testid="print-row"]').exists()).toBe(true);
    });

    it('never renders custom field values through v-html — only the four sanitized rich-text columns may use it', () => {
        const source = readFileSync(
            resolve(__dirname, '../../resources/js/Pages/Endorsement/Print.vue'),
            'utf-8',
        );

        // Exactly the one known-safe v-html usage (the rich-text columns), never a second one
        // for extra_fields — those values are plain text and are never sanitised server-side.
        const template = source.match(/<template>([\s\S]*)<\/template>/)[1];
        const vHtmlUses = template.match(/\bv-html\s*=/g) ?? [];
        expect(vHtmlUses).toHaveLength(1);
    });

    it('a custom field value containing markup prints as inert text, never parsed as an element', () => {
        const w = mountWith(twoDefs, [
            { id: 1, bed: '3', mrn: 'M-1', patient_name: 'Child', disease: 'x', details: 'y', plan: 'z', nevent: 'n',
              extra_fields: { weight_kg: '<b>3.2</b>', allergy_status: 'known' } },
        ]);

        const row = w.get('[data-testid="print-row"]');
        expect(row.find('b').exists()).toBe(false);
        expect(row.text()).toContain('<b>3.2</b>');
    });

    // The sentinel: a row whose extra_fields failed to decrypt must still print, with a visible
    // warning — dropping it silently would hand out a clean-looking but incomplete legal sheet.
    it('renders a visible warning on the printed sheet for the __unreadable sentinel', () => {
        const w = mountWith(twoDefs, [
            { id: 1, bed: '3', mrn: 'M-1', patient_name: 'Child', disease: 'x', details: 'y', plan: 'z', nevent: 'n',
              extra_fields: { __unreadable: '[unreadable — encrypted with a different key]' } },
        ]);

        expect(w.text()).toContain('unreadable');
    });

    it('caps the printed custom columns and says so when a unit has many definitions', () => {
        const manyDefs = Array.from({ length: 8 }, (_, i) => ({
            key: `field_${i}`, label: `Field ${i}`, type: 'text', options: null, required: false,
        }));

        const w = mountWith(manyDefs, [
            { id: 1, bed: '3', mrn: 'M-1', patient_name: 'Child', disease: 'x', details: 'y', plan: 'z', nevent: 'n', extra_fields: {} },
        ]);

        const headers = w.findAll('thead th').map((th) => th.text());
        const printedCustomHeaders = headers.filter((h) => h.startsWith('Field '));

        // Fewer columns than definitions were supplied — it capped.
        expect(printedCustomHeaders.length).toBeGreaterThan(0);
        expect(printedCustomHeaders.length).toBeLessThan(manyDefs.length);

        // And it SAYS so, visibly, on the printed page (not print:hidden).
        const note = w.get('[data-testid="print-custom-fields-note"]');
        expect(note.text()).toMatch(/more/i);
        expect(note.classes()).not.toContain('print:hidden');
    });

    it('does not show the cap note when every definition fits', () => {
        const w = mountWith(twoDefs, [
            { id: 1, bed: '3', mrn: 'M-1', patient_name: 'Child', disease: 'x', details: 'y', plan: 'z', nevent: 'n', extra_fields: {} },
        ]);

        expect(w.find('[data-testid="print-custom-fields-note"]').exists()).toBe(false);
    });
});
