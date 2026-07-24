<script setup>
import { ref, watch, onMounted } from 'vue';
import SaveStatus from './SaveStatus.vue';

/**
 * G3 — the rich-text handover editor: a `contenteditable` plus the formatting toolbar that the
 * Laravel rebuild had lost. Ports the legacy command set verbatim from
 * `picu-endorsement-patients.php:750-807` — bold / italic / underline / bulleted list / numbered
 * list / text colour / highlight colour — so the markup produced stays inside the
 * `RichTextSanitizer` allow-list (`span[style]` + color/background-color/font-weight/text-decoration)
 * and survives the save. Widening the toolbar beyond these commands would mean formatting silently
 * vanishing on write, so the two must be changed together (see the round-trip feature test).
 *
 * DELIBERATE DEVIATION FROM LEGACY: legacy revealed this toolbar ONLY on right-click inside an
 * editor (`:766-775`). A hidden right-click-only affordance is undiscoverable — staff had no way to
 * learn the feature existed — and unreachable by keyboard, so it is also an accessibility defect.
 * Here the toolbar appears on FOCUS (pointer or Tab), every control is a real focusable button with
 * an accessible name, and Ctrl/Cmd+B/I/U are bound explicitly. Right-click is kept working as well,
 * purely for the muscle memory of staff who used the legacy system for years.
 *
 * `document.execCommand` is deprecated but is still the pragmatic choice for `contenteditable`: it
 * is what legacy emitted, it needs no editor dependency, and its output is byte-compatible with the
 * existing sanitized rows. The `styleWithCSS` bracketing is legacy's (`:783-807`) and is what makes
 * colours land as `span[style]` rather than the `<font>` tags older browsers would otherwise emit.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    editable: { type: Boolean, default: true },
    // Accessible name for the editing region (the column this field belongs to).
    label: { type: String, default: 'Rich text' },
    // Per-field save feedback: '' (idle) | 'saved' | 'error'.
    status: { type: String, default: '' },
});

const emit = defineEmits(['save']);

const root = ref(null);
const editorEl = ref(null);
const open = ref(false);

// The last selection made inside this editor. Clicking a colour picker moves focus out of the
// contenteditable and collapses the selection, so it is captured and restored before every command.
let savedRange = null;

const rememberSelection = () => {
    const sel = window.getSelection?.();
    if (sel && sel.rangeCount > 0 && editorEl.value?.contains(sel.anchorNode)) {
        savedRange = sel.getRangeAt(0).cloneRange();
    }
};

const restoreSelection = () => {
    editorEl.value?.focus();
    if (!savedRange) {
        return;
    }
    const sel = window.getSelection?.();
    if (sel) {
        sel.removeAllRanges();
        sel.addRange(savedRange);
    }
};

/**
 * The commands that must run with `styleWithCSS` ON. VERIFIED IN A REAL BROWSER against the
 * sanitizer, because this is the difference between formatting that persists and formatting that is
 * silently discarded on save:
 *
 *   styleWithCSS ON  → bold emits `span[style="font-weight:bold"]`      (font-weight IS allow-listed)
 *                      italic emits `span[style="font-style:italic"]`   (font-style is NOT — STRIPPED)
 *                      underline emits `span[style="text-decoration-line:…"]`
 *                                                        (only `text-decoration` is allow-listed — STRIPPED)
 *   styleWithCSS OFF → bold/italic/underline emit `<b>` / `<i>` / `<u>` (all three allow-listed TAGS)
 *
 * So the tag-producing commands run with it OFF. Legacy bracketed EVERY command with it ON
 * (`picu-endorsement-patients.php:783-807`), which means legacy's italic and underline were quietly
 * thrown away by `sanitize.php` on write — a latent bug inherited from the port and fixed here.
 *
 * The two colour commands still need it ON: with it off, `foreColor` falls back to `<font color>`,
 * whose colour survives but which is the deprecated form, and only the CSS path gives `hiliteColor`
 * a highlight at all. Both emit `span[style]` with `color` / `background-color` — allow-listed.
 */
const CSS_STYLED_COMMANDS = ['foreColor', 'hiliteColor'];

/** Issue one formatting command, bracketed so its output lands inside the sanitizer allow-list. */
const exec = (command, value = null) => {
    if (typeof document.execCommand !== 'function') {
        return;
    }
    const useCss = CSS_STYLED_COMMANDS.includes(command);

    restoreSelection();
    document.execCommand('styleWithCSS', false, useCss);
    document.execCommand(command, false, value);
    document.execCommand('styleWithCSS', false, false);
    rememberSelection();
};

const onKeydown = (event) => {
    if (!props.editable || !(event.ctrlKey || event.metaKey)) {
        return;
    }
    const command = { b: 'bold', i: 'italic', u: 'underline' }[String(event.key).toLowerCase()];
    if (!command) {
        return;
    }
    // Take over from the browser's own handler so the styleWithCSS bracketing applies.
    event.preventDefault();
    exec(command);
};

const onFocus = () => {
    if (props.editable) {
        open.value = true;
    }
};

// Legacy muscle memory: right-click still summons the toolbar.
const onContextmenu = (event) => {
    if (!props.editable) {
        return;
    }
    event.preventDefault();
    open.value = true;
};

/**
 * Save-on-blur is kept (G3): per-keystroke saving as legacy did overwrote the whole row from a
 * possibly stale DOM and clobbered concurrent editors. Focus moving to our OWN toolbar (a colour
 * picker takes focus) is not leaving the field, so it neither saves nor closes the toolbar.
 */
const onFocusOut = (event) => {
    rememberSelection();

    if (root.value && event.relatedTarget && root.value.contains(event.relatedTarget)) {
        return;
    }

    open.value = false;

    if (props.editable && editorEl.value) {
        emit('save', editorEl.value.innerHTML);
    }
};

// The markup is written imperatively rather than through `v-html` so that a prop refresh can never
// clobber what the clinician is part-way through typing.
const syncFromProp = () => {
    if (editorEl.value && editorEl.value.innerHTML !== (props.modelValue ?? '')) {
        editorEl.value.innerHTML = props.modelValue ?? '';
    }
};

onMounted(syncFromProp);

watch(() => props.modelValue, () => {
    if (document.activeElement !== editorEl.value) {
        syncFromProp();
    }
});
</script>

<template>
    <div ref="root" class="relative">
        <!--
          v-show (not v-if) so the toolbar keeps its DOM position and the colour inputs keep their
          values between focuses. Absolutely positioned so revealing it never reflows the table row.
        -->
        <div v-if="editable" v-show="open" data-testid="rte-toolbar" role="toolbar"
             :aria-label="`${label} formatting`"
             class="absolute -top-9 left-0 z-20 flex items-center gap-0.5 rounded-md border border-line bg-panel px-1 py-1 shadow-sm">
            <button v-for="b in [
                        { cmd: 'bold', label: 'Bold', glyph: 'B', cls: 'font-bold' },
                        { cmd: 'italic', label: 'Italic', glyph: 'I', cls: 'italic' },
                        { cmd: 'underline', label: 'Underline', glyph: 'U', cls: 'underline' },
                        { cmd: 'insertUnorderedList', label: 'Bulleted list', glyph: '•', cls: '' },
                        { cmd: 'insertOrderedList', label: 'Numbered list', glyph: '1.', cls: '' },
                    ]"
                    :key="b.cmd" type="button" :data-testid="`rte-${b.cmd}`" :aria-label="b.label"
                    :title="b.label" @mousedown.prevent @click="exec(b.cmd)"
                    class="h-7 w-7 rounded text-sm text-body hover:bg-channel-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-channel"
                    :class="b.cls">
                {{ b.glyph }}
            </button>

            <span class="mx-1 h-5 w-px bg-line" aria-hidden="true"></span>

            <input type="color" data-testid="rte-foreColor" aria-label="Text colour" title="Text colour"
                   value="#b3261e"
                   class="h-7 w-7 cursor-pointer rounded border border-line bg-panel p-0.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-channel"
                   @input="exec('foreColor', $event.target.value)" @blur="onFocusOut" />
            <input type="color" data-testid="rte-hiliteColor" aria-label="Highlight colour" title="Highlight colour"
                   value="#ffff00"
                   class="h-7 w-7 cursor-pointer rounded border border-line bg-panel p-0.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-channel"
                   @input="exec('hiliteColor', $event.target.value)" @blur="onFocusOut" />
        </div>

        <div ref="editorEl" data-testid="rte-editor" :contenteditable="editable" role="textbox"
             aria-multiline="true" :aria-label="label"
             class="prose-handover min-w-[10rem] max-w-xs rounded-md border border-transparent px-1 py-0.5 text-body hover:border-line focus:border-channel focus:outline-none"
             @focus="onFocus" @blur="onFocusOut" @keydown="onKeydown" @contextmenu="onContextmenu"
             @mouseup="rememberSelection" @keyup="rememberSelection"></div>

        <!-- Restores the confirmation legacy gave as a green underline, plus the failure state it never had. -->
        <SaveStatus :status="status" testid="rte-status" />
    </div>
</template>
