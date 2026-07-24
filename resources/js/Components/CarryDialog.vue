<script setup>
import { router } from '@inertiajs/vue3';

/**
 * The new-day GAP dialog (spec ruling 2). Shown when the server answered a new-day request
 * with `carry_prompt` instead of creating anything: the most recent endorsement is older
 * than yesterday, so a human chooses between carrying that census forward and starting
 * blank. The payload carries unit code and dates only — no PHI.
 */
const props = defineProps({
    // { unit, date, last_date } — exactly what EndorsementController::newDay flashed.
    prompt: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const choose = (choice) => {
    router.post(`/endorsement/${props.prompt.unit}/new-day`, {
        date: props.prompt.date,
        carry_choice: choice,
    }, {
        onFinish: () => emit('close'),
    });
};
</script>

<template>
    <div class="fixed inset-0 z-50 grid place-items-center bg-ink/40 p-4" role="presentation">
        <div role="alertdialog" aria-modal="true" aria-labelledby="carry-title" data-testid="carry-dialog"
             class="channel-bar channel-bar-caution w-full max-w-md rounded-md border border-line bg-panel p-5">
            <h2 id="carry-title" class="mb-2 text-base font-semibold text-ink">
                Last endorsement was <span class="readout">{{ prompt.last_date }}</span>
            </h2>
            <p class="mb-4 text-sm text-body">
                There is no sheet for the day before <span class="readout">{{ prompt.date }}</span>.
                Carry the census from <span class="readout">{{ prompt.last_date }}</span> forward,
                or start this day with a blank sheet?
            </p>
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" data-testid="carry-cancel" @click="emit('close')"
                        class="min-h-11 rounded-md px-3 py-1.5 text-sm text-muted hover:text-body hover:underline">
                    Cancel
                </button>
                <button type="button" data-testid="carry-blank" @click="choose('blank')"
                        class="min-h-11 rounded-md border border-line px-3 py-1.5 text-sm font-semibold text-channel-ink hover:bg-channel-soft">
                    Start blank
                </button>
                <button type="button" data-testid="carry-forward" @click="choose('carry')"
                        class="min-h-11 rounded-md bg-channel px-3 py-1.5 text-sm font-semibold text-panel hover:bg-channel-ink">
                    Carry census forward
                </button>
            </div>
        </div>
    </div>
</template>
