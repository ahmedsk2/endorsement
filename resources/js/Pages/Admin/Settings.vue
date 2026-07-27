<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

/**
 * Admin → Settings: runtime settings editable in the app instead of the .env file.
 * SECRETS ARE WRITE-ONLY — the page only ever knows whether one is stored (`secrets.*`),
 * never the value; leaving a secret field empty keeps what is stored.
 */
const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    secrets: { type: Object, default: () => ({}) },
    defaults: { type: Object, default: () => ({ remind_delay_minutes: 10, handover_times: ['07:30', '15:30'] }) },
});

const form = useForm({
    mail_host: props.settings.mail_host ?? '',
    mail_port: props.settings.mail_port ?? '',
    mail_username: props.settings.mail_username ?? '',
    mail_password: '',
    mail_encryption: props.settings.mail_encryption ?? '',
    mail_from_address: props.settings.mail_from_address ?? '',
    mail_from_name: props.settings.mail_from_name ?? '',
    alert_email: props.settings.alert_email ?? '',
    vapid_subject: props.settings.vapid_subject ?? '',
    vapid_public_key: props.settings.vapid_public_key ?? '',
    vapid_private_key: '',
    remind_delay_minutes: props.settings.remind_delay_minutes ?? '',
    handover_time_morning: props.settings.handover_time_morning ?? '',
    handover_time_afternoon: props.settings.handover_time_afternoon ?? '',
});

// The result arrives as a flash banner (status or error) rendered by AppLayout, so the
// transport's own words reach the admin — "connection refused" and "535 authentication
// failed" point at different wrong fields, and one generic failure would hide which.
const testing = ref(false);
const sendTestEmail = () => {
    testing.value = true;
    router.post('/admin/settings/test-email', {}, {
        preserveScroll: true,
        onFinish: () => { testing.value = false; },
    });
};

const submit = () => form.put('/admin/settings', {
    preserveScroll: true,
    onSuccess: () => form.reset('mail_password', 'vapid_private_key'),
});

const secretPlaceholder = (stored) => (stored ? 'A value is stored — leave empty to keep it' : 'Not set');

const inputClass = 'w-full rounded-md border border-line bg-panel px-3 py-2 text-sm text-ink focus:border-channel focus:outline-none';
</script>

<template>
    <AppLayout title="Settings">
        <div class="mx-auto max-w-2xl space-y-6">
            <div>
                <h2 class="text-xl font-semibold text-ink">Runtime settings</h2>
                <p class="text-sm text-muted">
                    Saved values override the server's .env configuration. Secrets are write-only:
                    they are stored encrypted and never shown again.
                </p>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <!-- Mail (SMTP) -->
                <section class="channel-bar rounded-md border border-line bg-panel p-5">
                    <h3 class="mb-3 text-sm font-semibold text-ink">Outgoing mail (SMTP)</h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="channel-tag mb-1 block" for="mail_host">Host</label>
                            <input id="mail_host" v-model="form.mail_host" type="text" :class="inputClass" placeholder="smtp.example.org" />
                            <p v-if="form.errors.mail_host" class="mt-1 text-xs text-critical">{{ form.errors.mail_host }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="mail_port">Port</label>
                            <input id="mail_port" v-model="form.mail_port" type="number" class="readout" :class="inputClass" placeholder="587" />
                            <p v-if="form.errors.mail_port" class="mt-1 text-xs text-critical">{{ form.errors.mail_port }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="mail_encryption">Encryption</label>
                            <select id="mail_encryption" v-model="form.mail_encryption" :class="inputClass">
                                <option value="">Default</option>
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                                <option value="none">None</option>
                            </select>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="mail_username">Username</label>
                            <input id="mail_username" v-model="form.mail_username" type="text" autocomplete="off" :class="inputClass" />
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="mail_password">Password</label>
                            <input id="mail_password" v-model="form.mail_password" type="password" autocomplete="new-password"
                                   :class="inputClass" :placeholder="secretPlaceholder(secrets.mail_password)" data-testid="mail-password" />
                            <p class="mt-1 text-xs text-muted">
                                {{ secrets.mail_password ? 'A password is stored (never displayed).' : 'No password stored yet.' }}
                            </p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="mail_from_address">From address</label>
                            <input id="mail_from_address" v-model="form.mail_from_address" type="email" :class="inputClass" placeholder="endorsement@example.org" />
                            <p v-if="form.errors.mail_from_address" class="mt-1 text-xs text-critical">{{ form.errors.mail_from_address }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="mail_from_name">From name</label>
                            <input id="mail_from_name" v-model="form.mail_from_name" type="text" :class="inputClass" />
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="alert_email">Operational alerts to</label>
                            <input id="alert_email" v-model="form.alert_email" type="email" :class="inputClass" />
                            <p class="readout mt-1 text-xs">
                                Where a failed nightly backup or a broken audit chain is reported.
                                Alerts carry a job name and a time only &mdash; never patient information.
                            </p>
                        </div>
                    </div>

                    <!--
                      Save first, then send: the test uses the STORED settings, not what is
                      currently typed into the form. Sending is the only way to know mail
                      works — a saved configuration reports success either way.
                    -->
                    <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-line-soft pt-4">
                        <button type="button" data-testid="send-test-email" :disabled="testing"
                                class="min-h-11 rounded-md border border-line bg-panel px-3 py-1.5 text-sm font-semibold text-body hover:bg-ground disabled:opacity-60"
                                @click="sendTestEmail">
                            {{ testing ? 'Sending…' : 'Send test email' }}
                        </button>
                        <p class="text-xs text-muted">
                            Sends one message to the alerts address above (or to your own, if that is blank).
                            <strong class="text-ink">Save your changes first</strong> &mdash; the test uses what is stored.
                        </p>
                    </div>
                </section>

                <!-- Push / VAPID -->
                <section class="channel-bar rounded-md border border-line bg-panel p-5">
                    <h3 class="mb-1 text-sm font-semibold text-ink">Push notifications (VAPID)</h3>
                    <!--
                      This used to say `npx web-push generate-vapid-keys`, which cannot be
                      run where it matters: the production image ships no Node. The artisan
                      command below uses the library already installed here.
                    -->
                    <p class="mb-1 text-xs text-muted">
                        Sends the 07:30 and 15:30 handover reminders to phones. Optional —
                        reminders still run without it, they just have nowhere to go.
                    </p>
                    <p class="mb-3 text-xs text-muted">
                        Generate the pair once, in a shell on the server:
                        <span class="readout">php artisan push:vapid-keys</span>.
                        The private key is write-only and stored encrypted.
                        On iPhones, push only arrives if the site has been added to the Home Screen.
                    </p>
                    <div class="grid gap-3">
                        <div>
                            <label class="channel-tag mb-1 block" for="vapid_subject">Subject</label>
                            <input id="vapid_subject" v-model="form.vapid_subject" type="text" :class="inputClass" placeholder="mailto:admin@example.org" />
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="vapid_public_key">Public key</label>
                            <input id="vapid_public_key" v-model="form.vapid_public_key" type="text" class="readout" :class="inputClass" />
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="vapid_private_key">Private key</label>
                            <input id="vapid_private_key" v-model="form.vapid_private_key" type="password" autocomplete="new-password"
                                   :class="inputClass" :placeholder="secretPlaceholder(secrets.vapid_private_key)" />
                            <p class="mt-1 text-xs text-muted">
                                {{ secrets.vapid_private_key ? 'A private key is stored (never displayed).' : 'No private key stored yet.' }}
                            </p>
                        </div>
                    </div>
                </section>

                <!-- Handover reminders -->
                <section class="channel-bar rounded-md border border-line bg-panel p-5">
                    <h3 class="mb-1 text-sm font-semibold text-ink">Handover reminders</h3>
                    <p class="mb-3 text-xs text-muted">
                        Current: {{ defaults.handover_times.join(' / ') }}, reminder
                        {{ defaults.remind_delay_minutes }} min after each. Both times must be set
                        to change the pair.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="channel-tag mb-1 block" for="handover_time_morning">Morning handover</label>
                            <input id="handover_time_morning" v-model="form.handover_time_morning" type="time" class="readout" :class="inputClass" />
                            <p v-if="form.errors.handover_time_morning" class="mt-1 text-xs text-critical">{{ form.errors.handover_time_morning }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="handover_time_afternoon">Afternoon handover</label>
                            <input id="handover_time_afternoon" v-model="form.handover_time_afternoon" type="time" class="readout" :class="inputClass" />
                            <p v-if="form.errors.handover_time_afternoon" class="mt-1 text-xs text-critical">{{ form.errors.handover_time_afternoon }}</p>
                        </div>
                        <div>
                            <label class="channel-tag mb-1 block" for="remind_delay_minutes">Delay (minutes)</label>
                            <input id="remind_delay_minutes" v-model="form.remind_delay_minutes" type="number" min="0" max="120" class="readout" :class="inputClass" />
                            <p v-if="form.errors.remind_delay_minutes" class="mt-1 text-xs text-critical">{{ form.errors.remind_delay_minutes }}</p>
                        </div>
                    </div>
                </section>

                <div class="flex items-center justify-end gap-3">
                    <span v-if="form.recentlySuccessful" class="text-sm text-ok" role="status">Saved.</span>
                    <button type="submit" :disabled="form.processing" data-testid="save-settings"
                            class="rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                        Save settings
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
