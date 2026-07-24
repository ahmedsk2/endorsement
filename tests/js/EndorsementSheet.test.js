import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { describe, it, expect, vi, beforeEach } from 'vitest';

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
