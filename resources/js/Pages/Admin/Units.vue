<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → Structure → Units (Munawib UN-01…05).
 *
 * Create, rename, recolour, reorder, reflag, alias, retire. There is no delete — a unit that
 * has ever owned a handover is the row those handovers point at, so `active` is the only
 * "this unit is done" state (UN-04: hides forward, never deletes).
 *
 * Mobile cards + desktop table, matching Users.vue and Sheet.vue. Aliases are entered as a
 * comma-separated string and split on submit — a tag widget is a component this codebase does
 * not have and P1b is not the plan to introduce one.
 */
const props = defineProps({
    units: { type: Array, default: () => [] },
    palette: { type: Object, default: () => ({}) },
    reserved_codes: { type: Array, default: () => [] },
});

const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';

const flags = (unit) => [
    unit.training_rotation ? 'Rotation' : null,
    unit.call_target ? 'On-call' : null,
    unit.clinic_owner ? 'Clinics' : null,
].filter(Boolean);

const splitAliases = (value) => value.split(',').map((a) => a.trim()).filter(Boolean);

const nextDisplayOrder = () => (props.units.reduce((max, u) => Math.max(max, u.display_order), 0) + 10);

// --- Create -----------------------------------------------------------------------------

const createOpen = ref(false);

const blankCreateForm = () => ({
    code: '',
    name: '',
    name2: '',
    display_order: nextDisplayOrder(),
    active: true,
    training_rotation: false,
    call_target: false,
    clinic_owner: false,
    aliases: '',
    bar_class: 'channel-bar-slate',
});

const createForm = useForm(blankCreateForm());

const submitCreate = () => {
    createForm
        .transform((data) => ({ ...data, aliases: splitAliases(data.aliases) }))
        .post('/admin/structure/units', {
            preserveScroll: true,
            onSuccess: () => {
                createForm.reset();
                createForm.display_order = nextDisplayOrder();
                createOpen.value = false;
            },
        });
};

// --- Edit (one unit open at a time) ------------------------------------------------------

const editingId = ref(null);

const editForm = useForm({
    code: '',
    name: '',
    name2: '',
    display_order: 1,
    active: true,
    training_rotation: false,
    call_target: false,
    clinic_owner: false,
    aliases: '',
    bar_class: 'channel-bar-slate',
});

const startEdit = (unit) => {
    editingId.value = unit.id;
    editForm.clearErrors();
    editForm.code = unit.code;
    editForm.name = unit.name;
    editForm.name2 = unit.name2 ?? '';
    editForm.display_order = unit.display_order;
    editForm.active = unit.active;
    editForm.training_rotation = unit.training_rotation;
    editForm.call_target = unit.call_target;
    editForm.clinic_owner = unit.clinic_owner;
    editForm.aliases = unit.aliases.join(', ');
    editForm.bar_class = unit.bar_class;
};

const cancelEdit = () => {
    editingId.value = null;
    editForm.clearErrors();
};

const submitEdit = (unit) => {
    editForm
        .transform((data) => ({ ...data, aliases: splitAliases(data.aliases) }))
        .patch(`/admin/structure/units/${unit.id}`, {
            preserveScroll: true,
            onSuccess: () => { editingId.value = null; },
        });
};

// --- Retire / reactivate (UN-04) -------------------------------------------------------

const activeForm = useForm({ active: true });

const toggleActive = (unit) => {
    activeForm.active = !unit.active;
    activeForm.patch(`/admin/structure/units/${unit.id}/active`, { preserveScroll: true });
};
</script>

<template>
    <AppLayout title="Units">
        <div class="mx-auto max-w-5xl space-y-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-ink">Units</h2>
                    <p class="text-sm text-muted">
                        A unit is configuration, not code. Its code is its address
                        (<span class="readout">/endorsement/&lt;code&gt;</span>), so
                        <span class="readout">{{ reserved_codes.join(', ') }}</span> can never be used —
                        a unit with one of those codes would be permanently unreachable. There is no
                        delete: a retired unit is hidden going forward, never removed, because its
                        handover history still points at it.
                    </p>
                </div>
                <a href="/admin/structure/units/merge"
                   class="min-h-11 shrink-0 rounded-md border border-line bg-panel px-3 py-2 text-sm font-semibold text-body hover:bg-ground">
                    Merge units
                </a>
            </div>

            <!-- New unit -->
            <section class="channel-bar rounded-md border border-line bg-panel p-5">
                <button type="button" class="text-sm font-semibold text-ink" @click="createOpen = !createOpen">
                    {{ createOpen ? '– Hide' : '+ New unit' }}
                </button>

                <form v-if="createOpen" class="mt-4 space-y-4" @submit.prevent="submitCreate">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="channel-tag mb-1 block" for="new-code">Code</label>
                            <input id="new-code" v-model="createForm.code" type="text" class="readout" :class="inputClass"
                                   placeholder="RGH1" maxlength="20" />
                            <p v-if="createForm.errors.code" class="mt-1 text-xs text-critical">{{ createForm.errors.code }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-display-order">Order</label>
                            <input id="new-display-order" v-model.number="createForm.display_order" type="number" class="readout" :class="inputClass" />
                            <p v-if="createForm.errors.display_order" class="mt-1 text-xs text-critical">{{ createForm.errors.display_order }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-name">Name</label>
                            <input id="new-name" v-model="createForm.name" type="text" :class="inputClass" />
                            <p v-if="createForm.errors.name" class="mt-1 text-xs text-critical">{{ createForm.errors.name }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-name2">Secondary name</label>
                            <input id="new-name2" v-model="createForm.name2" type="text" :class="inputClass" />
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-bar-class">Colour</label>
                            <select id="new-bar-class" v-model="createForm.bar_class" :class="inputClass">
                                <option v-for="(label, cls) in palette" :key="cls" :value="cls">{{ label }}</option>
                            </select>
                            <span class="channel-bar mt-1 inline-block h-4 w-8 rounded-sm bg-ground" :class="createForm.bar_class"
                                  aria-hidden="true"></span>
                            <p v-if="createForm.errors.bar_class" class="mt-1 text-xs text-critical">{{ createForm.errors.bar_class }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-aliases">Aliases (comma separated)</label>
                            <input id="new-aliases" v-model="createForm.aliases" type="text" :class="inputClass" placeholder="Paeds ICU, PICU 2" />
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="createForm.active" type="checkbox" />
                            Active
                        </label>
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="createForm.training_rotation" type="checkbox" />
                            Training rotation
                        </label>
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="createForm.call_target" type="checkbox" />
                            On-call target
                        </label>
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="createForm.clinic_owner" type="checkbox" />
                            Owns clinics
                        </label>
                    </div>

                    <div class="flex items-center gap-3 border-t border-line-soft pt-4">
                        <button type="submit" :disabled="createForm.processing"
                                class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                            Create unit
                        </button>
                        <span v-if="createForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                    </div>
                </form>
            </section>

            <!-- Phone: one card per unit. -->
            <div class="space-y-3 lg:hidden">
                <article v-for="unit in units" :key="unit.id"
                         class="channel-bar rounded-md border border-line bg-panel p-4"
                         :class="unit.bar_class">
                    <div v-if="editingId !== unit.id">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="readout text-sm font-semibold text-ink">{{ unit.code }}</span>
                            <span class="channel-tag">{{ unit.active ? 'Active' : 'Retired' }}</span>
                        </div>
                        <p class="text-sm text-body">{{ unit.name }}</p>
                        <p v-if="flags(unit).length" class="channel-tag mt-1">{{ flags(unit).join(' · ') }}</p>
                        <p v-if="unit.aliases.length" class="mt-1 text-xs text-muted">
                            Also known as: {{ unit.aliases.join(', ') }}
                        </p>
                        <div class="mt-3 flex gap-3">
                            <button type="button" class="text-xs font-semibold text-channel-ink" @click="startEdit(unit)">Edit</button>
                            <button type="button" class="text-xs font-semibold text-critical" @click="toggleActive(unit)">
                                {{ unit.active ? 'Retire' : 'Reactivate' }}
                            </button>
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
                            <th scope="col" class="channel-tag px-4 py-2">Order</th>
                            <th scope="col" class="channel-tag px-4 py-2">Code</th>
                            <th scope="col" class="channel-tag px-4 py-2">Name</th>
                            <th scope="col" class="channel-tag px-4 py-2">Colour</th>
                            <th scope="col" class="channel-tag px-4 py-2">Used for</th>
                            <th scope="col" class="channel-tag px-4 py-2">Aliases</th>
                            <th scope="col" class="channel-tag px-4 py-2">Status</th>
                            <th scope="col" class="channel-tag px-4 py-2">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="unit in units" :key="unit.id">
                            <tr v-if="editingId !== unit.id" class="border-t border-line">
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
                                <td class="px-4 py-2 text-right">
                                    <button type="button" class="text-xs font-semibold text-channel-ink" @click="startEdit(unit)">Edit</button>
                                    <button type="button" class="ml-3 text-xs font-semibold text-critical" @click="toggleActive(unit)">
                                        {{ unit.active ? 'Retire' : 'Reactivate' }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-else class="border-t border-line bg-ground-deep">
                                <td colspan="8" class="px-4 py-4">
                                    <form class="space-y-4" @submit.prevent="submitEdit(unit)">
                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-code-${unit.id}`">Code</label>
                                                <input :id="`edit-code-${unit.id}`" v-model="editForm.code" type="text" class="readout" :class="inputClass" maxlength="20" />
                                                <p v-if="editForm.errors.code" class="mt-1 text-xs text-critical">{{ editForm.errors.code }}</p>
                                            </div>
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-order-${unit.id}`">Order</label>
                                                <input :id="`edit-order-${unit.id}`" v-model.number="editForm.display_order" type="number" class="readout" :class="inputClass" />
                                                <p v-if="editForm.errors.display_order" class="mt-1 text-xs text-critical">{{ editForm.errors.display_order }}</p>
                                            </div>
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-bar-${unit.id}`">Colour</label>
                                                <select :id="`edit-bar-${unit.id}`" v-model="editForm.bar_class" :class="inputClass">
                                                    <option v-for="(label, cls) in palette" :key="cls" :value="cls">{{ label }}</option>
                                                </select>
                                                <span class="channel-bar mt-1 inline-block h-4 w-8 rounded-sm bg-ground" :class="editForm.bar_class" aria-hidden="true"></span>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label class="channel-tag mb-1 block" :for="`edit-name-${unit.id}`">Name</label>
                                                <input :id="`edit-name-${unit.id}`" v-model="editForm.name" type="text" :class="inputClass" />
                                                <p v-if="editForm.errors.name" class="mt-1 text-xs text-critical">{{ editForm.errors.name }}</p>
                                            </div>
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-name2-${unit.id}`">Secondary name</label>
                                                <input :id="`edit-name2-${unit.id}`" v-model="editForm.name2" type="text" :class="inputClass" />
                                            </div>
                                            <div class="sm:col-span-3">
                                                <label class="channel-tag mb-1 block" :for="`edit-aliases-${unit.id}`">Aliases (comma separated)</label>
                                                <input :id="`edit-aliases-${unit.id}`" v-model="editForm.aliases" type="text" :class="inputClass" />
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap gap-4">
                                            <label class="flex items-center gap-2 text-sm text-body">
                                                <input v-model="editForm.active" type="checkbox" />
                                                Active
                                            </label>
                                            <label class="flex items-center gap-2 text-sm text-body">
                                                <input v-model="editForm.training_rotation" type="checkbox" />
                                                Training rotation
                                            </label>
                                            <label class="flex items-center gap-2 text-sm text-body">
                                                <input v-model="editForm.call_target" type="checkbox" />
                                                On-call target
                                            </label>
                                            <label class="flex items-center gap-2 text-sm text-body">
                                                <input v-model="editForm.clinic_owner" type="checkbox" />
                                                Owns clinics
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
