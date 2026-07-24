<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({ member_name: '', password: '', remember: false });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });

const flash = computed(() => usePage().props.flash);
</script>

<template>
    <Head title="Sign in" />
    <main class="min-h-screen flex items-center justify-center bg-ground p-6">
        <div class="w-full max-w-sm rounded-md border border-line bg-panel p-8">
            <span class="grid h-9 w-9 place-items-center rounded-md bg-channel text-sm font-bold text-white">PI</span>
            <h1 class="mt-4 text-xl font-semibold text-ink">Paediatric Endorsement</h1>
            <p class="mt-1 text-sm text-muted">Sign in to continue.</p>

            <p v-if="status" class="channel-bar channel-bar-ok mt-4 rounded-md bg-ok-soft px-3 py-2 text-sm text-ok">
                {{ status }}
            </p>
            <p v-if="flash?.status" class="channel-bar channel-bar-ok mt-4 rounded-md bg-ok-soft px-3 py-2 text-sm text-ok">
                {{ flash.status }}
            </p>

            <form @submit.prevent="submit" class="mt-6 space-y-4">
                <div>
                    <!--
                      The `for`/`id` pairing is load-bearing, not decoration: without it these
                      labels sit next to their fields visually but are not PROGRAMMATICALLY
                      associated, so a screen reader announces the login of a clinical system as
                      two unnamed edit fields. Found by the browser e2e harness (tests/e2e), which
                      resolves controls by accessible name exactly as assistive tech does — the
                      jsdom component tests never queried by label, so they could not see it.
                    -->
                    <label for="login-username" class="channel-tag mb-1.5 block">Username</label>
                    <input
                        id="login-username"
                        v-model="form.member_name"
                        type="text"
                        autofocus
                        autocomplete="username"
                        class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel"
                    />
                    <p v-if="form.errors.member_name" class="mt-1 text-xs text-critical">{{ form.errors.member_name }}</p>
                </div>

                <div>
                    <label for="login-password" class="channel-tag mb-1.5 block">Password</label>
                    <input
                        id="login-password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel"
                    />
                    <p v-if="form.errors.password" class="mt-1 text-xs text-critical">{{ form.errors.password }}</p>
                </div>

                <label class="flex items-center gap-2 text-sm text-body">
                    <input v-model="form.remember" type="checkbox" class="rounded-md border-line text-channel focus:ring-channel" />
                    Remember me
                </label>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-md bg-channel px-4 py-2.5 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60"
                >
                    Sign in
                </button>
            </form>

            <div class="mt-6 flex items-center justify-between text-sm">
                <Link href="/register" class="font-medium text-channel-ink hover:underline">Create account</Link>
                <Link href="/forgot-password" class="font-medium text-muted hover:text-body">Forgot password?</Link>
            </div>
        </div>
    </main>
</template>
