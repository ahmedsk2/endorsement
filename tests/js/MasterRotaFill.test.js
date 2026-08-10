import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

/**
 * Admin → Master Rota, MR-06's bulk moves as a SCREEN (P1d-2 Task 9): preview, then confirm.
 *
 * The engine is proved server-side (`RotaFillPlanTest`, `RotaFillCommitTest`). What is proved here
 * is the half a PHP test cannot see, and it is the half that keeps the operator safe:
 *
 *  - **No action writes on click.** Every one of the four goes to the PREVIEW route. `/admin/rota/
 *    fill` is reachable from exactly one control, and only after a plan has been rendered.
 *  - **The destructive case is legible.** A cell carrying deliberate split work shows the spans
 *    that would be discarded, dated, unit by unit — never a count. That is the entire reason the
 *    confirm step exists, and a count would defeat it while looking like it worked.
 *  - **The confirmed set is explicit in the request body**, per cell, including when the master
 *    "overwrite every split" tick set them — that tick sets the individual boxes rather than
 *    replacing them with a flag, so what is sent is always what is on screen.
 *  - **A stale digest is a refusal the operator can act on.** The rota moved under them; the plan
 *    they were reading no longer describes it; it is dropped and re-previewed, never retried.
 *
 * The mock mirrors `MasterRotaSplit.test.js`'s, plus a MUTABLE `flash` — the plan arrives on
 * `page.props.flash.rota_fill_preview` (`HandleInertiaRequests::share()`), which is what makes it
 * one-shot: a navigation clears it, so a plan can never be confirmed against a grid the operator
 * has since changed themselves.
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
        url: '/admin/rota',
    }),
}));

import { router } from '@inertiajs/vue3';
import MasterRota from '../../resources/js/Pages/Admin/MasterRota.vue';

const label = (date) => ({ date, hijri: '', weekend: false, holiday: null, day_type: 'WD' });

const periodOne = {
    id: 101, position: 1, label: 'Block 1', kind: 'week_block',
    starts_on: '2026-07-01', ends_on: '2026-07-28',
    starts_label: label('2026-07-01'), ends_label: label('2026-07-28'), weeks: [],
};

const periodTwo = {
    id: 102, position: 2, label: 'Block 2', kind: 'week_block',
    starts_on: '2026-07-29', ends_on: '2026-09-01',
    starts_label: label('2026-07-29'), ends_label: label('2026-09-01'), weeks: [],
};

const units = [
    { id: 10, code: 'PICU', name: 'PICU', bar_class: 'channel-bar-picu' },
    { id: 11, code: 'NICU', name: 'NICU', bar_class: 'channel-bar-nicu' },
];

const levels = [{ id: 1, code: 'R1', name: 'Resident 1' }];

const span = (unitId, from, to) => ({
    id: `${unitId}-${from}`, unit_id: unitId, unit_code: null,
    starts_on: from, ends_on: to, starts_label: label(from), ends_label: label(to),
});

const cell = (spans = [], uncovered = 0) => ({ spans, uncovered_days: uncovered, level_id: 1, vacations: [] });

const row = (id, fullName, cells, stale = false) => ({
    person: {
        id, full_name: fullName, short_name: null, position: 4,
        external: false, active: !stale, retired: false, has_account: true, joined_at: null,
    },
    group_level_id: 1,
    stale,
    cells,
});

const grid = () => ({
    periods: [periodOne, periodTwo],
    levels,
    units,
    rows: [
        row(5, 'Source Person', { 101: cell([span(10, '2026-07-01', '2026-07-28')]), 102: cell() }),
        row(6, 'Empty Peer', { 101: cell([], 28), 102: cell() }),
        row(7, 'Replaced Peer', { 101: cell([span(11, '2026-07-01', '2026-07-28')]), 102: cell() }),
        row(8, 'Same Peer', { 101: cell([span(10, '2026-07-01', '2026-07-28')]), 102: cell() }),
        row(9, 'Split Peer', { 101: cell([span(10, '2026-07-01', '2026-07-14'), span(11, '2026-07-21', '2026-07-28')], 6), 102: cell() }),
        row(10, 'Departed Peer', { 101: cell(), 102: cell() }, true),
        row(11, 'Other Split Peer', { 101: cell([span(11, '2026-07-05', '2026-07-28')], 4), 102: cell() }),
    ],
});

const whole = (unitId) => [{ unit_id: unitId, starts_on: '2026-07-01', ends_on: '2026-07-28' }];

const SPLIT_TARGET_REASON = 'This cell already carries a split. Filling over it would discard dates '
    + 'somebody chose deliberately, so it is skipped unless you confirm this cell.';
const SPLIT_SOURCE_REASON = 'Periods differ in length, so a split cannot be copied onto another '
    + 'period without inventing dates or truncating a unit. Only a whole-period assignment fills '
    + 'across — split the target cell by hand.';
const STALE_REASON = 'This person is no longer on the active roster. A fill never assigns to '
    + 'somebody who has left — clear their cells in the editor instead.';

const target = (personId, periodId, outcome, current, proposed, reason = null) => ({
    person_id: personId, period_id: periodId, key: `${personId}:${periodId}`,
    outcome, reason, current, proposed,
});

/** A fill-down-column plan carrying one of every outcome the operator can be shown. */
const columnPlan = () => ({
    op: 'fill_down_column',
    source: { person_id: 5, period_id: 101, target_period_id: null },
    targets: [
        target(6, 101, 'assign', [], whole(10)),
        target(7, 101, 'replace', whole(11), whole(10)),
        target(8, 101, 'unchanged', whole(10), whole(10)),
        target(9, 101, 'skip_split_target', [
            { unit_id: 10, starts_on: '2026-07-01', ends_on: '2026-07-14' },
            { unit_id: 11, starts_on: '2026-07-21', ends_on: '2026-07-28' },
        ], whole(10), SPLIT_TARGET_REASON),
        target(10, 101, 'skip_stale_person', [], [], STALE_REASON),
        target(11, 101, 'skip_split_target', [
            { unit_id: 11, starts_on: '2026-07-05', ends_on: '2026-07-28' },
        ], whole(10), SPLIT_TARGET_REASON),
    ],
    summary: { assign: 1, replace: 1, unchanged: 1, skipped: 3 },
    errors: [],
    digest: 'a'.repeat(64),
});

const acrossPlan = () => ({
    op: 'fill_across',
    source: { person_id: 5, period_id: 101, target_period_id: null },
    targets: [target(5, 102, 'skip_split_source', [], [], SPLIT_SOURCE_REASON)],
    summary: { assign: 0, replace: 0, unchanged: 0, skipped: 1 },
    errors: [],
    digest: 'b'.repeat(64),
});

const mountRota = (flash = {}) => {
    state.flash = flash;

    return mount(MasterRota, {
        props: { academic_years: ['2026-2027'], year: '2026-2027', grid: grid(), summary: null },
    });
};

/** Open the fill panel from a cell. Nothing has been posted at this point. */
const openFill = async (w, personId = 5, periodId = 101) => {
    await w.get(`[data-testid="fill-open-${personId}-${periodId}"]`).trigger('click');
};

/**
 * Choose an action and let its preview "return". Inertia calls `onSuccess` once the flash prop
 * has landed, so the plan becoming visible only after the server answered is mirrored here rather
 * than mocked away — it is the property that stops a previous plan being rendered under a newly
 * chosen action.
 */
const runAction = async (w, op) => {
    await w.get(`[data-testid="fill-action-${op}"]`).trigger('click');

    settle(router.post.mock.calls.at(-1), 'onSuccess');

    await w.vm.$nextTick();
};

/**
 * Play back one of an Inertia visit's own callbacks, then `onFinish` — which Inertia always calls,
 * and which is what re-enables the controls. Without it the panel stays correctly disabled
 * mid-flight and a second click never reaches its handler, so a test that skipped it would be
 * measuring the disabled state rather than the behaviour it names.
 */
const settle = (call, hook) => {
    if (hook) call[2][hook]?.();
    call[2].onFinish?.();
};

const lastPost = (url) => router.post.mock.calls.filter((c) => c[0] === url).at(-1);

describe('Admin/MasterRota — the fill preview (Task 9)', () => {
    beforeEach(() => {
        router.post.mockClear();
        router.patch.mockClear();
        router.delete.mockClear();
        state.flash = {};
    });

    it('offers all four actions from a cell, and every one of them previews rather than writes', async () => {
        const w = mountRota();

        await openFill(w);

        const ops = ['fill_down_level', 'fill_down_column', 'fill_across', 'copy_period'];

        ops.forEach((op) => {
            expect(w.find(`[data-testid="fill-action-${op}"]`).exists()).toBe(true);
        });

        // copy_period needs somewhere to copy ONTO, and the only offer is another period of this
        // same year — the server refuses anything else (`RotaFill`'s "not part of this academic
        // year"), so the control must not be able to ask for it.
        await w.get('[data-testid="fill-copy-target"]').setValue('102');

        for (const op of ops) {
            await w.get(`[data-testid="fill-action-${op}"]`).trigger('click');
            // The panel disables itself for the duration of a visit, so the tick has to be let
            // through before the next click or Vue Test Utils silently skips a disabled button —
            // which is how this loop first measured two posts for four clicks.
            settle(router.post.mock.calls.at(-1));
            await w.vm.$nextTick();
        }

        expect(router.post).toHaveBeenCalledTimes(4);
        router.post.mock.calls.forEach((call) => {
            expect(call[0]).toBe('/admin/rota/fill/preview');
        });

        // The write route is not reachable from an action button at all.
        expect(router.post.mock.calls.some((c) => c[0] === '/admin/rota/fill')).toBe(false);
        expect(router.patch).not.toHaveBeenCalled();
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('lists every target with its outcome and, for a skip, the reason the server gave', async () => {
        const w = mountRota({ rota_fill_preview: columnPlan() });

        await openFill(w);
        await runAction(w, 'fill_down_column');

        columnPlan().targets.forEach((t) => {
            expect(w.find(`[data-testid="fill-target-${t.key}"]`).exists()).toBe(true);
        });

        expect(w.get('[data-testid="fill-outcome-6:101"]').text()).toContain('assigned');
        expect(w.get('[data-testid="fill-outcome-7:101"]').text()).toContain('replaced');
        expect(w.get('[data-testid="fill-outcome-8:101"]').text()).toContain('No change');

        // The reason is the SERVER's own wording, rendered verbatim — a screen that reworded it
        // would be a second definition of why a cell was skipped.
        expect(w.get('[data-testid="fill-reason-9:101"]').text()).toContain('discard dates somebody chose deliberately');
        expect(w.get('[data-testid="fill-reason-10:101"]').text()).toContain('no longer on the active roster');

        // The person's name comes from the grid the operator is already looking at; the plan names
        // nobody (ids and counts only).
        expect(w.get('[data-testid="fill-target-9:101"]').text()).toContain('Split Peer');

        // The counts are the server's, in a live region.
        const summary = w.get('[data-testid="fill-summary"]');
        expect(summary.attributes('aria-live')).toBe('polite');
        expect(summary.text()).toContain('1 to assign');
        expect(summary.text()).toContain('1 to replace');
        expect(summary.text()).toContain('3 skipped');
    });

    it('shows the split work a fill would destroy span by span, dated, rather than as a count', async () => {
        const w = mountRota({ rota_fill_preview: columnPlan() });

        await openFill(w);
        await runAction(w, 'fill_down_column');

        const current = w.get('[data-testid="fill-current-9:101"]').text();

        // Both spans, both units, both dates. This is the whole reason the confirm step exists:
        // an operator about to overwrite deliberate work must see the work.
        expect(current).toContain('PICU');
        expect(current).toContain('2026-07-01');
        expect(current).toContain('2026-07-14');
        expect(current).toContain('NICU');
        expect(current).toContain('2026-07-21');
        expect(current).toContain('2026-07-28');

        // And what would stand in its place, from the same plan — never recomputed here.
        const proposed = w.get('[data-testid="fill-proposed-9:101"]').text();
        expect(proposed).toContain('PICU');
        expect(proposed).toContain('2026-07-01');
        expect(proposed).toContain('2026-07-28');

        // A skip that cannot be overruled proposes nothing, and says so in words.
        expect(w.get('[data-testid="fill-proposed-10:101"]').text()).toContain('Nothing');
    });

    it('renders a per-cell confirm box on a split target, unchecked by default', async () => {
        const w = mountRota({ rota_fill_preview: columnPlan() });

        await openFill(w);
        await runAction(w, 'fill_down_column');

        const box = w.get('[data-testid="fill-confirm-9:101"]');
        expect(box.element.checked).toBe(false);

        // Only the split targets are offered one — there is nothing to overrule anywhere else.
        expect(w.find('[data-testid="fill-confirm-11:101"]').exists()).toBe(true);
        expect(w.find('[data-testid="fill-confirm-6:101"]').exists()).toBe(false);
        expect(w.find('[data-testid="fill-confirm-7:101"]').exists()).toBe(false);
        expect(w.find('[data-testid="fill-confirm-8:101"]').exists()).toBe(false);
    });

    it('renders no confirm box for a split SOURCE, because there is nothing to confirm', async () => {
        const w = mountRota({ rota_fill_preview: acrossPlan() });

        await openFill(w);
        await runAction(w, 'fill_across');

        expect(w.get('[data-testid="fill-reason-5:102"]').text()).toContain('Only a whole-period assignment fills across');
        expect(w.find('[data-testid="fill-confirm-5:102"]').exists()).toBe(false);
        expect(w.find('[data-testid="fill-confirm-all"]').exists()).toBe(false);
    });

    it('has the master tick set the individual boxes, so the confirmed set is explicit in the body', async () => {
        const w = mountRota({ rota_fill_preview: columnPlan() });

        await openFill(w);
        await runAction(w, 'fill_down_column');

        await w.get('[data-testid="fill-confirm-all"]').setValue(true);

        // Set, not replaced: each box is individually on.
        expect(w.get('[data-testid="fill-confirm-9:101"]').element.checked).toBe(true);
        expect(w.get('[data-testid="fill-confirm-11:101"]').element.checked).toBe(true);

        await w.get('[data-testid="fill-submit"]').trigger('click');

        // Every confirmed cell is NAMED in the body. A master control that sent one flag instead
        // would look identical on screen and pass the two assertions above — Decision F's whole
        // point is that N destructive decisions travel as N keys, so the request the operator's
        // own preview was rendered from says which cells it means.
        expect(lastPost('/admin/rota/fill')[1].confirmations).toEqual({ '9:101': true, '11:101': true });

        settle(lastPost('/admin/rota/fill'));
        await w.vm.$nextTick();

        // And one of them can be taken back again without losing the rest, which a flag cannot do.
        await w.get('[data-testid="fill-confirm-9:101"]').setValue(false);
        expect(w.get('[data-testid="fill-confirm-11:101"]').element.checked).toBe(true);

        await w.get('[data-testid="fill-submit"]').trigger('click');

        expect(lastPost('/admin/rota/fill')[1].confirmations).toEqual({ '11:101': true });
    });

    it('posts the operation, the source cell, the confirmations and the digest — and nothing else', async () => {
        const w = mountRota({ rota_fill_preview: columnPlan() });

        await openFill(w);
        await runAction(w, 'fill_down_column');
        await w.get('[data-testid="fill-confirm-9:101"]').setValue(true);
        await w.get('[data-testid="fill-submit"]').trigger('click');

        const [url, body] = lastPost('/admin/rota/fill');

        expect(url).toBe('/admin/rota/fill');
        expect(Object.keys(body).sort()).toEqual(
            ['confirmations', 'digest', 'op', 'source_period_id', 'source_person_id'],
        );
        expect(body.op).toBe('fill_down_column');
        expect(body.source_person_id).toBe(5);
        expect(body.source_period_id).toBe(101);
        expect(body.confirmations).toEqual({ '9:101': true });
        expect(body.digest).toBe('a'.repeat(64));
    });

    it('carries target_period_id on a copy-period confirm, and no source person', async () => {
        const plan = { ...columnPlan(), op: 'copy_period', source: { person_id: null, period_id: 101, target_period_id: 102 } };
        const w = mountRota({ rota_fill_preview: plan });

        await openFill(w);
        await w.get('[data-testid="fill-copy-target"]').setValue('102');
        await runAction(w, 'copy_period');
        await w.get('[data-testid="fill-submit"]').trigger('click');

        const body = lastPost('/admin/rota/fill')[1];

        expect(Object.keys(body).sort()).toEqual(
            ['confirmations', 'digest', 'op', 'source_period_id', 'source_person_id', 'target_period_id'],
        );
        expect(body.source_person_id).toBeNull();
        expect(body.target_period_id).toBe(102);
    });

    it('drops the plan and re-previews when the digest is refused, and never retries the confirm', async () => {
        const w = mountRota({ rota_fill_preview: columnPlan() });

        await openFill(w);
        await runAction(w, 'fill_down_column');
        await w.get('[data-testid="fill-confirm-9:101"]').setValue(true);
        await w.get('[data-testid="fill-submit"]').trigger('click');

        const confirmCall = lastPost('/admin/rota/fill');
        expect(confirmCall).toBeTruthy();

        // What the server says when the rota moved under the operator: 422 on `digest`
        // (StaleFillPlanException -> ValidationException, Task 8).
        confirmCall[2].onError({
            digest: 'The rota changed since you previewed this fill, so it has not been applied. '
                + 'Preview it again to see what it would do now.',
        });
        await w.vm.$nextTick();

        // Said, in the server's own words, where the operator is looking.
        const notice = w.get('[data-testid="fill-stale-notice"]');
        expect(notice.text()).toContain('The rota changed since you previewed this fill');
        expect(notice.attributes('role')).toBe('alert');

        // The plan they were reading describes a rota that no longer exists, so it is gone —
        // not left on screen looking confirmable.
        expect(w.find('[data-testid="fill-submit"]').exists()).toBe(false);
        expect(w.find('[data-testid="fill-target-9:101"]').exists()).toBe(false);

        // Re-previewed, never retried: the last thing sent is a PREVIEW, and the confirm route was
        // posted exactly once in the whole exchange.
        expect(router.post.mock.calls.filter((c) => c[0] === '/admin/rota/fill')).toHaveLength(1);
        expect(router.post.mock.calls.at(-1)[0]).toBe('/admin/rota/fill/preview');

        // And the ticks are re-decided rather than carried over: they were answers about split
        // contents that may be exactly what changed.
        expect(lastPost('/admin/rota/fill/preview')[1].confirmations).toEqual({});
    });

    it('renders a server error from the preview instead of an empty plan', async () => {
        const plan = {
            op: 'fill_down_column',
            source: { person_id: 5, period_id: 101, target_period_id: null },
            targets: [],
            summary: { assign: 0, replace: 0, unchanged: 0, skipped: 0 },
            errors: ['That cell is empty — there is nothing to fill from. Assign the source cell first, then fill.'],
            digest: 'c'.repeat(64),
        };
        const w = mountRota({ rota_fill_preview: plan });

        await openFill(w);
        await runAction(w, 'fill_down_column');

        expect(w.get('[data-testid="fill-errors"]').text()).toContain('there is nothing to fill from');
        expect(w.find('[data-testid="fill-submit"]').exists()).toBe(false);
    });

    it('shows what the last fill actually did, from the server\'s own result', async () => {
        const w = mountRota({
            rota_fill_result: { ...columnPlan(), applied: 2 },
        });

        expect(w.get('[data-testid="fill-result"]').text()).toContain('2');
    });
});
