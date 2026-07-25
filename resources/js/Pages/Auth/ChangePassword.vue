<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () =>
    form.post('/change-password', {
        onFinish: () => form.reset('current_password', 'password', 'password_confirmation'),
    });
</script>

<template>
    <Head title="Change password" />
    <main class="min-h-screen flex items-center justify-center bg-ground p-6">
        <div class="channel-bar channel-bar-critical w-full max-w-sm rounded-md border border-line bg-panel p-8">
            <h1 class="text-xl font-semibold text-ink">Password expired</h1>
            <p class="mt-1 text-sm text-muted">
                Your password has expired. Set a new one to continue.
            </p>

            <form @submit.prevent="submit" class="mt-6 space-y-4">
                <div>
                    <label class="channel-tag mb-1.5 block">Current password</label>
                    <input v-model="form.current_password" type="password" autocomplete="current-password" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-2 focus:ring-channel focus:outline-none" />
                    <p v-if="form.errors.current_password" class="mt-1 text-xs text-critical">{{ form.errors.current_password }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block">New password</label>
                    <input v-model="form.password" type="password" autocomplete="new-password" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-2 focus:ring-channel focus:outline-none" />
                    <p v-if="form.errors.password" class="mt-1 text-xs text-critical">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block">Confirm new password</label>
                    <input v-model="form.password_confirmation" type="password" autocomplete="new-password" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-2 focus:ring-channel focus:outline-none" />
                </div>

                <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-channel px-4 py-2.5 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                    Change password
                </button>
            </form>
        </div>
    </main>
</template>
