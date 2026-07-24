<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

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

            <!-- Handover reminders (spec §10.2). Opt-in per unit; payloads never carry patient data. -->
            <section class="channel-bar rounded-md border border-line bg-panel p-6" data-testid="reminders-section">
                <h3 class="text-sm font-semibold text-ink">Handover reminders</h3>
                <p class="mt-1 text-xs text-muted">
                    Shortly after each handover time (07:30 / 13:30), get a notification when a unit
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
