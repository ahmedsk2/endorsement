import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';

import RichTextEditor from '../../resources/js/Components/RichTextEditor.vue';

/**
 * G3 — the rich-text formatting toolbar, the headline gap against legacy
 * (`picu-endorsement-patients.php:750-807`). jsdom implements neither `execCommand` nor a real
 * selection model, so these tests assert the CONTRACT the component has with the browser: which
 * command it issues, with the `styleWithCSS` bracketing legacy used, plus the accessibility
 * affordances legacy lacked.
 */
beforeEach(() => {
    document.execCommand = vi.fn(() => true);
});

const mountEditor = (props = {}) => mount(RichTextEditor, {
    props: { modelValue: '<p>Sepsis</p>', label: 'Clinical condition', ...props },
    attachTo: document.body,
});

// The commands issued, ignoring the styleWithCSS bracketing.
const commandsIssued = () => document.execCommand.mock.calls
    .map((c) => c[0])
    .filter((c) => c !== 'styleWithCSS');

describe('RichTextEditor — toolbar', () => {
    it('renders the full legacy command set', () => {
        const w = mountEditor();

        for (const cmd of ['bold', 'italic', 'underline', 'insertUnorderedList', 'insertOrderedList']) {
            expect(w.find(`[data-testid="rte-${cmd}"]`).exists()).toBe(true);
        }
        expect(w.find('[data-testid="rte-foreColor"]').exists()).toBe(true);
        expect(w.find('[data-testid="rte-hiliteColor"]').exists()).toBe(true);
    });

    it('applies bold through execCommand', async () => {
        const w = mountEditor();
        await w.get('[data-testid="rte-bold"]').trigger('click');

        expect(commandsIssued()).toContain('bold');
        // Every command is bracketed — the mode is always reset afterwards.
        expect(document.execCommand).toHaveBeenLastCalledWith('styleWithCSS', false, false);
    });

    /**
     * The load-bearing detail, verified in a real browser against the PHP sanitizer. With
     * styleWithCSS ON, Chrome emits `font-style:italic` / `text-decoration-line:underline`, and
     * NEITHER property is in the sanitizer's CSS allow-list — both are stripped to a bare <span> on
     * save, silently losing the formatting. With it OFF the same commands emit <b>/<i>/<u>, which
     * are allow-listed tags. Legacy bracketed everything with it ON and therefore lost italic and
     * underline on every write. If this test ever fails, formatting is being silently discarded.
     */
    it('emits tag markup (not CSS) for bold/italic/underline so the sanitizer keeps it', async () => {
        const w = mountEditor();

        for (const cmd of ['bold', 'italic', 'underline', 'insertUnorderedList', 'insertOrderedList']) {
            document.execCommand.mockClear();
            await w.get(`[data-testid="rte-${cmd}"]`).trigger('click');

            expect(document.execCommand).toHaveBeenNthCalledWith(1, 'styleWithCSS', false, false);
            expect(document.execCommand).toHaveBeenNthCalledWith(2, cmd, false, null);
        }
    });

    /** The colour commands are the only ones that need the CSS mode — they emit span[style]. */
    it('uses the CSS style mode for the colour commands', async () => {
        const w = mountEditor();

        document.execCommand.mockClear();
        const fore = w.get('[data-testid="rte-foreColor"]');
        fore.element.value = '#b3261e';
        await fore.trigger('input');

        expect(document.execCommand).toHaveBeenNthCalledWith(1, 'styleWithCSS', false, true);
        expect(document.execCommand).toHaveBeenNthCalledWith(2, 'foreColor', false, '#b3261e');
        expect(document.execCommand).toHaveBeenNthCalledWith(3, 'styleWithCSS', false, false);
    });

    it('applies each list command through execCommand', async () => {
        const w = mountEditor();
        await w.get('[data-testid="rte-insertUnorderedList"]').trigger('click');
        await w.get('[data-testid="rte-insertOrderedList"]').trigger('click');

        expect(commandsIssued()).toContain('insertUnorderedList');
        expect(commandsIssued()).toContain('insertOrderedList');
    });

    it('applies text and highlight colour from the colour inputs', async () => {
        const w = mountEditor();

        const fore = w.get('[data-testid="rte-foreColor"]');
        fore.element.value = '#b3261e';
        await fore.trigger('input');

        const hilite = w.get('[data-testid="rte-hiliteColor"]');
        hilite.element.value = '#ffff00';
        await hilite.trigger('input');

        expect(document.execCommand).toHaveBeenCalledWith('foreColor', false, '#b3261e');
        expect(document.execCommand).toHaveBeenCalledWith('hiliteColor', false, '#ffff00');
    });

    it('gives every toolbar control an accessible name and a non-submitting type', () => {
        const w = mountEditor();

        const buttons = w.findAll('[data-testid^="rte-"]').filter((el) => el.element.tagName === 'BUTTON');
        expect(buttons.length).toBeGreaterThanOrEqual(5);
        for (const b of buttons) {
            expect(b.attributes('aria-label')).toBeTruthy();
            // Never submit the surrounding form.
            expect(b.attributes('type')).toBe('button');
        }

        // The two colour pickers are labelled too (they are inputs, not buttons).
        expect(w.get('[data-testid="rte-foreColor"]').attributes('aria-label')).toBeTruthy();
        expect(w.get('[data-testid="rte-hiliteColor"]').attributes('aria-label')).toBeTruthy();
    });

    it('supports the Ctrl/Cmd+B/I/U keyboard shortcuts', async () => {
        const w = mountEditor();
        const editor = w.get('[data-testid="rte-editor"]');

        await editor.trigger('keydown', { key: 'b', ctrlKey: true });
        await editor.trigger('keydown', { key: 'i', ctrlKey: true });
        await editor.trigger('keydown', { key: 'u', metaKey: true });

        const issued = commandsIssued();
        expect(issued).toContain('bold');
        expect(issued).toContain('italic');
        expect(issued).toContain('underline');
    });

    it('reveals the toolbar on focus — not only on right-click as legacy did', async () => {
        const w = mountEditor();
        expect(w.get('[data-testid="rte-toolbar"]').isVisible()).toBe(false);

        await w.get('[data-testid="rte-editor"]').trigger('focus');
        expect(w.get('[data-testid="rte-toolbar"]').isVisible()).toBe(true);
    });

    it('still opens the toolbar on right-click, for legacy muscle memory', async () => {
        const w = mountEditor();
        await w.get('[data-testid="rte-editor"]').trigger('contextmenu');
        expect(w.get('[data-testid="rte-toolbar"]').isVisible()).toBe(true);
    });

    it('emits the edited markup on blur, keeping save-on-blur semantics', async () => {
        const w = mountEditor();
        const editor = w.get('[data-testid="rte-editor"]');
        editor.element.innerHTML = '<b>changed</b>';

        await editor.trigger('focus');
        await editor.trigger('blur');

        expect(w.emitted('save')).toBeTruthy();
        expect(w.emitted('save')[0][0]).toBe('<b>changed</b>');
    });

    it('renders no toolbar and is not editable without edit rights', () => {
        const w = mountEditor({ editable: false });
        expect(w.get('[data-testid="rte-editor"]').attributes('contenteditable')).toBe('false');
        expect(w.find('[data-testid="rte-toolbar"]').exists()).toBe(false);
    });
});

describe('RichTextEditor — save feedback', () => {
    it('shows a saved confirmation when the field save succeeded', () => {
        const w = mountEditor({ status: 'saved' });
        const badge = w.get('[data-testid="rte-status"]');
        expect(badge.text().toLowerCase()).toContain('saved');
        expect(badge.classes().join(' ')).toContain('ok');
    });

    it('shows an error state when the field save failed', () => {
        const w = mountEditor({ status: 'error' });
        const badge = w.get('[data-testid="rte-status"]');
        expect(badge.text().toLowerCase()).toContain('not saved');
        expect(badge.classes().join(' ')).toContain('critical');
    });

    it('shows nothing when idle', () => {
        const w = mountEditor({ status: '' });
        expect(w.find('[data-testid="rte-status"]').exists()).toBe(false);
    });
});
