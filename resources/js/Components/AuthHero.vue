<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The signed-out stage: a dawn scene with an organic curve cut out of it for the form.
 *
 * Two things it deliberately owns rather than the layout:
 *
 *  - THE CURVE. Its fill IS the form surface, so it has to be drawn with the illustration
 *    or the two meet at a fractional-pixel seam that shows as a hairline on some zoom
 *    levels. The layout positions the form over the white region; it does not draw it.
 *  - THE FALLBACK. The scene is built from tokens and ships complete, so the page is
 *    finished before any generated asset exists. Supplying `src` swaps one element.
 */
const props = defineProps({
    /** The generated illustration, once there is one. Falls back to the drawn scene. */
    src: { type: String, default: '' },
});

const root = ref(null);
let frame = 0;

/**
 * Pointer depth: three layers drift against the pointer, nearest moving most.
 *
 * Written to CSS custom properties rather than styles per layer so the browser does the
 * compositing and this stays transform-only.
 */
const onPointerMove = (event) => {
    if (frame) {
        return;
    }

    frame = requestAnimationFrame(() => {
        frame = 0;

        const el = root.value;

        if (!el) {
            return;
        }

        const box = el.getBoundingClientRect();

        if (!box.width || !box.height) {
            return;
        }

        // -1..1 from the centre, so the scene leans toward the pointer rather than jumping.
        const x = ((event.clientX - box.left) / box.width - 0.5) * 2;
        const y = ((event.clientY - box.top) / box.height - 0.5) * 2;

        el.style.setProperty('--hero-x', x.toFixed(3));
        el.style.setProperty('--hero-y', y.toFixed(3));
    });
};

/**
 * The global reduced-motion CSS block neutralises transitions and animations — it cannot
 * touch a transform written from JavaScript, so the refusal has to happen here. Also off
 * for coarse pointers: there is no hover on a phone, and the listener would fire on every
 * touch-drag for nothing.
 */
const motionIsWelcome = () => typeof window.matchMedia === 'function'
    && !window.matchMedia('(prefers-reduced-motion: reduce)').matches
    && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

onMounted(() => {
    if (motionIsWelcome()) {
        window.addEventListener('pointermove', onPointerMove, { passive: true });
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('pointermove', onPointerMove);

    if (frame) {
        cancelAnimationFrame(frame);
    }
});
</script>

<template>
    <div ref="root" class="auth-hero" data-testid="auth-hero">
        <!-- The scene. Each layer declares its own depth; the transform is shared. -->
        <div class="auth-hero-layer" style="--hero-depth: 0.35">
            <img v-if="props.src" :src="props.src" alt="" width="1920" height="1080"
                 loading="eager" fetchpriority="high" class="auth-hero-img" />

            <!-- Drawn from tokens: complete before any asset exists. -->
            <svg v-else data-testid="hero-fallback" class="auth-hero-img" viewBox="0 0 1200 800"
                 preserveAspectRatio="xMidYMid slice" aria-hidden="true"
                 xmlns="http://www.w3.org/2000/svg">
                <rect width="1200" height="800" fill="var(--color-dawn-sky)" />
                <circle cx="880" cy="210" r="58" fill="var(--color-dawn-glow)" opacity="0.9" />
                <path d="M0 470 L180 415 L340 462 L520 400 L700 452 L900 396 L1200 448 L1200 800 L0 800 Z"
                      fill="var(--color-dawn-near)" />
                <path d="M0 560 L220 512 L430 558 L660 505 L900 552 L1200 512 L1200 800 L0 800 Z"
                      fill="var(--color-dawn-mid)" />
                <path d="M0 660 L260 620 L520 664 L820 618 L1200 656 L1200 800 L0 800 Z"
                      fill="var(--color-dawn-deep)" />
                <!-- Lit windows: the warm note, and the only one. -->
                <g fill="var(--color-dawn-glow)">
                    <rect x="196" y="556" width="20" height="34" rx="3" />
                    <rect x="236" y="544" width="20" height="46" rx="3" />
                    <rect x="276" y="566" width="20" height="24" rx="3" />
                </g>
            </svg>
        </div>

        <!--
          The curve. Decorative: it carries no information, it is the SHAPE of the form
          surface. Two paths, swapped by media query rather than JS so the right one is
          present on first paint.
        -->
        <svg class="auth-hero-curve" data-testid="hero-curve" aria-hidden="true"
             viewBox="0 0 100 100" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
            <path class="auth-hero-curve-wide"
                  d="M100 0 L100 100 L46 100 C46 74 38 62 38 50 C38 38 46 26 46 0 Z"
                  fill="var(--color-panel)" />
            <!--
              Narrow: the scene is a ~30vh top band and white takes everything below. The
              form column is pinned under this edge by the layout's `min-h` on the brand
              panel — if the two drift apart, dark ink lands on the dark illustration.
            -->
            <path class="auth-hero-curve-narrow"
                  d="M0 100 L100 100 L100 32 C72 32 62 24 50 24 C38 24 28 32 0 32 Z"
                  fill="var(--color-panel)" />
        </svg>
    </div>
</template>
