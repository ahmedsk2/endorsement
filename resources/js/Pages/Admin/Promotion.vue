<script setup>
import { computed, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → Promotion (Munawib LV-03). P1b Owner Decision A, restated on this screen because it
 * is the one it was written for: there is no terminal level and none is inferred. The operator
 * picks BOTH the source level (the cohort) and the target level explicitly — this page computes
 * nothing resembling "the next level". `EXT` is offered as neither source nor target.
 *
 * ONE ACTION, PREVIEWED, SINGLE-TRANSACTION, AUDITED. The commit button stays disabled until a
 * preview has been run for the CURRENT source/target/date triple — changing any of the three
 * invalidates the preview client-side (`previewStale`), so a stale preview can never be
 * committed against changed inputs, which is the failure mode this whole screen exists to
 * prevent.
 *
 * "Retire this cohort instead" (Decision D's graduation path) is a SEPARATE, explicitly labelled
 * action on the same preview — never a value in the target picker that happens to mean "out".
 *
 * Every date here is entered by the operator as plain `Y-m-d` and rendered back verbatim from
 * the server's own response; this page performs no date arithmetic.
 */
const props = defineProps({
    levels: { type: Array, default: () => [] },
});

const page = usePage();
const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';

const fromLevelId = ref(props.levels[0]?.id ?? null);
const toLevelId = ref(null);
const effectiveFrom = ref('');
const reason = ref('');

// Tied to the EXACT source/target/date triple the last successful preview ran against.
const previewedKey = ref(null);
const currentKey = computed(() => `${fromLevelId.value ?? ''}|${toLevelId.value ?? ''}|${effectiveFrom.value}`);
const previewStale = computed(() => previewedKey.value === null || previewedKey.value !== currentKey.value);

const previewForm = useForm({});

const submitPreview = () => {
    previewForm.clearErrors();
    previewForm
        .transform(() => ({
            from_level_id: fromLevelId.value,
            to_level_id: toLevelId.value,
            effective_from: effectiveFrom.value,
        }))
        .post('/admin/promotion/preview', {
            preserveScroll: true,
            onSuccess: () => { previewedKey.value = currentKey.value; },
        });
};

const preview = computed(() => (previewStale.value ? null : page.props.flash?.promotion_preview));
const cohort = computed(() => preview.value?.cohort ?? []);
const isBackwards = computed(() => preview.value?.is_backwards ?? false);

// Every cohort row starts INCLUDED; the operator may exclude one before committing.
const excluded = ref(new Set());
watch(cohort, () => { excluded.value = new Set(); });

const isExcluded = (id) => excluded.value.has(id);
const toggleExcluded = (id) => {
    const next = new Set(excluded.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    excluded.value = next;
};

const includedIds = computed(() => cohort.value
    .map((row) => row.person_id)
    .filter((id) => !isExcluded(id)));

const canCommit = computed(() => !previewStale.value && includedIds.value.length > 0);

const commitForm = useForm({});

const submitCommit = (action) => {
    if (action === 'retire'
        && !confirm(`Retire this cohort of ${includedIds.value.length}? They will be marked inactive and their current level span closed. This cannot be undone from this screen.`)) {
        return;
    }

    commitForm.clearErrors();
    commitForm
        .transform(() => ({
            action,
            from_level_id: fromLevelId.value,
            to_level_id: action === 'promote' ? toLevelId.value : null,
            effective_from: effectiveFrom.value,
            ids: includedIds.value,
            reason: reason.value || null,
        }))
        .post('/admin/promotion/commit', {
            preserveScroll: true,
            onSuccess: () => { previewedKey.value = null; },
        });
};

const outcomeLabels = {
    assigned: 'Will be promoted',
    skipped_existing: 'Already has a change recorded on that date',
    skipped_same_level: 'Already at that level',
    refused_overlap: 'A later change already exists — not applied',
    closed: 'Will be retired',
    nothing_to_close: 'No open level span to close',
};

const result = computed(() => page.props.flash?.promotion_result);

const resultRows = computed(() => {
    const r = result.value;
    if (!r || !r.outcomes) return [];

    return Object.entries(r.outcomes).map(([id, outcome]) => {
        const row = cohort.value.find((c) => String(c.person_id) === String(id));

        return {
            id,
            name: row?.name ?? `Person #${id}`,
            label: outcomeLabels[outcome] ?? outcome,
        };
    });
});
</script>

<template>
    <AppLayout title="Promotion">
        <div class="mx-auto max-w-4xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Promotion</h2>
                <p class="text-sm text-muted">
                    Move a whole training-level cohort at once. Pick where they are and where
                    they go — this screen never guesses "the next level". Preview first; nothing
                    is written until you commit.
                </p>
            </div>

            <section class="rounded-md border border-line bg-panel p-5">
                <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="submitPreview">
                    <div>
                        <label class="channel-tag mb-1 block" for="promo-from">Source level</label>
                        <select id="promo-from" v-model.number="fromLevelId" :class="inputClass">
                            <option v-for="l in levels" :key="l.id" :value="l.id">{{ l.code }} — {{ l.name }}</option>
                        </select>
                        <p v-if="previewForm.errors.from_level_id" class="mt-1 text-xs text-critical">{{ previewForm.errors.from_level_id }}</p>
                    </div>
                    <div>
                        <label class="channel-tag mb-1 block" for="promo-to">Target level</label>
                        <select id="promo-to" v-model.number="toLevelId" :class="inputClass">
                            <option :value="null">— none (retire only) —</option>
                            <option v-for="l in levels" :key="l.id" :value="l.id">{{ l.code }} — {{ l.name }}</option>
                        </select>
                        <p v-if="previewForm.errors.to_level_id" class="mt-1 text-xs text-critical">{{ previewForm.errors.to_level_id }}</p>
                    </div>
                    <div>
                        <label class="channel-tag mb-1 block" for="promo-date">Effective date</label>
                        <input id="promo-date" v-model="effectiveFrom" type="date" :class="inputClass" />
                        <p v-if="previewForm.errors.effective_from" class="mt-1 text-xs text-critical">{{ previewForm.errors.effective_from }}</p>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="channel-tag mb-1 block" for="promo-reason">Reason (optional, recorded on each level span)</label>
                        <input id="promo-reason" v-model="reason" type="text" :class="inputClass" maxlength="500" />
                    </div>
                    <div class="sm:col-span-3">
                        <button type="submit" :disabled="previewForm.processing || !fromLevelId || !effectiveFrom"
                                class="min-h-11 rounded-md border border-line bg-ground-deep px-4 py-2 text-sm font-semibold text-ink disabled:opacity-60">
                            Preview
                        </button>
                    </div>
                </form>
            </section>

            <section v-if="!previewStale && preview" class="space-y-4 rounded-md border border-line bg-panel p-5">
                <div v-if="isBackwards" class="channel-bar-critical channel-bar rounded-md bg-critical-soft px-4 py-2 text-sm text-critical">
                    The target level is LOWER in the order than the source. This is not refused —
                    the system does not know your ladder's intent — but confirm this is what you mean.
                </div>

                <p class="channel-tag">{{ cohort.length }} in cohort</p>

                <p v-if="cohort.length === 0" class="text-sm text-muted">
                    Nobody is currently at the source level on this date.
                </p>

                <ul v-else class="space-y-2">
                    <li v-for="row in cohort" :key="row.person_id"
                        class="flex items-center justify-between gap-3 rounded-md border border-line-soft px-3 py-2">
                        <label class="flex min-w-0 items-center gap-2">
                            <input type="checkbox" :checked="!isExcluded(row.person_id)" @change="toggleExcluded(row.person_id)" />
                            <span class="readout truncate text-sm text-ink">{{ row.name }}</span>
                        </label>
                        <span class="channel-tag shrink-0">{{ outcomeLabels[row.outcome] ?? row.outcome }}</span>
                    </li>
                </ul>

                <div class="flex flex-wrap items-center gap-3 border-t border-line-soft pt-4">
                    <button type="button" :disabled="!canCommit || !toLevelId || commitForm.processing"
                            class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                            @click="submitCommit('promote')">
                        Commit promotion
                    </button>
                    <button type="button" :disabled="!canCommit || commitForm.processing"
                            class="min-h-11 rounded-md border border-critical px-4 py-2 text-sm font-semibold text-critical disabled:opacity-60"
                            @click="submitCommit('retire')">
                        Retire this cohort instead
                    </button>
                    <span v-if="commitForm.recentlySuccessful" class="text-sm text-ok" role="status">Done.</span>
                </div>
                <p v-if="commitForm.errors.ids" class="text-xs text-critical">{{ commitForm.errors.ids }}</p>
                <p v-if="commitForm.errors.to_level_id" class="text-xs text-critical">{{ commitForm.errors.to_level_id }}</p>
            </section>

            <section v-if="resultRows.length" class="rounded-md border border-line bg-panel p-4">
                <p class="channel-tag mb-2">Last promotion — batch {{ result.batch }}</p>
                <ul class="space-y-1 text-sm text-body">
                    <li v-for="row in resultRows" :key="row.id">
                        <span class="readout font-semibold text-ink">{{ row.name }}</span> — {{ row.label }}
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
