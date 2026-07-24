<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';

const props = defineProps({
    enabled: { type: Boolean, default: false },
    pending: { type: Boolean, default: false },
    // One-time enrollment payload (secret / otpauthUri / recoveryCodes), present right after enabling.
    enrollment: { type: Object, default: null },
});

const flash = computed(() => usePage().props.flash);

// Render the QR client-side from the otpauth:// URI (never round-tripped to a server).
const qr = ref('');
watch(
    () => props.enrollment?.otpauthUri,
    async (uri) => {
        qr.value = uri ? await QRCode.toDataURL(uri, { width: 220, margin: 1 }) : '';
    },
    { immediate: true },
);

const enableForm = useForm({});
const enable = () => enableForm.post('/user/two-factor', { preserveScroll: true });

const confirmForm = useForm({ code: '' });
const confirm = () =>
    confirmForm.post('/user/two-factor/confirm', {
        preserveScroll: true,
        onFinish: () => confirmForm.reset('code'),
    });

const disableForm = useForm({});
const disable = () => disableForm.delete('/user/two-factor', { preserveScroll: true });

const copyCodes = () => navigator.clipboard?.writeText((props.enrollment?.recoveryCodes ?? []).join('\n'));
</script>

<template>
    <Head title="Two-factor authentication" />
    <main class="min-h-screen bg-ground p-6">
        <div class="mx-auto max-w-2xl space-y-6">
            <div>
                <h1 class="text-xl font-semibold text-ink">Two-factor authentication</h1>
                <p class="mt-1 text-sm text-muted">
                    Add a one-time code from an authenticator app as a second step when signing in.
                </p>
            </div>

            <!-- Enabling/disabling 2FA only reports back through this flash.
                 G6: same fix as Admin/AccessControl — the region is rendered PERSISTENTLY and
                 populated, never created together with its content, or nothing is announced. -->
            <div role="status" aria-live="polite" data-testid="flash-status">
                <p v-if="flash?.status"
                   class="channel-bar channel-bar-ok rounded-md bg-ok-soft px-3 py-2 text-sm text-ok">{{ flash.status }}</p>
            </div>
            <p v-if="enableForm.errors.two_factor" role="alert"
               class="channel-bar channel-bar-critical rounded-md bg-critical-soft px-3 py-2 text-sm text-critical">
                {{ enableForm.errors.two_factor }}
            </p>

            <!-- Enrollment in progress: scan the QR + confirm with a code. -->
            <section v-if="enrollment" class="space-y-5 rounded-md border border-line bg-panel p-6">
                <div>
                    <h2 class="text-base font-semibold text-ink">1. Scan</h2>
                    <p class="mt-1 text-sm text-muted">Scan with Google Authenticator, Microsoft Authenticator, or Authy.</p>
                    <div class="mt-3 flex justify-center">
                        <img v-if="qr" :src="qr" alt="Two-factor QR code" class="rounded-md border border-line" />
                    </div>
                    <p id="tfa-secret-hint" class="mt-3 text-xs text-muted">Can't scan? Enter this key manually:</p>
                    <code aria-describedby="tfa-secret-hint" aria-label="Two-factor setup key"
                          class="readout mt-1 block break-all rounded-md bg-ground-deep px-3 py-2 text-sm font-semibold tracking-wider text-ink">{{ enrollment.secret }}</code>
                </div>

                <div aria-labelledby="tfa-recovery-heading" class="rounded-md border-2 border-dashed border-caution bg-caution-soft/60 p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <h2 id="tfa-recovery-heading" class="text-base font-semibold text-caution">Save your recovery codes</h2>
                        <button type="button" aria-label="Copy all recovery codes to the clipboard" @click="copyCodes"
                                class="rounded-md border border-line bg-panel px-3 py-1.5 text-xs font-semibold text-body hover:bg-ground">Copy all</button>
                    </div>
                    <p class="mb-3 text-sm text-body">Each code works once if you lose your device. They won't be shown again.</p>
                    <ul aria-label="Recovery codes" class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <li v-for="c in enrollment.recoveryCodes" :key="c">
                            <code class="readout block rounded-md border border-line bg-panel px-3 py-2 text-center text-sm font-semibold tracking-wider text-ink">{{ c }}</code>
                        </li>
                    </ul>
                </div>

                <form @submit.prevent="confirm">
                    <h2 class="text-base font-semibold text-ink">2. Confirm</h2>
                    <label for="tfa-code" id="tfa-code-hint" class="mt-1 mb-2 block text-sm text-muted">
                        Enter the 6-digit code your app shows to finish enabling.
                    </label>
                    <input
                        id="tfa-code"
                        v-model="confirmForm.code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        placeholder="123456"
                        :aria-invalid="confirmForm.errors.code ? 'true' : undefined"
                        :aria-describedby="confirmForm.errors.code ? 'tfa-code-error' : undefined"
                        class="readout w-full rounded-md border border-line bg-panel px-3 py-2 text-center text-2xl font-bold tracking-[0.3em] focus:border-channel focus:ring-channel"
                    />
                    <p v-if="confirmForm.errors.code" id="tfa-code-error" role="alert" class="mt-1 text-xs text-critical">{{ confirmForm.errors.code }}</p>
                    <button type="submit" :disabled="confirmForm.processing" class="mt-4 w-full rounded-md bg-channel px-4 py-2.5 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                        Enable two-factor
                    </button>
                </form>
            </section>

            <!-- Already enabled: offer disable. -->
            <section v-else-if="enabled" class="rounded-md border border-line bg-panel p-6">
                <p class="flex items-center gap-2 text-sm font-medium text-ok">
                    <span class="inline-block h-2 w-2 rounded-full bg-ok"></span>
                    Two-factor authentication is enabled.
                </p>
                <button type="button" @click="disable" :disabled="disableForm.processing" class="mt-4 rounded-md bg-critical px-4 py-2 font-semibold text-white transition hover:bg-critical/90 disabled:opacity-60">
                    Disable two-factor
                </button>
            </section>

            <!-- Not enrolled: offer enable. -->
            <section v-else class="rounded-md border border-line bg-panel p-6">
                <p class="text-sm text-body">Two-factor authentication is not enabled on your account.</p>
                <button type="button" @click="enable" :disabled="enableForm.processing" class="mt-4 rounded-md bg-channel px-4 py-2 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                    Enable two-factor
                </button>
            </section>
        </div>
    </main>
</template>
