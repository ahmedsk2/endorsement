<script setup>
import { computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * The department's week of clinics (Munawib CL-05), at /clinics, cap:clinics.view.
 *
 * READ-ONLY, AND THAT IS THE FEATURE. Nothing on this screen writes: no form, no picker, no
 * button that sends anything at all. The server enforces it too — the whole cap:clinics.view route
 * group is GET-only, asserted over the ROUTER by ClinicMapTest — but a control that appears and
 * then 403s is worse than no control, so there is none here. Defining a clinic lives on
 * Admin → Structure → Clinics, behind structure.manage.
 *
 * THIS COMPONENT NAMES NO DAY OF THE WEEK. The `weekdays` prop arrives from
 * App\Support\Calendar::weekdayColumns() already labelled AND already rotated into the order this
 * department's week runs in, so a department whose weekend is Friday-Saturday reads its columns
 * starting one day earlier than one whose weekend is Saturday-Sunday, from the same stored ISO
 * integers with nothing rewritten. A literal array of seven day names here would be a second
 * answer to "what order does the week run in", and CalendarIsTheOnlyConverterTest scans the whole
 * of resources/js for exactly that, with no allow-list.
 *
 * THIS COMPONENT COMPUTES NO DATE EITHER, and there is no date in its props to compute from. A
 * clinic is a weekly recurrence; the map shows the week, not a day.
 *
 * IT NAMES NO COLLEAGUE. "Who attends" arrives as a RULE — a mode label, plus the training-level
 * codes for a level-refined clinic — never as a resolved roster, so no person-shaped value is in
 * these props to render or to withhold. Nothing here needs to gate on the viewer, because nothing
 * here HAS a viewer-dependent field.
 *
 * IT NAMES NO UNIT EITHER. `rows` is the units that own clinics, from the units table, so a fifth
 * department appears here with no frontend change and its channel hue comes from its own row.
 *
 * IT ANSWERS NO QUESTION ABOUT AVAILABILITY OR COVERAGE, AND MUST NOT LEARN TO. CL-04 — personal
 * schedules, feeds, the on-now board, "who can cover Tuesday morning" — is P3, and MR-04 (the rota
 * driving on-call) is Stage 2. This screen says what the department's week IS; it never says who is
 * free, and it subtracts no leave from anything. It is worth saying here rather than only in the
 * plan because a weekly wall chart is the single most tempting place in the product to add a "who
 * is free" column, and the person tempted will be reading this file. ClinicHooksTest asserts the
 * absence over this file's own source with the comments stripped — which is the only reason this
 * paragraph is allowed to name what it is ruling out, and it is calibrated against these very
 * words: a stripper that stopped working would fail the build on this sentence.
 *
 * FREE TEXT IS INTERPOLATED, AND ONLY INTERPOLATED. A clinic's name, location and note are plain
 * text stored verbatim — never purified server-side, exactly like handovers.extra_fields — so
 * escaping is this file's job and the double-brace form is how it is done. The raw-markup
 * directive appears nowhere below; ClinicMap.test.js asserts that over this file's own source,
 * which is why it is not spelled out even here.
 *
 * MOBILE FIRST, THEN THE GRID. Below `lg` the week is one card per day — which is the question a
 * reader on a phone actually has ("what is on Tuesday?") rather than the one a wall chart answers
 * — listing every unit's clinics under that day, per session. From `lg` up it is the wall chart:
 * one row per unit, one column per day, each cell split by session. Both are in the DOM and CSS
 * hides one, the same shape MasterRota.vue and Rota.vue use.
 */
const props = defineProps({
    weekdays: { type: Array, default: () => [] },
    sessions: { type: Object, default: () => ({}) },
    rows: { type: Array, default: () => [] },
});

const sessionKeys = computed(() => Object.keys(props.sessions));

// The wall chart's width varies with nothing but the department's week, which is always seven —
// computed rather than a hardcoded colspan, so a future column cannot leave the empty-state row
// spanning the wrong width.
const desktopColumnCount = computed(() => 1 + props.weekdays.length);

const cellKey = (iso, session) => `${iso}-${session}`;

/** The sessions that actually have something in this unit's cell for this day, in the server's order. */
const cellSessions = (row, iso) => sessionKeys.value
    .map((key) => ({ key, label: props.sessions[key], clinics: row.cells?.[cellKey(iso, key)] ?? [] }))
    .filter((entry) => entry.clinics.length > 0);

/**
 * The same clinics, pivoted for the phone: day, then session, then every unit's clinics under it.
 * A day with nothing on it is KEPT rather than filtered out, so the week keeps its shape and a
 * reader can see that Thursday really is clear rather than wondering whether it failed to load.
 */
const byDay = computed(() => props.weekdays.map((day) => ({
    day,
    sessions: sessionKeys.value
        .map((key) => ({
            key,
            label: props.sessions[key],
            entries: props.rows.flatMap((row) => (row.cells?.[cellKey(day.iso, key)] ?? [])
                .map((clinic) => ({ clinic, unit: row.unit }))),
        }))
        .filter((entry) => entry.entries.length > 0),
})));

const clinicCount = computed(() => props.rows.reduce(
    (total, row) => total + Object.values(row.cells ?? {}).reduce((sum, cell) => sum + cell.length, 0),
    0,
));
</script>

<template>
    <AppLayout title="Clinics">
        <div class="mx-auto max-w-full space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Clinics</h2>
                <p class="text-sm text-muted">
                    The department's week: which unit runs which clinic, on which day, morning or
                    afternoon. The week below runs in this department's own order.
                </p>
            </div>

            <section v-if="!rows.length" class="rounded-md border border-line bg-panel p-6 text-center"
                     data-testid="map-no-units">
                <p class="text-sm font-semibold text-ink">No clinics yet</p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-muted">
                    No unit runs clinics at the moment. This page will show them once one does.
                </p>
            </section>

            <section v-else-if="clinicCount === 0" class="rounded-md border border-line bg-panel p-6 text-center"
                     data-testid="map-no-clinics">
                <p class="text-sm text-body">Nothing has been added to the clinic timetable yet.</p>
            </section>

            <template v-else>
                <!-- Phone: the week, one card per day. -->
                <div class="space-y-4 lg:hidden" data-testid="map-cards">
                    <article v-for="entry in byDay" :key="`m-${entry.day.iso}`"
                             :data-day-iso="entry.day.iso"
                             class="rounded-md border border-line bg-panel p-4">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-sm font-semibold text-ink">{{ entry.day.label }}</p>
                            <span v-if="entry.day.weekend" class="channel-tag">Weekend</span>
                        </div>

                        <p v-if="!entry.sessions.length" class="mt-2 text-xs text-muted">No clinics.</p>

                        <div v-for="session in entry.sessions" :key="`m-${entry.day.iso}-${session.key}`"
                             class="mt-3 rounded-md border border-line-soft p-3">
                            <p class="channel-tag">{{ session.label }}</p>
                            <div v-for="item in session.entries" :key="`m-${item.clinic.id}`"
                                 :data-clinic-id="item.clinic.id"
                                 class="channel-bar mt-2 rounded-sm px-2 py-1" :class="item.unit.bar_class">
                                <p class="text-sm text-ink">
                                    <span class="readout font-semibold">{{ item.unit.code }}</span>
                                    <span class="ml-2">{{ item.clinic.name }}</span>
                                </p>
                                <p v-if="item.clinic.location" class="text-xs text-body">{{ item.clinic.location }}</p>
                                <p class="text-xs text-muted">
                                    {{ item.clinic.who }}<template v-if="item.clinic.who_detail"> &middot; {{ item.clinic.who_detail }}</template>
                                </p>
                                <p v-if="item.clinic.note" class="text-xs text-muted">{{ item.clinic.note }}</p>
                            </div>
                        </div>
                    </article>
                </div>

                <!-- Desktop: the wall chart, one row per unit and one column per day. -->
                <div class="hidden overflow-x-auto rounded-md border border-line bg-panel lg:block"
                     data-testid="map-table">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-ground-deep">
                            <tr>
                                <th scope="col" class="channel-tag px-4 py-2">Unit</th>
                                <th v-for="day in weekdays" :key="day.iso" scope="col"
                                    :data-col-iso="day.iso" class="channel-tag px-3 py-2">
                                    <span class="block">{{ day.label }}</span>
                                    <span v-if="day.weekend" class="block text-xs font-normal normal-case text-muted">
                                        Weekend
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.unit.id" :data-row-id="`unit-${row.unit.id}`"
                                class="border-t border-line">
                                <th scope="row" class="channel-bar px-4 py-2 text-left align-top"
                                    :class="row.unit.bar_class">
                                    <span class="readout text-sm font-semibold text-ink">{{ row.unit.code }}</span>
                                    <span class="block text-xs font-normal text-muted">{{ row.unit.name }}</span>
                                </th>
                                <td v-for="day in weekdays" :key="`${row.unit.id}-${day.iso}`"
                                    :data-cell-key="`${row.unit.id}-${day.iso}`"
                                    class="px-3 py-2 align-top">
                                    <p v-if="!cellSessions(row, day.iso).length" class="channel-tag text-muted">&mdash;</p>
                                    <div v-for="session in cellSessions(row, day.iso)"
                                         :key="`${row.unit.id}-${day.iso}-${session.key}`" class="mb-2 last:mb-0">
                                        <p class="channel-tag">{{ session.label }}</p>
                                        <div v-for="clinic in session.clinics" :key="clinic.id"
                                             :data-clinic-id="clinic.id" class="mt-0.5">
                                            <p class="text-sm text-ink">{{ clinic.name }}</p>
                                            <p v-if="clinic.location" class="text-xs text-body">{{ clinic.location }}</p>
                                            <p class="text-xs text-muted">
                                                {{ clinic.who }}<template v-if="clinic.who_detail"> &middot; {{ clinic.who_detail }}</template>
                                            </p>
                                            <p v-if="clinic.note" class="text-xs text-muted">{{ clinic.note }}</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td :colspan="desktopColumnCount" class="px-4 py-4 text-sm text-muted">
                                    No unit runs clinics at the moment.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
