<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin -> Structure -> Department (Munawib ST-01, "profile").
 *
 * ONE editable field: the department's display name. The code beside it is read-only and says
 * why at the point of refusal rather than in a document nobody on this screen has open — it is
 * the key the deploy-time seed matches this institution on, so changing it creates a second
 * institution row instead of renaming this one.
 *
 * The read-only code is NOT the protection: the server accepts `name` and nothing else
 * (DepartmentProfileRequest). A disabled input is a courtesy to the operator, not a boundary.
 *
 * Free text is interpolated, never rendered as raw markup: a department name is plain text and
 * is not purified server-side.
 */
const props = defineProps({
    name: { type: String, default: '' },
    code: { type: String, default: '' },
    code_note: { type: String, default: '' },
    configurable: { type: Boolean, default: true },
});

const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none disabled:opacity-60';

// Seeded from the server's value; the server's value is authoritative on every reload.
const form = useForm({ name: props.name });
</script>

<template>
    <AppLayout title="Department">
        <div class="mx-auto max-w-2xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Department profile</h2>
                <p class="text-sm text-muted">
                    What this department is called. The name appears on screens, on the printed
                    handover sheet, and in the emails this system sends.
                </p>
            </div>

            <p v-if="!configurable" class="rounded-md border border-line bg-panel p-5 text-sm text-critical" role="alert">
                This deployment has no single active institution to configure. Run
                <code>php artisan db:seed --force</code> first.
            </p>

            <form v-else class="space-y-6" @submit.prevent="form.patch('/admin/structure/department', { preserveScroll: true })">
                <section class="channel-bar rounded-md border border-line bg-panel p-5">
                    <h3 class="mb-3 text-sm font-semibold text-ink">Name</h3>
                    <label class="channel-tag mb-1 block" for="department-name">Department name</label>
                    <input id="department-name" v-model="form.name" type="text" maxlength="255"
                           data-testid="department-name" class="readout" :class="inputClass" />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-critical">{{ form.errors.name }}</p>
                    <p class="mt-1 text-xs text-muted">
                        A rename here survives every deploy — the seed that runs on each one
                        writes this name when the department is first created and never again.
                    </p>
                </section>

                <section class="channel-bar rounded-md border border-line bg-panel p-5">
                    <h3 class="mb-3 text-sm font-semibold text-ink">Code</h3>
                    <label class="channel-tag mb-1 block" for="department-code">Institution code</label>
                    <input id="department-code" type="text" :value="code" disabled readonly
                           data-testid="department-code" class="readout" :class="inputClass" />
                    <p class="mt-1 text-xs text-muted">{{ code_note }}</p>
                </section>

                <div class="flex items-center justify-end gap-3">
                    <span v-if="form.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                    <button type="submit" :disabled="form.processing" data-testid="save-department-profile"
                            class="rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                        Save department profile
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
