<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    positions: { type: Object, default: () => ({}) },
});

const form = useForm({
    full_name: '',
    member_name: '',
    member_email: '',
    position: 4,
    password: '',
    password_confirmation: '',
});

/*
 * The live password checklist. These four requirements MIRROR THE SERVER RULE
 * (RegisteredUserController: Password::min(8)->mixedCase()->numbers()->symbols())
 * exactly — a checklist the server disagrees with is worse than none. Change them
 * together or not at all.
 */
const requirements = [
    { key: 'length', label: 'At least 8 characters', test: (p) => p.length >= 8 },
    { key: 'case', label: 'Upper and lower case letters', test: (p) => /[a-z]/.test(p) && /[A-Z]/.test(p) },
    { key: 'number', label: 'At least one number', test: (p) => /\d/.test(p) },
    { key: 'symbol', label: 'At least one symbol (e.g. ! - @ #)', test: (p) => /[^A-Za-z0-9]/.test(p) },
];

const met = computed(() => requirements.map((r) => r.test(form.password)));
const metCount = computed(() => met.value.filter(Boolean).length);

/*
 * Strength = how many of the requirements are met (plus a top tier for length ≥ 12).
 * Deliberately simple and explainable — the meter and the checklist always agree,
 * which is what makes the meter trustworthy rather than mysterious.
 */
const strength = computed(() => {
    if (form.password === '') {
        return null;
    }
    if (metCount.value === 4 && form.password.length >= 12) {
        return { label: 'Very strong', tone: 'ok' };
    }

    return [
        { label: 'Weak', tone: 'critical' },
        { label: 'Weak', tone: 'critical' },
        { label: 'Fair', tone: 'caution' },
        { label: 'Good', tone: 'caution' },
        { label: 'Strong', tone: 'ok' },
    ][metCount.value];
});

const segmentClass = (i) => {
    if (strength.value === null || i >= Math.max(metCount.value, form.password ? 1 : 0)) {
        return 'bg-line-soft';
    }

    return { critical: 'bg-critical', caution: 'bg-caution', ok: 'bg-ok' }[strength.value.tone];
};

// Live match check — the label appears BEFORE submitting, once both fields have content.
const bothEntered = computed(() => form.password !== '' && form.password_confirmation !== '');
const mismatch = computed(() => bothEntered.value && form.password !== form.password_confirmation);
const matches = computed(() => bothEntered.value && form.password === form.password_confirmation);

const submit = () =>
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
</script>

<template>
    <Head title="Create account" />
    <main class="min-h-screen flex items-center justify-center bg-ground p-6">
        <div class="w-full max-w-sm rounded-md border border-line bg-panel p-8">
            <h1 class="text-xl font-semibold text-ink">Create account</h1>
            <p class="mt-1 text-sm text-muted">
                An administrator activates your account before you can sign in.
            </p>

            <form @submit.prevent="submit" class="mt-6 space-y-4">
                <div>
                    <label class="channel-tag mb-1.5 block">Full name</label>
                    <input v-model="form.full_name" type="text" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                    <p v-if="form.errors.full_name" class="mt-1 text-xs text-critical">{{ form.errors.full_name }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block">Username</label>
                    <input v-model="form.member_name" type="text" autocomplete="username" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                    <p v-if="form.errors.member_name" class="mt-1 text-xs text-critical">{{ form.errors.member_name }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block">Email</label>
                    <input v-model="form.member_email" type="email" autocomplete="email" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />
                    <p v-if="form.errors.member_email" class="mt-1 text-xs text-critical">{{ form.errors.member_email }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block">Role</label>
                    <select v-model.number="form.position" class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel">
                        <option v-for="(label, value) in positions" :key="value" :value="Number(value)">{{ label }}</option>
                    </select>
                    <p v-if="form.errors.position" class="mt-1 text-xs text-critical">{{ form.errors.position }}</p>
                </div>

                <div>
                    <label class="channel-tag mb-1.5 block" for="reg-password">Password</label>
                    <input id="reg-password" v-model="form.password" data-testid="password" type="password" autocomplete="new-password"
                           :aria-describedby="'pw-requirements'"
                           class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />

                    <!-- Strength meter: one segment per met requirement; tier named beside it. -->
                    <div class="mt-2 flex items-center gap-2" aria-hidden="false">
                        <div class="flex flex-1 gap-1" data-testid="pw-meter">
                            <span v-for="i in 4" :key="i" data-testid="pw-meter-segment"
                                  class="h-1.5 flex-1 rounded-md transition-colors" :class="segmentClass(i - 1)" />
                        </div>
                        <span v-if="strength" data-testid="pw-strength-label" role="status"
                              class="channel-tag"
                              :class="{ critical: 'text-critical', caution: 'text-caution', ok: 'text-ok' }[strength.tone]">
                            {{ strength.label }}
                        </span>
                    </div>

                    <!-- The four server requirements, red until each is fulfilled. -->
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
                    <label class="channel-tag mb-1.5 block" for="reg-password-confirmation">Confirm password</label>
                    <input id="reg-password-confirmation" v-model="form.password_confirmation" data-testid="password-confirmation"
                           type="password" autocomplete="new-password"
                           class="w-full rounded-md border border-line bg-panel px-3 py-2 text-ink focus:border-channel focus:ring-channel" />

                    <!-- Live match state — visible BEFORE the form is ever submitted. -->
                    <p v-if="mismatch" data-testid="pw-mismatch" role="alert" class="mt-1 text-xs font-semibold text-critical">
                        Passwords do not match.
                    </p>
                    <p v-else-if="matches" data-testid="pw-match" role="status" class="mt-1 text-xs text-ok">
                        Passwords match.
                    </p>
                </div>

                <button type="submit" :disabled="form.processing" class="w-full rounded-md bg-channel px-4 py-2.5 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60">
                    Register
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-muted">
                Already have an account?
                <Link href="/login" class="font-medium text-channel-ink hover:underline">Sign in</Link>
            </p>
        </div>
    </main>
</template>
