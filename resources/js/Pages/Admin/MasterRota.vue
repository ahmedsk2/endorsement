<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import SaveStatus from '../../Components/SaveStatus.vue';

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
});

const selectedYear = ref(props.year ?? '');

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
                <!-- Mobile: one card per person. -->
                <div class="space-y-4 lg:hidden">
                    <div v-for="group in rowGroups" :key="`m-${group.level.id ?? 'none'}`" class="space-y-3">
                        <p class="channel-tag">{{ group.level.code }} &middot; {{ group.level.name }}</p>
                        <article v-for="row in group.rows" :key="row.person.id"
                                 :data-row-id="`person-${row.person.id}`"
                                 class="rounded-md border border-line bg-panel p-4">
                            <p class="text-sm font-semibold text-ink">{{ row.person.full_name }}</p>
                            <p v-if="row.person.external" class="channel-tag">External</p>
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

                                    <template v-if="cellMode(row.cells[period.id]) !== 'split'">
                                        <select class="mt-2 w-full min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink"
                                                :value="row.cells[period.id].spans[0]?.unit_id ?? ''"
                                                @change="onCellSelect(row.person.id, period.id, $event)">
                                            <option value="">&mdash; unassigned &mdash;</option>
                                            <option v-for="unit in grid.units" :key="unit.id" :value="unit.id">{{ unit.code }}</option>
                                        </select>
                                    </template>
                                    <template v-else>
                                        <ul class="mt-2 space-y-1">
                                            <li v-for="span in row.cells[period.id].spans" :key="span.id" class="channel-tag">
                                                {{ unitsById[span.unit_id]?.code ?? span.unit_code }}: {{ span.starts_label.date }} &ndash; {{ span.ends_label.date }}
                                            </li>
                                        </ul>
                                        <p v-if="row.cells[period.id].uncovered_days > 0" class="mt-1 text-xs text-critical">
                                            {{ row.cells[period.id].uncovered_days }} day(s) unassigned in this block
                                        </p>
                                    </template>

                                    <div class="mt-1 flex gap-3">
                                        <button type="button" class="text-xs font-semibold text-channel-ink"
                                                :data-testid="`split-open-${row.person.id}-${period.id}`"
                                                @click="openSplit(row, period)">
                                            Split&hellip;
                                        </button>
                                        <button type="button" class="text-xs font-semibold text-channel-ink"
                                                :data-testid="`vacation-open-${row.person.id}-${period.id}`"
                                                @click="openVacation(row, period)">
                                            On leave&hellip;
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
                                    </td>
                                    <td v-for="period in grid.periods" :key="period.id" :data-col-key="`period-${period.id}`"
                                        class="px-3 py-2 align-top">
                                        <span v-if="row.cells[period.id].level_id !== row.group_level_id"
                                              class="channel-tag mb-1 block text-critical">level differs</span>

                                        <template v-if="cellMode(row.cells[period.id]) !== 'split'">
                                            <select class="channel-bar w-full min-h-11 rounded-md border border-line bg-panel px-2 py-1 text-sm text-ink"
                                                    :class="unitsById[row.cells[period.id].spans[0]?.unit_id]?.bar_class"
                                                    :value="row.cells[period.id].spans[0]?.unit_id ?? ''"
                                                    @change="onCellSelect(row.person.id, period.id, $event)">
                                                <option value="">&mdash;</option>
                                                <option v-for="unit in grid.units" :key="unit.id" :value="unit.id">{{ unit.code }}</option>
                                            </select>
                                        </template>
                                        <template v-else>
                                            <ul class="space-y-0.5">
                                                <li v-for="span in row.cells[period.id].spans" :key="span.id"
                                                    class="channel-bar channel-tag rounded-sm px-1"
                                                    :class="unitsById[span.unit_id]?.bar_class">
                                                    {{ unitsById[span.unit_id]?.code ?? span.unit_code }}
                                                    {{ span.starts_label.date }}&ndash;{{ span.ends_label.date }}
                                                </li>
                                            </ul>
                                            <p v-if="row.cells[period.id].uncovered_days > 0" class="mt-0.5 text-xs text-critical">
                                                {{ row.cells[period.id].uncovered_days }}d unassigned
                                            </p>
                                        </template>

                                        <div class="mt-0.5 flex gap-2">
                                            <button type="button" class="text-xs font-semibold text-channel-ink"
                                                    :data-testid="`split-open-${row.person.id}-${period.id}`"
                                                    @click="openSplit(row, period)">
                                                Split&hellip;
                                            </button>
                                            <button type="button" class="text-xs font-semibold text-channel-ink"
                                                    :data-testid="`vacation-open-${row.person.id}-${period.id}`"
                                                    @click="openVacation(row, period)">
                                                On leave&hellip;
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
        </div>
    </AppLayout>
</template>
