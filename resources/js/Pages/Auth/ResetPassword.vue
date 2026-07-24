<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () =>
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
</script>

<template>
    <Head title="Reset password" />
    <main class="min-h-screen flex items-center justify-center bg-ground p-6">
        <div class="w-full max-w-sm rounded-md border border-line bg-panel p-8">
            <h1 class="text-xl font-semibold text-ink">Reset password</h1>

            <form @submit.prevent="submit" class="mt-6 space-y-4">
                <div>
                    <label class="channel-tag mb-1.5 block">Email</label>
                    <input v-model="form.email" type="email" autocomplete="email" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                    <p v-if="form.errors.email" class="mt-1 text-xs text-critical">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block">New password</label>
                    <input v-model="form.password" type="password" autocomplete="new-password" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                    <p v-if="form.errors.password" class="mt-1 text-xs text-critical">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block">Confirm new password</label>
                    <input v-model="form.password_confirmation" type="password" autocomplete="new-password" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                </div>

                <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-channel px-4 py-2.5 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                    Reset password
                </button>
            </form>
        </div>
    </main>
</template>
