<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    positions: { type: Object, default: () => ({}) },
});

const form = useForm({
    full_name: '',
    member_name: '',
    member_email: '',
    position: 4,
    password: '',
    password_confirmation: '',
});

const submit = () =>
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
</script>

<template>
    <Head title="Create account" />
    <main class="min-h-screen flex items-center justify-center bg-ground p-6">
        <div class="w-full max-w-sm rounded-md border border-line bg-panel p-8">
            <h1 class="text-xl font-semibold text-ink">Create account</h1>
            <p class="mt-1 text-sm text-muted">
                An administrator activates your account before you can sign in.
            </p>

            <form @submit.prevent="submit" class="mt-6 space-y-4">
                <div>
                    <label class="channel-tag mb-1.5 block">Full name</label>
                    <input v-model="form.full_name" type="text" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                    <p v-if="form.errors.full_name" class="mt-1 text-xs text-critical">{{ form.errors.full_name }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block">Username</label>
                    <input v-model="form.member_name" type="text" autocomplete="username" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                    <p v-if="form.errors.member_name" class="mt-1 text-xs text-critical">{{ form.errors.member_name }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block">Email</label>
                    <input v-model="form.member_email" type="email" autocomplete="email" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                    <p v-if="form.errors.member_email" class="mt-1 text-xs text-critical">{{ form.errors.member_email }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block">Role</label>
                    <select v-model.number="form.position" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel">
                        <option v-for="(label, value) in positions" :key="value" :value="Number(value)">{{ label }}</option>
                    </select>
                    <p v-if="form.errors.position" class="mt-1 text-xs text-critical">{{ form.errors.position }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block">Password</label>
                    <input v-model="form.password" type="password" autocomplete="new-password" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                    <p v-if="form.errors.password" class="mt-1 text-xs text-critical">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block">Confirm password</label>
                    <input v-model="form.password_confirmation" type="password" autocomplete="new-password" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                </div>

                <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-channel px-4 py-2.5 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                    Register
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-muted">
                Already have an account?
                <Link href="/login" class="font-medium text-channel-ink hover:underline">Sign in</Link>
            </p>
        </div>
    </main>
</template>
