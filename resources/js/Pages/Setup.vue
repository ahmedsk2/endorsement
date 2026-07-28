<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import StaffPrivacyNotice from '../Components/StaffPrivacyNotice.vue';

/*
 * First-login setup. Two questions this system must not answer on a clinician's behalf:
 * how they prove who they are at sign-in, and whether they want to be told about a
 * handover. Both were optional settings on a profile page, so realistically neither got
 * chosen.
 *
 * Deliberately NOT wrapped in AppLayout: the side menu leads to wards this account cannot
 * reach yet, and offering navigation that bounces straight back here would read as a broken
 * app. Sign out is the one way out, and it is present.
 */
const props = defineProps({
    security: { type: Object, required: true },
    reminders: { type: Object, required: true },
});

const stepOneDone = computed(() => props.security.active_method !== null);

const methodForm = useForm({ method: props.security.two_factor_method ?? '' });
const chooseEmail = () =>
    methodForm.transform(() => ({ method: 'email' })).put('/profile/two-factor-method', { preserveScroll: true });

const sendVerification = () => router.post('/profile/email/verify', {}, { preserveScroll: true });

const selectedUnits = ref([...(props.reminders.selected || [])]);
const remindersSaved = ref(false);
const saveReminders = () => {
    remindersSaved.value = false;
    router.patch('/profile/reminders', { unit_ids: selectedUnits.value }, {
        preserveScroll: true,
        onSuccess: () => { remindersSaved.value = true; },
    });
};

const pushState = ref('');
const urlB64ToUint8Array = (base64String) => {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
};

const enablePush = async () => {
    pushState.value = '';

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        pushState.value = 'This browser does not support notifications.';
        return;
    }
    if (!props.reminders.vapid_public_key) {
        pushState.value = 'Push is not switched on for this system yet — you can skip this.';
        return;
    }

    try {
        if ((await Notification.requestPermission()) !== 'granted') {
            pushState.value = 'Notifications were not allowed on this device.';
            return;
        }
        const registration = await navigator.serviceWorker.ready;
        const sub = (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlB64ToUint8Array(props.reminders.vapid_public_key),
        })).toJSON();

        router.post('/push/subscriptions', {
            endpoint: sub.endpoint,
            keys: { p256dh: sub.keys.p256dh, auth: sub.keys.auth },
        }, {
            preserveScroll: true,
            onSuccess: () => { pushState.value = 'Notifications enabled on this device.'; },
        });
    } catch {
        pushState.value = 'Could not enable notifications on this device.';
    }
};

const finishForm = useForm({});
const finish = () => finishForm.post('/setup');
</script>

<template>
    <Head title="Set up your account" />
    <main class="min-h-screen bg-ground px-4 py-10">
        <div class="mx-auto max-w-2xl space-y-6">
            <header>
                <p class="channel-tag">Paediatric Endorsement</p>
                <h1 class="mt-1 text-2xl font-semibold text-ink">Set up your account</h1>
                <p class="mt-2 text-sm text-muted">
                    Two things before you start. This takes a minute and you only do it once.
                </p>
            </header>

            <StaffPrivacyNotice context="setup" />

            <!-- STEP 1 — required. -->
            <section class="channel-bar rounded-md border border-line bg-panel p-6"
                     :class="stepOneDone ? 'channel-bar-ok' : 'channel-bar-caution'"
                     data-testid="setup-step-security">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-ink">1. How you sign in</h2>
                        <p class="mt-1 text-sm text-muted">
                            A password alone is not enough for a system holding children's records.
                            Pick a second step.
                        </p>
                    </div>
                    <span class="channel-tag shrink-0" :class="stepOneDone ? 'text-ok' : 'text-caution'"
                          data-testid="security-state">{{ stepOneDone ? 'Done' : 'Required' }}</span>
                </div>

                <div v-if="stepOneDone" class="mt-4 text-sm text-ok" data-testid="security-done">
                    <p v-if="security.active_method === 'totp'">Authenticator app is set up.</p>
                    <p v-else>Sign-in codes will be emailed to {{ security.email }}.</p>
                    <p class="mt-1 text-xs text-muted">You can change this later from your profile.</p>
                </div>

                <div v-else class="mt-4 space-y-3">
                    <div class="rounded-md border border-line p-4">
                        <p class="text-sm font-medium text-ink">Authenticator app <span class="text-xs font-normal text-muted">— recommended</span></p>
                        <p class="mt-1 text-xs text-muted">
                            A 6-digit code from an app on your phone. Works with no signal and no email.
                        </p>
                        <Link href="/user/two-factor" data-testid="setup-totp-link"
                              class="mt-3 inline-block min-h-11 rounded-md bg-channel px-4 py-2.5 text-sm font-semibold text-white hover:bg-channel-ink">
                            Set up the app
                        </Link>
                    </div>

                    <div class="rounded-md border border-line p-4">
                        <p class="text-sm font-medium text-ink">Code by email</p>
                        <p class="mt-1 text-xs text-muted">
                            A one-time code sent to {{ security.email }} each time you sign in.
                            <template v-if="!security.email_verified"> Confirm your address first.</template>
                        </p>
                        <button v-if="!security.email_verified" type="button" @click="sendVerification"
                                class="mt-3 min-h-11 rounded-md border border-line bg-panel px-4 py-2 text-sm font-semibold text-body hover:bg-ground">
                            Send confirmation email
                        </button>
                        <button v-else type="button" data-testid="setup-choose-email" @click="chooseEmail"
                                :disabled="methodForm.processing"
                                class="mt-3 min-h-11 rounded-md border border-line bg-panel px-4 py-2 text-sm font-semibold text-body hover:bg-ground disabled:opacity-60">
                            Use email codes
                        </button>
                        <p v-if="methodForm.errors.method" class="mt-2 text-xs text-critical" role="alert">
                            {{ methodForm.errors.method }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- STEP 2 — optional, and said so plainly. -->
            <section class="channel-bar rounded-md border border-line bg-panel p-6" data-testid="setup-step-reminders">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-ink">2. Handover reminders</h2>
                        <p class="mt-1 text-sm text-muted">
                            Which units should remind you if a handover has not been signed after the
                            07:30 or 15:30 changeover?
                        </p>
                    </div>
                    <span class="channel-tag shrink-0 text-muted">Optional</span>
                </div>

                <div class="mt-4 grid gap-2 sm:grid-cols-2">
                    <label v-for="u in reminders.units" :key="u.id"
                           class="flex min-h-11 cursor-pointer items-center gap-3 rounded-md border border-line px-3 py-2 text-sm">
                        <input v-model="selectedUnits" type="checkbox" :value="u.id" class="h-4 w-4" />
                        <span><span class="font-medium text-ink">{{ u.code }}</span>
                            <span class="block text-xs text-muted">{{ u.name }}</span></span>
                    </label>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <button type="button" data-testid="setup-save-reminders" @click="saveReminders"
                            class="min-h-11 rounded-md border border-line bg-panel px-4 py-2 text-sm font-semibold text-body hover:bg-ground">
                        Save my units
                    </button>
                    <button type="button" @click="enablePush"
                            class="min-h-11 rounded-md border border-line bg-panel px-4 py-2 text-sm font-semibold text-body hover:bg-ground">
                        Enable notifications on this device
                    </button>
                    <span v-if="remindersSaved" class="text-sm text-ok" role="status">Saved.</span>
                </div>
                <p v-if="pushState" class="mt-2 text-xs text-muted" role="status">{{ pushState }}</p>
                <p class="mt-2 text-xs text-muted">
                    On an iPhone, notifications only arrive if you add this site to your Home Screen first.
                </p>
            </section>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="POST" action="/logout" @submit.prevent="router.post('/logout')">
                    <button type="submit" class="text-sm text-muted hover:text-body hover:underline">Sign out</button>
                </form>
                <div class="flex items-center gap-3">
                    <p v-if="finishForm.errors.setup" class="text-xs text-critical" role="alert">
                        {{ finishForm.errors.setup }}
                    </p>
                    <button type="button" data-testid="setup-finish" @click="finish"
                            :disabled="!stepOneDone || finishForm.processing"
                            class="min-h-11 rounded-md bg-channel px-5 py-2.5 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                        Finish and start
                    </button>
                </div>
            </div>
        </div>
    </main>
</template>
