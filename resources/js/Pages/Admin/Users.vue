<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * F1 — Admin → Users (re-platform of legacy control.php). Two sections:
 *  - Pending registrations: approve (creates the active account) or reject (discard).
 *  - Users: activate/deactivate toggle + role select + (H/GAP-14) inline name/username/email
 *    correction and (H/GAP-13) a SOFT delete, with a client-side search box.
 * Every button hits the admin-only (`cap:users.manage`) write endpoints; the server enforces
 * the last-active-Administrator lockout guards and re-validates uniqueness on approval.
 */
const props = defineProps({
    pending: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    positions: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
});

const search = ref('');

const filteredUsers = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (q === '') return props.users;

    return props.users.filter((u) =>
        [u.full_name, u.member_name, u.member_email]
            .some((v) => (v || '').toLowerCase().includes(q)));
});

const positionName = (id) => props.positions.find((p) => p.id === id)?.name || `Role ${id}`;

const approve = (p) => {
    if (!confirm(`Approve "${p.member_name}" and activate the account?`)) return;
    router.post(`/admin/users/pending/${p.id}/approve`, {}, { preserveScroll: true });
};

const reject = (p) => {
    if (!confirm(`Reject and discard the registration for "${p.member_name}"?`)) return;
    router.delete(`/admin/users/pending/${p.id}`, { preserveScroll: true });
};

const toggleActive = (u) => {
    router.patch(`/admin/users/${u.id}/active`, { active: !u.active }, { preserveScroll: true });
};

const changePosition = (u, event) => {
    router.patch(`/admin/users/${u.id}/position`, { position: Number(event.target.value) }, { preserveScroll: true });
};

// H/GAP-14 — inline correction of another account's details. Role and password are NOT editable
// here; each has its own dedicated, separately-audited control/endpoint.
const editingId = ref(null);
const edit = reactive({ full_name: '', member_name: '', member_email: '' });

const startEdit = (u) => {
    editingId.value = u.id;
    edit.full_name = u.full_name || '';
    edit.member_name = u.member_name || '';
    edit.member_email = u.member_email || '';
};

const cancelEdit = () => { editingId.value = null; };

const saveProfile = (u) => {
    router.patch(`/admin/users/${u.id}/profile`, { ...edit }, {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
};

// There is deliberately NO delete affordance here (owner ruling, 2026-07-19). Deactivate is the
// supported way to retire an account: `users.id` is the actor FK on audit_log, so removing an
// account degrades the accountability trail, while deactivation revokes access and keeps every
// past action attributable. The server route was removed too — see routes/web.php.

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
</script>

<template>
    <AppLayout title="Users">
        <div class="mx-auto max-w-5xl space-y-8">
            <!-- Validation errors from approve / lockout guards surface here (Inertia errors prop). -->
            <div v-if="Object.keys(errors).length" role="alert"
                 class="channel-bar channel-bar-critical rounded-md bg-critical-soft px-4 py-2 text-sm text-critical">
                <p v-for="(message, key) in errors" :key="key">{{ message }}</p>
            </div>

            <!-- Pending registrations -->
            <section aria-labelledby="pending-heading">
                <h2 id="pending-heading" class="mb-3 text-base font-semibold text-ink">
                    Pending registrations
                    <span class="readout ml-1 rounded-full bg-caution-soft px-2 py-0.5 text-xs font-semibold text-caution">{{ pending.length }}</span>
                </h2>

                <p v-if="!pending.length" class="rounded-md border border-dashed border-line p-4 text-sm text-muted">
                    No registrations waiting for approval.
                </p>

                <div v-else class="overflow-x-auto rounded-md border border-line bg-panel">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Registrations waiting for administrator approval</caption>
                        <thead class="border-b border-line bg-ground-deep">
                            <tr>
                                <th scope="col" class="channel-tag px-4 py-2.5">Name</th>
                                <th scope="col" class="channel-tag px-4 py-2.5">Username</th>
                                <th scope="col" class="channel-tag px-4 py-2.5">Email</th>
                                <th scope="col" class="channel-tag px-4 py-2.5">Requested role</th>
                                <th scope="col" class="channel-tag px-4 py-2.5">Requested at</th>
                                <th scope="col" class="channel-tag px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in pending" :key="p.id" :data-testid="`pending-${p.id}`"
                                class="border-b border-line-soft last:border-0 hover:bg-channel-soft/50">
                                <th scope="row" class="px-4 py-2.5 text-left font-medium">{{ p.full_name || '—' }}</th>
                                <td class="readout px-4 py-2.5">{{ p.member_name }}</td>
                                <td class="px-4 py-2.5">{{ p.member_email || '—' }}</td>
                                <td class="px-4 py-2.5">{{ positionName(p.position) }}</td>
                                <td class="readout px-4 py-2.5 text-muted">{{ fmt(p.requested_at) }}</td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    <!-- Per-row aria-labels: a bare "Approve" repeated down the table
                                         gives a screen-reader user no way to tell the rows apart. -->
                                    <button type="button" :data-testid="`approve-${p.id}`" @click="approve(p)"
                                            :aria-label="`Approve registration for ${p.member_name}`"
                                            class="rounded-md bg-ok px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-ok/90">
                                        Approve
                                    </button>
                                    <button type="button" :data-testid="`reject-${p.id}`" @click="reject(p)"
                                            :aria-label="`Reject registration for ${p.member_name}`"
                                            class="ml-2 rounded-md border border-critical px-3 py-1.5 text-xs font-semibold text-critical transition hover:bg-critical-soft">
                                        Reject
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Users -->
            <section aria-labelledby="users-heading">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="users-heading" class="text-base font-semibold text-ink">Users ({{ users.length }})</h2>
                    <input v-model="search" type="search" data-testid="user-search"
                           placeholder="Search name, username or email…" aria-label="Search users"
                           class="w-64 rounded-md border border-line bg-panel px-3 py-1.5 text-sm" />
                </div>

                <!-- The search filters the table in place with no page change, so the new row
                     count has to be announced or it is a silent update. -->
                <p class="sr-only" role="status" aria-live="polite" data-testid="user-search-live">
                    {{ search.trim() === '' ? '' : `${filteredUsers.length} of ${users.length} users match the search.` }}
                </p>

                <div class="overflow-x-auto rounded-md border border-line bg-panel">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">Staff accounts, with role, two-factor state and activation status</caption>
                        <thead class="border-b border-line bg-ground-deep">
                            <tr>
                                <th scope="col" class="channel-tag px-4 py-2.5">Name</th>
                                <th scope="col" class="channel-tag px-4 py-2.5">Username</th>
                                <th scope="col" class="channel-tag px-4 py-2.5">Email</th>
                                <th scope="col" class="channel-tag px-4 py-2.5">Role</th>
                                <th scope="col" class="channel-tag px-4 py-2.5">2FA</th>
                                <th scope="col" class="channel-tag px-4 py-2.5">Status</th>
                                <th scope="col" class="channel-tag px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="u in filteredUsers" :key="u.id" :data-testid="`user-${u.id}`"
                                class="border-b border-line-soft last:border-0 hover:bg-channel-soft/50">
                                <th scope="row" class="px-4 py-2.5 text-left font-medium capitalize">
                                    <input v-if="editingId === u.id" v-model="edit.full_name" type="text"
                                           :aria-label="`Full name for ${u.member_name}`"
                                           class="w-40 rounded-md border border-line px-2 py-1 text-sm font-normal" />
                                    <span v-else>{{ u.full_name || '—' }}</span>
                                </th>
                                <td class="readout px-4 py-2.5">
                                    <input v-if="editingId === u.id" v-model="edit.member_name" type="text"
                                           :aria-label="`Username for ${u.member_name}`"
                                           class="readout w-32 rounded-md border border-line px-2 py-1 text-sm" />
                                    <span v-else>{{ u.member_name }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <input v-if="editingId === u.id" v-model="edit.member_email" type="email"
                                           :aria-label="`Email for ${u.member_name}`"
                                           class="w-52 rounded-md border border-line px-2 py-1 text-sm" />
                                    <span v-else>{{ u.member_email || '—' }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <select :value="u.position" :data-testid="`position-${u.id}`"
                                            :aria-label="`Role for ${u.member_name}`"
                                            @change="changePosition(u, $event)"
                                            class="rounded-md border border-line bg-panel px-2 py-1 text-xs">
                                        <option v-for="pos in positions" :key="pos.id" :value="pos.id">{{ pos.name }}</option>
                                    </select>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span v-if="u.has_two_factor" class="rounded bg-ok-soft px-2 py-0.5 text-xs font-semibold text-ok">Enabled</span>
                                    <span v-else class="text-xs text-muted">—</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span :class="['rounded px-2 py-0.5 text-xs font-semibold', u.active ? 'bg-ok-soft text-ok' : 'bg-ground-deep text-muted']">
                                        {{ u.active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <template v-if="editingId === u.id">
                                        <button type="button" :data-testid="`save-profile-${u.id}`" @click="saveProfile(u)"
                                                class="rounded-md bg-channel px-3 py-1.5 text-xs font-semibold text-panel">Save</button>
                                        <button type="button" @click="cancelEdit"
                                                class="ml-2 rounded-md border border-line px-3 py-1.5 text-xs font-semibold text-body">Cancel</button>
                                    </template>
                                    <template v-else>
                                    <button type="button" :data-testid="`edit-profile-${u.id}`" @click="startEdit(u)"
                                            :aria-label="`Edit the account details for ${u.member_name}`"
                                            class="mr-2 rounded-md border border-line px-3 py-1.5 text-xs font-semibold text-body transition hover:bg-ground">
                                        Edit
                                    </button>
                                    <button type="button" :data-testid="`toggle-active-${u.id}`" @click="toggleActive(u)"
                                            :aria-label="`${u.active ? 'Deactivate' : 'Activate'} the account for ${u.member_name}`"
                                            :class="['rounded-md px-3 py-1.5 text-xs font-semibold transition', u.active
                                                ? 'border border-critical text-critical hover:bg-critical-soft'
                                                : 'bg-ok text-white hover:bg-ok/90']">
                                        {{ u.active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="!filteredUsers.length">
                                <td colspan="7" class="px-4 py-6 text-center text-sm text-muted">No users match the search.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
