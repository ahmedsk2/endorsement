<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin -> Structure -> Periods (Munawib MR-01). Finding 4: this screen is the FIRST
 * production caller PeriodGenerator has ever had — preview only leaves `periods` permanently
 * empty, so this ships preview AND generate-and-commit AND delete-a-year (Decision D's unlock).
 *
 * Every date on this screen is a label the SERVER built (`Calendar::label()`,
 * `App\Http\Controllers\Admin\PeriodController::preview()`) — no date arithmetic happens here.
 */
const props = defineProps({
    settings: { type: Object, required: true },
    next_year_start: { type: String, default: null },
    preview: { type: Object, default: null },
    generate_disabled: { type: Boolean, default: false },
    years: { type: Array, default: () => [] },
});

const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';

// Re-requesting the SAME page with a query string is a GET-shaped read (no writes) — the
// server recomputes the preview; the browser performs no date arithmetic of its own.
const nextYearStartInput = ref(props.next_year_start ?? '');
const refreshPreview = () => {
    router.get('/admin/structure/periods',
        nextYearStartInput.value ? { next_year_start: nextYearStartInput.value } : {},
        { preserveState: true, preserveScroll: true, replace: true });
};

const generateForm = useForm({ next_year_start: '' });
const generate = () => {
    generateForm.next_year_start = nextYearStartInput.value;
    generateForm.post('/admin/structure/periods', { preserveScroll: true });
};

const deleteForms = {};
const deleteConfirm = ref({});
const deleteYear = (year) => {
    if (!deleteForms[year]) {
        deleteForms[year] = useForm({ confirm_academic_year: '' });
    }
    const form = deleteForms[year];
    form.confirm_academic_year = deleteConfirm.value[year] ?? '';
    form.delete(`/admin/structure/periods/${encodeURIComponent(year)}`, {
        preserveScroll: true,
        onSuccess: () => { deleteConfirm.value[year] = ''; },
    });
};
const errorsFor = (year) => deleteForms[year]?.errors ?? {};

const totalDaysLabel = computed(() => (props.preview ? `${props.preview.total_days} days` : ''));
</script>

<template>
    <AppLayout title="Periods">
        <div class="mx-auto max-w-3xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Rota periods</h2>
                <p class="text-sm text-muted">
                    Turns the department's configured calendar
                    (<Link href="/admin/structure/calendar" class="underline">Structure &rarr; Calendar</Link>)
                    into the year's actual periods. Currently: <span class="readout">{{ settings.period_type }}</span>,
                    starting <span class="readout">{{ settings.academic_year_start ?? 'not set' }}</span>.
                </p>
            </div>

            <section v-if="!settings.academic_year_start" class="channel-bar channel-bar-caution rounded-md border border-line bg-caution-soft p-5 text-sm text-caution">
                Set an academic year start on the Calendar settings screen before generating periods.
            </section>

            <section v-else class="channel-bar rounded-md border border-line bg-panel p-5">
                <h3 class="mb-3 text-sm font-semibold text-ink">Preview: {{ preview?.periods?.length ?? 0 }} periods, {{ totalDaysLabel }}</h3>

                <div v-if="settings.period_type === 'week_blocks'" class="mb-4 max-w-xs">
                    <label class="channel-tag mb-1 block" for="next-year-start">Next academic year's start (optional)</label>
                    <input id="next-year-start" v-model="nextYearStartInput" type="date" :class="inputClass" @change="refreshPreview" />
                    <p v-if="preview?.used_fallback_block" class="mt-1 text-xs text-muted">
                        No next-year start given — the final block falls back to its nominal length.
                        This is a preview convenience; regenerate once the next year's start is known.
                    </p>
                </div>

                <div v-if="preview?.warnings?.length" class="mb-4 space-y-2">
                    <p v-for="(warning, i) in preview.warnings" :key="i"
                       class="channel-bar-caution rounded-md bg-caution-soft px-3 py-2 text-sm text-caution">
                        {{ warning }}
                    </p>
                </div>

                <div class="overflow-x-auto rounded-md border border-line">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-ground-deep">
                            <tr>
                                <th scope="col" class="channel-tag px-3 py-2">#</th>
                                <th scope="col" class="channel-tag px-3 py-2">Label</th>
                                <th scope="col" class="channel-tag px-3 py-2">Starts</th>
                                <th scope="col" class="channel-tag px-3 py-2">Ends</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="period in preview?.periods ?? []" :key="period.position" class="border-t border-line">
                                <td class="readout px-3 py-2 text-body">{{ period.position }}</td>
                                <td class="px-3 py-2 text-body">{{ period.label }}</td>
                                <td class="readout px-3 py-2 text-body">
                                    {{ period.starts_label.date }}<span v-if="period.starts_label.hijri"> &middot; {{ period.starts_label.hijri }}</span>
                                </td>
                                <td class="readout px-3 py-2 text-body">
                                    {{ period.ends_label.date }}<span v-if="period.ends_label.hijri"> &middot; {{ period.ends_label.hijri }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <button type="button" :disabled="generate_disabled || generateForm.processing"
                            data-testid="generate-periods"
                            class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                            @click="generate">
                        Generate and save this year
                    </button>
                    <span v-if="generateForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                </div>
                <p v-if="generateForm.errors.next_year_start" class="mt-2 text-xs text-critical">{{ generateForm.errors.next_year_start }}</p>
            </section>

            <section class="channel-bar rounded-md border border-line bg-panel p-5">
                <h3 class="mb-3 text-sm font-semibold text-ink">Generated academic years</h3>
                <p v-if="!years.length" class="text-sm text-muted">No periods have been generated yet.</p>
                <ul v-else class="space-y-4">
                    <li v-for="year in years" :key="year.academic_year" class="rounded-md border border-line p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="readout text-sm font-semibold text-ink">{{ year.academic_year }}</span>
                            <span class="channel-tag">{{ year.count }} periods &middot; {{ year.starts_on }} – {{ year.ends_on }}</span>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <input v-model="deleteConfirm[year.academic_year]" type="text" :class="inputClass" class="max-w-xs"
                                   :placeholder="`Type &quot;${year.academic_year}&quot; to delete`" />
                            <button type="button" class="min-h-11 rounded-md border border-critical px-3 py-1.5 text-sm font-semibold text-critical"
                                    @click="deleteYear(year.academic_year)">
                                Delete this year's periods
                            </button>
                        </div>
                        <p v-if="errorsFor(year.academic_year).confirm_academic_year" class="mt-1 text-xs text-critical">
                            {{ errorsFor(year.academic_year).confirm_academic_year }}
                        </p>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
