<script setup>
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → People (Munawib PE-01…03, LV-02…04, ST-04).
 *
 * PERSON-scoped, where Admin → Users is ACCOUNT-scoped: a roster-only person (never logged in)
 * appears here and nowhere on Admin → Users, because that screen's list is
 * `User::query()->join('people', ...)`.
 *
 * Read-only and name-only for now (Task 1). No contact field exists yet (Task 2 — the
 * projection that decides what a person's props contain is a security control and gets its own
 * task), no level column yet (Task 3), no writes yet (Task 4).
 *
 * "Status" is always DERIVED (active/retired × account/roster-only) — there is no stored
 * `person_status` column, on purpose (design §5.1 deviation 3).
 *
 * Mobile cards + desktop table, matching Levels.vue and Units.vue.
 */
const props = defineProps({
    people: { type: Array, default: () => [] },
    positions: { type: Array, default: () => [] },
});

const search = ref('');

const positionName = (id) => props.positions.find((p) => p.id === id)?.name || `Role ${id}`;

const filteredPeople = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (q === '') return props.people;

    return props.people.filter((p) =>
        [p.full_name, p.short_name].some((v) => (v || '').toLowerCase().includes(q)));
});
</script>

<template>
    <AppLayout title="People">
        <div class="mx-auto max-w-5xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">People</h2>
                <p class="text-sm text-muted">
                    The departmental roster — everyone named on a sheet or a rota, whether or not
                    they have ever logged in. A roster-only person (an external consultant, say)
                    is invisible to Admin → Users by construction; this screen is where they live.
                </p>
            </div>

            <div>
                <label class="channel-tag mb-1 block" for="people-search">Search</label>
                <input id="people-search" v-model="search" type="search"
                       class="w-full max-w-sm rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none"
                       placeholder="Name…" />
            </div>

            <!-- Phone: one card per person. -->
            <div class="space-y-3 lg:hidden">
                <article v-for="person in filteredPeople" :key="person.id" class="rounded-md border border-line bg-panel p-4">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="readout text-sm font-semibold text-ink">{{ person.full_name }}</span>
                        <span class="channel-tag">{{ person.active ? 'Active' : 'Retired' }}</span>
                    </div>
                    <p class="text-sm text-body">{{ positionName(person.position) }}</p>
                    <p v-if="person.short_name" class="text-xs text-muted">{{ person.short_name }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span class="channel-tag">{{ person.has_account ? 'Account' : 'Roster only' }}</span>
                        <span v-if="person.external" class="channel-tag">External</span>
                    </div>
                </article>
            </div>

            <!-- Desktop: a table. -->
            <div class="hidden overflow-x-auto rounded-md border border-line bg-panel lg:block">
                <table class="w-full text-left text-sm">
                    <thead class="bg-ground-deep">
                        <tr>
                            <th scope="col" class="channel-tag px-4 py-2">Name</th>
                            <th scope="col" class="channel-tag px-4 py-2">Short name</th>
                            <th scope="col" class="channel-tag px-4 py-2">Role</th>
                            <th scope="col" class="channel-tag px-4 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="person in filteredPeople" :key="person.id" class="border-t border-line">
                            <td class="readout px-4 py-2 font-semibold text-ink">{{ person.full_name }}</td>
                            <td class="readout px-4 py-2 text-body">{{ person.short_name || '—' }}</td>
                            <td class="px-4 py-2 text-body">{{ positionName(person.position) }}</td>
                            <td class="px-4 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <span class="channel-tag">{{ person.active ? 'Active' : 'Retired' }}</span>
                                    <span class="channel-tag">{{ person.has_account ? 'Account' : 'Roster only' }}</span>
                                    <span v-if="person.external" class="channel-tag">External</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
