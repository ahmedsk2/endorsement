<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';

/**
 * Draw-your-signature canvas. Pointer events cover mouse, stylus and finger with one code
 * path, so a resident can sign on a phone exactly as on a desktop. The canvas is backed at
 * device pixel ratio so a drawn line is not a blurry upscale on a retina screen.
 *
 * Emits `change` with a PNG data URL (or null once cleared) — the parent decides when to save.
 */
const emit = defineEmits(['change']);

const canvas = ref(null);
const hasInk = ref(false);
let ctx = null;
let drawing = false;

const setup = () => {
    const el = canvas.value;
    if (!el) return;

    const ratio = window.devicePixelRatio || 1;
    const rect = el.getBoundingClientRect();

    el.width = Math.max(rect.width, 1) * ratio;
    el.height = Math.max(rect.height, 1) * ratio;

    ctx = el.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    // Ink colour is read from the token-driven computed style, so the pad follows the
    // design system instead of hard-coding a hex.
    ctx.strokeStyle = getComputedStyle(el).color || '#0b2e33';
};

const pos = (event) => {
    const rect = canvas.value.getBoundingClientRect();

    return { x: event.clientX - rect.left, y: event.clientY - rect.top };
};

const start = (event) => {
    if (!ctx) setup();
    drawing = true;
    canvas.value.setPointerCapture?.(event.pointerId);
    const { x, y } = pos(event);
    ctx.beginPath();
    ctx.moveTo(x, y);
    event.preventDefault();
};

const move = (event) => {
    if (!drawing) return;
    const { x, y } = pos(event);
    ctx.lineTo(x, y);
    ctx.stroke();
    hasInk.value = true;
    event.preventDefault();
};

const end = () => {
    if (!drawing) return;
    drawing = false;
    if (hasInk.value) {
        emit('change', canvas.value.toDataURL('image/png'));
    }
};

const clear = () => {
    if (!ctx) return;
    const el = canvas.value;
    ctx.clearRect(0, 0, el.width, el.height);
    hasInk.value = false;
    emit('change', null);
};

defineExpose({ clear });

onMounted(() => {
    setup();
    window.addEventListener('resize', setup);
});

onBeforeUnmount(() => window.removeEventListener('resize', setup));
</script>

<template>
    <div>
        <canvas ref="canvas" data-testid="signature-pad"
                class="h-40 w-full touch-none rounded-md border border-dashed border-line bg-panel text-ink"
                aria-label="Draw your signature"
                @pointerdown="start" @pointermove="move" @pointerup="end" @pointerleave="end" />
        <div class="mt-2 flex items-center justify-between">
            <p class="text-xs text-muted">Sign with a finger, stylus or mouse.</p>
            <button type="button" data-testid="signature-clear" @click="clear"
                    class="text-xs font-semibold text-channel-ink hover:underline">Clear</button>
        </div>
    </div>
</template>
