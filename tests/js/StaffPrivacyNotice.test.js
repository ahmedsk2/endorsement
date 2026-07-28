import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';

import StaffPrivacyNotice from '../../resources/js/Components/StaffPrivacyNotice.vue';

/**
 * PDPL requires that a person be informed about processing concerning them. "Activity on
 * this system is recorded" on the sign-in page is a WARNING — it says something happens
 * without saying what, for how long, or who to ask.
 *
 * The notice is one component used in two places (invitation acceptance and first-login
 * setup) precisely so the two cannot drift; a notice maintained in two copies will
 * eventually say two different things, and the one nobody updated is the one somebody
 * relied on. These pin the three facts it has to state.
 */
describe('staff privacy notice', () => {
    it('states what is recorded, for how long, and that the log cannot be altered', () => {
        const text = mount(StaffPrivacyNotice).text();

        expect(text).toContain('What this system records about you');
        // The signature on a handover — the part clinicians are most surprised by.
        expect(text).toMatch(/signature/i);
        // Which actions, not merely "activity".
        expect(text).toMatch(/viewing, printing, editing, signing and reopening/i);
        // How long, and that it is tamper-evident even against an administrator.
        expect(text).toMatch(/12 months/i);
        expect(text).toMatch(/including by an administrator/i);
        // Who to ask.
        expect(text).toMatch(/ask an administrator/i);
    });

    it('frames the moment differently before the account exists', () => {
        const invitation = mount(StaffPrivacyNotice, { props: { context: 'invitation' } }).text();
        const setup = mount(StaffPrivacyNotice, { props: { context: 'setup' } }).text();

        expect(invitation).toContain('Before you create your account');
        expect(setup).not.toContain('Before you create your account');
    });
});
