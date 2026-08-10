<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveStatus from '../../Components/SaveStatus.vue';
import AvailabilityPanel from '../../Components/AvailabilityPanel.vue';

/**
 * Admin -> Master Rota (Munawib MR-02/MR-03), cap:rota.manage.
 *
 * Task 8: the grid itself — rows by level (held at the academic year's start), columns by
 * period, per-cell save. `grid` is the shape App\Support\Rota\RotaGrid::forYear() builds; every
 * date on this screen (period bounds, span bounds, vacation bounds) arrives already
 * server-formatted and dual-dated (`starts_label`/`ends_label`) — this component performs NO
 * date arithmetic of its own (finding 7's ten needles, no allow-list).
 *
 * A cell with more than one span, OR a single span that does not cover the whole period (a
 * gap — owner decision 3), renders read-only: the plain unit `<select>` is not the control for
 * that state, because changing it would silently collapse deliberate split work into one
 * whole-period assignment. Every cell — empty, simple or already split — offers a "Split…"
 * affordance that opens Task 9's editor below. Existing vacations render read-only here too;
 * booking/cancelling one is Task 10.
 *
 * A row flagged `stale` (pre-merge finding 1) belongs to somebody who is no longer on the active
 * roster but still holds a span in this year — people are deactivated, never deleted, so a
 * resident who leaves mid-year keeps every span already planned for them. That row renders
 * READ-ONLY except for Clear: the server refuses to name an inactive person on set/split, so a
 * unit picker or a Split…/On leave… button there would only ever 422. Clear stays, because
 * emptying the cell is the one thing that has to remain possible — an assignment nobody can
 * remove blocks its academic year's periods from ever being deleted, and with them Decision D's
 * unlock of period_type/academic_year_start.
 *
 * Task 9's split editor NEVER computes the uncovered-day count itself from the (unsaved) date
 * inputs it holds — `splitCellState` below reads `uncovered_days` straight from `grid.rows`,
 * which is always the server's own last-known figure for that cell. Before any save this is
 * whatever was already persisted; after a successful save Inertia re-renders `grid` with the
 * server's fresh count, and the editor picks it up because it is a `computed`, not a snapshot
 * taken when the panel opened.
 */
const props = defineProps({
    academic_years: { type: Array, default: () => [] },
    year: { type: String, default: null },
    grid: { type: Object, default: null },
    /**
     * MR-07, computed by App\Support\Rota\AvailabilitySummary and rendered by the shared
     * Components/AvailabilityPanel.vue — the same computation and the same component the resident
     * read view at /rota uses (Task 5). A planner needs the coverage figures more than a reader
     * does: this is the screen where a "people with a gap" of five turns into five cells filled.
     */
    summary: { type: Object, default: null },
    /**
     * MR-06's export, finding 5. How many people who would APPEAR in the two files have no
     * `short_name` — the app-wide unique handle the files identify a person by, and the one the
     * importer matches on. Nullable on `people`, so a person without one exports a blank handle
     * and cannot be re-imported; the count is shown BESIDE the export buttons rather than
     * discovered when a third of the re-import comes back skipped.
     */
    people_without_a_short_name: { type: Number, default: 0 },
});

const page = usePage();

const selectedYear = ref(props.year ?? '');

/**
 * MR-06's two export URLs. PLAIN `<a href>`, never an Inertia `<Link>` and never `router.get`:
 * the response is a file stream carrying a Content-Disposition header, not an X-Inertia page
 * object, so Inertia's own router cannot handle it. Both are GET, so unlike People.vue's roster
 * export — a POST, which needed a hand-built form and a CSRF field — a link is the whole
 * mechanism.
 */
const exportHref = (file) => `/admin/rota/export/${file}?year=${encodeURIComponent(props.year ?? '')}`;

const changeYear = () => {
    router.get('/admin/rota', selectedYear.value ? { year: selectedYear.value } : {}, {
        preserveScroll: true,
    });
};

// finding 14 — a rota grid's column count varies by academic year and period system; it is
// computed, never a hardcoded colspan.
const desktopColumnCount = computed(() => 1 + (props.grid?.periods?.length ?? 0));

const unitsById = computed(() => {
    const map = {};
    (props.grid?.units ?? []).forEach((unit) => { map[unit.id] = unit; });
    return map;
});

// Decision G: rows group by the level held at the academic year's START. `grid.levels` already
// arrives in display order; rows whose group_level_id matches none of them (no level history at
// all) fall into a trailing "Unassigned" group rather than disappearing.
const rowGroups = computed(() => {
    if (!props.grid) return [];

    const groups = props.grid.levels.map((level) => ({
        level,
        rows: props.grid.rows.filter((row) => row.group_level_id === level.id),
    }));

    const knownIds = props.grid.levels.map((level) => level.id);
    const unassigned = props.grid.rows.filter((row) => !knownIds.includes(row.group_level_id));

    if (unassigned.length > 0) {
        groups.push({ level: { id: null, code: '—', name: 'Unassigned' }, rows: unassigned });
    }

    return groups.filter((group) => group.rows.length > 0);
});

// --- per-cell save status, keyed `personId:periodId` — Sheet.vue's G3 mechanism lifted
// verbatim, including preserveState (finding 13: without it Inertia remounts the page and wipes
// the indicator before it can be seen).
const cellStatus = ref({});
const timers = {};

const statusKey = (personId, periodId) => `${personId}:${periodId}`;

const setStatus = (personId, periodId, value) => {
    const key = statusKey(personId, periodId);
    cellStatus.value = { ...cellStatus.value, [key]: value };

    clearTimeout(timers[key]);
    if (value === 'saved') {
        timers[key] = setTimeout(() => {
            const next = { ...cellStatus.value };
            delete next[key];
            cellStatus.value = next;
        }, 2500);
    }
};

const statusOf = (personId, periodId) => cellStatus.value[statusKey(personId, periodId)] ?? '';

/** 'empty' (no span yet) | 'simple' (one span, whole period — the plain <select> case) | 'split' (more than one span, or a single partial span with a gap). */
const cellMode = (cell) => {
    if (cell.spans.length === 0) return 'empty';
    if (cell.spans.length === 1 && cell.uncovered_days === 0) return 'simple';
    return 'split';
};

const saveCell = (personId, periodId, unitId) => {
    setStatus(personId, periodId, 'saving');
    router.patch('/admin/rota/cell', { person_id: personId, period_id: periodId, unit_id: unitId }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => setStatus(personId, periodId, 'saved'),
        onError: () => setStatus(personId, periodId, 'error'),
    });
};

const onCellSelect = (personId, periodId, event) => {
    const value = event.target.value;
    if (value === '') return;
    saveCell(personId, periodId, Number(value));
};

/**
 * Empties a cell — every span, whole-period or split alike (`RotaAssignment::clear()` does not
 * distinguish). The route existed from Task 8 and Task 9's own "Remove span" tooltip already
 * named Clear as the way to do this, but no task before Task 11 actually built the control — the
 * plain `<select>`'s blank option is deliberately a no-op (`onCellSelect` above), not a clear, so
 * without this button there was no way to empty an assignment from the screen at all. Found while
 * writing the e2e journey, which needs a real control to drive; see this task's Amendments entry.
 */
const clearCell = (personId, periodId) => {
    setStatus(personId, periodId, 'saving');
    router.delete('/admin/rota/cell', {
        data: { person_id: personId, period_id: periodId },
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => setStatus(personId, periodId, 'saved'),
        onError: () => setStatus(personId, periodId, 'error'),
    });
};

// --- Task 9: splits in a cell -----------------------------------------------------------

// `{ personId, periodId, spans: [{unit_id, starts_on, ends_on}] }` while a split is being
// edited; the local `spans` array is the ONLY thing this panel keeps as working state — the
// period bounds and the uncovered-day count are always read live from `props.grid` below.
const splitEditor = ref(null);
const splitProcessing = ref(false);
const splitErrors = ref({});

const splitPeriod = computed(() => {
    if (!splitEditor.value || !props.grid) return null;
    return props.grid.periods.find((period) => period.id === splitEditor.value.periodId) ?? null;
});

// The live cell this editor is working on — re-derived from `props.grid` on every render, so a
// fresh save's server-computed `uncovered_days` shows up here without this component ever
// computing that count itself from the (possibly unsaved) rows in `splitEditor.spans`.
const splitCellState = computed(() => {
    if (!splitEditor.value || !props.grid) return null;
    const row = props.grid.rows.find((r) => r.person.id === splitEditor.value.personId);
    return row ? row.cells[splitEditor.value.periodId] : null;
});

const openSplit = (row, period) => {
    const cell = row.cells[period.id];

    splitErrors.value = {};
    splitEditor.value = {
        personId: row.person.id,
        periodId: period.id,
        // A blank cell starts the editor with one empty row rather than zero — an empty panel
        // with only "Add span" would make the first span harder to add than every later one.
        spans: cell.spans.length > 0
            ? cell.spans.map((span) => ({ unit_id: span.unit_id, starts_on: span.starts_on, ends_on: span.ends_on }))
            : [{ unit_id: '', starts_on: '', ends_on: '' }],
    };
};

const closeSplit = () => {
    splitEditor.value = null;
    splitErrors.value = {};
};

const addSpan = () => {
    // Empty, never guessed — a blank row makes the person pick both dates deliberately rather
    // than silently inheriting a neighbour's range.
    splitEditor.value.spans.push({ unit_id: '', starts_on: '', ends_on: '' });
};

const removeSpan = (index) => {
    // The title matches the writer's own refusal message (RotaAssignment::split()'s "To remove
    // an assignment, clear it.") so the UI and the exception say the same thing.
    if (splitEditor.value.spans.length <= 1) return;
    splitEditor.value.spans.splice(index, 1);
};

const submitSplit = () => {
    splitProcessing.value = true;
    splitErrors.value = {};

    router.post('/admin/rota/cell/split', {
        person_id: splitEditor.value.personId,
        period_id: splitEditor.value.periodId,
        spans: splitEditor.value.spans,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            splitProcessing.value = false;
            closeSplit();
        },
        onError: (errors) => {
            splitProcessing.value = false;
            splitErrors.value = errors;
        },
    });
};

// --- Task 10: vacations on the grid -----------------------------------------------------

// `{ personId, periodId, granularity, starts_on, ends_on }`. Week/date is a toggle on the
// LOCAL working state only — the writer (App\Support\Rota\VacationBooking) is what actually
// snaps a week booking to the department's own week; this panel only PREVIEWS that snap.
const vacationEditor = ref(null);
const vacationProcessing = ref(false);
const vacationErrors = ref({});

const vacationPeriod = computed(() => {
    if (!vacationEditor.value || !props.grid) return null;
    return props.grid.periods.find((period) => period.id === vacationEditor.value.periodId) ?? null;
});

/**
 * The week `date` falls in, found by matching it against the CURRENT period's own `weeks` list
 * (`Calendar::weeksIn()`, built once server-side in Task 8 — no extra query). This is a
 * lexicographic comparison of two already-server-supplied `Y-m-d` strings, the exact idiom
 * `Period::contains()` and `Calendar::weeksIn()`'s own PHP implementation use — no client date
 * object is ever constructed (finding 7's guard).
 */
const weekContaining = (date) => {
    if (!date || !vacationPeriod.value) return null;
    return vacationPeriod.value.weeks.find((week) => date >= week.starts_on && date <= week.ends_on) ?? null;
};

// The snapped range a `week`-granularity booking would actually store — a PREVIEW only. When
// either date falls outside the currently open period's own week list (a booking that reaches
// into a neighbouring block), this stays null and the form says so rather than guessing; the
// server's own snap (App\Support\Rota\VacationBooking::book()) is authoritative regardless.
const vacationWeekPreview = computed(() => {
    if (!vacationEditor.value || vacationEditor.value.granularity !== 'week') return null;

    const startWeek = weekContaining(vacationEditor.value.starts_on);
    const endWeek = weekContaining(vacationEditor.value.ends_on || vacationEditor.value.starts_on);

    if (!startWeek || !endWeek) return null;

    return {
        starts_on: startWeek.starts_on, starts_label: startWeek.starts_label,
        ends_on: endWeek.ends_on, ends_label: endWeek.ends_label,
    };
});

const openVacation = (row, period) => {
    vacationErrors.value = {};
    vacationEditor.value = {
        personId: row.person.id,
        periodId: period.id,
        granularity: 'date',
        starts_on: '',
        ends_on: '',
    };
};

const closeVacationEditor = () => {
    vacationEditor.value = null;
    vacationErrors.value = {};
};

const submitVacation = () => {
    vacationProcessing.value = true;
    vacationErrors.value = {};

    router.post('/admin/rota/vacations', {
        person_id: vacationEditor.value.personId,
        starts_on: vacationEditor.value.starts_on,
        ends_on: vacationEditor.value.ends_on,
        granularity: vacationEditor.value.granularity,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            vacationProcessing.value = false;
            closeVacationEditor();
        },
        onError: (errors) => {
            vacationProcessing.value = false;
            vacationErrors.value = errors;
        },
    });
};

const cancelLeave = (vacationId) => {
    router.delete(`/admin/rota/vacations/${vacationId}`, { preserveScroll: true, preserveState: true });
};

// --- Task 9: MR-06's bulk moves — preview, then confirm ---------------------------------

/**
 * PREVIEW, THEN CONFIRM, AND NEVER A SILENT OVERWRITE. This is the most destructive surface in
 * the rota: one confirmation can rewrite several hundred cells, which is why it is the only rota
 * action on `AuditAnomalies`' single-occurrence watch list (Decision F).
 *
 * NOTHING ON THIS PANEL WRITES EXCEPT ONE BUTTON. All four actions POST to
 * `/admin/rota/fill/preview`, which `RotaFillCommitTest::test_the_preview_route_writes_nothing`
 * pins server-side. `/admin/rota/fill` is reachable from `submitFill()` alone, and only once a
 * plan has been rendered.
 *
 * THE COMPONENT DERIVES NO ROTA FIGURE. Outcomes, reasons, current and proposed span sets and the
 * four counts all arrive computed on every target (`RotaFill::plan()`); this file decides layout
 * and nothing else. The one number it does compute is how many confirm boxes the OPERATOR has
 * ticked — their own decisions, not a fact about the rota — and it is labelled as such.
 *
 * IT CONVERTS NO DATE. A span's `starts_on`/`ends_on` are `Y-m-d` strings the server formatted
 * through `App\Support\Calendar`; they are printed, compared to nothing, and parsed by nobody
 * (ST-06 / design Decision A, ten needles and no allow-list).
 *
 * THE PLAN IS ONE-SHOT AND MATCHED. It arrives on `flash.rota_fill_preview`, so any navigation
 * clears it; and it is only rendered when the operation and source cell it ECHOES are the ones
 * currently selected, so a plan cannot be shown under a different action's heading.
 */
const FILL_ACTIONS = [
    {
        op: 'fill_down_level',
        label: 'Fill down · this level group',
        hint: 'Copy this cell to everybody else at the same level, in this block.',
    },
    {
        op: 'fill_down_column',
        label: 'Fill down · whole column',
        hint: 'Copy this cell to everybody else in this block.',
    },
    {
        op: 'fill_across',
        label: 'Fill across · later blocks',
        hint: 'Copy this cell forwards to every later block of the year. Whole-block assignments only.',
    },
];

const FILL_SPLIT_TARGET = 'skip_split_target';

/**
 * The badge beside each target. The REASON under it is the server's own sentence, rendered
 * verbatim — a screen that reworded it would be a second definition of why a cell was skipped,
 * and the two would drift the first time one of them was edited.
 */
const FILL_OUTCOME_LABELS = {
    assign: 'Will be assigned',
    replace: 'Will be replaced',
    unchanged: 'No change',
    skip_split_target: 'Skipped — this cell is split',
    skip_split_source: 'Skipped — the source is split',
    skip_stale_person: 'Skipped — off the roster',
    skip_retired_unit: 'Skipped — retired unit',
};

// `{ personId, periodId, op }` while a fill is being planned; `op` is null until the operator
// picks one of the four. Two explicit fill-downs, never one that guesses (Decision E).
const fillEditor = ref(null);
const fillCopyTarget = ref('');
const fillConfirmations = ref({});
const fillPlanVisible = ref(false);
const fillStaleNotice = ref('');
const fillErrors = ref({});
const fillStatus = ref('');
const fillProcessing = ref(false);
const fillPreviewedTicks = ref('[]');

const rowsByPersonId = computed(() => {
    const map = {};
    (props.grid?.rows ?? []).forEach((row) => { map[row.person.id] = row; });
    return map;
});

/**
 * A plan names NOBODY — ids and counts only, deliberately (`RotaFill`'s docblock), and the same
 * discipline the single `rota_fill` audit row inherits. The name comes from the grid the operator
 * is already looking at. An id that is not on the grid falls back to the id itself rather than a
 * dash: on a preview the operator has to check, "#42" is actionable and "—" is not.
 */
const fillPersonName = (personId) => rowsByPersonId.value[personId]?.person.full_name ?? `#${personId}`;

const fillPeriodLabel = (periodId) => (props.grid?.periods ?? []).find((p) => p.id === periodId)?.label ?? `#${periodId}`;

/**
 * A unit RETIRED since the rota was planned is not in `grid.units` (the grid offers active units
 * only) and a plan's span tuples carry an id, never a code. Marked rather than invented, the same
 * choice `AvailabilityPanel` makes: a bare id in a span line reads as a ward name.
 */
const fillUnitCode = (unitId) => unitsById.value[unitId]?.code ?? '—';

const fillSourceCell = computed(() => {
    if (!fillEditor.value || !props.grid) return null;
    const row = rowsByPersonId.value[fillEditor.value.personId];
    return row ? row.cells[fillEditor.value.periodId] : null;
});

// The three cell-sourced actions have nothing to copy from an empty cell — the server answers
// "that cell is empty" and the preview would be an error. Said before the click rather than after.
const fillSourceIsEmpty = computed(() => (fillSourceCell.value?.spans.length ?? 0) === 0);

// Copy-period moves one whole column onto another of the SAME academic year; the server refuses
// anything else, so nothing else is offered.
const fillCopyTargets = computed(
    () => (props.grid?.periods ?? []).filter((period) => period.id !== fillEditor.value?.periodId),
);

/** The request body, built once for both routes so the confirm cannot name a different cell. */
const fillBody = () => {
    const editor = fillEditor.value;
    const copy = editor.op === 'copy_period';

    const body = {
        op: editor.op,
        // Copy-period is the one operation with no source PERSON: it moves a whole column.
        source_person_id: copy ? null : editor.personId,
        source_period_id: editor.periodId,
    };

    if (copy) body.target_period_id = Number(fillCopyTarget.value) || null;

    // No dates. No spans. No unit. Every span a fill writes is read from the source cell
    // server-side — a body that could carry spans would be a second, unvalidated write path into
    // master_rota_assignments alongside RotaCellRequest's.
    return body;
};

const fillPlan = computed(() => {
    if (!fillPlanVisible.value || !fillEditor.value?.op) return null;

    const plan = page.props.flash?.rota_fill_preview ?? null;
    if (!plan) return null;

    const expected = fillBody();

    // The server echoes the operation and the source cell it planned against. A plan that names
    // anything else is a previous plan surviving a navigation, and is never rendered as if it
    // described the current selection.
    if (plan.op !== expected.op) return null;
    if ((plan.source?.person_id ?? null) !== expected.source_person_id) return null;
    if ((plan.source?.period_id ?? null) !== expected.source_period_id) return null;
    if ((plan.source?.target_period_id ?? null) !== (expected.target_period_id ?? null)) return null;

    return plan;
});

/** What the last fill actually did, in the server's own counts. Survives the panel being closed. */
const fillResult = computed(() => page.props.flash?.rota_fill_result ?? null);

const fillSplitTargets = computed(
    () => (fillPlan.value?.targets ?? []).filter((target) => target.outcome === FILL_SPLIT_TARGET),
);

const fillAllSplitsConfirmed = computed(
    () => fillSplitTargets.value.length > 0
        && fillSplitTargets.value.every((target) => fillConfirmations.value[target.key] === true),
);

/** Only the ticked cells, which IS the explicit confirmed set — absent means false, server-side. */
const fillConfirmedSet = () => {
    const out = {};
    Object.entries(fillConfirmations.value).forEach(([key, on]) => { if (on) out[key] = true; });
    return out;
};

const fillTickSignature = (map) => JSON.stringify(Object.keys(map).sort());

// The confirm stays valid whatever is ticked — `RotaFill::digest()` deliberately excludes the
// confirmations, so a tick never invalidates the pin. But the OUTCOMES on screen were computed
// against the ticks the preview ran with, so a changed tick makes the table one preview out of
// date, and saying so is cheaper than silently showing "skipped" beside a cell about to be
// overwritten.
const fillTicksDrifted = computed(
    () => fillPlan.value !== null && fillTickSignature(fillConfirmedSet()) !== fillPreviewedTicks.value,
);

const openFill = (row, period) => {
    fillErrors.value = {};
    fillStaleNotice.value = '';
    fillStatus.value = '';
    fillPlanVisible.value = false;
    fillConfirmations.value = {};
    fillCopyTarget.value = '';
    fillEditor.value = { personId: row.person.id, periodId: period.id, op: null };
};

const closeFill = () => {
    fillEditor.value = null;
    fillPlanVisible.value = false;
    fillConfirmations.value = {};
    fillStaleNotice.value = '';
    fillErrors.value = {};
    fillStatus.value = '';
};

const chooseFillAction = (op) => {
    fillEditor.value = { ...fillEditor.value, op };
    // A different action is a different plan. The previous one described another operation, and
    // its confirmations were answers about that operation's targets.
    fillPlanVisible.value = false;
    fillConfirmations.value = {};
    fillStaleNotice.value = '';
    runFillPreview();
};

const toggleFillConfirmation = (key, event) => {
    fillConfirmations.value = { ...fillConfirmations.value, [key]: event.target.checked };
};

/**
 * The master control SETS the individual boxes rather than replacing them with a flag. One bit
 * standing for N destructive decisions is exactly what Decision F refuses: the confirmed set is
 * always explicit, per cell, in the request body the operator's own preview was rendered from —
 * and an operator can still untick one of them afterwards without losing the rest.
 */
const setAllSplitConfirmations = (event) => {
    const on = event.target.checked;
    const next = { ...fillConfirmations.value };

    fillSplitTargets.value.forEach((target) => { next[target.key] = on; });

    fillConfirmations.value = next;
};

const runFillPreview = () => {
    if (!fillEditor.value?.op) return;

    const confirmations = fillConfirmedSet();

    fillProcessing.value = true;
    fillErrors.value = {};
    // Nothing to show while a preview is in flight — the plan on screen is the previous answer.
    fillPlanVisible.value = false;

    router.post('/admin/rota/fill/preview', { ...fillBody(), confirmations }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            // `flash.rota_fill_preview` has landed by the time Inertia calls this, so the plan
            // becomes visible only once the SERVER has answered for this exact selection.
            fillPlanVisible.value = true;
            fillPreviewedTicks.value = fillTickSignature(confirmations);
        },
        onError: (errors) => { fillErrors.value = errors; },
        onFinish: () => { fillProcessing.value = false; },
    });
};

const submitFill = () => {
    const plan = fillPlan.value;
    if (!plan) return;

    fillProcessing.value = true;
    fillStatus.value = 'saving';
    fillErrors.value = {};

    router.post('/admin/rota/fill', {
        ...fillBody(),
        confirmations: fillConfirmedSet(),
        // The pin. `RotaFill::apply()` recomputes this over the rota it is about to write and
        // `hash_equals` it; a mismatch means the grid moved and is refused outright.
        digest: plan.digest,
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            fillStatus.value = 'saved';
            // The fill happened, so the plan that described it no longer describes the rota.
            // Dropped rather than left on screen behind a button that would now be refused.
            fillPlanVisible.value = false;
            fillConfirmations.value = {};
            fillStaleNotice.value = '';
        },
        onError: (errors) => {
            fillStatus.value = 'error';

            if (errors.digest) {
                // THE ROTA MOVED UNDER THE OPERATOR — `StaleFillPlanException`, a 422 on `digest`
                // (Task 8). Never retried: the plan they approved describes a rota that no longer
                // exists, and re-sending it with a fresh digest would apply a set they never saw.
                // So: say it in the server's own words, DROP the plan so nothing on screen looks
                // confirmable, clear the ticks — they were answers about split contents, which may
                // be exactly what changed — and re-run the PREVIEW, which writes nothing.
                fillStaleNotice.value = errors.digest;
                fillPlanVisible.value = false;
                fillConfirmations.value = {};
                runFillPreview();

                return;
            }

            fillErrors.value = errors;
        },
        onFinish: () => { fillProcessing.value = false; },
    });
};
</script>

<template>
    <AppLayout title="Master Rota">
        <div class="mx-auto max-w-full space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-ink">Master rota</h2>
                    <p class="text-sm text-muted">
                        Plan which unit each person rotates through, period by period, for an
                        academic year.
                    </p>
                </div>
                <div v-if="academic_years.length" class="flex items-end gap-2">
                    <div>
                        <label class="channel-tag mb-1 block" for="rota-year">Academic year</label>
                        <select id="rota-year" v-model="selectedYear" class="min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink"
                                @change="changeYear">
                            <option value="">Choose a year&hellip;</option>
                            <option v-for="y in academic_years" :key="y" :value="y">{{ y }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <section v-if="academic_years.length === 0" class="rounded-md border border-line bg-panel p-6 text-center">
                <p class="text-sm font-semibold text-ink">No academic years yet</p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-muted">
                    The master rota's columns are the periods of an academic year. Generate one
                    first on Structure &rarr; Periods, then come back here to plan it.
                </p>
                <a href="/admin/structure/periods"
                   class="mt-4 inline-block min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white">
                    Go to Structure &rarr; Periods
                </a>
            </section>

            <section v-else-if="!grid" class="rounded-md border border-line bg-panel p-6 text-center">
                <p class="text-sm text-body">Choose an academic year above to plan its rota.</p>
            </section>

            <template v-else>
                <!--
                  MR-06's export (Decision G). TWO files: one row per span, one row per vacation.
                  A person is named by their short name plus their full name — no email, no phone,
                  no id — so the file can be re-imported and carries nobody's contact detail.
                -->
                <section class="flex flex-wrap items-center gap-3 rounded-md border border-line bg-panel p-4"
                         data-testid="rota-export">
                    <p class="text-sm text-body">Export this year</p>
                    <a :href="exportHref('assignments')" data-testid="export-assignments"
                       class="min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm font-semibold text-ink">
                        Rota (CSV)
                    </a>
                    <a :href="exportHref('vacations')" data-testid="export-vacations"
                       class="min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm font-semibold text-ink">
                        Vacations (CSV)
                    </a>
                    <p v-if="people_without_a_short_name > 0" role="alert" data-testid="export-short-name-warning"
                       class="text-sm text-critical">
                        {{ people_without_a_short_name }}
                        {{ people_without_a_short_name === 1 ? 'person' : 'people' }}
                        in this year {{ people_without_a_short_name === 1 ? 'has' : 'have' }}
                        no short name. They export with a blank handle and cannot be imported back.
                        <a href="/admin/people" class="font-semibold underline">Fix on Admin &rarr; People</a>
                    </p>
                </section>

                <!-- Mobile: one card per person. -->
                <div class="space-y-4 lg:hidden">
                    <div v-for="group in rowGroups" :key="`m-${group.level.id ?? 'none'}`" class="space-y-3">
                        <p class="channel-tag">{{ group.level.code }} &middot; {{ group.level.name }}</p>
                        <article v-for="row in group.rows" :key="row.person.id"
                                 :data-row-id="`person-${row.person.id}`"
                                 class="rounded-md border border-line bg-panel p-4">
                            <p class="text-sm font-semibold text-ink">{{ row.person.full_name }}</p>
                            <p v-if="row.person.external" class="channel-tag">External</p>
                            <p v-if="row.stale" class="channel-tag text-critical">
                                Inactive &middot; read-only, clear only
                            </p>
                            <div class="mt-3 space-y-3">
                                <div v-for="period in grid.periods" :key="period.id"
                                     :data-col-key="`period-${period.id}`"
                                     class="rounded-md border border-line-soft p-3">
                                    <p class="channel-tag">
                                        {{ period.label }}
                                        <span v-if="row.cells[period.id].level_id !== row.group_level_id" class="text-critical">
                                            &middot; level differs this period
                                        </span>
                                    </p>
                                    <p class="text-xs text-muted">{{ period.starts_label.date }} ({{ period.starts_label.hijri }}) &ndash; {{ period.ends_label.date }}</p>

                                    <template v-if="!row.stale && cellMode(row.cells[period.id]) !== 'split'">
                                        <select class="mt-2 w-full min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink"
                                                :value="row.cells[period.id].spans[0]?.unit_id ?? ''"
                                                @change="onCellSelect(row.person.id, period.id, $event)">
                                            <option value="">&mdash; unassigned &mdash;</option>
                                            <option v-for="unit in grid.units" :key="unit.id" :value="unit.id">{{ unit.code }}</option>
                                        </select>
                                    </template>
                                    <template v-else-if="row.cells[period.id].spans.length">
                                        <ul class="mt-2 space-y-1">
                                            <li v-for="span in row.cells[period.id].spans" :key="span.id" class="channel-tag">
                                                {{ unitsById[span.unit_id]?.code ?? span.unit_code }}: {{ span.starts_label.date }} &ndash; {{ span.ends_label.date }}
                                            </li>
                                        </ul>
                                        <!-- A stale row's uncovered days are not a planning gap to fill — nobody
                                             may be assigned into them — so the count is left off that row. -->
                                        <p v-if="!row.stale && row.cells[period.id].uncovered_days > 0" class="mt-1 text-xs text-critical">
                                            {{ row.cells[period.id].uncovered_days }} day(s) unassigned in this block
                                        </p>
                                    </template>
                                    <template v-else>
                                        <p class="mt-2 text-sm text-muted">&mdash;</p>
                                    </template>

                                    <div class="mt-1 flex gap-3">
                                        <button v-if="!row.stale" type="button" class="text-xs font-semibold text-channel-ink"
                                                :data-testid="`split-open-${row.person.id}-${period.id}`"
                                                @click="openSplit(row, period)">
                                            Split&hellip;
                                        </button>
                                        <button v-if="!row.stale" type="button" class="text-xs font-semibold text-channel-ink"
                                                :data-testid="`vacation-open-${row.person.id}-${period.id}`"
                                                @click="openVacation(row, period)">
                                            On leave&hellip;
                                        </button>
                                        <!-- Task 9: MR-06's bulk moves. Opens a PREVIEW panel; nothing
                                             here writes. Off a stale row for the same reason Split is:
                                             the server refuses to name an inactive person as a source. -->
                                        <button v-if="!row.stale" type="button" class="text-xs font-semibold text-channel-ink"
                                                :data-testid="`fill-open-${row.person.id}-${period.id}`"
                                                @click="openFill(row, period)">
                                            Fill&hellip;
                                        </button>
                                        <button v-if="cellMode(row.cells[period.id]) !== 'empty'" type="button"
                                                class="text-xs font-semibold text-critical"
                                                :data-testid="`clear-cell-${row.person.id}-${period.id}`"
                                                @click="clearCell(row.person.id, period.id)">
                                            Clear
                                        </button>
                                    </div>

                                    <ul v-if="row.cells[period.id].vacations.length" class="mt-2 space-y-1">
                                        <li v-for="vac in row.cells[period.id].vacations" :key="vac.id" class="channel-tag">
                                            On leave: {{ vac.starts_label.date }} &ndash; {{ vac.ends_label.date }}
                                            <button type="button" class="ml-1 text-critical"
                                                    :data-testid="`vacation-cancel-${vac.id}`"
                                                    @click="cancelLeave(vac.id)">
                                                Cancel
                                            </button>
                                        </li>
                                    </ul>

                                    <SaveStatus :status="statusOf(row.person.id, period.id)" :testid="`cell-status-${row.person.id}-${period.id}`" />
                                </div>
                            </div>
                        </article>
                    </div>
                </div>

                <!-- Desktop: a table. -->
                <div class="hidden overflow-x-auto rounded-md border border-line bg-panel lg:block">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-ground-deep">
                            <tr>
                                <th scope="col" class="channel-tag px-4 py-2">Person</th>
                                <th v-for="period in grid.periods" :key="period.id" scope="col" class="channel-tag px-3 py-2">
                                    <span class="block">{{ period.label }}</span>
                                    <span class="block text-xs font-normal normal-case text-muted">
                                        {{ period.starts_label.date }} &ndash; {{ period.ends_label.date }}
                                    </span>
                                    <span class="block text-xs font-normal normal-case text-muted">{{ period.starts_label.hijri }}</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="group in rowGroups" :key="`d-${group.level.id ?? 'none'}`">
                                <tr class="border-t border-line bg-ground-deep">
                                    <th :colspan="desktopColumnCount" scope="colgroup" class="channel-tag px-4 py-2 text-left">
                                        {{ group.level.code }} &middot; {{ group.level.name }}
                                    </th>
                                </tr>
                                <tr v-for="row in group.rows" :key="row.person.id" :data-row-id="`person-${row.person.id}`"
                                    class="border-t border-line">
                                    <td class="px-4 py-2 text-body">
                                        {{ row.person.full_name }}
                                        <span v-if="row.person.external" class="channel-tag ml-1">External</span>
                                        <span v-if="row.stale" class="channel-tag ml-1 text-critical">
                                            Inactive &middot; read-only
                                        </span>
                                    </td>
                                    <td v-for="period in grid.periods" :key="period.id" :data-col-key="`period-${period.id}`"
                                        class="px-3 py-2 align-top">
                                        <span v-if="row.cells[period.id].level_id !== row.group_level_id"
                                              class="channel-tag mb-1 block text-critical">level differs</span>

                                        <template v-if="!row.stale && cellMode(row.cells[period.id]) !== 'split'">
                                            <select class="channel-bar w-full min-h-11 rounded-md border border-line bg-panel px-2 py-1 text-sm text-ink"
                                                    :class="unitsById[row.cells[period.id].spans[0]?.unit_id]?.bar_class"
                                                    :value="row.cells[period.id].spans[0]?.unit_id ?? ''"
                                                    @change="onCellSelect(row.person.id, period.id, $event)">
                                                <option value="">&mdash;</option>
                                                <option v-for="unit in grid.units" :key="unit.id" :value="unit.id">{{ unit.code }}</option>
                                            </select>
                                        </template>
                                        <template v-else-if="row.cells[period.id].spans.length">
                                            <ul class="space-y-0.5">
                                                <li v-for="span in row.cells[period.id].spans" :key="span.id"
                                                    class="channel-bar channel-tag rounded-sm px-1"
                                                    :class="unitsById[span.unit_id]?.bar_class">
                                                    {{ unitsById[span.unit_id]?.code ?? span.unit_code }}
                                                    {{ span.starts_label.date }}&ndash;{{ span.ends_label.date }}
                                                </li>
                                            </ul>
                                            <p v-if="!row.stale && row.cells[period.id].uncovered_days > 0" class="mt-0.5 text-xs text-critical">
                                                {{ row.cells[period.id].uncovered_days }}d unassigned
                                            </p>
                                        </template>
                                        <template v-else>
                                            <p class="text-sm text-muted">&mdash;</p>
                                        </template>

                                        <div class="mt-0.5 flex gap-2">
                                            <button v-if="!row.stale" type="button" class="text-xs font-semibold text-channel-ink"
                                                    :data-testid="`split-open-${row.person.id}-${period.id}`"
                                                    @click="openSplit(row, period)">
                                                Split&hellip;
                                            </button>
                                            <button v-if="!row.stale" type="button" class="text-xs font-semibold text-channel-ink"
                                                    :data-testid="`vacation-open-${row.person.id}-${period.id}`"
                                                    @click="openVacation(row, period)">
                                                On leave&hellip;
                                            </button>
                                            <button v-if="!row.stale" type="button" class="text-xs font-semibold text-channel-ink"
                                                    :data-testid="`fill-open-${row.person.id}-${period.id}`"
                                                    @click="openFill(row, period)">
                                                Fill&hellip;
                                            </button>
                                            <button v-if="cellMode(row.cells[period.id]) !== 'empty'" type="button"
                                                    class="text-xs font-semibold text-critical"
                                                    :data-testid="`clear-cell-${row.person.id}-${period.id}`"
                                                    @click="clearCell(row.person.id, period.id)">
                                                Clear
                                            </button>
                                        </div>

                                        <ul v-if="row.cells[period.id].vacations.length" class="mt-1 space-y-0.5">
                                            <li v-for="vac in row.cells[period.id].vacations" :key="vac.id" class="channel-tag">
                                                Leave {{ vac.starts_label.date }}&ndash;{{ vac.ends_label.date }}
                                                <button type="button" class="ml-1 text-critical"
                                                        :data-testid="`vacation-cancel-${vac.id}`"
                                                        @click="cancelLeave(vac.id)">
                                                    Cancel
                                                </button>
                                            </li>
                                        </ul>

                                        <SaveStatus :status="statusOf(row.person.id, period.id)" :testid="`cell-status-${row.person.id}-${period.id}`" />
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!--
                  MR-07, the same component and the same numbers the read view at /rota renders
                  (Task 5). It is handed the grid's periods, levels and units — never the rows, so
                  that this screen showing every stale row and /rota hiding them cannot make the
                  two panels differ. `tests/js/AvailabilityPanel.test.js` mounts both pages and
                  compares this subtree's markup.
                -->
                <AvailabilityPanel :periods="grid.periods" :levels="grid.levels" :units="grid.units"
                                   :summary="summary" />
            </template>

            <!-- Task 9: the split editor. Submits the WHOLE span set to POST /admin/rota/cell/split
                 — RotaAssignment::split() replaces, never merges (Decision F). -->
            <section v-if="splitEditor" class="rounded-md border border-line bg-panel p-6" data-testid="split-editor">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-ink">Split assignment</p>
                        <p v-if="splitPeriod" class="text-xs text-muted">
                            {{ splitPeriod.label }}: {{ splitPeriod.starts_label.date }} &ndash; {{ splitPeriod.ends_label.date }}
                        </p>
                    </div>
                    <button type="button" class="text-sm font-semibold text-body" @click="closeSplit">Close</button>
                </div>

                <div class="mt-4 space-y-3">
                    <div v-for="(span, index) in splitEditor.spans" :key="index"
                         class="grid grid-cols-1 gap-2 rounded-md border border-line-soft p-3 sm:grid-cols-4 sm:items-end"
                         data-testid="split-span-row">
                        <div>
                            <label class="channel-tag mb-1 block" :for="`split-unit-${index}`">Unit</label>
                            <select :id="`split-unit-${index}`" v-model="span.unit_id"
                                    class="w-full min-h-11 rounded-md border border-line bg-panel px-2 py-1 text-sm text-ink"
                                    data-testid="split-span-unit">
                                <option value="">&mdash; choose &mdash;</option>
                                <option v-for="unit in grid.units" :key="unit.id" :value="unit.id">{{ unit.code }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" :for="`split-start-${index}`">Starts</label>
                            <input :id="`split-start-${index}`" v-model="span.starts_on" type="date"
                                   :min="splitPeriod?.starts_on" :max="splitPeriod?.ends_on"
                                   class="w-full min-h-11 rounded-md border border-line bg-panel px-2 py-1 text-sm text-ink"
                                   data-testid="split-span-start" />
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" :for="`split-end-${index}`">Ends</label>
                            <input :id="`split-end-${index}`" v-model="span.ends_on" type="date"
                                   :min="splitPeriod?.starts_on" :max="splitPeriod?.ends_on"
                                   class="w-full min-h-11 rounded-md border border-line bg-panel px-2 py-1 text-sm text-ink"
                                   data-testid="split-span-end" />
                        </div>
                        <div>
                            <button type="button"
                                    class="min-h-11 rounded-md border border-line px-3 py-2 text-xs font-semibold text-critical disabled:opacity-40"
                                    :disabled="splitEditor.spans.length <= 1"
                                    :title="splitEditor.spans.length <= 1 ? 'Use Clear on the cell to remove the last span.' : 'Remove this span'"
                                    data-testid="split-remove-span"
                                    @click="removeSpan(index)">
                                Remove
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <button type="button" class="min-h-11 rounded-md border border-line px-3 py-2 text-sm font-semibold text-body"
                            data-testid="split-add-span" @click="addSpan">
                        + Add span
                    </button>
                    <!-- Always the SERVER's own count (splitCellState is derived from props.grid,
                         never from the unsaved rows above) — a gap is a legitimate state
                         (owner decision 3), never treated as an error here. -->
                    <p v-if="splitCellState" class="text-xs text-muted" data-testid="split-uncovered">
                        {{ splitCellState.uncovered_days }} day(s) in this block are currently unassigned.
                    </p>
                </div>

                <p v-if="splitErrors.spans" class="mt-2 text-sm text-critical" data-testid="split-error">{{ splitErrors.spans }}</p>

                <div class="mt-4 flex items-center gap-3 border-t border-line-soft pt-4">
                    <button type="button" :disabled="splitProcessing"
                            class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                            data-testid="split-save" @click="submitSplit">
                        Save split
                    </button>
                    <button type="button" class="text-sm font-semibold text-body" @click="closeSplit">Cancel</button>
                </div>
            </section>

            <!-- Task 10: book leave at week or exact-date granularity. -->
            <section v-if="vacationEditor" class="rounded-md border border-line bg-panel p-6" data-testid="vacation-editor">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-ink">Book leave</p>
                        <p v-if="vacationPeriod" class="text-xs text-muted">{{ vacationPeriod.label }}</p>
                    </div>
                    <button type="button" class="text-sm font-semibold text-body" @click="closeVacationEditor">Close</button>
                </div>

                <div class="mt-4 space-y-3">
                    <fieldset class="flex flex-wrap items-center gap-4">
                        <legend class="channel-tag mb-1 w-full">Granularity</legend>
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="vacationEditor.granularity" type="radio" value="week" data-testid="vacation-granularity-week" />
                            Week
                        </label>
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="vacationEditor.granularity" type="radio" value="date" data-testid="vacation-granularity-date" />
                            Exact dates
                        </label>
                    </fieldset>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="channel-tag mb-1 block" for="vacation-starts-on">Starts</label>
                            <input id="vacation-starts-on" v-model="vacationEditor.starts_on" type="date"
                                   :min="vacationPeriod?.starts_on" :max="vacationPeriod?.ends_on"
                                   class="w-full min-h-11 rounded-md border border-line bg-panel px-2 py-1 text-sm text-ink"
                                   data-testid="vacation-starts-on" />
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="vacation-ends-on">Ends</label>
                            <input id="vacation-ends-on" v-model="vacationEditor.ends_on" type="date"
                                   :min="vacationPeriod?.starts_on" :max="vacationPeriod?.ends_on"
                                   class="w-full min-h-11 rounded-md border border-line bg-panel px-2 py-1 text-sm text-ink"
                                   data-testid="vacation-ends-on" />
                        </div>
                    </div>

                    <!-- The snap is server-side (VacationBooking::book()); this line PREVIEWS it
                         from periods[].weeks, which Task 8 already built, so no date arithmetic
                         happens here at all. -->
                    <p v-if="vacationEditor.granularity === 'week'" class="text-xs text-muted" data-testid="vacation-week-preview">
                        <template v-if="vacationWeekPreview">
                            Will be stored as {{ vacationWeekPreview.starts_label.date }} &ndash; {{ vacationWeekPreview.ends_label.date }} (the department's full week).
                        </template>
                        <template v-else>
                            Choose dates within this block to preview the snapped week.
                        </template>
                    </p>
                </div>

                <p v-if="vacationErrors.starts_on" class="mt-2 text-sm text-critical" data-testid="vacation-error">{{ vacationErrors.starts_on }}</p>

                <div class="mt-4 flex items-center gap-3 border-t border-line-soft pt-4">
                    <button type="button" :disabled="vacationProcessing"
                            class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                            data-testid="vacation-save" @click="submitVacation">
                        Book leave
                    </button>
                    <button type="button" class="text-sm font-semibold text-body" @click="closeVacationEditor">Cancel</button>
                </div>
            </section>

            <!--
              Task 9: MR-06's bulk moves — PREVIEW, THEN CONFIRM.

              What the last fill did, from the server's own result, outside the panel gate: an
              operator who closed the panel still gets told what happened.
            -->
            <section v-if="fillResult" class="rounded-md border border-line bg-panel p-4" data-testid="fill-result">
                <p class="channel-tag">
                    Last fill &mdash; <span class="readout">{{ fillResult.applied }}</span> cell(s) written,
                    <span class="readout">{{ fillResult.summary.unchanged }}</span> unchanged,
                    <span class="readout">{{ fillResult.summary.skipped }}</span> skipped
                </p>
            </section>

            <section v-if="fillEditor" class="rounded-md border border-line bg-panel p-6" data-testid="fill-panel">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-ink">Fill from this cell</p>
                        <p class="text-xs text-muted">
                            {{ fillPersonName(fillEditor.personId) }} &middot; {{ fillPeriodLabel(fillEditor.periodId) }}
                            &mdash; nothing is written until you confirm a preview.
                        </p>
                    </div>
                    <button type="button" class="text-sm font-semibold text-body" @click="closeFill">Close</button>
                </div>

                <!-- The four actions. Each one PREVIEWS; none writes. -->
                <div class="mt-4 flex flex-wrap items-end gap-2">
                    <button v-for="action in FILL_ACTIONS" :key="action.op" type="button"
                            class="min-h-11 rounded-md border border-line bg-ground-deep px-3 py-2 text-sm font-semibold text-ink disabled:opacity-50"
                            :class="fillEditor.op === action.op ? 'border-channel' : ''"
                            :disabled="fillSourceIsEmpty || fillProcessing"
                            :title="action.hint"
                            :data-testid="`fill-action-${action.op}`"
                            @click="chooseFillAction(action.op)">
                        {{ action.label }}
                    </button>

                    <div class="flex items-end gap-2">
                        <div>
                            <label class="channel-tag mb-1 block" for="fill-copy-target">Copy this whole block onto</label>
                            <select id="fill-copy-target" v-model="fillCopyTarget" data-testid="fill-copy-target"
                                    class="min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink">
                                <option value="">Choose a block&hellip;</option>
                                <option v-for="period in fillCopyTargets" :key="period.id" :value="String(period.id)">
                                    {{ period.label }}
                                </option>
                            </select>
                        </div>
                        <button type="button"
                                class="min-h-11 rounded-md border border-line bg-ground-deep px-3 py-2 text-sm font-semibold text-ink disabled:opacity-50"
                                :class="fillEditor.op === 'copy_period' ? 'border-channel' : ''"
                                :disabled="!fillCopyTarget || fillProcessing"
                                title="Copy every assignment in this block onto the chosen block. Whole-block assignments only."
                                data-testid="fill-action-copy_period"
                                @click="chooseFillAction('copy_period')">
                            Copy period&hellip;
                        </button>
                    </div>
                </div>

                <p v-if="fillSourceIsEmpty" class="mt-2 text-xs text-caution">
                    This cell is empty, so there is nothing to fill down or across from. Assign it
                    first &mdash; or copy this whole block onto another one.
                </p>

                <!-- The rota moved under the operator. Their plan is gone and a fresh one is on its way. -->
                <p v-if="fillStaleNotice" role="alert" data-testid="fill-stale-notice"
                   class="mt-3 rounded-md border border-line bg-critical-soft p-3 text-sm text-critical">
                    {{ fillStaleNotice }}
                </p>

                <div v-if="fillPlan?.errors?.length" class="mt-3" data-testid="fill-errors">
                    <p v-for="(message, index) in fillPlan.errors" :key="index" class="text-sm text-critical">
                        {{ message }}
                    </p>
                </div>

                <p v-if="fillErrors.op" class="mt-2 text-sm text-critical" data-testid="fill-error">{{ fillErrors.op }}</p>
                <p v-if="fillErrors.confirmations" class="mt-2 text-sm text-critical">{{ fillErrors.confirmations }}</p>

                <template v-if="fillPlan && fillPlan.targets.length">
                    <!-- The SERVER's counts. This component computes none of them. -->
                    <p class="channel-tag mt-4" aria-live="polite" data-testid="fill-summary">
                        <span class="readout">{{ fillPlan.targets.length }}</span> cell(s) &middot;
                        <span class="readout">{{ fillPlan.summary.assign }}</span> to assign &middot;
                        <span class="readout">{{ fillPlan.summary.replace }}</span> to replace &middot;
                        <span class="readout">{{ fillPlan.summary.unchanged }}</span> unchanged &middot;
                        <span class="readout">{{ fillPlan.summary.skipped }}</span> skipped
                    </p>

                    <!--
                      The destructive case, named before the table shows it cell by cell. A cell
                      carrying a split holds dates somebody chose deliberately (Decision E's "the
                      four of them who join late start on the 9th"), so it is SKIPPED by default and
                      overwritten only per cell. The master control below sets those boxes; it does
                      not replace them.
                    -->
                    <div v-if="fillSplitTargets.length" class="mt-3 rounded-md border border-line bg-caution-soft p-3"
                         data-testid="fill-split-warning">
                        <p class="text-sm text-ink">
                            <span class="readout">{{ fillSplitTargets.length }}</span> cell(s) below already carry
                            dates somebody chose. They are skipped unless you confirm each one, and
                            what would be discarded is listed against each.
                        </p>
                        <label class="mt-2 flex items-center gap-2 text-sm text-body">
                            <input type="checkbox" data-testid="fill-confirm-all"
                                   :checked="fillAllSplitsConfirmed" @change="setAllSplitConfirmations" />
                            Overwrite every split listed below
                        </label>
                    </div>

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-ground-deep">
                                <tr>
                                    <th scope="col" class="channel-tag px-2 py-1">Person</th>
                                    <th scope="col" class="channel-tag px-2 py-1">Block</th>
                                    <th scope="col" class="channel-tag px-2 py-1">Outcome</th>
                                    <th scope="col" class="channel-tag px-2 py-1">Now</th>
                                    <th scope="col" class="channel-tag px-2 py-1">After</th>
                                    <th scope="col" class="channel-tag px-2 py-1">Confirm</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="target in fillPlan.targets" :key="target.key"
                                    :data-testid="`fill-target-${target.key}`"
                                    class="border-t border-line-soft align-top">
                                    <td class="px-2 py-1 text-body">{{ fillPersonName(target.person_id) }}</td>
                                    <td class="px-2 py-1 text-body">{{ fillPeriodLabel(target.period_id) }}</td>
                                    <td class="px-2 py-1">
                                        <span class="channel-tag" :data-testid="`fill-outcome-${target.key}`">
                                            {{ FILL_OUTCOME_LABELS[target.outcome] ?? target.outcome }}
                                        </span>
                                        <p v-if="target.reason" class="mt-1 max-w-xs text-xs text-muted"
                                           :data-testid="`fill-reason-${target.key}`">
                                            {{ target.reason }}
                                        </p>
                                    </td>
                                    <!--
                                      WHAT WOULD BE DESTROYED, span by span and dated — never a
                                      count. This column is the entire reason the confirm step
                                      exists: an operator about to overwrite deliberate split work
                                      has to see the work, not a number standing for it.
                                    -->
                                    <td class="px-2 py-1" :data-testid="`fill-current-${target.key}`">
                                        <ul v-if="target.current.length" class="space-y-0.5">
                                            <li v-for="(span, index) in target.current" :key="index"
                                                class="channel-tag"
                                                :class="target.outcome === 'replace' || target.outcome === FILL_SPLIT_TARGET ? 'text-critical' : ''">
                                                {{ fillUnitCode(span.unit_id) }}
                                                <span class="readout">{{ span.starts_on }}</span>&ndash;<span class="readout">{{ span.ends_on }}</span>
                                            </li>
                                        </ul>
                                        <p v-else class="text-xs text-muted">Empty</p>
                                    </td>
                                    <td class="px-2 py-1" :data-testid="`fill-proposed-${target.key}`">
                                        <ul v-if="target.proposed.length" class="space-y-0.5">
                                            <li v-for="(span, index) in target.proposed" :key="index" class="channel-tag">
                                                {{ fillUnitCode(span.unit_id) }}
                                                <span class="readout">{{ span.starts_on }}</span>&ndash;<span class="readout">{{ span.ends_on }}</span>
                                            </li>
                                        </ul>
                                        <p v-else class="text-xs text-muted">Nothing would be written</p>
                                    </td>
                                    <td class="px-2 py-1">
                                        <!-- Per cell, unchecked by default, and only where there is
                                             something to overrule. A skip nobody may overrule (a split
                                             SOURCE, a departed person, a retired unit) offers no box,
                                             because there is nothing to confirm. -->
                                        <label v-if="target.outcome === FILL_SPLIT_TARGET"
                                               class="flex items-center gap-2 text-xs text-body">
                                            <input type="checkbox" :data-testid="`fill-confirm-${target.key}`"
                                                   :checked="fillConfirmations[target.key] === true"
                                                   @change="toggleFillConfirmation(target.key, $event)" />
                                            Overwrite it
                                        </label>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-if="fillTicksDrifted" class="mt-2 text-xs text-caution" data-testid="fill-ticks-drifted">
                        The outcomes above were worked out before you changed those boxes. Preview
                        again to see what the fill would do now &mdash; confirming without doing so is
                        still safe, it just applies more than the table says.
                    </p>

                    <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-line-soft pt-4">
                        <button type="button" :disabled="fillProcessing"
                                class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                                data-testid="fill-submit" @click="submitFill">
                            Apply this fill
                        </button>
                        <button type="button" :disabled="fillProcessing"
                                class="min-h-11 rounded-md border border-line px-3 py-2 text-sm font-semibold text-body disabled:opacity-60"
                                data-testid="fill-preview-again" @click="runFillPreview">
                            Preview again
                        </button>
                        <button type="button" class="text-sm font-semibold text-body" @click="closeFill">Cancel</button>
                        <SaveStatus :status="fillStatus" testid="fill-status" />
                    </div>
                </template>
            </section>
        </div>
    </AppLayout>
</template>
