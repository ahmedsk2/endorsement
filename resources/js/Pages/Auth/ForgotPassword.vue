<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({ email: '' });
const submit = () => form.post('/forgot-password');
</script>

<template>
    <Head title="Forgot password" />
    <main class="min-h-screen flex items-center justify-center bg-ground p-6">
        <div class="w-full max-w-sm rounded-md border border-line bg-panel p-8">
            <h1 class="text-xl font-semibold text-ink">Forgot password</h1>
            <p class="mt-1 text-sm text-muted">
                Enter your email and we'll send a reset link.
            </p>

            <p v-if="status" class="channel-bar channel-bar-ok mt-4 rounded-md bg-ok-soft px-3 py-2 text-sm text-ok">{{ status }}</p>

            <form @submit.prevent="submit" class="mt-6 space-y-4">
                <div>
                    <label class="channel-tag mb-1.5 block">Email</label>
                    <input v-model="form.email" type="email" autocomplete="email" autofocus class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                    <p v-if="form.errors.email" class="mt-1 text-xs text-critical">{{ form.errors.email }}</p>
                </div>

                <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-channel px-4 py-2.5 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                    Email reset link
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-muted">
                <Link href="/login" class="font-medium text-channel-ink hover:underline">Back to sign in</Link>
            </p>
        </div>
    </main>
</template>
