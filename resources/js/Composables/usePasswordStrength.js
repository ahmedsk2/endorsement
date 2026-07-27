import { computed } from 'vue';

/*
 * The live password checklist and strength meter, shared by every form that sets a password.
 *
 * These four requirements MIRROR THE SERVER RULE — App\Support\PasswordPolicy, which is
 * Password::min(8)->mixedCase()->numbers()->symbols(). A checklist the server disagrees with
 * is worse than none: it either blocks a password the server would accept, or promises one
 * it will reject. Change them together or not at all.
 *
 * It lives here rather than in the registration page because there are now two such forms —
 * registration and invitation acceptance — and a second copy of a rule is a rule that will
 * eventually disagree with itself.
 */
export const requirements = [
    { key: 'length', label: 'At least 8 characters', test: (p) => p.length >= 8 },
    { key: 'case', label: 'Upper and lower case letters', test: (p) => /[a-z]/.test(p) && /[A-Z]/.test(p) },
    { key: 'number', label: 'At least one number', test: (p) => /\d/.test(p) },
    { key: 'symbol', label: 'At least one symbol (e.g. ! - @ #)', test: (p) => /[^A-Za-z0-9]/.test(p) },
];

/**
 * @param {() => string} read  getter for the current password
 * @param {() => string} readConfirmation  getter for the confirmation field
 */
export function usePasswordStrength(read, readConfirmation) {
    const met = computed(() => requirements.map((r) => r.test(read())));
    const metCount = computed(() => met.value.filter(Boolean).length);

    /*
     * Strength = how many requirements are met, plus a top tier for length >= 12.
     * Deliberately simple and explainable — the meter and the checklist always agree,
     * which is what makes the meter trustworthy rather than mysterious.
     */
    const strength = computed(() => {
        if (read() === '') {
            return null;
        }
        if (metCount.value === 4 && read().length >= 12) {
            return { label: 'Very strong', tone: 'ok' };
        }

        return [
            { label: 'Weak', tone: 'critical' },
            { label: 'Weak', tone: 'critical' },
            { label: 'Fair', tone: 'caution' },
            { label: 'Good', tone: 'caution' },
            { label: 'Strong', tone: 'ok' },
        ][metCount.value];
    });

    const segmentClass = (index) => {
        // Math.max keeps ONE segment lit for a non-empty password that meets nothing yet,
        // so the meter reacts to the first keystroke instead of staying blank and looking
        // broken. Kept verbatim from the registration form this was extracted from.
        if (strength.value === null || index >= Math.max(metCount.value, read() ? 1 : 0)) {
            return 'bg-line-soft';
        }

        return { critical: 'bg-critical', caution: 'bg-caution', ok: 'bg-ok' }[strength.value.tone];
    };

    // Live match state, visible BEFORE the form is ever submitted.
    const bothEntered = computed(() => read() !== '' && readConfirmation() !== '');
    const mismatch = computed(() => bothEntered.value && read() !== readConfirmation());
    const matches = computed(() => bothEntered.value && read() === readConfirmation());

    return { requirements, met, metCount, strength, segmentClass, mismatch, matches };
}
