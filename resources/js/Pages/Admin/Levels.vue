<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → Structure → Levels (Munawib LV-01).
 *
 * Create, rename, reorder, toggle `external`, retire. There is no delete — a level that has
 * ever been assigned to a person is the row that assignment points at, so `active` is the only
 * "this level is done" state (LV-01/LV-04: hides forward, never deletes).
 *
 * OWNER DECISION A (2026-08-09): there is no `terminal` flag and this screen offers no toggle
 * for one. Whatever P1c's annual-promotion screen needs, it takes the target level as explicit
 * input — nothing here encodes "one step up".
 *
 * Mobile cards + desktop table, matching Units.vue.
 */
const props = defineProps({
    levels: { type: Array, default: () => [] },
});

const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';

const nextDisplayOrder = () => (props.levels.reduce((max, l) => Math.max(max, l.display_order), 0) + 10);

// --- Create -----------------------------------------------------------------------------

const createOpen = ref(false);

const blankCreateForm = () => ({
    code: '',
    name: '',
    display_order: nextDisplayOrder(),
    active: true,
    external: false,
});

const createForm = useForm(blankCreateForm());

const submitCreate = () => {
    createForm.post('/admin/structure/levels', {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            createForm.display_order = nextDisplayOrder();
            createOpen.value = false;
        },
    });
};

// --- Edit (one level open at a time) ------------------------------------------------------

const editingId = ref(null);

const editForm = useForm({
    code: '',
    name: '',
    display_order: 1,
    active: true,
    external: false,
});

const startEdit = (level) => {
    editingId.value = level.id;
    editForm.clearErrors();
    editForm.code = level.code;
    editForm.name = level.name;
    editForm.display_order = level.display_order;
    editForm.active = level.active;
    editForm.external = level.external;
};

const cancelEdit = () => {
    editingId.value = null;
    editForm.clearErrors();
};

const submitEdit = (level) => {
    editForm.patch(`/admin/structure/levels/${level.id}`, {
        preserveScroll: true,
        onSuccess: () => { editingId.value = null; },
    });
};

// --- Retire / reactivate (LV-04) -------------------------------------------------------

const activeForm = useForm({ active: true });

const toggleActive = (level) => {
    activeForm.active = !level.active;
    activeForm.patch(`/admin/structure/levels/${level.id}/active`, { preserveScroll: true });
};
</script>

<template>
    <AppLayout title="Levels">
        <div class="mx-auto max-w-4xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Training levels</h2>
                <p class="text-sm text-muted">
                    The training-level ladder (R1…R4, plus External). Names and order are the
                    department's to edit. There is no delete: a level that anyone has ever held
                    is never removed, because past history still resolves through it.
                </p>
            </div>

            <!-- New level -->
            <section class="rounded-md border border-line bg-panel p-5">
                <button type="button" class="text-sm font-semibold text-ink" @click="createOpen = !createOpen">
                    {{ createOpen ? '– Hide' : '+ New level' }}
                </button>

                <form v-if="createOpen" class="mt-4 space-y-4" @submit.prevent="submitCreate">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="channel-tag mb-1 block" for="new-code">Code</label>
                            <input id="new-code" v-model="createForm.code" type="text" class="readout" :class="inputClass"
                                   placeholder="R5" maxlength="20" />
                            <p v-if="createForm.errors.code" class="mt-1 text-xs text-critical">{{ createForm.errors.code }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="new-display-order">Order</label>
                            <input id="new-display-order" v-model.number="createForm.display_order" type="number" class="readout" :class="inputClass" />
                            <p v-if="createForm.errors.display_order" class="mt-1 text-xs text-critical">{{ createForm.errors.display_order }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="channel-tag mb-1 block" for="new-name">Name</label>
                            <input id="new-name" v-model="createForm.name" type="text" :class="inputClass" />
                            <p v-if="createForm.errors.name" class="mt-1 text-xs text-critical">{{ createForm.errors.name }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="createForm.active" type="checkbox" />
                            Active
                        </label>
                        <label class="flex items-center gap-2 text-sm text-body">
                            <input v-model="createForm.external" type="checkbox" />
                            External (outside the ladder — never promoted)
                        </label>
                    </div>

                    <div class="flex items-center gap-3 border-t border-line-soft pt-4">
                        <button type="submit" :disabled="createForm.processing"
                                class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                            Create level
                        </button>
                        <span v-if="createForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                    </div>
                </form>
            </section>

            <!-- Phone: one card per level. -->
            <div class="space-y-3 lg:hidden">
                <article v-for="level in levels" :key="level.id" class="rounded-md border border-line bg-panel p-4">
                    <div v-if="editingId !== level.id">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="readout text-sm font-semibold text-ink">{{ level.code }}</span>
                            <span class="channel-tag">{{ level.active ? 'Active' : 'Retired' }}</span>
                        </div>
                        <p class="text-sm text-body">{{ level.name }}</p>
                        <p v-if="level.external" class="channel-tag mt-1">External</p>
                        <div class="mt-3 flex gap-3">
                            <button type="button" class="text-xs font-semibold text-channel-ink" @click="startEdit(level)">Edit</button>
                            <button type="button" class="text-xs font-semibold text-critical" @click="toggleActive(level)">
                                {{ level.active ? 'Retire' : 'Reactivate' }}
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
                            <th scope="col" class="channel-tag px-4 py-2">External</th>
                            <th scope="col" class="channel-tag px-4 py-2">Status</th>
                            <th scope="col" class="channel-tag px-4 py-2">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="level in levels" :key="level.id">
                            <tr v-if="editingId !== level.id" class="border-t border-line">
                                <td class="readout px-4 py-2 text-body">{{ level.display_order }}</td>
                                <td class="readout px-4 py-2 font-semibold text-ink">{{ level.code }}</td>
                                <td class="px-4 py-2 text-body">{{ level.name }}</td>
                                <td class="px-4 py-2 text-body">{{ level.external ? 'Yes' : '—' }}</td>
                                <td class="px-4 py-2">
                                    <span class="channel-tag">{{ level.active ? 'Active' : 'Retired' }}</span>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <button type="button" class="text-xs font-semibold text-channel-ink" @click="startEdit(level)">Edit</button>
                                    <button type="button" class="ml-3 text-xs font-semibold text-critical" @click="toggleActive(level)">
                                        {{ level.active ? 'Retire' : 'Reactivate' }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-else class="border-t border-line bg-ground-deep">
                                <td colspan="6" class="px-4 py-4">
                                    <form class="space-y-4" @submit.prevent="submitEdit(level)">
                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-code-${level.id}`">Code</label>
                                                <input :id="`edit-code-${level.id}`" v-model="editForm.code" type="text" class="readout" :class="inputClass" maxlength="20" />
                                                <p v-if="editForm.errors.code" class="mt-1 text-xs text-critical">{{ editForm.errors.code }}</p>
                                            </div>
                                            <div>
                                                <label class="channel-tag mb-1 block" :for="`edit-order-${level.id}`">Order</label>
                                                <input :id="`edit-order-${level.id}`" v-model.number="editForm.display_order" type="number" class="readout" :class="inputClass" />
                                                <p v-if="editForm.errors.display_order" class="mt-1 text-xs text-critical">{{ editForm.errors.display_order }}</p>
                                            </div>
                                            <div class="sm:col-span-1">
                                                <label class="channel-tag mb-1 block" :for="`edit-name-${level.id}`">Name</label>
                                                <input :id="`edit-name-${level.id}`" v-model="editForm.name" type="text" :class="inputClass" />
                                                <p v-if="editForm.errors.name" class="mt-1 text-xs text-critical">{{ editForm.errors.name }}</p>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap gap-4">
                                            <label class="flex items-center gap-2 text-sm text-body">
                                                <input v-model="editForm.active" type="checkbox" />
                                                Active
                                            </label>
                                            <label class="flex items-center gap-2 text-sm text-body">
                                                <input v-model="editForm.external" type="checkbox" />
                                                External (outside the ladder — never promoted)
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
