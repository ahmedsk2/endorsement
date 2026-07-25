<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import SignaturePad from '../../Components/SignaturePad.vue';

/**
 * Own-profile editing (re-platform of legacy profile.php). Only the three identity
 * fields; the server binds the update to the SESSION user (any submitted id is ignored) and
 * uniqueness-checks against both `users` and the pending-registration queue.
 *
 * Below it: the handover-reminder opt-in (spec §10.2) — pick units, then enable push on
 * THIS device. Reminder payloads carry unit + date + status only, never patient data.
 */
const props = defineProps({
    profile: { type: Object, required: true },
    security: {
        type: Object,
        default: () => ({
            email: null, email_verified: false, two_factor_method: null,
            two_factor_active: null, totp_confirmed: false,
            has_signature: false, signature_updated_at: null,
        }),
    },
    reminders: {
        type: Object,
        default: () => ({ units: [], selected: [], vapid_public_key: '' }),
    },
});

const form = useForm({
    full_name: props.profile.full_name || '',
    member_name: props.profile.member_name || '',
    member_email: props.profile.member_email || '',
});

const submit = () => form.patch('/profile', { preserveScroll: true });

// ------------------------------------------------------------ password

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

// Same four requirements the server enforces and the registration page checklists.
const pwRequirements = [
    { key: 'length', label: 'At least 8 characters', test: (p) => p.length >= 8 },
    { key: 'case', label: 'Upper and lower case letters', test: (p) => /[a-z]/.test(p) && /[A-Z]/.test(p) },
    { key: 'number', label: 'At least one number', test: (p) => /\d/.test(p) },
    { key: 'symbol', label: 'At least one symbol', test: (p) => /[^A-Za-z0-9]/.test(p) },
];
const pwMet = computed(() => pwRequirements.map((r) => r.test(passwordForm.password)));
const pwMismatch = computed(() => passwordForm.password !== '' && passwordForm.password_confirmation !== ''
    && passwordForm.password !== passwordForm.password_confirmation);

const changePassword = () => passwordForm.put('/profile/password', {
    preserveScroll: true,
    onSuccess: () => passwordForm.reset(),
});

// ------------------------------------------------------------ second factor

const methodForm = useForm({ method: props.security.two_factor_method ?? '' });
const saveMethod = () => methodForm.transform((d) => ({ method: d.method === '' ? null : d.method }))
    .put('/profile/two-factor-method', { preserveScroll: true });

const sendVerification = () => router.post('/profile/email/verify', {}, { preserveScroll: true });

// ------------------------------------------------------------ signature

const drawn = ref(null);
const signatureFile = ref(null);
const signatureError = ref('');
const padRef = ref(null);
const signatureVersion = ref(0);

const saveSignature = () => {
    signatureError.value = '';
    const data = new FormData();

    if (signatureFile.value) {
        data.append('signature_file', signatureFile.value);
    } else if (drawn.value) {
        data.append('signature_data', drawn.value);
    } else {
        signatureError.value = 'Draw a signature or choose an image file first.';
        return;
    }

    router.post('/profile/signature', data, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            drawn.value = null;
            signatureFile.value = null;
            padRef.value?.clear();
            signatureVersion.value += 1;   // bust the img cache so the new one shows
        },
        onError: (errors) => {
            signatureError.value = Object.values(errors)[0] ?? 'Could not save that signature.';
        },
    });
};

const removeSignature = () => router.delete('/profile/signature', { preserveScroll: true });

// ------------------------------------------------------------ reminders

const selectedUnits = ref([...(props.reminders.selected || [])]);
const reminderSaved = ref(false);
const pushState = ref('');

const saveReminders = () => {
    reminderSaved.value = false;
    router.patch('/profile/reminders', { unit_ids: selectedUnits.value }, {
        preserveScroll: true,
        onSuccess: () => {
            reminderSaved.value = true;
        },
    });
};

// Convert the base64url VAPID key to the Uint8Array subscribe() expects.
const urlB64ToUint8Array = (base64String) => {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
};

const enablePush = async () => {
    pushState.value = '';

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        pushState.value = 'This browser does not support push notifications.';
        return;
    }
    if (!props.reminders.vapid_public_key) {
        pushState.value = 'Push is not configured on the server yet (VAPID keys missing).';
        return;
    }

    try {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            pushState.value = 'Notifications were not allowed on this device.';
            return;
        }

        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlB64ToUint8Array(props.reminders.vapid_public_key),
        });

        const json = subscription.toJSON();
        router.post('/push/subscriptions', {
            endpoint: json.endpoint,
            keys: { p256dh: json.keys.p256dh, auth: json.keys.auth },
        }, {
            preserveScroll: true,
            onSuccess: () => {
                pushState.value = 'Notifications enabled on this device.';
            },
        });
    } catch {
        pushState.value = 'Could not enable notifications on this device.';
    }
};
</script>

<template>
    <AppLayout title="My Profile">
        <div class="mx-auto max-w-lg space-y-6">
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

            <!-- Email confirmation + second factor -->
            <section class="channel-bar rounded-md border border-line bg-panel p-6" data-testid="security-section"
                     :class="security.email_verified && security.two_factor_active ? 'channel-bar-ok' : 'channel-bar-caution'">
                <h3 class="text-sm font-semibold text-ink">Sign-in security</h3>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-b border-line-soft pb-4">
                    <div>
                        <p class="channel-tag">Email address</p>
                        <p class="text-sm text-ink">{{ security.email || 'Not set' }}</p>
                        <p class="mt-0.5 text-xs" :class="security.email_verified ? 'text-ok' : 'text-critical'"
                           data-testid="email-state">
                            {{ security.email_verified ? 'Confirmed' : 'Not confirmed yet' }}
                        </p>
                    </div>
                    <button v-if="!security.email_verified && security.email" type="button"
                            data-testid="send-verification" @click="sendVerification"
                            class="min-h-11 rounded-md border border-line px-3 py-1.5 text-sm font-semibold text-channel-ink hover:bg-channel-soft">
                        Send confirmation email
                    </button>
                </div>

                <form class="mt-4" @submit.prevent="saveMethod">
                    <p class="channel-tag mb-2">Two-step sign-in</p>
                    <div class="space-y-2">
                        <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-md border border-line px-3 py-2 text-sm">
                            <input v-model="methodForm.method" type="radio" value="" data-testid="tfa-none" class="mt-1 h-4 w-4" />
                            <span>
                                <span class="font-medium text-ink">Off</span>
                                <span class="block text-xs text-muted">Password only. Not recommended for accounts that can sign off handovers.</span>
                            </span>
                        </label>
                        <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-md border border-line px-3 py-2 text-sm">
                            <input v-model="methodForm.method" type="radio" value="totp" data-testid="tfa-totp" class="mt-1 h-4 w-4" />
                            <span>
                                <span class="font-medium text-ink">Authenticator app</span>
                                <span class="block text-xs text-muted">
                                    A 6-digit code from an app on your phone. Works with no signal.
                                    <a href="/user/two-factor" class="font-semibold text-channel-ink hover:underline">Set up the app</a>
                                    <template v-if="!security.totp_confirmed"> — not set up yet.</template>
                                </span>
                            </span>
                        </label>
                        <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-md border border-line px-3 py-2 text-sm">
                            <input v-model="methodForm.method" type="radio" value="email" data-testid="tfa-email" class="mt-1 h-4 w-4" />
                            <span>
                                <span class="font-medium text-ink">Code by email</span>
                                <span class="block text-xs text-muted">
                                    A one-time code is emailed each time you sign in.
                                    <template v-if="!security.email_verified"> Confirm your email address first.</template>
                                </span>
                            </span>
                        </label>
                    </div>
                    <p v-if="methodForm.errors.method" class="mt-2 text-xs text-critical" role="alert">{{ methodForm.errors.method }}</p>
                    <div class="mt-3 flex items-center justify-end gap-3">
                        <span v-if="methodForm.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                        <button type="submit" data-testid="save-tfa" :disabled="methodForm.processing"
                                class="min-h-11 rounded-md bg-channel px-3 py-1.5 text-sm font-semibold text-white hover:bg-channel-ink disabled:opacity-60">
                            Save
                        </button>
                    </div>
                </form>
            </section>

            <!-- Change password -->
            <section class="channel-bar rounded-md border border-line bg-panel p-6" data-testid="password-section">
                <h3 class="text-sm font-semibold text-ink">Change password</h3>
                <form class="mt-4 space-y-3" @submit.prevent="changePassword">
                    <div>
                        <label for="current_password" class="channel-tag mb-1 block">Current password</label>
                        <input id="current_password" v-model="passwordForm.current_password" type="password"
                               autocomplete="current-password" data-testid="current-password"
                               class="w-full rounded-md border border-line bg-panel px-3 py-2 text-base text-ink focus:border-channel focus:ring-2 focus:ring-channel focus:outline-none" />
                        <p v-if="passwordForm.errors.current_password" class="mt-1 text-xs text-critical" role="alert">
                            {{ passwordForm.errors.current_password }}
                        </p>
                    </div>
                    <div>
                        <label for="new_password" class="channel-tag mb-1 block">New password</label>
                        <input id="new_password" v-model="passwordForm.password" type="password"
                               autocomplete="new-password" data-testid="new-password"
                               class="w-full rounded-md border border-line bg-panel px-3 py-2 text-base text-ink focus:border-channel focus:ring-2 focus:ring-channel focus:outline-none" />
                        <ul class="mt-2 space-y-1 text-xs">
                            <li v-for="(r, i) in pwRequirements" :key="r.key" :data-testid="`pf-req-${r.key}`"
                                class="flex items-center gap-2" :class="pwMet[i] ? 'text-ok' : 'text-critical'">
                                <span aria-hidden="true" class="readout">{{ pwMet[i] ? '✓' : '✗' }}</span>{{ r.label }}
                            </li>
                        </ul>
                        <p v-if="passwordForm.errors.password" class="mt-1 text-xs text-critical" role="alert">
                            {{ passwordForm.errors.password }}
                        </p>
                    </div>
                    <div>
                        <label for="confirm_password" class="channel-tag mb-1 block">Confirm new password</label>
                        <input id="confirm_password" v-model="passwordForm.password_confirmation" type="password"
                               autocomplete="new-password" data-testid="confirm-password"
                               class="w-full rounded-md border border-line bg-panel px-3 py-2 text-base text-ink focus:border-channel focus:ring-2 focus:ring-channel focus:outline-none" />
                        <p v-if="pwMismatch" data-testid="pf-mismatch" role="alert" class="mt-1 text-xs font-semibold text-critical">
                            Passwords do not match.
                        </p>
                    </div>
                    <div class="flex items-center justify-end gap-3">
                        <span v-if="passwordForm.recentlySuccessful" class="text-sm text-ok" role="status">Password changed.</span>
                        <button type="submit" data-testid="save-password" :disabled="passwordForm.processing"
                                class="min-h-11 rounded-md bg-channel px-3 py-1.5 text-sm font-semibold text-white hover:bg-channel-ink disabled:opacity-60">
                            Change password
                        </button>
                    </div>
                </form>
            </section>

            <!-- Signature -->
            <section class="channel-bar rounded-md border border-line bg-panel p-6" data-testid="signature-section"
                     :class="security.has_signature ? 'channel-bar-ok' : 'channel-bar-caution'">
                <h3 class="text-sm font-semibold text-ink">Your signature</h3>
                <p class="mt-1 text-xs text-muted">
                    Printed next to your name on every handover you sign. Sheets you have already
                    signed keep the signature they were signed with.
                </p>

                <div v-if="security.has_signature" class="mt-4 rounded-md border border-line bg-ground p-3">
                    <p class="channel-tag mb-1">On file</p>
                    <img :src="`/signatures/me?v=${signatureVersion}`" alt="Your signature"
                         data-testid="signature-current" class="h-16 bg-panel" />
                    <p class="mt-1 text-xs text-muted">Updated {{ security.signature_updated_at }}</p>
                </div>

                <div class="mt-4">
                    <p class="channel-tag mb-1">Draw a new signature</p>
                    <SignaturePad ref="padRef" @change="(v) => { drawn = v; signatureFile = null; }" />
                </div>

                <div class="mt-4">
                    <label for="signature_file" class="channel-tag mb-1 block">…or upload an image (PNG/JPEG)</label>
                    <input id="signature_file" type="file" accept="image/png,image/jpeg" data-testid="signature-file"
                           class="w-full text-sm"
                           @change="(e) => { signatureFile = e.target.files[0] ?? null; drawn = null; }" />
                </div>

                <p v-if="signatureError" class="mt-2 text-xs text-critical" role="alert">{{ signatureError }}</p>

                <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
                    <button v-if="security.has_signature" type="button" data-testid="remove-signature" @click="removeSignature"
                            class="min-h-11 rounded-md px-3 py-1.5 text-sm text-critical hover:underline">
                        Remove
                    </button>
                    <button type="button" data-testid="save-signature" @click="saveSignature"
                            class="min-h-11 rounded-md bg-channel px-3 py-1.5 text-sm font-semibold text-white hover:bg-channel-ink">
                        Save signature
                    </button>
                </div>
            </section>

            <!-- Handover reminders (spec §10.2). Opt-in per unit; payloads never carry patient data. -->
            <section class="channel-bar rounded-md border border-line bg-panel p-6" data-testid="reminders-section">
                <h3 class="text-sm font-semibold text-ink">Handover reminders</h3>
                <p class="mt-1 text-xs text-muted">
                    Shortly after each handover time (07:30 / 15:30), get a notification when a unit
                    you follow has no signed endorsement yet. Notifications name the unit and date only.
                </p>

                <fieldset class="mt-4 space-y-2">
                    <legend class="channel-tag">Remind me about</legend>
                    <label v-for="u in reminders.units" :key="u.id"
                           class="flex min-h-11 cursor-pointer items-center gap-3 rounded-md border border-line px-3 py-2 text-sm">
                        <input v-model="selectedUnits" type="checkbox" :value="u.id" :data-testid="`reminder-${u.code}`"
                               class="h-4 w-4 accent-[--color-channel]" />
                        <span class="font-medium text-ink">{{ u.code }}</span>
                        <span class="text-muted">{{ u.name }}</span>
                    </label>
                </fieldset>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button type="button" data-testid="save-reminders" @click="saveReminders"
                            class="rounded-md bg-channel px-3 py-1.5 text-sm font-semibold text-white hover:bg-channel-ink">
                        Save preferences
                    </button>
                    <button type="button" data-testid="enable-push" @click="enablePush"
                            class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold text-channel-ink hover:bg-channel-soft">
                        Enable notifications on this device
                    </button>
                    <span v-if="reminderSaved" class="text-sm text-ok" role="status">Saved.</span>
                </div>
                <p v-if="pushState" class="mt-2 text-xs text-body" role="status" data-testid="push-state">{{ pushState }}</p>
            </section>
        </div>
    </AppLayout>
</template>
