<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    // Full capability catalog: [{ id, key, label, description }].
    capabilities: { type: Array, default: () => [] },
    // Role catalog: [{ id, name }] for positions 0..4.
    positions: { type: Array, default: () => [] },
    // position => [capability ids the role currently holds].
    roleMatrix: { type: Object, default: () => ({}) },
    // Roster for the per-user picker: [{ id, member_name, full_name, position }].
    users: { type: Array, default: () => [] },
    // The chosen user (via ?user_id=): { id, member_name, full_name, position, effective, overrides } | null.
    selectedUser: { type: Object, default: null },
});

const flash = computed(() => usePage().props.flash);

// One Inertia form per role — "Save role defaults" PUTs just that role's capability id set.
const roleForms = {};
for (const position of props.positions) {
    roleForms[position.id] = useForm({
        position: position.id,
        capability_ids: [...(props.roleMatrix[position.id] ?? [])],
    });
}
const saveRole = (positionId) =>
    roleForms[positionId].put('/admin/access-control/role', { preserveScroll: true });

// Per-user override editor. overrides is a map { capabilityId: 'grant'|'deny' }; a capability
// absent from the map is inherited from the role default.
const userForm = useForm({
    user_id: props.selectedUser?.id ?? null,
    overrides: { ...(props.selectedUser?.overrides ?? {}) },
});
const overrideFor = (capId) => userForm.overrides[capId] ?? 'inherit';
const setUserOverride = (capId, value) => {
    if (value === 'inherit') {
        delete userForm.overrides[capId];
    } else {
        userForm.overrides[capId] = value;
    }
};
const saveUser = () => userForm.put('/admin/access-control/user', { preserveScroll: true });

// User picker — searches the shipped roster client-side, then reloads the page with ?user_id=.
const search = ref('');
const filteredUsers = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (term === '') {
        return props.users;
    }

    return props.users.filter((u) =>
        `${u.full_name ?? ''} ${u.member_name ?? ''}`.toLowerCase().includes(term)
    );
});
const positionName = (id) => props.positions.find((p) => p.id === id)?.name ?? `#${id}`;
const pickUser = (userId) =>
    router.get('/admin/access-control', { user_id: userId }, { preserveState: true, preserveScroll: true });

const effectiveSet = computed(() => new Set(props.selectedUser?.effective ?? []));
</script>

<template>
    <Head title="Access control" />
    <main class="min-h-screen bg-ground p-6">
        <div class="mx-auto max-w-6xl space-y-8">
            <div>
                <h1 class="text-xl font-semibold text-ink">Access control</h1>
                <p class="mt-1 text-sm text-muted">
                    Edit each role's default capabilities and set per-user grant / deny overrides.
                    Changes take effect immediately.
                </p>
            </div>

            <!-- Saving a role or an override only reports back through this flash.
                 G6: the region is rendered PERSISTENTLY (empty until there is news) — a live
                 region that appears together with its text is never announced, because
                 assistive tech has to be watching the region before the content lands. Only
                 the styled banner inside it is conditional. -->
            <div role="status" aria-live="polite" data-testid="flash-status">
                <p v-if="flash?.status"
                   class="channel-bar-ok channel-bar rounded-md bg-ok-soft px-3 py-2 text-sm text-ok">{{ flash.status }}</p>
            </div>

            <!-- Role → capability matrix -->
            <section aria-labelledby="role-defaults-heading" class="rounded-md border border-line bg-panel p-6">
                <h2 id="role-defaults-heading" class="text-base font-semibold text-ink">Role defaults</h2>
                <p class="mt-1 mb-4 text-sm text-muted">
                    Rows are capabilities; columns are roles. Tick to grant a capability to a role by default.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">
                            Default capabilities per role — rows are capabilities, columns are roles
                        </caption>
                        <thead>
                            <tr class="border-b border-line bg-ground-deep">
                                <th scope="col" class="channel-tag py-2 pr-4 pl-3">Capability</th>
                                <th
                                    v-for="position in positions"
                                    :key="position.id"
                                    scope="col"
                                    class="channel-tag px-3 py-2 text-center"
                                >
                                    {{ position.name }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="cap in capabilities"
                                :key="cap.id"
                                class="border-b border-line-soft hover:bg-channel-soft/50"
                            >
                                <th scope="row" class="py-2 pr-4 text-left font-normal">
                                    <span class="font-medium text-ink">{{ cap.label }}</span>
                                    <span class="readout ml-2 text-xs text-muted">{{ cap.key }}</span>
                                    <!-- The catalog carries a longer description for the capabilities
                                         whose label alone does not say enough to grant them safely
                                         (e.g. endorsement.reopen). It was shipped as a prop but never
                                         rendered — an administrator was deciding blind. -->
                                    <span v-if="cap.description" class="mt-0.5 block max-w-md text-xs font-normal text-muted"
                                          data-testid="capability-description">{{ cap.description }}</span>
                                </th>
                                <td
                                    v-for="position in positions"
                                    :key="position.id"
                                    class="px-3 py-2 text-center"
                                >
                                    <input
                                        type="checkbox"
                                        :value="cap.id"
                                        v-model="roleForms[position.id].capability_ids"
                                        :data-testid="`role-${position.id}-cap-${cap.id}`"
                                        :aria-label="`${cap.key} for ${position.name}`"
                                        class="h-4 w-4 rounded border-line bg-panel text-channel focus:ring-channel"
                                    />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="py-3 pr-4 pl-3 text-sm text-muted">Save each role after editing.</td>
                                <td v-for="position in positions" :key="position.id" class="px-3 py-3 text-center">
                                    <button
                                        type="button"
                                        @click="saveRole(position.id)"
                                        :disabled="roleForms[position.id].processing"
                                        :data-testid="`save-role-${position.id}`"
                                        :aria-label="`Save the default capabilities for ${position.name}`"
                                        class="rounded-md bg-channel px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60"
                                    >
                                        Save
                                    </button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <!-- Per-user overrides -->
            <section aria-labelledby="user-overrides-heading" class="rounded-md border border-line bg-panel p-6">
                <h2 id="user-overrides-heading" class="text-base font-semibold text-ink">Per-user overrides</h2>
                <p class="mt-1 mb-4 text-sm text-muted">
                    Pick a user, then grant or deny individual capabilities. A per-user deny always wins over the role default.
                </p>

                <div class="grid gap-6 md:grid-cols-[18rem_1fr]">
                    <!-- Picker -->
                    <div>
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search by name or username"
                            aria-label="Search users by name or username"
                            class="w-full rounded-md border border-line bg-panel px-3 py-2 text-sm focus:border-channel focus:ring-channel"
                        />
                        <p class="sr-only" role="status" aria-live="polite" data-testid="access-search-live">
                            {{ search.trim() === '' ? '' : `${filteredUsers.length} users match the search.` }}
                        </p>
                        <ul aria-label="Users" class="mt-3 max-h-80 divide-y divide-line-soft overflow-y-auto rounded-md border border-line bg-panel">
                            <li v-for="u in filteredUsers" :key="u.id">
                                <button
                                    type="button"
                                    @click="pickUser(u.id)"
                                    :data-testid="`pick-user-${u.id}`"
                                    :aria-label="`Edit overrides for ${u.full_name || u.member_name}`"
                                    :aria-current="selectedUser?.id === u.id ? 'true' : undefined"
                                    class="flex w-full flex-col items-start px-3 py-2 text-left text-sm hover:bg-channel-soft/50"
                                    :class="{ 'bg-channel-soft': selectedUser?.id === u.id }"
                                >
                                    <span class="font-medium text-ink">{{ u.full_name || u.member_name }}</span>
                                    <span class="readout text-xs text-muted">{{ u.member_name }} · {{ positionName(u.position) }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <!-- Override editor -->
                    <div v-if="selectedUser">
                        <div class="mb-3">
                            <p class="font-medium text-ink">
                                {{ selectedUser.full_name || selectedUser.member_name }}
                            </p>
                            <p class="channel-tag">{{ positionName(selectedUser.position) }}</p>
                        </div>

                        <table class="w-full text-left text-sm">
                            <caption class="sr-only">
                                Per-capability overrides for {{ selectedUser.full_name || selectedUser.member_name }}
                            </caption>
                            <thead>
                                <tr class="border-b border-line bg-ground-deep">
                                    <th scope="col" class="channel-tag py-2 pr-4 pl-3">Capability</th>
                                    <th scope="col" class="channel-tag px-3 py-2">Effective</th>
                                    <th scope="col" class="channel-tag px-3 py-2">Override</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="cap in capabilities"
                                    :key="cap.id"
                                    class="border-b border-line-soft hover:bg-channel-soft/50"
                                >
                                    <th scope="row" class="py-2 pr-4 text-left font-normal">
                                        <span class="font-medium text-ink">{{ cap.label }}</span>
                                        <span class="readout ml-2 text-xs text-muted">{{ cap.key }}</span>
                                        <span v-if="cap.description" class="mt-0.5 block max-w-md text-xs font-normal text-muted"
                                              data-testid="capability-description">{{ cap.description }}</span>
                                    </th>
                                    <td class="px-3 py-2">
                                        <span
                                            v-if="effectiveSet.has(cap.key)"
                                            class="inline-flex items-center gap-1 text-xs font-medium text-ok"
                                        >
                                            <span class="inline-block h-2 w-2 rounded-full bg-ok"></span> allowed
                                        </span>
                                        <span v-else class="text-xs text-muted">denied</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <select
                                            :value="overrideFor(cap.id)"
                                            @change="setUserOverride(cap.id, $event.target.value)"
                                            :data-testid="`user-cap-${cap.id}`"
                                            :aria-label="`Override ${cap.key}`"
                                            class="rounded-md border border-line bg-panel px-2 py-1 text-xs focus:border-channel focus:ring-channel"
                                        >
                                            <option value="inherit">Inherit (role default)</option>
                                            <option value="grant">Grant</option>
                                            <option value="deny">Deny</option>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <button
                            type="button"
                            @click="saveUser"
                            :disabled="userForm.processing"
                            data-testid="save-user"
                            class="mt-4 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60"
                        >
                            Save overrides
                        </button>
                    </div>
                    <div v-else class="flex items-center text-sm text-muted">
                        Select a user to edit their overrides.
                    </div>
                </div>
            </section>
        </div>
    </main>
</template>
