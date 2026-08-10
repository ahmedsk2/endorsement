<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

/**
 * The master rota as a resident reads it (Munawib MR-05), at /rota, cap:rota.view — with MR-07's
 * per-period availability summary underneath it.
 *
 * READ-ONLY, AND THAT IS THE FEATURE. Nothing on this screen writes: no unit picker, no Split, no
 * On leave, no Clear, no form, no button that sends anything but a GET. The server enforces it too
 * — the whole cap:rota.view route group is GET-only, asserted over the ROUTER by RotaAccessTest —
 * but a control that appears and then 403s is worse than no control, so there is none here.
 * `Rota.test.js` asserts the absence at the component: no form, no control inside the strip, and
 * no write verb reachable by clicking every button on the page.
 *
 * THERE IS NO PUBLISH STATE (owner decision 1, 2026-08-10). No status badge, no draft ribbon, no
 * "visible from" note: this screen always shows the current rota, so there is nothing to badge.
 *
 * NO CONTACT DETAIL, EVER. Rows arrive through PersonPresenter::contactFree() (Decision C), so
 * there is no email or phone in these props for this screen to render or withhold. Nothing here
 * needs to gate on the viewer, because nothing here HAS a viewer-dependent field.
 *
 * SEARCH AND THE LEVEL FILTER ARE THE SERVER'S. The two controls below push the query string and
 * let the server re-render; they never filter `grid.rows` in the browser. Two consequences worth
 * stating: a reader can share or bookmark exactly what they are looking at, and the availability
 * summary stays the DEPARTMENT's rather than the reader's — the server computes it from the full
 * grid before it filters the rows (Decision D's ordering trap).
 *
 * EVERY DATE ON THIS SCREEN WAS FORMATTED BY THE SERVER. Period bounds, span bounds, vacation
 * bounds and the week strip all arrive as label pairs (Gregorian plus Hijri) or as enumerated
 * ranges; this component places those strings and derives none of its own. Not even "today" — the
 * one converter is App\Support\Calendar, server-side (design Decision A / ST-06, guarded at source
 * over the whole of resources/js).
 *
 * MOBILE FIRST, THEN THE TABLE. Below `lg` the strip is one card per person with a stacked block
 * per period; from `lg` up it is a table, one row per person and one column per period. Both are
 * in the DOM and CSS hides one, the same shape MasterRota.vue uses — which is why a per-row
 * lookup in a browser test must be scoped by `data-row-id` rather than by an unscoped testid.
 */
const props = defineProps({
    academic_years: { type: Array, default: () => [] },
    year: { type: String, default: null },
    grid: { type: Object, default: null },
    summary: { type: Object, default: null },
    filters: { type: Object, default: () => ({ q: '', level: null }) },
});

// Seeded from what the SERVER applied (`filters`), never re-derived from the URL — one source of
// truth for "what am I looking at", and the two cannot drift apart.
const selectedYear = ref(props.year ?? '');
const search = ref(props.filters?.q ?? '');
const selectedLevel = ref(props.filters?.level == null ? '' : String(props.filters.level));

/**
 * One navigation for all three controls. `preserveState` keeps the caret in the search box and the
 * page's scroll where it was; `replace` keeps a filter fiddle out of the back button's history, so
 * Back leaves the rota rather than walking through six keystrokes.
 */
const applyFilters = () => {
    const query = {};

    if (selectedYear.value) query.year = selectedYear.value;
    if (search.value) query.q = search.value;
    if (selectedLevel.value) query.level = selectedLevel.value;

    router.get('/rota', query, { preserveState: true, preserveScroll: true, replace: true });
};

const unitsById = computed(() => {
    const map = {};
    (props.grid?.units ?? []).forEach((unit) => { map[unit.id] = unit; });
    return map;
});

const levelsById = computed(() => {
    const map = {};
    (props.grid?.levels ?? []).forEach((level) => { map[level.id] = level; });
    return map;
});

/**
 * A unit RETIRED since the rota was planned is not in `grid.units` (the grid offers only active
 * units), but its spans still carry their own `unit_code` so a historical assignment does not go
 * blank the day a department closes a ward. This falls back through both before giving up.
 */
const codeFromSpans = computed(() => {
    const map = {};
    (props.grid?.rows ?? []).forEach((row) => {
        Object.values(row.cells ?? {}).forEach((cell) => {
            (cell.spans ?? []).forEach((span) => {
                if (span.unit_code) map[span.unit_id] = span.unit_code;
            });
        });
    });
    return map;
});

const unitCode = (unitId) => unitsById.value[unitId]?.code ?? codeFromSpans.value[unitId] ?? '—';

// The rota's column count varies by academic year and period system; computed, never a hardcoded
// colspan.
const desktopColumnCount = computed(() => 1 + (props.grid?.periods?.length ?? 0));

const filtering = computed(() => Boolean(search.value) || Boolean(selectedLevel.value));

// Same grouping as the editor: by the level held at the ACADEMIC YEAR's start, in the ladder's own
// display order, with anybody who has no level history at all in a trailing group rather than
// dropped from the one screen that exists to show where everybody is.
const rowGroups = computed(() => {
    if (!props.grid) return [];

    const groups = props.grid.levels.map((level) => ({
        level,
        rows: props.grid.rows.filter((row) => row.group_level_id === level.id),
    }));

    const knownIds = props.grid.levels.map((level) => level.id);
    const ungrouped = props.grid.rows.filter((row) => !knownIds.includes(row.group_level_id));

    if (ungrouped.length > 0) {
        groups.push({ level: { id: null, code: '—', name: 'No level recorded' }, rows: ungrouped });
    }

    return groups.filter((group) => group.rows.length > 0);
});

// --- MR-07's summary panel ---------------------------------------------------------------
//
// Every figure below arrives COMPUTED, from App\Support\Rota\AvailabilitySummary — one fold, two
// screens (the editor renders the identical numbers). Nothing here sums, counts or converts; the
// helpers only decide the order things appear in and which label goes beside them.

const summaryFor = (periodId) => props.summary?.[periodId] ?? null;

/** The units this period actually uses, so a period on one ward is not a table of empty columns. */
const summaryUnitIds = (periodSummary) => {
    const ids = new Set();

    Object.values(periodSummary.by_level_unit ?? {}).forEach((byUnit) => {
        Object.keys(byUnit).forEach((unitId) => ids.add(Number(unitId)));
    });

    return [...ids].sort((a, b) => a - b);
};

/**
 * The level rows, in the ladder's display order rather than by id. `AvailabilitySummary::NO_LEVEL`
 * is `0` — a person whose level history says nothing about this period. They are bucketed rather
 * than dropped, because dropping them would break the property that the buckets add up to
 * `assigned_days` and would hide a real person from a real block.
 */
const summaryLevelRows = (periodSummary) => {
    const buckets = periodSummary.by_level_unit ?? {};
    const out = [];

    (props.grid?.levels ?? []).forEach((level) => {
        if (buckets[level.id]) out.push({ id: level.id, label: level.code, units: buckets[level.id] });
    });

    Object.keys(buckets).forEach((key) => {
        const id = Number(key);
        if (!levelsById.value[id]) {
            out.push({ id, label: id === 0 ? 'No level' : '—', units: buckets[key] });
        }
    });

    return out;
};

/**
 * A week's identity, taken from the PERIOD's own week strip at the same index — the summary's
 * weeks were built by walking that same array in that same order, so index correspondence is
 * exact, and it is the period prop that carries the dual-dated labels.
 *
 * The comparison below is string equality between two already-formatted values, which is how this
 * screen can say "this week is only partly inside the block" without going near a calendar.
 */
const weekOf = (period, index, week) => {
    const source = period.weeks?.[index] ?? null;

    return {
        starts: source?.starts_label?.date ?? week.clipped_starts_on,
        hijri: source?.starts_label?.hijri ?? '',
        ends: source?.ends_label?.date ?? week.clipped_ends_on,
        partial: source !== null
            && (source.starts_on !== week.clipped_starts_on || source.ends_on !== week.clipped_ends_on),
    };
};
</script>

<template>
    <AppLayout title="Rota">
        <div class="mx-auto max-w-full space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-ink">Rota</h2>
                    <p class="text-sm text-muted">
                        Which unit each person rotates through, period by period.
                    </p>
                </div>
                <div v-if="academic_years.length" class="flex items-end gap-2">
                    <div>
                        <label class="channel-tag mb-1 block" for="read-rota-year">Academic year</label>
                        <select id="read-rota-year" v-model="selectedYear"
                                class="min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink"
                                data-testid="rota-year"
                                @change="applyFilters">
                            <!--
                              The server always lands a reader on a year when the department has
                              one (the one containing today, else the most recent), so this blank
                              entry exists only so a `?year=` the server could not resolve still
                              renders a `<select>` that matches its own value.
                            -->
                            <option v-if="!selectedYear" value="">Choose a year&hellip;</option>
                            <option v-for="y in academic_years" :key="y" :value="y">{{ y }}</option>
                        </select>
                    </div>
                </div>
            </div>

            <section v-if="academic_years.length === 0" class="rounded-md border border-line bg-panel p-6 text-center">
                <p class="text-sm font-semibold text-ink">No rota yet</p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-muted">
                    Nothing has been planned for an academic year yet. This page will show it once
                    it has.
                </p>
            </section>

            <section v-else-if="!grid" class="rounded-md border border-line bg-panel p-6 text-center">
                <p class="text-sm text-body">Choose an academic year above to see its rota.</p>
            </section>

            <template v-else>
                <!--
                  MR-05's search and level filter. Outside the strip below, deliberately: the strip
                  carries no control of any kind, and these two only navigate.
                -->
                <div class="flex flex-wrap items-end gap-3 rounded-md border border-line bg-panel p-4">
                    <div class="min-w-48 flex-1">
                        <label class="channel-tag mb-1 block" for="rota-search">Search by name</label>
                        <input id="rota-search" v-model="search" type="search" autocomplete="off"
                               placeholder="Name or short name"
                               class="w-full min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink"
                               data-testid="rota-search"
                               @change="applyFilters" @keyup.enter="applyFilters" />
                    </div>
                    <div>
                        <label class="channel-tag mb-1 block" for="rota-level">Level</label>
                        <select id="rota-level" v-model="selectedLevel"
                                class="min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink"
                                data-testid="rota-level"
                                @change="applyFilters">
                            <option value="">All levels</option>
                            <option v-for="level in grid.levels" :key="level.id" :value="String(level.id)">
                                {{ level.code }} &middot; {{ level.name }}
                            </option>
                        </select>
                    </div>
                    <p role="status" class="text-xs text-muted" data-testid="rota-count">
                        Showing {{ grid.rows.length }} {{ grid.rows.length === 1 ? 'person' : 'people' }}
                    </p>
                </div>

                <section v-if="grid.rows.length === 0" class="rounded-md border border-line bg-panel p-6 text-center"
                         data-testid="rota-no-matches">
                    <p v-if="filtering" class="text-sm text-body">
                        Nobody on this year's rota matches &ldquo;{{ search }}&rdquo;<template v-if="selectedLevel"> at the level chosen</template>.
                    </p>
                    <p v-else class="text-sm text-body">
                        Nobody is on the roster for this year yet.
                    </p>
                </section>

                <div v-else data-testid="rota-strip">
                    <!-- Phone: one card per person, a block per period. -->
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
                                        <p class="channel-tag">{{ period.label }}</p>
                                        <p class="text-xs text-muted">
                                            {{ period.starts_label.date }} ({{ period.starts_label.hijri }}) &ndash; {{ period.ends_label.date }}
                                        </p>
                                        <div class="mt-2" :data-testid="`cell-${row.person.id}-${period.id}`">
                                            <template v-if="row.cells[period.id].spans.length">
                                                <ul class="space-y-1">
                                                    <li v-for="span in row.cells[period.id].spans" :key="span.id"
                                                        class="channel-bar channel-tag rounded-sm px-1"
                                                        :class="unitsById[span.unit_id]?.bar_class">
                                                        {{ unitCode(span.unit_id) }}
                                                        <span v-if="row.cells[period.id].spans.length > 1" class="normal-case">
                                                            {{ span.starts_label.date }} &ndash; {{ span.ends_label.date }}
                                                        </span>
                                                    </li>
                                                </ul>
                                                <p v-if="row.cells[period.id].uncovered_days > 0"
                                                   class="mt-1 text-xs text-caution"
                                                   :data-testid="`uncovered-${row.person.id}-${period.id}`">
                                                    {{ row.cells[period.id].uncovered_days }} day(s) not yet assigned
                                                </p>
                                            </template>
                                            <p v-else class="channel-tag text-muted">Unassigned</p>

                                            <p v-for="vac in row.cells[period.id].vacations" :key="vac.id"
                                               class="channel-tag mt-1"
                                               :data-testid="`leave-${row.person.id}-${period.id}`">
                                                On leave {{ vac.starts_label.date }} &ndash; {{ vac.ends_label.date }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        </div>
                    </div>

                    <!-- Desktop: the year on one grid. -->
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
                                    <tr v-for="row in group.rows" :key="row.person.id"
                                        :data-row-id="`person-${row.person.id}`" class="border-t border-line">
                                        <td class="px-4 py-2 text-body">
                                            {{ row.person.full_name }}
                                            <span v-if="row.person.external" class="channel-tag ml-1">External</span>
                                        </td>
                                        <td v-for="period in grid.periods" :key="period.id"
                                            :data-col-key="`period-${period.id}`" class="px-3 py-2 align-top"
                                            :data-testid="`cell-${row.person.id}-${period.id}`">
                                            <template v-if="row.cells[period.id].spans.length">
                                                <ul class="space-y-0.5">
                                                    <li v-for="span in row.cells[period.id].spans" :key="span.id"
                                                        class="channel-bar channel-tag rounded-sm px-1"
                                                        :class="unitsById[span.unit_id]?.bar_class">
                                                        {{ unitCode(span.unit_id) }}
                                                        <span v-if="row.cells[period.id].spans.length > 1" class="normal-case">
                                                            {{ span.starts_label.date }}&ndash;{{ span.ends_label.date }}
                                                        </span>
                                                    </li>
                                                </ul>
                                                <p v-if="row.cells[period.id].uncovered_days > 0"
                                                   class="mt-0.5 text-xs text-caution"
                                                   :data-testid="`uncovered-${row.person.id}-${period.id}`">
                                                    {{ row.cells[period.id].uncovered_days }}d not yet assigned
                                                </p>
                                            </template>
                                            <p v-else class="channel-tag text-muted">Unassigned</p>

                                            <p v-for="vac in row.cells[period.id].vacations" :key="vac.id"
                                               class="channel-tag mt-0.5"
                                               :data-testid="`leave-${row.person.id}-${period.id}`">
                                                On leave {{ vac.starts_label.date }}&ndash;{{ vac.ends_label.date }}
                                            </p>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!--
                  MR-07. One card per period, reflowing from one column on a phone to three on a
                  wide screen — the same content at every width rather than a second markup, so
                  there is nothing here that can disagree with itself.

                  These are the DEPARTMENT's figures, not the filtered list's: the server computes
                  them from the full grid, stale rows included, before it narrows the rows above.
                -->
                <section v-if="summary" class="space-y-3">
                    <div>
                        <h3 class="text-base font-semibold text-ink">Availability</h3>
                        <p class="text-sm text-muted">
                            How each period is covered, by level and unit — and who is on leave each
                            week. These figures cover the whole department, whatever the filters
                            above are showing.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <article v-for="period in grid.periods" :key="`s-${period.id}`"
                                 :data-testid="`summary-period-${period.id}`"
                                 class="rounded-md border border-line bg-panel p-4">
                            <p class="channel-tag">{{ period.label }}</p>
                            <p class="text-xs text-muted">
                                {{ period.starts_label.date }} ({{ period.starts_label.hijri }}) &ndash; {{ period.ends_label.date }}
                            </p>

                            <template v-if="summaryFor(period.id)">
                                <dl class="mt-3 grid grid-cols-2 gap-2">
                                    <div>
                                        <dt class="channel-tag">Assigned days</dt>
                                        <dd class="readout text-sm text-ink">{{ summaryFor(period.id).assigned_days }}</dd>
                                    </div>
                                    <div>
                                        <dt class="channel-tag">Days not assigned</dt>
                                        <dd class="readout text-sm"
                                            :class="summaryFor(period.id).uncovered_days > 0 ? 'text-caution' : 'text-ink'">
                                            {{ summaryFor(period.id).uncovered_days }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="channel-tag">People with a gap</dt>
                                        <dd class="readout text-sm text-ink">{{ summaryFor(period.id).people_with_a_gap }}</dd>
                                    </div>
                                    <div>
                                        <dt class="channel-tag">Unassigned people</dt>
                                        <dd class="readout text-sm text-ink">{{ summaryFor(period.id).unassigned_people }}</dd>
                                    </div>
                                </dl>

                                <div class="mt-3 overflow-x-auto">
                                    <table class="w-full text-left text-sm">
                                        <thead>
                                            <tr>
                                                <th scope="col" class="channel-tag py-1">Level</th>
                                                <th v-for="unitId in summaryUnitIds(summaryFor(period.id))" :key="unitId"
                                                    scope="col" class="channel-tag py-1">
                                                    {{ unitCode(unitId) }}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="levelRow in summaryLevelRows(summaryFor(period.id))" :key="levelRow.id"
                                                class="border-t border-line-soft">
                                                <th scope="row" class="py-1 font-medium text-body">{{ levelRow.label }}</th>
                                                <td v-for="unitId in summaryUnitIds(summaryFor(period.id))" :key="unitId"
                                                    class="py-1 text-body"
                                                    :data-testid="`summary-cell-${period.id}-${levelRow.id}-${unitId}`">
                                                    <template v-if="levelRow.units[unitId]">
                                                        <span class="readout">{{ levelRow.units[unitId].people }}</span>
                                                        <span class="text-xs text-muted">
                                                            (<span class="readout">{{ levelRow.units[unitId].days }}</span> days)
                                                        </span>
                                                    </template>
                                                    <template v-else>&mdash;</template>
                                                </td>
                                            </tr>
                                            <tr v-if="summaryLevelRows(summaryFor(period.id)).length === 0">
                                                <td class="py-1 text-sm text-muted">Nothing planned in this period yet.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <p class="channel-tag mt-3">On leave, week by week</p>
                                <ul class="mt-1 space-y-0.5">
                                    <li v-for="(week, index) in summaryFor(period.id).weeks" :key="week.starts_on"
                                        class="text-xs text-body"
                                        :data-testid="`summary-week-${period.id}-${index}`">
                                        <span class="readout">{{ weekOf(period, index, week).starts }}</span>
                                        &ndash;
                                        <span class="readout">{{ weekOf(period, index, week).ends }}</span>
                                        <span v-if="weekOf(period, index, week).partial" class="text-muted">(part week)</span>
                                        &middot; {{ week.on_vacation }} on leave
                                    </li>
                                </ul>

                                <!--
                                  Decision D: a person who has left the department is off the list
                                  above, but the cells they still hold are neither counted as cover
                                  nor silently zeroed — counting them would overstate availability,
                                  and hiding them would leave nobody a reason to clear them.
                                -->
                                <p v-if="summaryFor(period.id).stale_assignments > 0"
                                   class="mt-3 text-xs text-caution"
                                   :data-testid="`summary-stale-${period.id}`">
                                    <span class="readout">{{ summaryFor(period.id).stale_assignments }}</span>
                                    assignment(s) here belong to someone no longer on the roster.
                                </p>
                            </template>
                        </article>
                    </div>
                </section>
            </template>
        </div>
    </AppLayout>
</template>
