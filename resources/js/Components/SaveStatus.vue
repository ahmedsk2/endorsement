<script setup>
/**
 * G3 — per-field save feedback. Legacy flashed a green 5px underline on the element it had just
 * saved (`picu-endorsement-patients.php:572`); the Laravel rebuild replaced that with a single
 * page-level flash, so a clinician editing one cell among dozens got no confirmation that THAT cell
 * landed. This restores the per-field signal and adds the failure state legacy never had (legacy's
 * autosave was fire-and-forget and ignored the response entirely, `:568-571`).
 */
defineProps({
    // '' (idle, renders nothing) | 'saving' (renders nothing) | 'saved' | 'error'.
    status: { type: String, default: '' },
    testid: { type: String, default: 'save-status' },
});
</script>

<template>
    <span v-if="status === 'saved'" :data-testid="testid" role="status"
          class="channel-tag mt-0.5 block text-ok">Saved</span>
    <span v-else-if="status === 'error'" :data-testid="testid" role="alert"
          class="channel-tag mt-0.5 block text-critical">Not saved</span>
</template>
