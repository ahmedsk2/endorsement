<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({ code: '', trust_device: false });
const recovery = ref(false);
const submit = () => form.post('/two-factor-challenge', { onFinish: () => form.reset('code') });
</script>

<template>
    <Head title="Two-factor verification" />
    <main class="min-h-screen flex items-center justify-center bg-ground p-6">
        <div class="w-full max-w-sm rounded-md border border-line bg-panel p-8">
            <h1 class="text-xl font-semibold text-ink">Two-factor verification</h1>
            <p class="mt-1 text-sm text-muted">
                {{ recovery ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your authenticator app.' }}
            </p>

            <form @submit.prevent="submit" class="mt-6 space-y-4">
                <div>
                    <input
                        v-model="form.code"
                        :inputmode="recovery ? 'text' : 'numeric'"
                        autofocus
                        autocomplete="one-time-code"
                        :placeholder="recovery ? 'XXXXXXXX-XXXXXXXX' : '123456'"
                        class="readout w-full rounded-md border border-line bg-panel px-3 py-2 text-center text-2xl font-bold tracking-[0.3em] focus:border-channel focus:ring-2 focus:ring-channel focus:outline-none"
                    />
                    <p v-if="form.errors.code" class="mt-1 text-center text-xs text-critical">{{ form.errors.code }}</p>
                </div>


                <!--
                  Offered only after the code is proven, and it skips the SECOND factor alone
                  — never the password, never the account checks. Scoped to this user, so a
                  cookie left on a shared ward workstation cannot carry a colleague past
                  their own.
                -->
                <label class="flex min-h-11 cursor-pointer items-start gap-3 text-sm text-body">
                    <input v-model="form.trust_device" type="checkbox" data-testid="trust-device" class="mt-1 h-4 w-4" />
                    <span>
                        <span class="font-medium text-ink">Don't ask for a code on this device for 7 days</span>
                        <span class="block text-xs text-muted">Only on a device that is yours. Changing your password ends this everywhere.</span>
                    </span>
                </label>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-md bg-channel px-4 py-2.5 font-semibold text-white transition hover:bg-channel-ink disabled:opacity-60"
                >
                    {{ form.processing ? 'Verifying…' : 'Verify' }}
                </button>

                <button
                    type="button"
                    @click="recovery = !recovery; form.reset('code')"
                    class="w-full text-center text-xs font-semibold text-channel-ink hover:underline"
                >
                    {{ recovery ? 'Use an authenticator code instead' : 'Use a recovery code instead' }}
                </button>
            </form>

            <p class="mt-6 text-center text-sm">
                <Link href="/login" class="font-medium text-muted hover:text-body">Back to sign in</Link>
            </p>
        </div>
    </main>
</template>
