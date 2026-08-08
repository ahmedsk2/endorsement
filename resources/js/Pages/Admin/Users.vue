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
    // False for a scoped (Chief Resident) manager: the server already limits the payload
    // to residents; the page additionally hides the full-manager-only controls
    // (role changes, profile edits) the server would 403 anyway.
    canManageAll: { type: Boolean, default: true },
    // Open invitations, and the roles THIS manager may invite into — a Chief Resident sees
    // Resident only, matching the server rule in App\Support\ManagerScope.
    invitations: { type: Array, default: () => [] },
    invitablePositions: { type: Object, default: () => ({}) },
});

const search = ref('');

/*
 * Invitations. The link comes back in a one-shot flash — it is a bearer credential that
 * creates an account, so it is stored hashed and this is the only moment it exists in a
 * readable form. If the admin loses it, the answer is a new invitation, not a lookup.
 */
const invite = reactive({ member_email: '', position: 4 });
const inviteSending = ref(false);
const invitationLink = ref(null);
const copied = ref(false);

const sendInvite = () => {
    inviteSending.value = true;
    copied.value = false;

    router.post('/admin/invitations', { ...invite }, {
        preserveScroll: true,
        onSuccess: (page) => {
            invitationLink.value = page.props.flash?.invitation_link ?? null;
            invite.member_email = '';
        },
        onFinish: () => { inviteSending.value = false; },
    });
};

const copyLink = async () => {
    try {
        await navigator.clipboard.writeText(invitationLink.value);
        copied.value = true;
    } catch {
        // Clipboard access can be refused (insecure context, permissions). The link is on
        // screen and selectable, so this is a convenience failing, not the feature failing.
        copied.value = false;
    }
};

const revokeInvite = (i) => {
    if (!confirm(`Revoke the invitation for ${i.member_email}? The link stops working immediately.`)) return;
    router.delete(`/admin/invitations/${i.id}`, { preserveScroll: true });
};

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

// Task 6 / Decision A — `requested_at` used to arrive as an ISO string and be locale-formatted
// in the browser here, which renders in the BROWSER's own locale and timezone rather than the
// instance's. UserManagementController::index() now sends it already formatted, so this
// component performs no date construction at all (guarded by CalendarIsTheOnlyConverterTest).
</script>

<template>
    <AppLayout title="Users">
        <div class="mx-auto max-w-5xl space-y-8">
            <!-- Validation errors from approve / lockout guards surface here (Inertia errors prop). -->
            <div v-if="Object.keys(errors).length" role="alert"
                 class="channel-bar channel-bar-critical rounded-md bg-critical-soft px-4 py-2 text-sm text-critical">
                <p v-for="(message, key) in errors" :key="key">{{ message }}</p>
            </div>

            <!--
              Invitations. Since 2026-07-27 this is the ONLY way an account is created —
              /register is closed. The link is shown once, here, and never again: it is a
              bearer credential, so it is stored hashed and cannot be re-displayed.
            -->
            <section aria-labelledby="invite-heading">
                <h2 id="invite-heading" class="mb-3 text-base font-semibold text-ink">Invite someone</h2>

                <form @submit.prevent="sendInvite" class="flex flex-wrap items-end gap-3 rounded-md border border-line bg-panel p-4">
                    <div class="min-w-56 flex-1">
                        <label class="channel-tag mb-1.5 block" for="invite-email">Email address</label>
                        <input id="invite-email" v-model="invite.member_email" type="email" required
                               class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                    </div>
                    <div>
                        <label class="channel-tag mb-1.5 block" for="invite-position">Role</label>
                        <select id="invite-position" v-model.number="invite.position"
                                class="rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel">
                            <option v-for="(label, value) in invitablePositions" :key="value" :value="Number(value)">{{ label }}</option>
                        </select>
                    </div>
                    <button type="submit" :disabled="inviteSending"
                            class="rounded-md bg-channel px-4 py-2 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                        Send invitation
                    </button>
                </form>

                <!-- Shown once. Copy it now or issue a new invitation. -->
                <div v-if="invitationLink" data-testid="invitation-link"
                     class="channel-bar channel-bar-ok mt-3 rounded-md bg-ok-soft px-4 py-3 text-sm">
                    <p class="mb-1 font-semibold text-ink">Invitation link — shown only once</p>
                    <p class="readout break-all text-xs text-ink">{{ invitationLink }}</p>
                    <button type="button" class="mt-2 text-xs font-semibold text-channel-ink hover:underline"
                            @click="copyLink">{{ copied ? 'Copied' : 'Copy link' }}</button>
                </div>

                <div v-if="invitations.length" class="mt-4 overflow-x-auto rounded-md border border-line">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-panel-soft">
                            <tr>
                                <th class="channel-tag px-3 py-2">Email</th>
                                <th class="channel-tag px-3 py-2">Role</th>
                                <th class="channel-tag px-3 py-2">Invited by</th>
                                <th class="channel-tag px-3 py-2">Expires</th>
                                <th class="channel-tag px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="i in invitations" :key="i.id" class="border-t border-line">
                                <td class="px-3 py-2 text-ink">{{ i.member_email }}</td>
                                <td class="px-3 py-2 text-muted">{{ i.position_label }}</td>
                                <td class="px-3 py-2 text-muted">{{ i.invited_by || '—' }}</td>
                                <td class="readout px-3 py-2 text-muted">{{ i.expires_at }}</td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button" class="text-xs font-semibold text-critical hover:underline"
                                            @click="revokeInvite(i)">Revoke</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="mt-3 text-sm text-muted">No invitations are waiting to be accepted.</p>
            </section>

            <!-- Pending registrations -->
            <section v-if="pending.length" aria-labelledby="pending-heading">
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
                                <td class="readout px-4 py-2.5 text-muted">{{ p.requested_at || '—' }}</td>
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
                                <!--
                                  The leaver control, made visible. The chosen process is a
                                  periodic review of active accounts, and that review is only
                                  as good as this column: without it, an account belonging to
                                  someone who left six months ago looks exactly like one in
                                  daily use.
                                -->
                                <th scope="col" class="channel-tag px-4 py-2.5">Last signed in</th>
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
                                    <select v-if="canManageAll" :value="u.position" :data-testid="`position-${u.id}`"
                                            :aria-label="`Role for ${u.member_name}`"
                                            @change="changePosition(u, $event)"
                                            class="rounded-md border border-line bg-panel px-2 py-1 text-xs">
                                        <option v-for="pos in positions" :key="pos.id" :value="pos.id">{{ pos.name }}</option>
                                    </select>
                                    <span v-else class="text-xs text-body">{{ positionName(u.position) }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span v-if="u.has_two_factor" class="rounded bg-ok-soft px-2 py-0.5 text-xs font-semibold text-ok">Enabled</span>
                                    <span v-else class="text-xs text-muted">—</span>
                                </td>
                                <td class="px-4 py-2.5" :data-testid="`last-login-${u.id}`">
                                    <span v-if="!u.last_login_at" class="text-xs text-muted">Never</span>
                                    <span v-else class="readout text-xs"
                                          :class="u.dormant_days >= 90 ? 'font-semibold text-caution' : 'text-body'">
                                        {{ u.last_login_at }}
                                        <span v-if="u.dormant_days >= 90" class="block text-xs font-normal">
                                            {{ u.dormant_days }} days ago
                                        </span>
                                    </span>
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
                                    <button v-if="canManageAll" type="button" :data-testid="`edit-profile-${u.id}`" @click="startEdit(u)"
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
