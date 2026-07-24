<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * F1 — own-profile editing (re-platform of legacy profile.php). Only the three identity
 * fields; the server binds the update to the SESSION user (any submitted id is ignored) and
 * uniqueness-checks against both `users` and the pending-registration queue.
 */
const props = defineProps({
    profile: { type: Object, required: true },
});

const form = useForm({
    full_name: props.profile.full_name || '',
    member_name: props.profile.member_name || '',
    member_email: props.profile.member_email || '',
});

const submit = () => form.patch('/profile', { preserveScroll: true });
</script>

<template>
    <AppLayout title="My Profile">
        <div class="mx-auto max-w-lg">
            <form class="space-y-4 rounded-md border border-line bg-panel p-6"
                  @submit.prevent="submit">
                <div>
                    <label for="full_name" class="mb-1 block text-sm font-medium text-ink">Full name</label>
                    <input id="full_name" v-model="form.full_name" type="text" required data-testid="full-name"
                           class="w-full rounded-md border border-line bg-panel px-3 py-2 text-sm" />
                    <p v-if="form.errors.full_name" class="mt-1 text-xs text-critical">{{ form.errors.full_name }}</p>
                </div>

                <div>
                    <label for="member_name" class="mb-1 block text-sm font-medium text-ink">Username</label>
                    <input id="member_name" v-model="form.member_name" type="text" required data-testid="member-name"
                           class="w-full rounded-md border border-line bg-panel px-3 py-2 text-sm" />
                    <p v-if="form.errors.member_name" class="mt-1 text-xs text-critical">{{ form.errors.member_name }}</p>
                </div>

                <div>
                    <label for="member_email" class="mb-1 block text-sm font-medium text-ink">Email</label>
                    <input id="member_email" v-model="form.member_email" type="email" required data-testid="member-email"
                           class="w-full rounded-md border border-line bg-panel px-3 py-2 text-sm" />
                    <p v-if="form.errors.member_email" class="mt-1 text-xs text-critical">{{ form.errors.member_email }}</p>
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <span v-if="form.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                    <button type="submit" data-testid="save-profile" :disabled="form.processing"
                            class="rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white transition hover:bg-channel-ink disabled:opacity-50">
                        Save changes
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
