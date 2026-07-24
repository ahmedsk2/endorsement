<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import CarryDialog from '../../Components/CarryDialog.vue';
import { useCan } from '../../Composables/useCan.js';

/**
 * C2 — the endorsement day index for one unit: the list of handover_dates (newest first), each a
 * link into the editable sheet. A "New day" action carries the most recent day's census forward.
 * G1 — the registry is PICU-only; the unit is still a prop from the server so the route and the
 * component stay unchanged. G3 restores the two legacy affordances this page had lost: the
 * start/end date filter (`PICU-Endorsement.php:120-131`) and the date picker that creates a sheet
 * for an arbitrary/past date (`PICU-Endorsement.php:172-199`) — without which a missed night could
 * not be back-filled. Server `cap:` gates are authoritative; `canEdit` only decides whether the
 * write affordances show.
 */
const props = defineProps({
    unit: { type: Object, default: () => ({ code: '', name: '' }) },
    dates: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ from: null, to: null }) },
});

const { can } = useCan();
const canEdit = can('endorsement.edit');

// Presentation only: the signature channel bar carries this sheet's unit. G1 — PICU is the only
// unit left, so this is the single remaining variant; anything else falls back to the default bar.
const unitBarClass = computed(
    () => (String(props.unit.code ?? '').toLowerCase() === 'picu' ? 'channel-bar-picu' : ''),
);

const from = ref(props.filters?.from ?? '');
const to = ref(props.filters?.to ?? '');
const isFiltered = computed(() => Boolean(props.filters?.from || props.filters?.to));

// The filter is a GET so a narrowed index stays linkable and survives a refresh, as legacy's
// form-post index did not.
const applyFilter = () => {
    const params = {};
    if (from.value) {
        params.from = from.value;
    }
    if (to.value) {
        params.to = to.value;
    }
    router.get(`/endorsement/${props.unit.code}`, params, { preserveScroll: true });
};

const clearFilter = () => {
    from.value = '';
    to.value = '';
    router.get(`/endorsement/${props.unit.code}`, {}, { preserveScroll: true });
};

// H/GAP-5 — the hover detail behind the "Signed off" badge: who endorsed to whom, and when. These
// are the snapshots frozen at sign-off, so they read the same however the accounts later change.
const signedTitle = (d) => {
    const parts = [];
    if (d.endorsed_by_name) {
        parts.push(`By ${d.endorsed_by_name}`);
    }
    if (d.endorsed_to_name) {
        parts.push(`to ${d.endorsed_to_name}`);
    }
    if (d.endorsement_time) {
        parts.push(`at ${d.endorsement_time}`);
    }
    return parts.length > 0 ? parts.join(' ') : 'Signed off';
};

const newDayDate = ref('');

const newDay = () => {
    router.post(`/endorsement/${props.unit.code}/new-day`, {}, { preserveScroll: true });
};

const newDayForDate = () => {
    if (!newDayDate.value) {
        return;
    }
    router.post(`/endorsement/${props.unit.code}/new-day`, { date: newDayDate.value });
};

/*
 * Ruling 2 — THE CARRY DIALOG. When the requested day is after a gap, the server creates
 * nothing and flashes `carry_prompt` ({unit, date, last_date}); the dialog puts the choice —
 * carry that census forward, or start blank — to a human, then re-posts with `carry_choice`.
 */
const page = usePage();
const dismissedPrompt = ref(null);
const carryPrompt = computed(() => {
    const p = page.props.flash?.carry_prompt;

    return p && p !== dismissedPrompt.value ? p : null;
});
</script>

<template>
    <AppLayout :title="`${unit.code} Endorsement`">
        <CarryDialog v-if="carryPrompt" :prompt="carryPrompt" @close="dismissedPrompt = carryPrompt" />
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold text-ink">{{ unit.name }} — Endorsement</h2>
                <p class="text-sm text-muted">
                    <span class="readout">{{ dates.length }}</span> handover day(s)<span v-if="isFiltered"> in range</span>
                </p>
            </div>
            <button v-if="canEdit" type="button" data-testid="new-day" @click="newDay"
                    class="rounded-md bg-channel px-4 py-2 text-sm font-semibold text-panel transition hover:bg-channel-ink">
                New day
            </button>
        </div>

        <div class="mb-5 flex flex-wrap items-end gap-4 rounded-md border border-line bg-panel p-4">
            <form data-testid="filter-form" class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilter">
                <div>
                    <label for="filter-from" class="channel-tag mb-1 block">Start date</label>
                    <input id="filter-from" v-model="from" data-testid="filter-from" type="date"
                           class="readout rounded-md border border-line bg-panel px-2 py-1.5 text-sm text-ink focus:border-channel focus:outline-none" />
                </div>
                <div>
                    <label for="filter-to" class="channel-tag mb-1 block">End date</label>
                    <input id="filter-to" v-model="to" data-testid="filter-to" type="date"
                           class="readout rounded-md border border-line bg-panel px-2 py-1.5 text-sm text-ink focus:border-channel focus:outline-none" />
                </div>
                <button type="submit" data-testid="filter-apply"
                        class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold text-channel-ink hover:bg-channel-soft">
                    Filter
                </button>
                <button v-if="isFiltered" type="button" data-testid="filter-clear" @click="clearFilter"
                        class="rounded-md px-3 py-1.5 text-sm text-muted hover:text-body hover:underline">
                    Clear
                </button>
            </form>

            <form v-if="canEdit" data-testid="new-day-for-date" class="flex flex-wrap items-end gap-3 border-line md:border-l md:pl-4"
                  @submit.prevent="newDayForDate">
                <div>
                    <label for="new-day-date" class="channel-tag mb-1 block">Create sheet for date</label>
                    <input id="new-day-date" v-model="newDayDate" data-testid="new-day-date" type="date"
                           class="readout rounded-md border border-line bg-panel px-2 py-1.5 text-sm text-ink focus:border-channel focus:outline-none" />
                </div>
                <button type="submit" data-testid="new-day-for-date-submit" :disabled="!newDayDate"
                        class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold text-channel-ink hover:bg-channel-soft disabled:cursor-not-allowed disabled:opacity-50">
                    Create
                </button>
            </form>
        </div>

        <p v-if="dates.length === 0" class="rounded-md border border-dashed border-line p-8 text-center text-sm text-muted">
            <span v-if="isFiltered">No handover days in that date range.</span>
            <span v-else>No handover days yet. Start one with "New day".</span>
        </p>

        <ul v-else class="channel-bar divide-y divide-line-soft overflow-hidden rounded-md border border-line bg-panel"
            :class="unitBarClass">
            <li v-for="d in dates" :key="d.date" data-testid="day-row">
                <Link :href="`/endorsement/${unit.code}/${d.date}`"
                      class="flex items-center justify-between gap-3 px-4 py-3 text-sm hover:bg-ground">
                    <span class="flex items-center gap-2">
                        <span class="readout font-medium">{{ d.date }}</span>
                        <!--
                          H/GAP-5 — a signed day must be tellable from an unsigned one at a glance:
                          an unsigned past handover is an incomplete medico-legal record, and the
                          index is where someone goes looking for it.
                        -->
                        <span v-if="d.signed_off" data-testid="signed-badge"
                              :title="signedTitle(d)"
                              class="rounded-md border border-ok px-2 py-0.5 text-xs font-semibold text-ok">
                            Signed off
                        </span>
                        <span v-else data-testid="unsigned-badge"
                              class="rounded-md border border-line px-2 py-0.5 text-xs font-semibold text-muted">
                            Not signed
                        </span>
                    </span>
                    <span class="text-xs text-muted"><span class="readout">{{ d.count }}</span> patient(s)</span>
                </Link>
            </li>
        </ul>
    </AppLayout>
</template>
