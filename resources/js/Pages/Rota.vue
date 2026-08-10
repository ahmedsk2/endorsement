<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import AvailabilityPanel from '../Components/AvailabilityPanel.vue';

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

/**
 * A unit RETIRED since the rota was planned is not in `grid.units` — the grid offers active units
 * only — and there is nothing to fall back to, so the span is marked rather than labelled with an
 * invented name. Task 4 shipped a `span.unit_code` fallback here on the belief that a historical
 * span carried its own code; it does not. `RotaGrid::cellFor()` reads that field out of the SAME
 * active-units map this one is built from (`'unit_code' => $unit?->code`, where `$unitsById` comes
 * from `Unit::query()->active()`), so it is null in exactly the case the fallback existed to
 * cover, and non-null only where this lookup already succeeds. It was removed rather than left
 * as unreachable code with a rationale that reads as true. Task 5's `AvailabilityPanel` resolves
 * a unit code the same way, so the strip and the summary beneath it cannot label one unit two
 * ways.
 */
const unitCode = (unitId) => unitsById.value[unitId]?.code ?? '—';

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

// MR-07's summary panel is `Components/AvailabilityPanel.vue`, and it is the SAME component the
// editor mounts (Task 5). It was inline here for exactly one task; the moment a second surface
// needed the same numbers, a copy would have been two renderings of one computation, free to drift
// on a screen a department reads as authoritative. `tests/js/AvailabilityPanel.test.js` mounts both
// pages and compares the markup, so the copy cannot come back unnoticed.
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
                  MR-07, the department's availability, rendered by the SAME component the editor
                  mounts (Task 5). It is handed the grid's periods, levels and units — never the
                  rows, which this screen has already filtered and the editor has not.
                -->
                <AvailabilityPanel :periods="grid.periods" :levels="grid.levels" :units="grid.units"
                                   :summary="summary" />
            </template>
        </div>
    </AppLayout>
</template>
