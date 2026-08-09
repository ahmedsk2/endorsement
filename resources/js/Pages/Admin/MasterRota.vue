<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin -> Master Rota (Munawib MR-02/MR-03), cap:rota.manage.
 *
 * P1d-1 Task 1 ships the route and its capability gate only. `grid` is always `null` here — the
 * person-rows x period-columns editor (Task 8) does not exist yet. What this screen DOES do is
 * answer the one honest question an administrator can ask it today: "are there any academic
 * years to plan against" — because the rota's columns ARE periods (P1b), a department with none
 * generated yet has nowhere to put an assignment. A teaching empty state names exactly where to
 * go (Structure -> Periods) rather than showing a blank grid with no explanation.
 *
 * No date arithmetic happens here — `academic_years` is a list of opaque academic-year labels
 * the server already formed; nothing on this screen parses or formats a date client-side.
 */
defineProps({
    academic_years: { type: Array, default: () => [] },
    grid: { type: Object, default: null },
});
</script>

<template>
    <AppLayout title="Master Rota">
        <div class="mx-auto max-w-3xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Master rota</h2>
                <p class="text-sm text-muted">
                    Plan which unit each person rotates through, period by period, for an
                    academic year.
                </p>
            </div>

            <section v-if="academic_years.length === 0" class="rounded-md border border-line bg-panel p-6 text-center">
                <p class="text-sm font-semibold text-ink">No academic years yet</p>
                <p class="mx-auto mt-1 max-w-sm text-sm text-muted">
                    The master rota's columns are the periods of an academic year. Generate one
                    first on Structure &rarr; Periods, then come back here to plan it.
                </p>
                <Link href="/admin/structure/periods"
                      class="mt-4 inline-block min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white">
                    Go to Structure &rarr; Periods
                </Link>
            </section>

            <section v-else class="rounded-md border border-line bg-panel p-6">
                <p class="channel-tag mb-2">Academic years</p>
                <ul class="space-y-1 text-sm text-body">
                    <li v-for="year in academic_years" :key="year" class="readout">{{ year }}</li>
                </ul>
                <p class="mt-4 text-sm text-muted">
                    The rota grid itself is coming soon.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
