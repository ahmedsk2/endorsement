<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin -> Structure -> Calendar (Munawib ST-02).
 *
 * FINDING 1: every save here flushes App\Support\Calendar's memo server-side
 * (CalendarSettingsController::update()) — this screen carries no client-side proof of that
 * beyond the usual "Saved." + reload discipline every admin write in this plan follows.
 *
 * DECISION D: `period_type` and `academic_year_start` are `:disabled="locked"` the moment ANY
 * period has been generated (Admin -> Structure -> Periods) — the server refuses a change to
 * either with the same message even if this disabled attribute were ever bypassed.
 *
 * Owner decision 3 / P1 finding 5: NO timezone field. `instance_timezone` is a read-only
 * display value; the deployment's timezone is `APP_TIMEZONE`, set once at deployment, never
 * per-department.
 *
 * Decision A (this module's own convention, not the ladder's): every date here — `today` — is
 * a label the SERVER built (`Calendar::label()`). This screen performs no date arithmetic.
 */
const props = defineProps({
    form: { type: Object, required: true },
    hijri_offset_bounds: { type: Array, default: () => [-2, 2] },
    weekday_options: { type: Object, default: () => ({}) },
    period_type_options: { type: Object, default: () => ({}) },
    locked: { type: Boolean, default: false },
    instance_timezone: { type: String, default: '' },
    today: { type: Object, default: () => ({ date: '', hijri: '', weekend: false, holiday: null, day_type: 'WD' }) },
});

const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none disabled:opacity-60';

const splitWeeks = (value) => value.split(',').map((v) => parseInt(v.trim(), 10)).filter((v) => Number.isInteger(v));

const form = useForm({
    hijri_enabled: props.form.hijri_enabled,
    hijri_offset_days: props.form.hijri_offset_days,
    weekend_days: [...props.form.weekend_days],
    period_type: props.form.period_type,
    block_weeks: props.form.block_weeks.join(', '),
    academic_year_start: props.form.academic_year_start ?? '',
});

const weekdayEntries = computed(() => Object.entries(props.weekday_options));
const periodTypeEntries = computed(() => Object.entries(props.period_type_options));

const isWeekendDay = (day) => form.weekend_days.includes(day);
const toggleWeekendDay = (day) => {
    form.weekend_days = isWeekendDay(day)
        ? form.weekend_days.filter((d) => d !== day)
        : [...form.weekend_days, day];
};

const submit = () => {
    form.transform((data) => ({ ...data, block_weeks: splitWeeks(data.block_weeks) }))
        .put('/admin/structure/calendar', { preserveScroll: true });
};
</script>

<template>
    <AppLayout title="Calendar">
        <div class="mx-auto max-w-2xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Calendar settings</h2>
                <p class="text-sm text-muted">
                    The department's shared calendar — Hijri display, the weekend, and how a
                    year is divided into rota periods.
                </p>
            </div>

            <!-- Today, rendered from the server (UX-04) — proves the calibration lands. -->
            <section class="channel-bar rounded-md border border-line bg-panel p-5">
                <h3 class="mb-1 text-sm font-semibold text-ink">Today</h3>
                <p class="readout text-sm text-body">
                    {{ today.date }}<span v-if="today.hijri"> &middot; {{ today.hijri }}</span>
                </p>
                <p class="mt-1 text-xs text-muted">
                    Verify against the department's own published calendar across a month
                    boundary before trusting a Hijri date on screen.
                </p>
            </section>

            <form class="space-y-6" @submit.prevent="submit">
                <!-- Hijri display -->
                <section class="channel-bar rounded-md border border-line bg-panel p-5">
                    <h3 class="mb-3 text-sm font-semibold text-ink">Hijri display</h3>
                    <label class="mb-3 flex items-center gap-2 text-sm text-body">
                        <input v-model="form.hijri_enabled" type="checkbox" />
                        Show Hijri dates alongside Gregorian ones
                    </label>
                    <div class="max-w-xs">
                        <label class="channel-tag mb-1 block" for="hijri-offset">
                            Calibration offset (days)
                        </label>
                        <input id="hijri-offset" v-model.number="form.hijri_offset_days" type="number"
                               :min="hijri_offset_bounds[0]" :max="hijri_offset_bounds[1]" class="readout" :class="inputClass" />
                        <p class="mt-1 text-xs text-muted">
                            Between {{ hijri_offset_bounds[0] }} and {{ hijri_offset_bounds[1] }}. An
                            offset that large is a wrong timezone or a wrong hospital, not a
                            calibration.
                        </p>
                        <p v-if="form.errors.hijri_offset_days" class="mt-1 text-xs text-critical">{{ form.errors.hijri_offset_days }}</p>
                    </div>
                </section>

                <!-- Weekend days -->
                <section class="channel-bar rounded-md border border-line bg-panel p-5">
                    <h3 class="mb-3 text-sm font-semibold text-ink">Weekend days</h3>
                    <div class="flex flex-wrap gap-4">
                        <label v-for="[num, label] in weekdayEntries" :key="num" class="flex items-center gap-2 text-sm text-body">
                            <input type="checkbox" :checked="isWeekendDay(Number(num))" @change="toggleWeekendDay(Number(num))" />
                            {{ label }}
                        </label>
                    </div>
                    <p v-if="form.errors.weekend_days" class="mt-1 text-xs text-critical">{{ form.errors.weekend_days }}</p>
                </section>

                <!-- Periods -->
                <section class="channel-bar rounded-md border border-line bg-panel p-5">
                    <h3 class="mb-1 text-sm font-semibold text-ink">Rota periods</h3>
                    <p v-if="locked" class="mb-3 text-xs text-critical">
                        Periods have already been generated (Structure &rarr; Periods). The period
                        system and the academic year start are locked — delete this academic
                        year's periods first, then change either.
                    </p>
                    <div class="mb-4 flex flex-wrap gap-4">
                        <label v-for="[value, label] in periodTypeEntries" :key="value" class="flex items-center gap-2 text-sm text-body">
                            <input v-model="form.period_type" type="radio" :value="value" :disabled="locked" />
                            {{ label }}
                        </label>
                    </div>
                    <p v-if="form.errors.period_type" class="mb-3 text-xs text-critical">{{ form.errors.period_type }}</p>

                    <div v-if="form.period_type === 'week_blocks'" class="mb-4">
                        <label class="channel-tag mb-1 block" for="block-weeks">Block lengths (weeks, comma separated)</label>
                        <input id="block-weeks" v-model="form.block_weeks" type="text" :disabled="locked" class="readout" :class="inputClass" placeholder="4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5" />
                        <p v-if="form.errors.block_weeks" class="mt-1 text-xs text-critical">{{ form.errors.block_weeks }}</p>
                    </div>

                    <div class="max-w-xs">
                        <label class="channel-tag mb-1 block" for="academic-year-start">Academic year start</label>
                        <input id="academic-year-start" v-model="form.academic_year_start" type="date" :disabled="locked" class="readout" :class="inputClass" />
                        <p v-if="form.period_type === 'months'" class="mt-1 text-xs text-muted">
                            A calendar-month period system must begin on the first of a month.
                        </p>
                        <p v-if="form.errors.academic_year_start" class="mt-1 text-xs text-critical">{{ form.errors.academic_year_start }}</p>
                    </div>
                </section>

                <!-- Instance timezone: read-only, no input, deliberately -->
                <section class="channel-bar rounded-md border border-line bg-panel p-5">
                    <h3 class="mb-1 text-sm font-semibold text-ink">Instance timezone</h3>
                    <p class="readout text-sm text-body">{{ instance_timezone }}</p>
                    <p class="mt-1 text-xs text-muted">
                        Set once at deployment for this instance, not per department — there is
                        no field here to change it.
                    </p>
                </section>

                <div class="flex items-center justify-end gap-3">
                    <span v-if="form.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                    <button type="submit" :disabled="form.processing" data-testid="save-calendar-settings"
                            class="rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                        Save calendar settings
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
