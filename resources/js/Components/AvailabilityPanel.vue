<script setup>
import { computed } from 'vue';

/**
 * Munawib MR-07 — "per-period availability summary per level and unit, including who is on
 * vacation each week" — rendered once, for both rota surfaces.
 *
 * ONE COMPONENT, TWO SURFACES, AND THAT IS THE WHOLE POINT. `App\Support\Rota\AvailabilitySummary`
 * already made the COMPUTATION single (P1d-2 Decision B: one pure fold over the grid, no queries,
 * no hidden inputs). This file makes the RENDERING single too. The read view (`/rota`, MR-05) and
 * the editor (`/admin/rota`, MR-02) both mount this component with the same four props, and
 * `tests/js/AvailabilityPanel.test.js` mounts both PAGES and compares the markup this subtree
 * produces on each. Two screens showing a department two different answers to "how many R2s are on
 * PICU in Block 11" is the failure class this arrangement exists to make impossible — not merely
 * unlikely.
 *
 * IT DERIVES NOTHING. Every figure below arrives computed. Nothing here sums, counts, averages or
 * re-buckets; the helpers only decide what order things appear in and which label goes beside
 * them. If a number is wanted that is not in `summary`, it belongs in `AvailabilitySummary`, where
 * both surfaces get it at once and a test can pin it — never here, where one surface would get it.
 *
 * IT CONVERTS NO DATE. Not one. The week strip places `starts_label`/`ends_label` pairs the server
 * built (`Calendar::weekOf()` via `Calendar::weeksIn()`, the one converter — design Decision A /
 * ST-06), and decides "part week" by comparing two already-formatted `Y-m-d` strings for equality.
 * That is string comparison, not date arithmetic, and it is the same idiom `AvailabilitySummary`
 * itself uses server-side.
 *
 * IT TAKES NO ROWS, DELIBERATELY. The read view filters its `grid.rows` for display and the editor
 * does not (Decision D), so a panel that read rows would render differently on the two screens for
 * a reason that has nothing to do with the numbers. It is handed `periods`, `levels` and `units`
 * and never the row list, which makes the parity property structural rather than a thing to
 * remember.
 *
 * IT OFFERS NO CONTROL. No button, no select, no input, no link. `/rota` is GET-only at the router
 * and a control that appears there could only 403 — and a component shared with a read-only screen
 * is exactly where a write affordance would arrive without anybody meaning it to. Asserted on both
 * mounts.
 *
 * IT NAMES NOBODY. `AvailabilitySummary` emits ids and counts only, so there is no name, email or
 * phone in these props for this panel to render or withhold. `rota.view` is seeded for every
 * authenticated position, so this is read by the entire department.
 */
const props = defineProps({
    /** `grid.periods` — labels, dual-dated bounds, and the week strip each week's label comes from. */
    periods: { type: Array, default: () => [] },
    /** `grid.levels`, in the ladder's display order — the order the level rows appear in. */
    levels: { type: Array, default: () => [] },
    /** `grid.units` — the only source of a unit's code here. */
    units: { type: Array, default: () => [] },
    /** `AvailabilitySummary::forGrid()` output, keyed by period id. Null renders nothing at all. */
    summary: { type: Object, default: null },
});

const unitsById = computed(() => {
    const map = {};
    props.units.forEach((unit) => { map[unit.id] = unit; });
    return map;
});

const levelsById = computed(() => {
    const map = {};
    props.levels.forEach((level) => { map[level.id] = level; });
    return map;
});

/**
 * A unit RETIRED since the rota was planned is not in `units` — the grid offers active units only
 * — and its spans carry a null `unit_code` for the same reason, so there is genuinely no code
 * anywhere to fall back to. The column is marked rather than invented: a bare id in a column head
 * reads as a ward name, and the days under it are still real and still counted.
 */
const unitCode = (unitId) => unitsById.value[unitId]?.code ?? '—';

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

    props.levels.forEach((level) => {
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
 * The dates shown are the TRUE week bounds, which is what the server labelled; a week the block
 * does not fully contain is marked instead of being silently trimmed to a range with no label of
 * its own. The comparison below is string equality between two already-formatted values, which is
 * how this screen can say "this week is only partly inside the block" without going near a
 * calendar.
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
    <!--
      One card per period, reflowing from one column on a phone to three on a wide screen — the
      same content at every width rather than a second markup, so there is nothing here that can
      disagree with itself.

      These are the DEPARTMENT's figures, not the filtered list's: the server computes them from
      the full grid, stale rows included, before either screen narrows anything.
    -->
    <section v-if="summary" class="space-y-3" data-testid="availability-panel">
        <div>
            <h3 class="text-base font-semibold text-ink">Availability</h3>
            <p class="text-sm text-muted">
                How each period is covered, by level and unit — and who is on leave each week.
                These figures cover the whole department, whatever is being shown above.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="period in periods" :key="`s-${period.id}`"
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
                      Decision D: a person who has left the department is off the read view's list,
                      but the cells they still hold are neither counted as cover nor silently
                      zeroed — counting them would overstate availability, and hiding them would
                      leave nobody a reason to clear them. On the EDITOR this number is a to-do
                      with a control beside it; on the read view it is a fact about the year.

                      PEOPLE, not assignments (finding 5). The server counts period-cells, and in
                      one period a cell is one person; this used to say "N assignment(s)" over that
                      count, which made one departed person's three-way split read as three. It is
                      also the number of Clear controls somebody has to press, which a span count
                      would overstate.
                    -->
                    <p v-if="summaryFor(period.id).stale_people > 0"
                       class="mt-3 text-xs text-caution"
                       :data-testid="`summary-stale-${period.id}`">
                        <span class="readout">{{ summaryFor(period.id).stale_people }}</span>
                        person(s) no longer on the roster still hold assignments in this period.
                    </p>
                </template>
            </article>
        </div>
    </section>
</template>
