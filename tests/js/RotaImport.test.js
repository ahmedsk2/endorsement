import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * Admin → Rota import as a SCREEN (P1d-2 Task 12). MR-06's importer is proved server-side
 * (`RotaImportTest`, 25 cases) and its routes are proved at the HTTP layer
 * (`RotaImportScreenTest`). What is proved here is the half neither of those can see, and it is
 * the half that keeps the operator's year safe:
 *
 *  - **No click writes.** The preview button goes to the preview route, which writes nothing;
 *    `/admin/rota/import/commit` is reachable from exactly one control, and only once the SERVER
 *    has answered for the file currently chosen.
 *  - **The destructive case is legible.** A cell the file would REPLACE lists the spans it would
 *    discard, dated, unit by unit — never a count. A count would defeat the preview while looking
 *    like it worked, which is the defect probe 3 below plants.
 *  - **A stale file is a refusal the operator can act on**, never a silent retry and never a
 *    stale analysis left on screen looking committable.
 *  - **The file's own cells are escaped.** An imported CSV is untrusted input that reaches an
 *    administrator's page; there is no `v-html` on this screen and this asserts the consequence
 *    rather than the absence.
 *
 * The mock mirrors `MasterRotaFill.test.js`'s: a hoisted `flash` fixed before mount (the
 * component captures `usePage()` once, so replacing it afterwards would be invisible), and every
 * visit settled with `onFinish` — Inertia always calls it, and it is what re-enables the
 * controls. Vue Test Utils SILENTLY SKIPS a click on a disabled element, so a test that forgot
 * `onFinish` would be measuring the in-flight disabled state rather than the behaviour it names.
 */
const state = vi.hoisted(() => ({ flash: {} }));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), patch: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({
        props: {
            auth: { can: ['rota.manage'], user: { id: 1, member_name: 'admin', full_name: 'Admin', position: 0 } },
            nav: { units: [] },
            flash: state.flash,
        },
        url: '/admin/rota/import',
    }),
}));

import { router } from '@inertiajs/vue3';
import RotaImport from '../../resources/js/Pages/Admin/RotaImport.vue';

const PREVIEW_URL = '/admin/rota/import/preview';
const COMMIT_URL = '/admin/rota/import/commit';
const DIGEST = 'f'.repeat(64);
const STATE_DIGEST = 'a'.repeat(64);

const span = (code, from, to) => ({ unit_id: 10, unit_code: code, starts_on: from, ends_on: to });

const assignmentsAnalysis = (overrides = {}) => ({
    kind: 'assignments',
    file_errors: [],
    digest: DIGEST,
    state_digest: STATE_DIGEST,
    summary: { create: 1, replace: 1, unchanged: 1, skipped: 1, error: 0 },
    outcomes: [
        {
            lines: [2], short_name: 'import.one', full_name: 'Nadia Import',
            academic_year: '2026-2027', period_position: '1', period_label: 'Block 1',
            person_id: 1, period_id: 11,
            spans: [span('PICU', '2026-07-01', '2026-07-28')], current: [],
            errors: [], reason: null, outcome: 'create',
        },
        {
            // TWO lines, ONE outcome — finding 12's whole point, and the operator still has to be
            // able to find both rows in their own file.
            lines: [3, 4], short_name: 'import.two', full_name: 'Yusuf Import',
            academic_year: '2026-2027', period_position: '2', period_label: 'Block 2',
            person_id: 2, period_id: 12,
            spans: [span('WARD', '2026-07-29', '2026-08-25')],
            current: [span('PICU', '2026-07-29', '2026-08-11'), span('NICU', '2026-08-12', '2026-08-25')],
            errors: [], reason: null, outcome: 'replace',
        },
        {
            lines: [5], short_name: 'import.three', full_name: 'Sara Import',
            academic_year: '2026-2027', period_position: '1', period_label: 'Block 1',
            person_id: 3, period_id: 11,
            spans: [span('PICU', '2026-07-01', '2026-07-28')],
            current: [span('PICU', '2026-07-01', '2026-07-28')],
            errors: [], reason: null, outcome: 'unchanged',
        },
        {
            lines: [6], short_name: 'nobody', full_name: 'Nobody At All',
            academic_year: '2026-2027', period_position: '1', period_label: 'Block 1',
            person_id: null, period_id: 11,
            spans: [], current: [],
            errors: [], reason: 'No person on this roster has the short name "nobody".',
            outcome: 'skip_unknown_person',
        },
    ],
    ...overrides,
});

const vacationsAnalysis = (overrides = {}) => ({
    kind: 'vacations',
    file_errors: [],
    digest: DIGEST,
    state_digest: STATE_DIGEST,
    summary: { create: 1, replace: 0, unchanged: 0, skipped: 0, error: 0 },
    outcomes: [
        {
            lines: [2], short_name: 'import.one', full_name: 'Nadia Import', person_id: 1,
            granularity: 'week', starts_on: '2026-07-15', ends_on: '2026-07-16',
            snapped_starts_on: '2026-07-12', snapped_ends_on: '2026-07-18', snapped: true,
            errors: [], reason: null, outcome: 'create',
        },
    ],
    ...overrides,
});

const mountScreen = (flash = {}) => {
    state.flash = flash;

    return mount(RotaImport, {
        props: { academic_years: ['2026-2027'], max_kilobytes: 4096 },
        global: {
            stubs: {
                AppLayout: { name: 'AppLayout', props: ['title'], template: '<div><slot /></div>' },
            },
        },
    });
};

/** jsdom gives the input an empty FileList; a real choice has to be defined onto the element. */
const chooseFile = async (w, name = 'rota.csv') => {
    const input = w.find('[data-testid="import-file"]');

    Object.defineProperty(input.element, 'files', {
        value: [new File(['academic_year,period_position\n'], name, { type: 'text/csv' })],
        configurable: true,
    });

    await input.trigger('change');
};

/** Play back one visit's own callbacks, in Inertia's order, ending with the `onFinish` it always calls. */
const settle = (call, hook, argument) => {
    if (hook) call[2][hook]?.(argument);
    call[2].onFinish?.();
};

const lastCall = (url) => router.post.mock.calls.filter((c) => c[0] === url).at(-1);

/** Choose a file and let the server answer the preview, which is what makes anything committable. */
const previewed = async (w) => {
    await chooseFile(w);
    await w.find('[data-testid="import-preview"]').trigger('click');
    settle(lastCall(PREVIEW_URL), 'onSuccess');
    await w.vm.$nextTick();
};

describe('Admin/RotaImport — preview, then import (Task 12)', () => {
    beforeEach(() => {
        router.post.mockClear();
        state.flash = {};
    });

    it('renders no analysis until the server has answered for the file currently chosen', async () => {
        // The flash is ALREADY carrying an analysis, and the screen must still show nothing: the
        // operator has chosen no file, so nothing on screen has been previewed.
        const w = mountScreen({ rota_import_preview: assignmentsAnalysis() });

        expect(w.find('[data-testid="import-preview-section"]').exists()).toBe(false);
        expect(w.find('[data-testid="import-commit"]').exists()).toBe(false);

        await previewed(w);

        expect(w.find('[data-testid="import-preview-section"]').exists()).toBe(true);
        expect(w.find('[data-testid="import-commit"]').exists()).toBe(true);
    });

    it('previews rather than imports, and the commit route is unreachable from the preview button', async () => {
        const w = mountScreen({ rota_import_preview: assignmentsAnalysis() });

        await previewed(w);

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post.mock.calls[0][0]).toBe(PREVIEW_URL);
        expect(router.post.mock.calls.some((c) => c[0] === COMMIT_URL)).toBe(false);

        // The kind travels in the body — never sniffed from the file's headers.
        expect(lastCall(PREVIEW_URL)[1].get('kind')).toBe('assignments');
        expect(lastCall(PREVIEW_URL)[1].get('file')).toBeInstanceOf(File);
    });

    it('lists every cell with its outcome, its line numbers and the reason the server gave', async () => {
        const w = mountScreen({ rota_import_preview: assignmentsAnalysis() });

        await previewed(w);

        const rows = w.findAll('[data-testid^="import-row-"]');
        expect(rows).toHaveLength(4);

        // Two lines, ONE row — a cell is the unit of outcome, and both line numbers are on it.
        expect(rows[1].find('[data-testid="import-lines"]').text()).toBe('3, 4');
        expect(rows[1].find('[data-testid="import-outcome"]').text()).toBe('Will be replaced');

        expect(rows[0].find('[data-testid="import-outcome"]').text()).toBe('Will be added');
        expect(rows[2].find('[data-testid="import-outcome"]').text()).toBe('No change');

        // The server's own sentence, verbatim — a screen that reworded it would be a second
        // definition of why a cell was skipped, and the two would drift.
        expect(rows[3].find('[data-testid="import-reason"]').text())
            .toBe('No person on this roster has the short name "nobody".');
    });

    it('shows what a replaced cell would discard, span by span and dated, never a count', async () => {
        const w = mountScreen({ rota_import_preview: assignmentsAnalysis() });

        await previewed(w);

        const now = w.findAll('[data-testid^="import-row-"]')[1].find('[data-testid="import-current"]');

        // THE LEGIBILITY REQUIREMENT. Both spans, both units, both date pairs.
        expect(now.text()).toContain('PICU');
        expect(now.text()).toContain('2026-07-29');
        expect(now.text()).toContain('2026-08-11');
        expect(now.text()).toContain('NICU');
        expect(now.text()).toContain('2026-08-12');
        expect(now.findAll('li')).toHaveLength(2);

        // ...and what would replace them.
        const after = w.findAll('[data-testid^="import-row-"]')[1].find('[data-testid="import-proposed"]');
        expect(after.text()).toContain('WARD');
        expect(after.text()).toContain('2026-08-25');

        // The count is named too, as a warning — but beside the list, never instead of it.
        expect(w.find('[data-testid="import-replace-warning"]').exists()).toBe(true);
    });

    it('prints the server\'s five counts and computes none of them', async () => {
        const w = mountScreen({ rota_import_preview: assignmentsAnalysis() });

        await previewed(w);

        const summary = w.find('[data-testid="import-summary"]').text();

        expect(summary).toContain('1 to add');
        expect(summary).toContain('1 to replace');
        expect(summary).toContain('1 unchanged');
        expect(summary).toContain('1 skipped');
    });

    it('imports with the digest the preview returned — the pin, in one field', async () => {
        const w = mountScreen({ rota_import_preview: assignmentsAnalysis() });

        await previewed(w);
        await w.find('[data-testid="import-commit"]').trigger('click');

        const call = lastCall(COMMIT_URL);
        expect(call[0]).toBe(COMMIT_URL);
        expect(call[1].get('digest')).toBe(DIGEST);
        expect(call[1].get('kind')).toBe('assignments');
    });

    /**
     * TWO pins, and the second is the one the byte digest cannot supply. A file that did not change
     * still re-derives against whatever the rota says at commit time, so `state_digest` is what
     * stops a cell shown as "No change" committing as a replacement of somebody's split.
     */
    it('imports with the STATE digest as well, so an unchanged file cannot ride a changed rota', async () => {
        const w = mountScreen({ rota_import_preview: assignmentsAnalysis() });

        await previewed(w);
        await w.find('[data-testid="import-commit"]').trigger('click');

        expect(lastCall(COMMIT_URL)[1].get('state_digest')).toBe(STATE_DIGEST);
    });

    it('treats a rota that moved like a file that moved — notice, drop, re-PREVIEW', async () => {
        const w = mountScreen({ rota_import_preview: assignmentsAnalysis() });

        await previewed(w);
        await w.find('[data-testid="import-commit"]').trigger('click');

        settle(lastCall(COMMIT_URL), 'onError', {
            state_digest: 'The rota changed since you previewed this file, so nothing has been imported. The file itself is unchanged — preview it again to see what it would do now.',
        });
        await w.vm.$nextTick();

        // The operator is told the FILE is fine, which is the whole reason this is a second message
        // rather than the file one reused.
        expect(w.find('[data-testid="import-stale-notice"]').text()).toContain('The rota changed');
        expect(w.find('[data-testid="import-stale-notice"]').text()).toContain('file itself is unchanged');

        expect(w.find('[data-testid="import-preview-section"]').exists()).toBe(false);
        expect(router.post.mock.calls.at(-1)[0]).toBe(PREVIEW_URL);
        expect(router.post.mock.calls.filter((c) => c[0] === COMMIT_URL)).toHaveLength(1);
    });

    it('drops a stale analysis, says so in the server\'s words, and re-PREVIEWS rather than retrying', async () => {
        const w = mountScreen({ rota_import_preview: assignmentsAnalysis() });

        await previewed(w);
        await w.find('[data-testid="import-commit"]').trigger('click');

        settle(lastCall(COMMIT_URL), 'onError', {
            file: 'This file has changed since you previewed it, so nothing has been imported. Preview it again to see what it would do now.',
        });
        await w.vm.$nextTick();

        expect(w.find('[data-testid="import-stale-notice"]').text()).toContain('has changed since you previewed it');

        // Nothing on screen looks committable any more...
        expect(w.find('[data-testid="import-preview-section"]').exists()).toBe(false);

        // ...and the retry is a PREVIEW, which writes nothing. Never a second commit: the analysis
        // they approved describes bytes that no longer exist.
        expect(router.post.mock.calls.at(-1)[0]).toBe(PREVIEW_URL);
        expect(router.post.mock.calls.filter((c) => c[0] === COMMIT_URL)).toHaveLength(1);
    });

    it('discards the analysis when the kind changes, because that is a different question', async () => {
        const w = mountScreen({ rota_import_preview: assignmentsAnalysis() });

        await previewed(w);
        expect(w.find('[data-testid="import-preview-section"]').exists()).toBe(true);

        await w.find('[data-testid="import-kind-vacations"]').trigger('change');

        expect(w.find('[data-testid="import-preview-section"]').exists()).toBe(false);
    });

    it('never draws an analysis the server computed for the other file', async () => {
        // The client key says "previewed"; the SERVER's echo says this analysis is a leave file.
        // A client-side key alone cannot close this case, which is why the echo is checked too.
        const w = mountScreen({ rota_import_preview: vacationsAnalysis() });

        await previewed(w);

        expect(w.find('[data-testid="import-preview-section"]').exists()).toBe(false);
    });

    it('refuses to offer the import at all while the file has a file-level problem', async () => {
        const w = mountScreen({
            rota_import_preview: assignmentsAnalysis({
                file_errors: ['This file is missing the column(s) unit_code.'],
                outcomes: [],
                summary: { create: 0, replace: 0, unchanged: 0, skipped: 0, error: 0 },
            }),
        });

        await previewed(w);

        expect(w.find('[data-testid="import-file-errors"]').text()).toContain('missing the column(s) unit_code');
        expect(w.find('[data-testid="import-commit"]').attributes('disabled')).toBeDefined();
    });

    it('shows the leave bounds the server would actually write, and says when it widened them', async () => {
        const w = mountScreen({ rota_import_preview: vacationsAnalysis() });

        // The screen has to be on the leave kind for its own analysis to be drawn — the echo test
        // above is the other half of this one.
        await w.find('[data-testid="import-kind-vacations"]').trigger('change');
        await previewed(w);

        const row = w.find('[data-testid="import-row-0"]');

        expect(row.find('[data-testid="import-proposed"]').text()).toContain('2026-07-12');
        expect(row.find('[data-testid="import-proposed"]').text()).toContain('2026-07-18');
        expect(row.find('[data-testid="import-snapped"]').exists()).toBe(true);
    });

    it('renders a cell from the file as text, never as markup', async () => {
        const nasty = assignmentsAnalysis();
        nasty.outcomes[0].short_name = '<script>alert(1)</script>';
        nasty.outcomes[0].full_name = '<img src=x onerror=alert(1)>';
        nasty.outcomes[3].reason = 'No person on this roster has the short name "<b>bold</b>".';

        const w = mountScreen({ rota_import_preview: nasty });

        await previewed(w);

        const html = w.html();

        // An imported file is untrusted input reaching an administrator's page. Escaped on render,
        // because the cells themselves are never sanitised on write — that is deliberate for the
        // rich-text fields' neighbours (CLAUDE.md) and it makes escaping here non-optional.
        expect(html).not.toContain('<script>alert(1)</script>');
        expect(html).not.toContain('<img src=x');
        expect(html).not.toContain('<b>bold</b>');
        expect(html).toContain('&lt;script&gt;');
        expect(w.find('[data-testid="import-row-0"]').text()).toContain('<script>alert(1)</script>');
    });
});
