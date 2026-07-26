<script setup>
import { Head } from '@inertiajs/vue3';
import BrandMark from '../Components/BrandMark.vue';
import AuthHero from '../Components/AuthHero.vue';

/**
 * The shell every signed-out page shares (sign in, request access, second factor).
 *
 * ONE STAGE, NOT TWO COLUMNS. The hero fills the viewport and the form sits over the
 * white region its curve cuts out — so the boundary is an organic sweep rather than a
 * straight split, which is the whole point of the redesign.
 *
 * What survived unchanged, because it is load-bearing rather than styling:
 *  - DOM order brand → form → orientation, so the form is above the fold on a phone and
 *    tab order matches visual order;
 *  - `min-h-dvh`, not `min-h-screen` — 100vh on iOS sits behind the URL bar and pushes
 *    the submit button off-screen;
 *  - the lockup carries the page's single <h1>, since on a phone it is the only part of
 *    the brand panel visible.
 *
 * Glass (`.auth-glass`) is on the lockup and unit chips ONLY. It never sits behind form
 * text: translucency over an unpredictable illustration is how contrast quietly fails,
 * and this is read at 3am on a shared ward monitor.
 */
defineProps({
    title: { type: String, default: '' },
    heading: { type: String, default: 'Sign in' },
    subheading: { type: String, default: '' },
    // Register needs more room than a sign-in form.
    wide: { type: Boolean, default: false },
    /** The generated dawn illustration. Absent → AuthHero draws its own. */
    heroSrc: { type: String, default: '' },
});

const units = [
    { code: 'PICU', name: 'Paediatric Intensive Care' },
    { code: 'NICU', name: 'Neonatal Intensive Care' },
    { code: 'SCBU', name: 'Special Care Baby Unit' },
    { code: 'WARD', name: 'Paediatric Ward' },
];
</script>

<template>
    <Head :title="title" />

    <div class="relative min-h-dvh">
        <AuthHero :src="heroSrc" />

        <!-- Everything below sits over the hero. -->
        <div class="relative flex min-h-dvh flex-col lg:flex-row">
            <!-- Brand + orientation, over the illustration. -->
            <!--
              min-h-[34vh] on narrow is load-bearing, not spacing: it holds the form column
              below the hero curve's white edge (which begins at 24–32%). Without it the
              form floats up onto the dark illustration and `ink` text sits on `dawn-near`.
            -->
            <aside class="flex min-h-[34vh] shrink-0 flex-col justify-between px-5 pt-5 pb-6 lg:min-h-0 lg:w-[54%] lg:px-12 lg:py-10">
                <div class="auth-reveal auth-glass flex w-fit items-center gap-3 rounded-xl px-3 py-2.5"
                     style="--reveal-order: 0">
                    <BrandMark class="h-10 w-10" />
                    <div class="leading-tight">
                        <h1 class="text-base font-bold text-white">Paediatric Endorsement</h1>
                        <p class="channel-tag !text-white/75">Qatif Central Hospital</p>
                    </div>
                </div>

                <div class="hidden lg:block">
                    <h2 class="auth-reveal max-w-md text-3xl leading-tight font-semibold text-white"
                        style="--reveal-order: 1">
                        Every shift, handed over in writing.
                    </h2>
                    <p class="auth-reveal mt-3 max-w-md text-sm text-white/80" style="--reveal-order: 2">
                        The census, the plan and the follow-ups for every child on the unit — written
                        during the shift, signed at handover, printed for the round.
                    </p>

                    <ul class="auth-reveal mt-8 flex max-w-md flex-wrap gap-2" data-testid="unit-legend"
                        style="--reveal-order: 3">
                        <li v-for="u in units" :key="u.code"
                            class="auth-glass flex items-baseline gap-2 rounded-lg px-2.5 py-1.5">
                            <span class="readout text-xs font-semibold text-white">{{ u.code }}</span>
                            <span class="text-xs text-white/75">{{ u.name }}</span>
                        </li>
                    </ul>
                </div>

                <p class="channel-tag hidden !text-white/60 lg:block">
                    Department of Paediatrics · internal clinical system
                </p>
            </aside>

            <!-- The form, over the white region the curve cuts. -->
            <main class="flex flex-1 items-center justify-center px-5 pb-10 lg:px-12 lg:py-8">
                <div class="w-full" :class="wide ? 'max-w-md' : 'max-w-sm'">
                    <h2 class="auth-reveal text-xl font-semibold text-ink" style="--reveal-order: 1">
                        {{ heading }}
                    </h2>
                    <p v-if="subheading" class="auth-reveal mt-1 text-sm text-muted" style="--reveal-order: 2">
                        {{ subheading }}
                    </p>

                    <div class="auth-reveal mt-6" style="--reveal-order: 3">
                        <slot />
                    </div>

                    <!-- Orientation for a first-time visitor, after the form on a phone. -->
                    <section class="mt-10 border-t border-line-soft pt-6 lg:hidden">
                        <p class="text-sm text-body">
                            Shift handover for the paediatric units — PICU, NICU, SCBU and the Ward.
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span v-for="u in units" :key="u.code"
                                  class="channel-bar rounded-md bg-ground px-2 py-1">
                                <span class="readout text-xs font-semibold">{{ u.code }}</span>
                            </span>
                        </div>
                    </section>
                </div>
            </main>
        </div>
    </div>
</template>
