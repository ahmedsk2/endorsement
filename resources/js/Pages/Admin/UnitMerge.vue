<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → Structure → Units → Merge (Munawib UN-01) — the highest-risk screen in P1b.
 *
 * `handover_signoffs` carries UNIQUE(unit_id, handover_date): if both units already have a
 * signed day on the same date, re-pointing the source's row onto the target would collide. This
 * screen surfaces every such date BEFORE anything is written, and the merge cannot be confirmed
 * until each one is individually acknowledged — one checkbox per date, not a single blanket
 * "I understand". Nothing signed is ever deleted: a colliding date's patient rows move to the
 * target, but the source's own signed header for that date stays exactly where it is, attached
 * to the retired (never deleted) source unit.
 *
 * SEVEN TABLES MOVE, not four. The rota, the clinics and the reminder opt-ins were stranded on the
 * retired unit for four slices (design §14 item 23) — the reminders worst of all, since nothing
 * anywhere could repair one — so each is now counted here BEFORE the merge runs. Two lines carry a
 * consequence rather than a number: a duplicate reminder opt-in is dropped, said plainly because a
 * count that folded it into "moved" would be a silent delete, and a clinic cannot land on a unit
 * that does not own clinics, which is a refusal with a tick-box behind it rather than a warning.
 */
const props = defineProps({
    units: { type: Array, default: () => [] },
    source_unit_id: { type: [Number, String], default: null },
    target_unit_id: { type: [Number, String], default: null },
    plan: { type: Object, default: null },
    field_definition_conflicts: { type: Array, default: () => [] },
    clinics_the_target_cannot_own: { type: Number, default: 0 },
});

const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';

const sourceId = ref(props.source_unit_id ?? '');
const targetId = ref(props.target_unit_id ?? '');

const sourceUnit = computed(() => props.units.find((u) => u.id === Number(sourceId.value)) ?? null);
const targetUnit = computed(() => props.units.find((u) => u.id === Number(targetId.value)) ?? null);

const previewing = ref(false);

const preview = () => {
    if (!sourceId.value || !targetId.value) return;
    previewing.value = true;
    router.get('/admin/structure/units/merge', { source: sourceId.value, target: targetId.value }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => { previewing.value = false; },
    });
};

// Per-date acknowledgement. Reset whenever a fresh plan arrives (new pair, or the same pair
// reloaded after a stale-collision refusal) so a stale tick can never carry into a new pair.
const acknowledged = reactive({});
watch(() => props.plan, (plan) => {
    Object.keys(acknowledged).forEach((k) => delete acknowledged[k]);
    (plan?.collisions ?? []).forEach((date) => { acknowledged[date] = false; });
}, { immediate: true });

const allAcknowledged = computed(() => (props.plan?.collisions ?? []).every((date) => acknowledged[date]));

const hasConflicts = computed(() => props.field_definition_conflicts.length > 0);
const hasHomelessClinics = computed(() => props.clinics_the_target_cannot_own > 0);

const canMerge = computed(() => sourceUnit.value && targetUnit.value && props.plan && !hasConflicts.value
    && !hasHomelessClinics.value
    && (props.plan.collisions.length === 0 || allAcknowledged.value));

const mergeForm = useForm({
    source_unit_id: null,
    target_unit_id: null,
    resolution: null,
    accepted_collisions: [],
});

const confirmMerge = () => {
    if (!canMerge.value) return;
    mergeForm.source_unit_id = sourceUnit.value.id;
    mergeForm.target_unit_id = targetUnit.value.id;
    mergeForm.resolution = props.plan.collisions.length > 0 ? 'keep_target' : null;
    mergeForm.accepted_collisions = props.plan.collisions;
    mergeForm.post('/admin/structure/units/merge', { preserveScroll: true });
};

const cancelMerge = () => {
    if (!sourceUnit.value || !targetUnit.value) return;
    mergeForm.source_unit_id = sourceUnit.value.id;
    mergeForm.target_unit_id = targetUnit.value.id;
    mergeForm.resolution = 'abort';
    mergeForm.accepted_collisions = [];
    mergeForm.post('/admin/structure/units/merge', { preserveScroll: true });
};
</script>

<template>
    <AppLayout title="Merge units">
        <div class="mx-auto max-w-3xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Merge units</h2>
                <p class="text-sm text-muted">
                    Every handover, sign-off and custom field the source unit owns is re-pointed onto
                    the target. The source unit is then <strong class="text-ink">retired, never deleted</strong>
                    &mdash; its handover history still refers to it, so the row stays in the database. There
                    is no undo: review the counts below before confirming.
                </p>
            </div>

            <section class="channel-bar rounded-md border border-line bg-panel p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="channel-tag mb-1 block" for="merge-source">Merge this unit (source)</label>
                        <select id="merge-source" v-model="sourceId" :class="inputClass">
                            <option value="">Choose a unit&hellip;</option>
                            <option v-for="unit in units" :key="unit.id" :value="unit.id">
                                {{ unit.code }} &mdash; {{ unit.name }}{{ unit.active ? '' : ' (retired)' }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="channel-tag mb-1 block" for="merge-target">Into this unit (target)</label>
                        <select id="merge-target" v-model="targetId" :class="inputClass">
                            <option value="">Choose a unit&hellip;</option>
                            <option v-for="unit in units" :key="unit.id" :value="unit.id" :disabled="!unit.active">
                                {{ unit.code }} &mdash; {{ unit.name }}{{ unit.active ? '' : ' (retired — cannot be a target)' }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 flex items-center gap-3 border-t border-line-soft pt-4">
                    <button type="button" :disabled="!sourceId || !targetId || sourceId === targetId || previewing"
                            class="min-h-11 rounded-md border border-line bg-panel px-3 py-2 text-sm font-semibold text-body hover:bg-ground disabled:opacity-60"
                            @click="preview">
                        {{ previewing ? 'Loading…' : 'Preview merge' }}
                    </button>
                    <p v-if="sourceId && targetId && sourceId === targetId" class="text-xs text-critical">
                        A unit cannot be merged into itself.
                    </p>
                </div>
            </section>

            <section v-if="plan && sourceUnit && targetUnit" class="channel-bar rounded-md border border-line bg-panel p-5">
                <h3 class="mb-3 text-sm font-semibold text-ink">
                    What merging {{ sourceUnit.code }} into {{ targetUnit.code }} will do
                </h3>

                <ul class="grid gap-2 text-sm text-body sm:grid-cols-2">
                    <li><span class="readout font-semibold text-ink">{{ plan.handovers }}</span> handover row(s) move to {{ targetUnit.code }}</li>
                    <li><span class="readout font-semibold text-ink">{{ plan.signoffs }}</span> sign-off day(s) move to {{ targetUnit.code }}</li>
                    <li><span class="readout font-semibold text-ink">{{ plan.field_definitions }}</span> custom field definition(s) move</li>
                    <li><span class="readout font-semibold text-ink">{{ plan.preferred_unit_users }}</span> account(s)' remembered unit updates</li>
                    <li><span class="readout font-semibold text-ink">{{ plan.rota_assignments }}</span> master rota span(s) move to {{ targetUnit.code }}</li>
                    <li><span class="readout font-semibold text-ink">{{ plan.clinics }}</span> clinic(s) move to {{ targetUnit.code }}</li>
                    <li><span class="readout font-semibold text-ink">{{ plan.reminders }}</span> reminder opt-in(s) move to {{ targetUnit.code }}</li>
                </ul>

                <p v-if="plan.reminders_dropped" class="mt-3 text-sm text-body">
                    <span class="readout font-semibold text-ink">{{ plan.reminders_dropped }}</span>
                    account(s) already ask for {{ targetUnit.code }}'s reminders as well, so their
                    {{ sourceUnit.code }} opt-in is <strong class="text-ink">dropped</strong> rather than
                    duplicated. They keep receiving {{ targetUnit.code }}'s reminders exactly as before.
                </p>

                <p class="mt-4 text-sm text-body">
                    <strong class="text-ink">{{ sourceUnit.code }}</strong> is then retired (never deleted) and
                    disappears from the sidebar and from new handovers, going forward only.
                </p>

                <div v-if="hasConflicts" class="mt-4 channel-bar-critical channel-bar rounded-md bg-critical-soft p-3 text-sm text-critical">
                    Both units already define a custom field with the same key
                    (<span class="readout">{{ field_definition_conflicts.join(', ') }}</span>). Rename or
                    retire one of them before this merge can proceed.
                </div>

                <div v-if="hasHomelessClinics" class="mt-4 channel-bar-critical channel-bar rounded-md bg-critical-soft p-3 text-sm text-critical">
                    <span class="readout">{{ clinics_the_target_cannot_own }}</span> clinic(s) on
                    {{ sourceUnit.code }} have nowhere to go: {{ targetUnit.code }} does not own clinics.
                    Tick &ldquo;owns clinics&rdquo; on {{ targetUnit.code }} in Structure &rarr; Units first.
                </div>

                <div v-if="plan.collisions.length" class="mt-5 border-t border-line-soft pt-4">
                    <p class="text-sm font-semibold text-ink">
                        Both units already have a signed day on {{ plan.collisions.length }} of the same date(s)
                    </p>
                    <p class="mt-1 text-xs text-muted">
                        Nothing signed is ever deleted. For each date below, {{ targetUnit.code }}'s own signed
                        record stands; {{ sourceUnit.code }}'s signed record for that date stays attached to
                        {{ sourceUnit.code }} (retired, not deleted) instead of moving. Only the patient rows for
                        that date move to {{ targetUnit.code }}. Acknowledge every date to confirm.
                    </p>
                    <ul class="mt-3 space-y-2">
                        <li v-for="date in plan.collisions" :key="date" class="flex items-start gap-2">
                            <input :id="`ack-${date}`" v-model="acknowledged[date]" type="checkbox" class="mt-0.5" />
                            <label :for="`ack-${date}`" class="text-sm text-body">
                                <span class="readout font-semibold text-ink">{{ date }}</span> &mdash;
                                {{ targetUnit.code }}'s sign-off stands; {{ sourceUnit.code }}'s stays on
                                {{ sourceUnit.code }}, unmoved and undeleted.
                            </label>
                        </li>
                    </ul>
                </div>

                <p v-if="mergeForm.errors.resolution" class="mt-3 text-xs text-critical">{{ mergeForm.errors.resolution }}</p>
                <p v-if="mergeForm.errors.accepted_collisions" class="mt-3 text-xs text-critical">{{ mergeForm.errors.accepted_collisions }}</p>
                <p v-if="mergeForm.errors.target_unit_id" class="mt-3 text-xs text-critical">{{ mergeForm.errors.target_unit_id }}</p>

                <div class="mt-5 flex items-center gap-3 border-t border-line-soft pt-4">
                    <button type="button" :disabled="!canMerge || mergeForm.processing"
                            class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                            @click="confirmMerge">
                        Confirm merge: retire {{ sourceUnit.code }} into {{ targetUnit.code }}
                    </button>
                    <button type="button" class="text-sm font-semibold text-body" @click="cancelMerge">
                        Cancel — make no changes
                    </button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
