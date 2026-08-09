<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → People (Munawib PE-01…03, LV-02…04, ST-04).
 *
 * PERSON-scoped, where Admin → Users is ACCOUNT-scoped: a roster-only person (never logged in)
 * appears here and nowhere on Admin → Users, because that screen's list is
 * `User::query()->join('people', ...)`.
 *
 * Contact fields (Task 2, PE-02): `phone` renders only when the key is PRESENT in a person's
 * props — `'phone' in p`, never `p.phone` — because absent and null are different facts (a
 * withheld number vs one nobody recorded) and the header must not appear for a viewer the
 * policy has refused. Every row on this page was projected by `App\Support\PersonPresenter`,
 * so the key is either present for everyone in the list or absent for everyone in it. (On THIS
 * screen it is always present: the route itself requires `people.manage`, and a holder of that
 * capability always passes `PersonPolicy::viewContact()`/`viewNotes()`'s first branch.)
 *
 * "Status" is always DERIVED (active/retired × account/roster-only) — there is no stored
 * `person_status` column, on purpose (design §5.1 deviation 3).
 *
 * NOTHING HERE CREATES AN ACCOUNT — position is a JOB ROLE, not a login. There is no delete
 * control: people are deactivated (the "Retire" toggle below), never deleted (owner ruling).
 *
 * Mobile cards + desktop table, matching Levels.vue and Units.vue. Create/edit mirror
 * `Levels.vue`'s `createOpen` / `editingId` structure verbatim.
 */
const props = defineProps({
    people: { type: Array, default: () => [] },
    positions: { type: Array, default: () => [] },
    contact_visibility: { type: String, default: 'admins' },
    contact_visibilities: { type: Object, default: () => ({}) },
});

const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';

const search = ref('');

const positionName = (id) => props.positions.find((p) => p.id === id)?.name || `Role ${id}`;

// Every row was projected by the same presenter for the same viewer, so checking the first
// row's shape is enough to know whether the `phone` column applies to the whole list.
const showsPhone = computed(() => props.people.length > 0 && 'phone' in props.people[0]);

const filteredPeople = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (q === '') return props.people;

    return props.people.filter((p) =>
        [p.full_name, p.short_name].some((v) => (v || '').toLowerCase().includes(q)));
});

// --- Contact visibility (PE-02's department setting) ------------------------------------

const visibilityForm = useForm({ contact_visibility: props.contact_visibility });

const submitVisibility = () => {
    visibilityForm.patch('/admin/people/visibility', { preserveScroll: true });
};

// --- Constraints: a small JSON object edited as text ------------------------------------
//
// `constraints` (PE-01) is structured JSON the scheduling solver reads directly (P1d); there is
// no dedicated field-by-field editor yet, so it round-trips through a plain textarea. Blank
// clears it to null; invalid JSON is a client-side error that blocks submit rather than sending
// malformed data the server would 422 on anyway.
const parseConstraints = (text, formErrors) => {
    const trimmed = text.trim();

    if (trimmed === '') return null;

    try {
        const parsed = JSON.parse(trimmed);

        if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
            throw new Error('not an object');
        }

        return parsed;
    } catch {
        formErrors.constraints = 'Enter valid JSON (e.g. {"no_nights": true}), or leave blank.';

        return undefined;
    }
};

// --- Create -------------------------------------------------------------------------------

const createOpen = ref(false);

const blankCreateForm = () => ({
    full_name: '',
    short_name: '',
    position: props.positions[0]?.id ?? 4,
    email: '',
    phone: '',
    joined_at: '',
    notes: '',
    constraintsText: '',
    external: false,
    active: true,
});

const createForm = useForm(blankCreateForm());

const submitCreate = () => {
    createForm.clearErrors();
    const constraints = parseConstraints(createForm.constraintsText, createForm.errors);
    if (constraints === undefined) return;

    createForm
        .transform((data) => ({ ...data, constraints, joined_at: data.joined_at || null }))
        .post('/admin/people', {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset();
                createOpen.value = false;
            },
        });
};

// --- Edit (one person open at a time) ----------------------------------------------------

const editingId = ref(null);

const editForm = useForm({
    full_name: '',
    short_name: '',
    position: 4,
    email: '',
    phone: '',
    joined_at: '',
    notes: '',
    constraintsText: '',
    external: false,
    active: true,
});

const startEdit = (person) => {
    editingId.value = person.id;
    editForm.clearErrors();
    editForm.full_name = person.full_name;
    editForm.short_name = person.short_name || '';
    editForm.position = person.position;
    editForm.email = person.email || '';
    editForm.phone = 'phone' in person ? (person.phone || '') : '';
    editForm.joined_at = person.joined_at || '';
    editForm.notes = 'notes' in person ? (person.notes || '') : '';
    editForm.constraintsText = ('constraints' in person && person.constraints)
        ? JSON.stringify(person.constraints)
        : '';
    editForm.external = person.external;
    editForm.active = person.active;
};

const cancelEdit = () => {
    editingId.value = null;
    editForm.clearErrors();
};

const submitEdit = (person) => {
    editForm.clearErrors();
    const constraints = parseConstraints(editForm.constraintsText, editForm.errors);
    if (constraints === undefined) return;

    editForm
        .transform((data) => ({ ...data, constraints, joined_at: data.joined_at || null }))
        .patch(`/admin/people/${person.id}`, {
            preserveScroll: true,
            onSuccess: () => { editingId.value = null; },
        });
};
</script>

<template>
    <AppLayout title="People">
        <div class="mx-auto max-w-6xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">People</h2>
                <p class="text-sm text-muted">
                    The departmental roster — everyone named on a sheet or a rota, whether or not
                    they have ever logged in. A roster-only person (an external consultant, say)
                    is invisible to Admin → Users by construction; this screen is where they live.
                    Creating or editing a person here never creates an account — the invitation
                    flow (Admin → Users) is the only way one is made.
                </p>
            </div>

            <section v-if="Object.keys(contact_visibilities).length" class="rounded-md border border-line bg-panel p-5">
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="submitVisibility">
                    <div>
                        <label class="channel-tag mb-1 block" for="contact-visibility">Contact visibility</label>
                        <select id="contact-visibility" v-model="visibilityForm.contact_visibility"
                                class="w-full max-w-xs rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none">
                            <option v-for="(label, key) in contact_visibilities" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <p v-if="visibilityForm.errors.contact_visibility" class="mt-1 text-xs text-critical">
                            {{ visibilityForm.errors.contact_visibility }}
                        </p>
                    </div>
                    <button type="submit" :disabled="visibilityForm.processing"
                            class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                        Save
                    </button>
                    <span v-if="visibilityForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                </form>
                <p class="mt-2 text-xs text-muted">
                    Notes are always restricted to roster managers, whatever this is set to.
                </p>
            </section>

            <!-- New person -->
            <section class="rounded-md border border-line bg-panel p-5">
                <button type="button" class="text-sm font-semibold text-ink" @click="createOpen = !createOpen">
                    {{ createOpen ? '– Hide' : '+ New person' }}
                </button>

                <form v-if="createOpen" class="mt-4 space-y-4" @submit.prevent="submitCreate">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="channel-tag mb-1 block" for="new-full-name">Full name</label>
                            <input id="new-full-name" v-model="createForm.full_name" type="text" :class="inputClass" maxlength="255" />
                            <p v-if="createForm.errors.full_name" class="mt-1 text-xs text-critical">{{ createForm.errors.full_name }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-short-name">Short name (rota handle)</label>
                            <input id="new-short-name" v-model="createForm.short_name" type="text" :class="inputClass" maxlength="50" />
                            <p v-if="createForm.errors.short_name" class="mt-1 text-xs text-critical">{{ createForm.errors.short_name }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-position">Role</label>
                            <select id="new-position" v-model.number="createForm.position" :class="inputClass">
                                <option v-for="p in positions" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                            <p v-if="createForm.errors.position" class="mt-1 text-xs text-critical">{{ createForm.errors.position }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-joined-at">Joined</label>
                            <input id="new-joined-at" v-model="createForm.joined_at" type="date" :class="inputClass" />
                            <p v-if="createForm.errors.joined_at" class="mt-1 text-xs text-critical">{{ createForm.errors.joined_at }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-email">Email</label>
                            <input id="new-email" v-model="createForm.email" type="email" :class="inputClass" maxlength="255" />
                            <p v-if="createForm.errors.email" class="mt-1 text-xs text-critical">{{ createForm.errors.email }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-phone">Phone</label>
                            <input id="new-phone" v-model="createForm.phone" type="text" :class="inputClass" maxlength="32" />
                            <p v-if="createForm.errors.phone" class="mt-1 text-xs text-critical">{{ createForm.errors.phone }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="channel-tag mb-1 block" for="new-notes">Notes</label>
                            <textarea id="new-notes" v-model="createForm.notes" :class="inputClass" rows="2" maxlength="5000"></textarea>
                            <p v-if="createForm.errors.notes" class="mt-1 text-xs text-critical">{{ createForm.errors.notes }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="channel-tag mb-1 block" for="new-constraints">Scheduling constraints (JSON, optional)</label>
                            <textarea id="new-constraints" v-model="createForm.constraintsText" :class="inputClass" rows="2"
                                      placeholder='{"no_nights": true}'></textarea>
                            <p v-if="createForm.errors.constraints" class="mt-1 text-xs text-critical">{{ createForm.errors.constraints }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="createForm.active" type="checkbox" />
                            Active
                        </label>
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="createForm.external" type="checkbox" />
                            External rotator
                        </label>
                    </div>

                    <div class="flex items-center gap-3 border-t border-line-soft pt-4">
                        <button type="submit" :disabled="createForm.processing"
                                class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                            Create person
                        </button>
                        <span v-if="createForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                    </div>
                </form>
            </section>

            <div>
                <label class="channel-tag mb-1 block" for="people-search">Search</label>
                <input id="people-search" v-model="search" type="search"
                       class="w-full max-w-sm rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none"
                       placeholder="Name…" />
            </div>

            <!-- Phone: one card per person. -->
            <div class="space-y-3 lg:hidden">
                <article v-for="person in filteredPeople" :key="person.id" class="rounded-md border border-line bg-panel p-4">
                    <div v-if="editingId !== person.id">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="readout text-sm font-semibold text-ink">{{ person.full_name }}</span>
                            <span class="channel-tag">{{ person.active ? 'Active' : 'Retired' }}</span>
                        </div>
                        <p class="text-sm text-body">{{ positionName(person.position) }} · {{ person.level?.code ?? '—' }}</p>
                        <p v-if="person.short_name" class="text-xs text-muted">{{ person.short_name }}</p>
                        <p v-if="'phone' in person" class="readout text-xs text-body">{{ person.phone || '—' }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span class="channel-tag">{{ person.has_account ? 'Account' : 'Roster only' }}</span>
                            <span v-if="person.external" class="channel-tag">External</span>
                        </div>
                        <div class="mt-3 flex gap-3">
                            <button type="button" class="text-xs font-semibold text-channel-ink" @click="startEdit(person)">Edit</button>
                        </div>
                    </div>
                    <p v-else class="text-xs text-muted">Editing below — use the desktop table for the full form, or resize the window.</p>
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
                            <th scope="col" class="channel-tag px-4 py-2">Level</th>
                            <th v-if="showsPhone" scope="col" class="channel-tag px-4 py-2">Phone</th>
                            <th scope="col" class="channel-tag px-4 py-2">Status</th>
                            <th scope="col" class="channel-tag px-4 py-2">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="person in filteredPeople" :key="person.id">
                            <tr v-if="editingId !== person.id" class="border-t border-line">
                                <td class="readout px-4 py-2 font-semibold text-ink">{{ person.full_name }}</td>
                                <td class="readout px-4 py-2 text-body">{{ person.short_name || '—' }}</td>
                                <td class="px-4 py-2 text-body">{{ positionName(person.position) }}</td>
                                <td class="readout px-4 py-2 text-body">{{ person.level?.code ?? '—' }}</td>
                                <td v-if="'phone' in person" class="readout px-4 py-2 text-body">{{ person.phone || '—' }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex flex-wrap gap-2">
                                        <span class="channel-tag">{{ person.active ? 'Active' : 'Retired' }}</span>
                                        <span class="channel-tag">{{ person.has_account ? 'Account' : 'Roster only' }}</span>
                                        <span v-if="person.external" class="channel-tag">External</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <button type="button" class="text-xs font-semibold text-channel-ink" @click="startEdit(person)">Edit</button>
                                </td>
                            </tr>
                            <tr v-else class="border-t border-line bg-ground-deep">
                                <td :colspan="showsPhone ? 7 : 6" class="px-4 py-4">
                                    <form class="space-y-4" @submit.prevent="submitEdit(person)">
                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-full-name-${person.id}`">Full name</label>
                                                <input :id="`edit-full-name-${person.id}`" v-model="editForm.full_name" type="text" :class="inputClass" maxlength="255" />
                                                <p v-if="editForm.errors.full_name" class="mt-1 text-xs text-critical">{{ editForm.errors.full_name }}</p>
                                            </div>
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-short-name-${person.id}`">Short name</label>
                                                <input :id="`edit-short-name-${person.id}`" v-model="editForm.short_name" type="text" :class="inputClass" maxlength="50" />
                                                <p v-if="editForm.errors.short_name" class="mt-1 text-xs text-critical">{{ editForm.errors.short_name }}</p>
                                            </div>
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-position-${person.id}`">Role</label>
                                                <select :id="`edit-position-${person.id}`" v-model.number="editForm.position" :class="inputClass">
                                                    <option v-for="p in positions" :key="p.id" :value="p.id">{{ p.name }}</option>
                                                </select>
                                                <p v-if="editForm.errors.position" class="mt-1 text-xs text-critical">{{ editForm.errors.position }}</p>
                                            </div>
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-joined-at-${person.id}`">Joined</label>
                                                <input :id="`edit-joined-at-${person.id}`" v-model="editForm.joined_at" type="date" :class="inputClass" />
                                                <p v-if="editForm.errors.joined_at" class="mt-1 text-xs text-critical">{{ editForm.errors.joined_at }}</p>
                                            </div>
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-email-${person.id}`">Email</label>
                                                <input :id="`edit-email-${person.id}`" v-model="editForm.email" type="email" :class="inputClass" maxlength="255" />
                                                <p v-if="editForm.errors.email" class="mt-1 text-xs text-critical">{{ editForm.errors.email }}</p>
                                            </div>
                                            <div v-if="'phone' in person">
                                                <label class="channel-tag mb-1 block" :for="`edit-phone-${person.id}`">Phone</label>
                                                <input :id="`edit-phone-${person.id}`" v-model="editForm.phone" type="text" :class="inputClass" maxlength="32" />
                                                <p v-if="editForm.errors.phone" class="mt-1 text-xs text-critical">{{ editForm.errors.phone }}</p>
                                            </div>
                                            <div class="sm:col-span-3" v-if="'notes' in person">
                                                <label class="channel-tag mb-1 block" :for="`edit-notes-${person.id}`">Notes</label>
                                                <textarea :id="`edit-notes-${person.id}`" v-model="editForm.notes" :class="inputClass" rows="2" maxlength="5000"></textarea>
                                                <p v-if="editForm.errors.notes" class="mt-1 text-xs text-critical">{{ editForm.errors.notes }}</p>
                                            </div>
                                            <div class="sm:col-span-3">
                                                <label class="channel-tag mb-1 block" :for="`edit-constraints-${person.id}`">Scheduling constraints (JSON, optional)</label>
                                                <textarea :id="`edit-constraints-${person.id}`" v-model="editForm.constraintsText" :class="inputClass" rows="2"></textarea>
                                                <p v-if="editForm.errors.constraints" class="mt-1 text-xs text-critical">{{ editForm.errors.constraints }}</p>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap gap-4">
                                            <label class="flex items-center gap-2 text-sm text-body">
                                                <input v-model="editForm.active" type="checkbox" />
                                                Active
                                            </label>
                                            <label class="flex items-center gap-2 text-sm text-body">
                                                <input v-model="editForm.external" type="checkbox" />
                                                External rotator
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
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
