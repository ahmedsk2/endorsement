<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * The missed-days view (spec §10.3) — the system's ONLY aggregate. Per unit: how many days
 * in the chosen range have no signed endorsement, expandable to the missing dates, each
 * linking into that day's sheet (which offers creation via the carry dialog if absent).
 * Counts and dates only; no patient data reaches this page.
 */
const props = defineProps({
    // [{ code, name, bar_class, total_days, missed: [{date, kind}] }]
    units: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ from: null, to: null }) },
});

const from = ref(props.filters?.from ?? '');
const to = ref(props.filters?.to ?? '');
const expanded = ref({});

const applyRange = () => {
    router.get('/endorsement/compliance', { from: from.value, to: to.value }, { preserveScroll: true });
};

const toggle = (code) => {
    expanded.value = { ...expanded.value, [code]: !expanded.value[code] };
};

const toneFor = (u) => {
    if (u.missed.length === 0) {
        return 'text-ok';
    }

    return u.missed.length >= 5 ? 'text-critical' : 'text-caution';
};

const kindLabel = (kind) => (kind === 'no_sheet' ? 'no sheet' : 'unsigned');
</script>

<template>
    <AppLayout title="Missed days">
        <div class="mb-5">
            <h2 class="text-xl font-semibold text-ink">Days without endorsement</h2>
            <p class="text-sm text-muted">A day counts once it has a <em>signed</em> endorsement. Counts and dates only.</p>
        </div>

        <form class="mb-5 flex flex-wrap items-end gap-3" data-testid="range-form" @submit.prevent="applyRange">
            <div>
                <label for="range-from" class="channel-tag mb-1 block">From</label>
                <input id="range-from" v-model="from" type="date"
                       class="readout rounded-md border border-line bg-panel px-2 py-1.5 text-sm focus:border-channel focus:outline-none" />
            </div>
            <div>
                <label for="range-to" class="channel-tag mb-1 block">To</label>
                <input id="range-to" v-model="to" type="date"
                       class="readout rounded-md border border-line bg-panel px-2 py-1.5 text-sm focus:border-channel focus:outline-none" />
            </div>
            <button type="submit" class="rounded-md bg-channel px-3 py-1.5 text-sm font-semibold text-panel hover:bg-channel-ink">
                Recalculate
            </button>
        </form>

        <div class="space-y-3">
            <section v-for="u in units" :key="u.code" data-testid="unit-compliance"
                     class="channel-bar rounded-md border border-line bg-panel p-4" :class="u.bar_class">
                <button type="button" class="flex w-full flex-wrap items-center justify-between gap-2 text-left"
                        :data-testid="`toggle-${u.code}`" :aria-expanded="Boolean(expanded[u.code])"
                        @click="toggle(u.code)">
                    <span>
                        <span class="text-base font-semibold text-ink">{{ u.code }}</span>
                        <span class="ml-2 text-sm text-muted">{{ u.name }}</span>
                    </span>
                    <span class="readout text-sm font-semibold" :class="toneFor(u)">
                        {{ u.missed.length }} / {{ u.total_days }} days missed
                    </span>
                </button>

                <div v-if="expanded[u.code]" class="mt-3 border-t border-line-soft pt-3">
                    <p v-if="u.missed.length === 0" class="text-sm text-ok">
                        Every day in this range has a signed endorsement.
                    </p>
                    <div v-else class="flex flex-wrap gap-2" :data-testid="`missed-dates-${u.code}`">
                        <Link v-for="m in u.missed" :key="m.date"
                              :href="`/endorsement/${u.code.toLowerCase()}/${m.date}`"
                              class="channel-bar channel-bar-critical rounded-md border border-line bg-critical-soft px-2 py-1 text-xs text-critical hover:underline">
                            <span class="readout">{{ m.date }}</span> · {{ kindLabel(m.kind) }}
                        </Link>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
