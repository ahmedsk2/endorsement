<script setup>
import { useForm } from '@inertiajs/vue3';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import { usePasswordStrength } from '../../Composables/usePasswordStrength.js';
import StaffPrivacyNotice from '../../Components/StaffPrivacyNotice.vue';

const props = defineProps({
    token: { type: String, required: true },
    member_email: { type: String, required: true },
    position_label: { type: String, required: true },
});

/*
 * Note what is NOT in this form: the email address and the role. Both travel with the
 * invitation and are read from it on the server, so there is no field here that a tampered
 * request could use to claim a different address or a higher position. They are shown
 * read-only so the person can see what they are accepting — and stop if it is wrong.
 */
const form = useForm({
    full_name: '',
    member_name: '',
    password: '',
    password_confirmation: '',
});

const { requirements, met, strength, segmentClass, mismatch, matches } = usePasswordStrength(
    () => form.password,
    () => form.password_confirmation,
);

const submit = () =>
    form.post(`/invitation/${props.token}`, {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
</script>

<template>
    <AuthLayout title="Accept your invitation" heading="Set up your account" wide
                hero-src="/img/auth-dawn.webp"
                subheading="Choose a username and password. Your access is already approved.">
        <form @submit.prevent="submit" class="space-y-4">
            <!-- What was decided for you, shown so it can be checked before accepting. -->
            <div class="rounded-md border border-line bg-panel-soft px-3 py-2.5">
                <p class="channel-tag mb-1">Invited as</p>
                <p class="text-sm font-semibold text-ink" data-testid="invited-role">{{ position_label }}</p>
                <p class="mt-1 text-sm text-muted" data-testid="invited-email">{{ member_email }}</p>
                <p class="mt-2 text-xs text-muted">
                    If either of these is wrong, stop and ask the person who invited you — they cannot be
                    changed here.
                </p>
            </div>

            <!-- Before the account exists, not after. PDPL requires the person be
                 informed; being told once they are already committed is not being informed. -->
            <StaffPrivacyNotice context="invitation" />

            <div>
                <label class="channel-tag mb-1.5 block">Full name</label>
                <input v-model="form.full_name" type="text"
                       class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                <p v-if="form.errors.full_name" class="mt-1 text-xs text-critical">{{ form.errors.full_name }}</p>
            </div>

            <div>
                <label class="channel-tag mb-1.5 block">Username</label>
                <input v-model="form.member_name" type="text" autocomplete="username"
                       class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                <p v-if="form.errors.member_name" class="mt-1 text-xs text-critical">{{ form.errors.member_name }}</p>
            </div>

            <div>
                <label class="channel-tag mb-1.5 block" for="inv-password">Password</label>
                <input id="inv-password" v-model="form.password" data-testid="password" type="password"
                       autocomplete="new-password" aria-describedby="pw-requirements"
                       class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />

                <div class="mt-2 flex items-center gap-2">
                    <div class="flex flex-1 gap-1" data-testid="pw-meter">
                        <span v-for="i in 4" :key="i" data-testid="pw-meter-segment"
                              class="h-1.5 flex-1 rounded-md transition-colors" :class="segmentClass(i - 1)" />
                    </div>
                    <span v-if="strength" data-testid="pw-strength-label" role="status" class="channel-tag"
                          :class="{ critical: 'text-critical', caution: 'text-caution', ok: 'text-ok' }[strength.tone]">
                        {{ strength.label }}
                    </span>
                </div>

                <ul id="pw-requirements" class="mt-2 space-y-1 text-xs">
                    <li v-for="(r, i) in requirements" :key="r.key" :data-testid="`pw-req-${r.key}`"
                        class="flex items-center gap-2 transition-colors"
                        :class="met[i] ? 'text-ok' : 'text-critical'">
                        <span aria-hidden="true" class="readout">{{ met[i] ? '✓' : '✗' }}</span>
                        {{ r.label }}
                        <span class="sr-only">{{ met[i] ? '(met)' : '(not met yet)' }}</span>
                    </li>
                </ul>

                <p v-if="form.errors.password" class="mt-1 text-xs text-critical">{{ form.errors.password }}</p>
            </div>

            <div>
                <label class="channel-tag mb-1.5 block" for="inv-password-confirmation">Confirm password</label>
                <input id="inv-password-confirmation" v-model="form.password_confirmation"
                       data-testid="password-confirmation" type="password" autocomplete="new-password"
                       class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />

                <p v-if="mismatch" data-testid="pw-mismatch" role="alert" class="mt-1 text-xs font-semibold text-critical">
                    Passwords do not match.
                </p>
                <p v-else-if="matches" data-testid="pw-match" role="status" class="mt-1 text-xs text-ok">
                    Passwords match.
                </p>
            </div>

            <button type="submit" :disabled="form.processing"
                    class="w-full rounded-md bg-channel px-4 py-2.5 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                Create my account
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-muted">
            This link works once. Authorised clinical staff only.
        </p>
    </AuthLayout>
</template>
