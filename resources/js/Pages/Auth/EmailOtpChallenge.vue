<script setup>
import { useForm, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthLayout from '../../Layouts/AuthLayout.vue';

/**
 * The e-mail second factor: the code that was just sent. Sibling of TwoFactorChallenge.vue
 * for users who chose "a code by email" instead of an authenticator app.
 */
const props = defineProps({
    hint: { type: String, default: 'your email address' },
    lifetimeMinutes: { type: Number, default: 10 },
});

const form = useForm({ code: '' });
const submit = () => form.post('/email-code', { onFinish: () => form.reset('code') });
const resend = () => router.post('/email-code/resend', {}, { preserveScroll: true });

const notice = computed(() => usePage().props.flash?.status ?? null);
</script>

<template>
    <AuthLayout title="Enter your code" heading="Check your email" hero-src="/img/auth-dawn.webp"
                :subheading="`We sent a ${6}-digit code to ${props.hint}.`">
        <div role="status" aria-live="polite">
            <p v-if="notice" class="channel-bar channel-bar-ok mb-5 rounded-md bg-ok-soft px-3 py-2 text-sm text-ink">
                {{ notice }}
            </p>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <label for="otp-code" class="channel-tag mb-1.5 block">Six-digit code</label>
                <input id="otp-code" v-model="form.code" data-testid="otp-code"
                       type="text" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" autofocus
                       class="readout w-full rounded-md border border-line bg-panel px-3 py-2 text-center text-2xl tracking-[0.4em] text-ink focus:border-channel focus:ring-2 focus:ring-channel focus:outline-none" />
                <p v-if="form.errors.code" class="mt-1 text-xs text-critical" role="alert">{{ form.errors.code }}</p>
                <p class="mt-2 text-xs text-muted">
                    The code expires in {{ lifetimeMinutes }} minutes and can be used once.
                </p>
            </div>

            <button type="submit" :disabled="form.processing"
                    class="min-h-11 w-full rounded-md bg-channel px-4 py-2.5 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                Sign in
            </button>
        </form>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 text-sm">
            <button type="button" data-testid="otp-resend" @click="resend"
                    class="font-medium text-channel-ink hover:underline">Send another code</button>
            <a href="/login" class="font-medium text-muted hover:text-body hover:underline">Start again</a>
        </div>
    </AuthLayout>
</template>
