<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin -> Structure -> Holidays (Munawib §30). P1a's own docblock said "the CRUD screen is
 * P1b" — this is it. The resolver semantics (Hijri-through-the-offset, holiday-beats-weekend,
 * excluded from MissedDays) are all already implemented and tested; this screen only edits the
 * rows those resolvers read.
 *
 * Every resolved date (`occurrences`) is built server-side (`HolidayController::resolve()`,
 * `Calendar::label()`) — a rule stored as "Hijri 10/1" means nothing here until the server says
 * what Gregorian date it lands on.
 */
const props = defineProps({
    holidays: { type: Array, default: () => [] },
});

const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';

const calendarOptions = [
    { value: 'gregorian', label: 'Gregorian' },
    { value: 'hijri', label: 'Hijri' },
];

const blankForm = () => ({
    name: '', calendar: 'gregorian', month: 1, day: 1, year: '',
    duration_days: 1, equity_tracked: true, active: true,
});

// --- Create -----------------------------------------------------------------------------

const createOpen = ref(false);
const createForm = useForm(blankForm());

const submitCreate = () => {
    createForm.transform((data) => ({ ...data, year: data.year === '' ? null : data.year }))
        .post('/admin/structure/holidays', {
            preserveScroll: true,
            onSuccess: () => { createForm.reset(); createOpen.value = false; },
        });
};

// --- Edit (one rule open at a time) ------------------------------------------------------

const editingId = ref(null);
const editForm = useForm(blankForm());

const startEdit = (holiday) => {
    editingId.value = holiday.id;
    editForm.clearErrors();
    editForm.name = holiday.name;
    editForm.calendar = holiday.calendar;
    editForm.month = holiday.month;
    editForm.day = holiday.day;
    editForm.year = holiday.year ?? '';
    editForm.duration_days = holiday.duration_days;
    editForm.equity_tracked = holiday.equity_tracked;
    editForm.active = holiday.active;
};

const cancelEdit = () => { editingId.value = null; editForm.clearErrors(); };

const submitEdit = (holiday) => {
    editForm.transform((data) => ({ ...data, year: data.year === '' ? null : data.year }))
        .patch(`/admin/structure/holidays/${holiday.id}`, {
            preserveScroll: true,
            onSuccess: () => { editingId.value = null; },
        });
};

// --- Retire / reactivate ------------------------------------------------------------------

const activeForm = useForm({ active: true });
const toggleActive = (holiday) => {
    activeForm.active = !holiday.active;
    activeForm.patch(`/admin/structure/holidays/${holiday.id}/active`, { preserveScroll: true });
};

const occurrenceLabel = (occurrence) => {
    const start = occurrence.starts_on;
    const end = occurrence.ends_on;
    const range = start.date === end.date ? start.date : `${start.date} – ${end.date}`;

    return start.hijri ? `${range} (${start.hijri})` : range;
};
</script>

<template>
    <AppLayout title="Holidays">
        <div class="mx-auto max-w-4xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Holidays</h2>
                <p class="text-sm text-muted">
                    Recurring or one-off holiday rules, in either calendar. A Hijri rule resolves
                    through the department's calibration on the
                    <a href="/admin/structure/calendar" class="underline">Calendar settings</a>
                    screen — its resolved date below moves if that calibration changes. A holiday
                    beats a weekend day when both apply.
                </p>
            </div>

            <!-- New holiday -->
            <section class="rounded-md border border-line bg-panel p-5">
                <button type="button" class="text-sm font-semibold text-ink" @click="createOpen = !createOpen">
                    {{ createOpen ? '– Hide' : '+ New holiday' }}
                </button>

                <form v-if="createOpen" class="mt-4 space-y-4" @submit.prevent="submitCreate">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="channel-tag mb-1 block" for="new-name">Name</label>
                            <input id="new-name" v-model="createForm.name" type="text" :class="inputClass" />
                            <p v-if="createForm.errors.name" class="mt-1 text-xs text-critical">{{ createForm.errors.name }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-calendar">Calendar</label>
                            <select id="new-calendar" v-model="createForm.calendar" :class="inputClass">
                                <option v-for="opt in calendarOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <p v-if="createForm.errors.calendar" class="mt-1 text-xs text-critical">{{ createForm.errors.calendar }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="channel-tag mb-1 block" for="new-month">Month</label>
                                <input id="new-month" v-model.number="createForm.month" type="number" min="1" max="12" class="readout" :class="inputClass" />
                                <p v-if="createForm.errors.month" class="mt-1 text-xs text-critical">{{ createForm.errors.month }}</p>
                            </div>
                            <div>
                                <label class="channel-tag mb-1 block" for="new-day">Day</label>
                                <input id="new-day" v-model.number="createForm.day" type="number" min="1" max="31" class="readout" :class="inputClass" />
                                <p v-if="createForm.errors.day" class="mt-1 text-xs text-critical">{{ createForm.errors.day }}</p>
                            </div>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-year">Year (blank = every year)</label>
                            <input id="new-year" v-model="createForm.year" type="number" class="readout" :class="inputClass" placeholder="Every year" />
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-duration">Duration (days)</label>
                            <input id="new-duration" v-model.number="createForm.duration_days" type="number" min="1" max="60" class="readout" :class="inputClass" />
                            <p v-if="createForm.errors.duration_days" class="mt-1 text-xs text-critical">{{ createForm.errors.duration_days }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="createForm.equity_tracked" type="checkbox" />
                            Track for holiday equity
                        </label>
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="createForm.active" type="checkbox" />
                            Active
                        </label>
                    </div>

                    <div class="flex items-center gap-3 border-t border-line-soft pt-4">
                        <button type="submit" :disabled="createForm.processing"
                                class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                            Create holiday
                        </button>
                        <span v-if="createForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                    </div>
                </form>
            </section>

            <!-- List -->
            <div class="space-y-3">
                <article v-for="holiday in holidays" :key="holiday.id" class="rounded-md border border-line bg-panel p-4">
                    <div v-if="editingId !== holiday.id">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="text-sm font-semibold text-ink">{{ holiday.name }}</span>
                            <span class="channel-tag">{{ holiday.active ? 'Active' : 'Retired' }}</span>
                        </div>
                        <p class="readout mt-1 text-xs text-muted">
                            {{ holiday.calendar === 'hijri' ? 'Hijri' : 'Gregorian' }} {{ holiday.month }}/{{ holiday.day }}
                            <span v-if="holiday.year">, {{ holiday.year }} only</span>
                            <span v-else>, every year</span>
                            <span v-if="holiday.duration_days > 1"> &middot; {{ holiday.duration_days }} days</span>
                        </p>
                        <p v-if="holiday.occurrences.length" class="mt-1 text-xs text-body">
                            Resolves to:
                            <span v-for="(occ, i) in holiday.occurrences" :key="i">
                                {{ occurrenceLabel(occ) }}<span v-if="i < holiday.occurrences.length - 1">, </span>
                            </span>
                        </p>
                        <p v-else class="mt-1 text-xs text-muted">No upcoming occurrence found.</p>
                        <div class="mt-3 flex gap-3">
                            <button type="button" class="text-xs font-semibold text-channel-ink" @click="startEdit(holiday)">Edit</button>
                            <button type="button" class="text-xs font-semibold text-critical" @click="toggleActive(holiday)">
                                {{ holiday.active ? 'Retire' : 'Reactivate' }}
                            </button>
                        </div>
                    </div>

                    <form v-else class="space-y-4" @submit.prevent="submitEdit(holiday)">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="channel-tag mb-1 block" :for="`edit-name-${holiday.id}`">Name</label>
                                <input :id="`edit-name-${holiday.id}`" v-model="editForm.name" type="text" :class="inputClass" />
                                <p v-if="editForm.errors.name" class="mt-1 text-xs text-critical">{{ editForm.errors.name }}</p>
                            </div>
                            <div>
                                <label class="channel-tag mb-1 block" :for="`edit-calendar-${holiday.id}`">Calendar</label>
                                <select :id="`edit-calendar-${holiday.id}`" v-model="editForm.calendar" :class="inputClass">
                                    <option v-for="opt in calendarOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="channel-tag mb-1 block" :for="`edit-month-${holiday.id}`">Month</label>
                                    <input :id="`edit-month-${holiday.id}`" v-model.number="editForm.month" type="number" min="1" max="12" class="readout" :class="inputClass" />
                                </div>
                                <div>
                                    <label class="channel-tag mb-1 block" :for="`edit-day-${holiday.id}`">Day</label>
                                    <input :id="`edit-day-${holiday.id}`" v-model.number="editForm.day" type="number" min="1" max="31" class="readout" :class="inputClass" />
                                    <p v-if="editForm.errors.day" class="mt-1 text-xs text-critical">{{ editForm.errors.day }}</p>
                                </div>
                            </div>
                            <div>
                                <label class="channel-tag mb-1 block" :for="`edit-year-${holiday.id}`">Year (blank = every year)</label>
                                <input :id="`edit-year-${holiday.id}`" v-model="editForm.year" type="number" class="readout" :class="inputClass" />
                            </div>
                            <div>
                                <label class="channel-tag mb-1 block" :for="`edit-duration-${holiday.id}`">Duration (days)</label>
                                <input :id="`edit-duration-${holiday.id}`" v-model.number="editForm.duration_days" type="number" min="1" max="60" class="readout" :class="inputClass" />
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-4">
                            <label class="flex items-center gap-2 text-sm text-body">
                                <input v-model="editForm.equity_tracked" type="checkbox" />
                                Track for holiday equity
                            </label>
                            <label class="flex items-center gap-2 text-sm text-body">
                                <input v-model="editForm.active" type="checkbox" />
                                Active
                            </label>
                        </div>

                        <div class="flex items-center gap-3">
                            <button type="submit" :disabled="editForm.processing"
                                    class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                                Save
                            </button>
                            <button type="button" class="text-sm font-semibold text-body" @click="cancelEdit">Cancel</button>
                            <span v-if="editForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                        </div>
                    </form>
                </article>
            </div>
        </div>
    </AppLayout>
</template>
