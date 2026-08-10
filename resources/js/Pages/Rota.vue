<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';

/**
 * The master rota as a resident reads it (Munawib MR-05), at /rota, cap:rota.view.
 *
 * READ-ONLY, AND THAT IS THE FEATURE. No select, no button that writes, no form. The server
 * enforces it too — the whole cap:rota.view route group is GET-only, asserted over the router by
 * RotaAccessTest — but a control that appears and then 403s is worse than no control, so there
 * is none here.
 *
 * THERE IS NO PUBLISH STATE (owner decision 1, 2026-08-10). No status badge, no draft ribbon, no
 * "visible from" note: this screen always shows the current rota, so there is nothing to badge.
 *
 * Every label on this screen is rendered by the server, dual-dated, and this component derives
 * none of its own — the props carry finished strings and enumerated ranges, and the client's job
 * is to place them (design Decision A / ST-06).
 *
 * SCOPE OF THIS FILE, HONESTLY STATED: P1d-2 Task 3 shipped the server half of MR-05 (the route,
 * the controller, the contact-free projection) and this minimal screen alongside it, so the route
 * it registers renders something rather than 500ing in a deployed tree. Task 4 replaces this with
 * the real one: search, the level filter, the per-person period strip, the availability summary
 * panel below it, and mobile cards beside the desktop table. The search and level filter are
 * ALREADY applied server-side — `filters` echoes what was used — they simply have no input here
 * yet.
 */
const props = defineProps({
    academic_years: { type: Array, default: () => [] },
    year: { type: String, default: null },
    grid: { type: Object, default: null },
    summary: { type: Object, default: null },
    filters: { type: Object, default: () => ({ q: '', level: null }) },
});

const selectedYear = ref(props.year ?? '');

const changeYear = () => {
    router.get('/rota', selectedYear.value ? { year: selectedYear.value } : {}, {
        preserveScroll: true,
    });
};

const unitCodesFor = (cell) => (cell?.spans ?? []).map((span) => span.unit_code).filter(Boolean);
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
                                @change="changeYear">
                            <option value="">Choose a year&hellip;</option>
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

            <section v-else class="overflow-x-auto rounded-md border border-line bg-panel">
                <table class="w-full min-w-max text-left text-sm">
                    <thead>
                        <tr class="border-b border-line">
                            <th scope="col" class="px-3 py-2 font-semibold text-ink">Person</th>
                            <th v-for="period in grid.periods" :key="period.id" scope="col"
                                class="px-3 py-2 font-semibold text-ink">
                                {{ period.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in grid.rows" :key="row.person.id" class="border-b border-line last:border-b-0">
                            <th scope="row" class="px-3 py-2 font-medium text-body">{{ row.person.full_name }}</th>
                            <td v-for="period in grid.periods" :key="period.id" class="px-3 py-2 text-body">
                                <span v-for="code in unitCodesFor(row.cells[period.id])" :key="code"
                                      class="channel-tag mr-1 inline-block">{{ code }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
