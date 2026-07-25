<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useCan } from '../../Composables/useCan.js';

/**
 * The four-unit chooser — the app's landing surface. One card per unit carrying today's
 * status (signed / in progress / no sheet), so a forgotten day is visible the moment the
 * app opens. No unit is privileged.
 */
const props = defineProps({
    // [{ code, name, bar_class, today: { date, has_sheet, rows, signed_off } }]
    units: { type: Array, default: () => [] },
    // Account-setup checklist: anything unfinished is surfaced on the first screen
    // after sign-in, because a signature 'set up later' never is.
    setup: { type: Object, default: () => ({ complete: true }) },
});

const { can } = useCan();
const canEdit = can('endorsement.edit');

const statusOf = (u) => {
    if (u.today.signed_off) {
        return { text: 'Signed off', tone: 'text-ok' };
    }
    if (u.today.has_sheet) {
        return { text: 'In progress — not signed', tone: 'text-caution' };
    }

    return { text: 'No sheet today', tone: 'text-critical' };
};

const unfilled = () => props.units.filter((u) => !u.today.has_sheet);
</script>

<template>
    <AppLayout title="Endorsement">
        <div class="mb-5">
            <h2 class="text-xl font-semibold text-ink">Shift endorsement</h2>
            <p class="text-sm text-muted">Today · <span class="readout">{{ units[0]?.today.date }}</span></p>
        </div>

        <!-- Account setup. Booleans only — no addresses, no secrets. -->
        <div v-if="!setup.complete" role="status" data-testid="setup-alert"
             class="channel-bar channel-bar-caution mb-5 rounded-md border border-line bg-caution-soft px-4 py-3 text-sm text-ink">
            <p class="font-semibold">Finish setting up your account</p>
            <ul class="mt-1 space-y-0.5 text-xs">
                <li v-if="!setup.email_verified" data-testid="setup-email">
                    <span class="readout">✗</span> Your email address is not confirmed — password resets and sign-in codes need it.
                </li>
                <li v-if="!setup.two_factor" data-testid="setup-2fa">
                    <span class="readout">✗</span> Two-step sign-in is off.
                </li>
                <li v-if="!setup.has_signature" data-testid="setup-signature">
                    <span class="readout">✗</span> No signature on file — handovers you sign will print without one.
                </li>
            </ul>
            <Link href="/profile" class="mt-2 inline-block text-xs font-semibold text-channel-ink hover:underline">
                Open my profile
            </Link>
        </div>

        <div v-if="unfilled().length > 0" role="status" data-testid="unfilled-banner"
             class="channel-bar channel-bar-caution mb-5 rounded-md border border-line bg-caution-soft px-4 py-2 text-sm text-caution">
            No endorsement sheet yet today for:
            <span class="font-semibold">{{ unfilled().map((u) => u.code).join(', ') }}</span>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <Link v-for="u in units" :key="u.code" :href="`/endorsement/${u.code.toLowerCase()}`"
                  data-testid="unit-card"
                  class="channel-bar block rounded-md border border-line bg-panel p-4 transition hover:bg-ground"
                  :class="u.bar_class">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h3 class="text-base font-semibold text-ink">{{ u.code }}</h3>
                        <p class="text-sm text-muted">{{ u.name }}</p>
                    </div>
                    <span v-if="u.today.has_sheet" class="readout text-sm">{{ u.today.rows }} pt</span>
                </div>
                <p class="mt-3 text-sm font-semibold" :class="statusOf(u).tone" data-testid="unit-status">
                    {{ statusOf(u).text }}
                </p>
            </Link>
        </div>
    </AppLayout>
</template>
