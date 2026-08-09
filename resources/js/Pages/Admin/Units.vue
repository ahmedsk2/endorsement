<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → Structure → Units (Munawib UN-01…05).
 *
 * Read-only in P1b Task 2 — the capability, the route and the nav entry had to land together,
 * and a nav entry pointing at a 404 is worse than no nav entry. Task 4 adds the write forms.
 *
 * Mobile cards + desktop table, matching Users.vue and Sheet.vue.
 */
defineProps({
    units: { type: Array, default: () => [] },
    palette: { type: Object, default: () => ({}) },
    reserved_codes: { type: Array, default: () => [] },
});

const flags = (unit) => [
    unit.training_rotation ? 'Rotation' : null,
    unit.call_target ? 'On-call' : null,
    unit.clinic_owner ? 'Clinics' : null,
].filter(Boolean);
</script>

<template>
    <AppLayout title="Units">
        <div class="mx-auto max-w-5xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Units</h2>
                <p class="text-sm text-muted">
                    A unit is configuration, not code. Its code is its address
                    (<span class="readout">/endorsement/&lt;code&gt;</span>), so
                    <span class="readout">{{ reserved_codes.join(', ') }}</span> can never be used —
                    a unit with one of those codes would be permanently unreachable.
                </p>
            </div>

            <!-- Phone: one card per unit. -->
            <div class="space-y-3 lg:hidden">
                <article v-for="unit in units" :key="unit.id"
                         class="channel-bar rounded-md border border-line bg-panel p-4"
                         :class="unit.bar_class">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="readout text-sm font-semibold text-ink">{{ unit.code }}</span>
                        <span class="channel-tag">{{ unit.active ? 'Active' : 'Retired' }}</span>
                    </div>
                    <p class="text-sm text-body">{{ unit.name }}</p>
                    <p v-if="flags(unit).length" class="channel-tag mt-1">{{ flags(unit).join(' · ') }}</p>
                    <p v-if="unit.aliases.length" class="mt-1 text-xs text-muted">
                        Also known as: {{ unit.aliases.join(', ') }}
                    </p>
                </article>
            </div>

            <!-- Desktop: a table. -->
            <div class="hidden overflow-x-auto rounded-md border border-line bg-panel lg:block">
                <table class="w-full text-left text-sm">
                    <thead class="bg-ground-deep">
                        <tr>
                            <th scope="col" class="channel-tag px-4 py-2">Order</th>
                            <th scope="col" class="channel-tag px-4 py-2">Code</th>
                            <th scope="col" class="channel-tag px-4 py-2">Name</th>
                            <th scope="col" class="channel-tag px-4 py-2">Colour</th>
                            <th scope="col" class="channel-tag px-4 py-2">Used for</th>
                            <th scope="col" class="channel-tag px-4 py-2">Aliases</th>
                            <th scope="col" class="channel-tag px-4 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="unit in units" :key="unit.id" class="border-t border-line">
                            <td class="readout px-4 py-2 text-body">{{ unit.display_order }}</td>
                            <td class="readout px-4 py-2 font-semibold text-ink">{{ unit.code }}</td>
                            <td class="px-4 py-2 text-body">{{ unit.name }}</td>
                            <td class="px-4 py-2">
                                <span class="channel-bar inline-block h-4 w-8 rounded-sm bg-ground"
                                      :class="unit.bar_class" aria-hidden="true"></span>
                                <span class="sr-only">{{ palette[unit.bar_class] || unit.bar_class }}</span>
                            </td>
                            <td class="px-4 py-2 text-body">{{ flags(unit).join(' · ') || '—' }}</td>
                            <td class="px-4 py-2 text-muted">{{ unit.aliases.join(', ') || '—' }}</td>
                            <td class="px-4 py-2">
                                <span class="channel-tag">{{ unit.active ? 'Active' : 'Retired' }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
