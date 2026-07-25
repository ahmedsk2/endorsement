<script setup>
import { Head } from '@inertiajs/vue3';
import BrandMark from '../Components/BrandMark.vue';

/**
 * The shell every signed-out page shares (sign in, request access, reset, second factor).
 *
 * Layout: a full-bleed split at lg — a calm brand/orientation panel on the left, the form
 * column on the right — collapsing to one column below that, with DOM order
 * brand → form → orientation so the form is above the fold on a phone and the tab order
 * matches the visual order. `min-h-dvh`, not `min-h-screen`: 100vh on iOS sits behind the
 * URL bar and pushes the submit button off-screen.
 *
 * No card inside the form column on desktop — the column IS the surface; a bordered card
 * inside a bordered column is the double-frame mistake.
 */
defineProps({
    title: { type: String, default: '' },
    heading: { type: String, default: 'Sign in' },
    subheading: { type: String, default: '' },
    // Register needs more room than a sign-in form.
    wide: { type: Boolean, default: false },
});

const units = [
    { code: 'PICU', name: 'Paediatric Intensive Care', bar: 'channel-bar-picu' },
    { code: 'NICU', name: 'Neonatal Intensive Care', bar: 'channel-bar-nicu' },
    { code: 'SCBU', name: 'Special Care Baby Unit', bar: 'channel-bar-scbu' },
    { code: 'WARD', name: 'Paediatric Ward', bar: 'channel-bar-ward' },
];
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-dvh flex-col lg:flex-row">
        <!-- Brand + orientation. The lockup is the only part visible on a phone, which is
             why it carries the page's single <h1>. -->
        <aside class="flex shrink-0 flex-col justify-between border-b border-line bg-ground-deep px-5 py-4 lg:w-[46%] lg:border-b-0 lg:border-r lg:px-12 lg:py-10">
            <div class="flex items-center gap-3">
                <BrandMark class="h-10 w-10" />
                <div class="leading-tight">
                    <h1 class="text-base font-bold text-ink">Paediatric Endorsement</h1>
                    <p class="channel-tag">Qatif Central Hospital</p>
                </div>
            </div>

            <div class="hidden lg:block">
                <h2 class="max-w-md text-2xl font-semibold text-ink">
                    Shift handover for the paediatric units
                </h2>
                <p class="mt-3 max-w-md text-sm text-body">
                    The census, the plan and the follow-ups for every child on the unit — written
                    during the shift, signed at handover, printed for the round.
                </p>

                <ul class="mt-8 max-w-md space-y-2" data-testid="unit-legend">
                    <li v-for="u in units" :key="u.code"
                        class="channel-bar flex items-baseline gap-3 rounded-md bg-panel py-2 pr-3 pl-3"
                        :class="u.bar">
                        <span class="readout text-sm font-semibold">{{ u.code }}</span>
                        <span class="text-sm text-body">{{ u.name }}</span>
                    </li>
                </ul>
            </div>

            <p class="channel-tag hidden lg:block">Department of Paediatrics · internal clinical system</p>
        </aside>

        <!-- The form column. -->
        <main class="flex flex-1 items-center justify-center bg-panel px-5 py-8 lg:px-12">
            <div class="w-full" :class="wide ? 'max-w-md' : 'max-w-sm'">
                <h2 class="text-xl font-semibold text-ink">{{ heading }}</h2>
                <p v-if="subheading" class="mt-1 text-sm text-muted">{{ subheading }}</p>

                <div class="mt-6">
                    <slot />
                </div>

                <!-- Orientation for a first-time visitor, after the form on a phone. -->
                <section class="mt-10 border-t border-line-soft pt-6 lg:hidden">
                    <p class="text-sm text-body">
                        Shift handover for the paediatric units — PICU, NICU, SCBU and the Ward.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span v-for="u in units" :key="u.code"
                              class="channel-bar rounded-md bg-ground px-2 py-1" :class="u.bar">
                            <span class="readout text-xs font-semibold">{{ u.code }}</span>
                        </span>
                    </div>
                </section>
            </div>
        </main>
    </div>
</template>
