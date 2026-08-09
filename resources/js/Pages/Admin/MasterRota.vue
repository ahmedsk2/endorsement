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
 * whole-period assignment. The "Split" editor that opens from it is Task 9. Existing vacations
 * render read-only here too; booking/cancelling one is Task 10.
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

                                    <ul v-if="row.cells[period.id].vacations.length" class="mt-2 space-y-1">
                                        <li v-for="vac in row.cells[period.id].vacations" :key="vac.id" class="channel-tag">
                                            On leave: {{ vac.starts_label.date }} &ndash; {{ vac.ends_label.date }}
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

                                        <ul v-if="row.cells[period.id].vacations.length" class="mt-1 space-y-0.5">
                                            <li v-for="vac in row.cells[period.id].vacations" :key="vac.id" class="channel-tag">
                                                Leave {{ vac.starts_label.date }}&ndash;{{ vac.ends_label.date }}
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
        </div>
    </AppLayout>
</template>
