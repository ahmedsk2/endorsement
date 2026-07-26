<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({ member_name: '', password: '', remember: false });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });

const page = usePage();
const notice = computed(() => page.props.flash?.status ?? null);
const problem = computed(() => page.props.flash?.error ?? null);

// Shared input recipe. `focus:ring-channel` alone is a no-op — a ring COLOUR with no ring
// WIDTH paints nothing — so the width is set here and the unlayered :focus-visible rule in
// app.css still provides the keyboard outline.
const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-base text-ink '
    + 'focus:border-channel focus:ring-2 focus:ring-channel focus:outline-none';
</script>

<template>
    <AuthLayout title="Sign in" heading="Sign in" hero-src="/img/auth-dawn.webp"
                subheading="Use the account your unit administrator activated.">
        <!-- Announcements. Text is `ink` on the tinted panel: `text-ok` on `bg-ok-soft`
             measures 3.66:1 and fails AA, and this is the banner a newly-registered
             visitor reads. The colour is carried by the channel bar, which is structural. -->
        <div role="status" aria-live="polite">
            <p v-if="status || notice" data-testid="login-status"
               class="channel-bar channel-bar-ok mb-5 rounded-md bg-ok-soft px-3 py-2 text-sm text-ink">
                {{ status || notice }}
            </p>
        </div>
        <p v-if="problem" role="alert" data-testid="login-error"
           class="channel-bar channel-bar-critical mb-5 rounded-md bg-critical-soft px-3 py-2 text-sm text-ink">
            {{ problem }}
        </p>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <!--
                  The `for`/`id` pairing is load-bearing, not decoration: without it these
                  labels sit next to their fields visually but are not PROGRAMMATICALLY
                  associated, so a screen reader announces the login of a clinical system as
                  two unnamed edit fields. Found by the browser e2e harness, which resolves
                  controls by accessible name exactly as assistive tech does.
                -->
                <label for="login-username" class="channel-tag mb-1.5 block">Username</label>
                <input id="login-username" v-model="form.member_name" type="text" autofocus
                       autocomplete="username" :class="inputClass" />
                <p v-if="form.errors.member_name" class="mt-1 text-xs text-critical">{{ form.errors.member_name }}</p>
            </div>

            <div>
                <label for="login-password" class="channel-tag mb-1.5 block">Password</label>
                <input id="login-password" v-model="form.password" type="password"
                       autocomplete="current-password" :class="inputClass" />
                <p v-if="form.errors.password" class="mt-1 text-xs text-critical">{{ form.errors.password }}</p>
            </div>

            <label class="flex min-h-11 items-center gap-2 text-sm text-body">
                <input v-model="form.remember" type="checkbox"
                       class="h-4 w-4 rounded-md border-line text-channel focus:ring-2 focus:ring-channel" />
                Keep me signed in on this device
            </label>

            <button type="submit" :disabled="form.processing"
                    class="min-h-11 w-full rounded-md bg-channel px-4 py-2.5 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                Sign in
            </button>
        </form>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 text-sm">
            <!-- "Request access", not "Create account": registering writes a request an
                 administrator must approve — it does not create a usable account. -->
            <Link href="/register" class="font-medium text-channel-ink hover:underline">Request access</Link>
            <Link href="/forgot-password" class="font-medium text-muted hover:text-body hover:underline">Forgot password?</Link>
        </div>

        <p class="mt-6 border-t border-line-soft pt-4 text-xs text-muted">
            Authorised clinical staff only. Activity on this system is recorded.
        </p>
    </AuthLayout>
</template>
