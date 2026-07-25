import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import { reactive } from 'vue';

/**
 * Registration-page password UX: a live requirements checklist (red -> green), a strength
 * meter, and a "passwords do not match" label that appears BEFORE submitting. The checklist
 * mirrors the SERVER rule exactly (Password::min(8)->mixedCase()->numbers()->symbols()) —
 * a checklist the server disagrees with is worse than none.
 */
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div><slot /></div>' },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (fields) => reactive({
        ...fields,
        errors: {},
        processing: false,
        post: vi.fn(),
        reset: vi.fn(),
    }),
}));

import Register from '../../resources/js/Pages/Auth/Register.vue';

const mountPage = () => mount(Register, { props: { positions: { 4: 'Resident' } } });

const setPassword = async (w, value) => {
    await w.get('[data-testid="password"]').setValue(value);
};

const setConfirmation = async (w, value) => {
    await w.get('[data-testid="password-confirmation"]').setValue(value);
};

describe('Register — password requirements checklist', () => {
    it('lists the four server requirements, all unmet (red) before typing', () => {
        const w = mountPage();
        const items = w.findAll('[data-testid^="pw-req-"]');

        expect(items).toHaveLength(4);
        for (const item of items) {
            expect(item.classes()).toContain('text-critical');
        }
    });

    it('turns each requirement green exactly as it is fulfilled', async () => {
        const w = mountPage();

        await setPassword(w, 'abc');
        expect(w.get('[data-testid="pw-req-length"]').classes()).toContain('text-critical');
        expect(w.get('[data-testid="pw-req-case"]').classes()).toContain('text-critical');

        await setPassword(w, 'Abcdefgh');
        expect(w.get('[data-testid="pw-req-length"]').classes()).toContain('text-ok');
        expect(w.get('[data-testid="pw-req-case"]').classes()).toContain('text-ok');
        expect(w.get('[data-testid="pw-req-number"]').classes()).toContain('text-critical');
        expect(w.get('[data-testid="pw-req-symbol"]').classes()).toContain('text-critical');

        await setPassword(w, 'Abcdefg1!');
        for (const item of w.findAll('[data-testid^="pw-req-"]')) {
            expect(item.classes()).toContain('text-ok');
        }
    });
});

describe('Register — strength meter', () => {
    it('fills one segment per met requirement and names the tier', async () => {
        const w = mountPage();

        await setPassword(w, 'abcdefgh'); // length + lowercase only -> 1 requirement short of case
        expect(w.get('[data-testid="pw-strength-label"]').text()).toBe('Weak');

        await setPassword(w, 'Abcdefgh1'); // length + case + number
        expect(w.get('[data-testid="pw-strength-label"]').text()).toBe('Good');
        expect(w.findAll('[data-testid="pw-meter-segment"].bg-caution')).toHaveLength(3);

        await setPassword(w, 'Abcdefg1!'); // all four
        expect(w.get('[data-testid="pw-strength-label"]').text()).toBe('Strong');
        expect(w.findAll('[data-testid="pw-meter-segment"].bg-ok')).toHaveLength(4);

        await setPassword(w, 'Abcdefg1!extra'); // all four + 12+ chars
        expect(w.get('[data-testid="pw-strength-label"]').text()).toBe('Very strong');
    });

    it('shows no tier label before typing', () => {
        expect(mountPage().find('[data-testid="pw-strength-label"]').exists()).toBe(false);
    });
});

describe('Register — live confirmation match', () => {
    it('says nothing while the confirmation is empty', async () => {
        const w = mountPage();
        await setPassword(w, 'Abcdefg1!');

        expect(w.find('[data-testid="pw-mismatch"]').exists()).toBe(false);
    });

    it('flags a mismatch before the form is ever submitted', async () => {
        const w = mountPage();
        await setPassword(w, 'Abcdefg1!');
        await setConfirmation(w, 'Abcdefg1?');

        const label = w.get('[data-testid="pw-mismatch"]');
        expect(label.text().toLowerCase()).toContain('do not match');
        expect(label.attributes('role')).toBe('alert');
    });

    it('clears the flag the moment they match', async () => {
        const w = mountPage();
        await setPassword(w, 'Abcdefg1!');
        await setConfirmation(w, 'Abcdefg1?');
        await setConfirmation(w, 'Abcdefg1!');

        expect(w.find('[data-testid="pw-mismatch"]').exists()).toBe(false);
        expect(w.get('[data-testid="pw-match"]').text().toLowerCase()).toContain('match');
    });
});
