<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { usePasswordStrength } from '../../Composables/usePasswordStrength.js';

/*
 * Changing your own password, on a page of its own.
 *
 * It used to be the third form on the profile page, below identity and sign-in security.
 * Three forms stacked on one screen with three separate Save buttons is a good way to
 * submit the wrong one, and a password change deserves to be a deliberate act rather than
 * something you scroll past.
 *
 * The requirement checklist comes from the shared composable, which mirrors the SERVER rule
 * (App\Support\PasswordPolicy). The profile page carried its own private copy of those four
 * rules — a third one — and a checklist that disagrees with the server is worse than none.
 */
const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const { requirements, met, strength, segmentClass, mismatch, matches } = usePasswordStrength(
    () => form.password,
    () => form.password_confirmation,
);

const submit = () =>
    form.put('/profile/password', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
</script>

<template>
    <AppLayout title="Change password">
        <div class="mx-auto max-w-lg space-y-6">
            <div>
                <p class="text-sm text-muted">
                    You will stay signed in on this device. Passwords expire every 90 days, and a
                    previous password cannot be reused.
                </p>
                <p class="mt-2 text-sm">
                    <Link href="/profile" class="font-semibold text-channel-ink hover:underline">Back to my profile</Link>
                </p>
            </div>

            <form class="space-y-4 rounded-md border border-line bg-panel p-6" @submit.prevent="submit">
                <div>
                    <label class="channel-tag mb-1.5 block" for="current-password">Current password</label>
                    <input id="current-password" v-model="form.current_password" data-testid="current-password"
                           type="password" autocomplete="current-password"
                           class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                    <p v-if="form.errors.current_password" class="mt-1 text-xs text-critical" role="alert">
                        {{ form.errors.current_password }}
                    </p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block" for="new-password">New password</label>
                    <input id="new-password" v-model="form.password" data-testid="new-password"
                           type="password" autocomplete="new-password" aria-describedby="pw-requirements"
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
                        <li v-for="(r, i) in requirements" :key="r.key" :data-testid="`pf-req-${r.key}`"
                            class="flex items-center gap-2 transition-colors"
                            :class="met[i] ? 'text-ok' : 'text-critical'">
                            <span aria-hidden="true" class="readout">{{ met[i] ? '✓' : '✗' }}</span>
                            {{ r.label }}
                            <span class="sr-only">{{ met[i] ? '(met)' : '(not met yet)' }}</span>
                        </li>
                    </ul>

                    <p v-if="form.errors.password" class="mt-1 text-xs text-critical" role="alert">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block" for="confirm-password">Confirm new password</label>
                    <input id="confirm-password" v-model="form.password_confirmation" data-testid="confirm-password"
                           type="password" autocomplete="new-password"
                           class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />

                    <p v-if="mismatch" data-testid="pf-mismatch" role="alert" class="mt-1 text-xs font-semibold text-critical">
                        Passwords do not match.
                    </p>
                    <p v-else-if="matches" role="status" class="mt-1 text-xs text-ok">Passwords match.</p>
                </div>

                <div class="flex items-center justify-end gap-3">
                    <span v-if="form.recentlySuccessful" class="text-sm text-ok" role="status">Password changed.</span>
                    <button type="submit" data-testid="save-password" :disabled="form.processing"
                            class="min-h-11 rounded-md bg-channel px-4 py-2 text-sm font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                        Change password
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
