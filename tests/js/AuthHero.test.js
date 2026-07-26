import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

import AuthHero from '../../resources/js/Components/AuthHero.vue';

/**
 * Lets each test decide what the browser says about reduced motion. jsdom has no
 * matchMedia at all, so without this every component that asks the question throws.
 */
const setReducedMotion = (reduce, { finePointer = true } = {}) => {
    window.matchMedia = vi.fn().mockImplementation((query) => ({
        // Describes a desktop by default: fine pointer, hover available. The component
        // requires BOTH — no reduced-motion preference and a real pointer — before it
        // listens, so a mock that answers false to everything looks like a phone.
        matches: query.includes('prefers-reduced-motion') ? reduce : finePointer,
        media: query,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
    }));
};

describe('AuthHero', () => {
    beforeEach(() => {
        setReducedMotion(false);
        vi.spyOn(window, 'addEventListener');
        vi.spyOn(window, 'removeEventListener');
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('draws the built-in dawn illustration when no image has been generated yet', () => {
        // The page must be complete and shippable before any 1min.ai asset exists.
        const wrapper = mount(AuthHero);

        expect(wrapper.find('[data-testid="hero-fallback"]').exists()).toBe(true);
        expect(wrapper.find('img').exists()).toBe(false);
    });

    it('uses the generated image once one is supplied', () => {
        const wrapper = mount(AuthHero, { props: { src: '/img/auth-dawn.webp' } });

        const img = wrapper.find('img');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toBe('/img/auth-dawn.webp');
        // Above the fold: reserve space so the curve does not jump when it loads.
        expect(img.attributes('width')).toBeTruthy();
        expect(img.attributes('height')).toBeTruthy();
        expect(wrapper.find('[data-testid="hero-fallback"]').exists()).toBe(false);
    });

    it('hides the decorative curve from assistive technology', () => {
        const wrapper = mount(AuthHero);

        // The curve carries no information — it is the shape of the form surface.
        expect(wrapper.find('[data-testid="hero-curve"]').attributes('aria-hidden')).toBe('true');
    });

    it('tracks the pointer to give the illustration depth', async () => {
        const wrapper = mount(AuthHero, { attachTo: document.body });

        const listened = window.addEventListener.mock.calls.map(([event]) => event);
        expect(listened).toContain('pointermove');

        wrapper.unmount();
    });

    it('does not move at all when the visitor asks for reduced motion', () => {
        // The global CSS block only neutralises transitions and animations. This movement
        // is a transform written from JS, so it has to be refused here or it still runs.
        setReducedMotion(true);

        mount(AuthHero, { attachTo: document.body });

        const listened = window.addEventListener.mock.calls.map(([event]) => event);
        expect(listened).not.toContain('pointermove');
    });

    it('stops listening when it goes away', () => {
        const wrapper = mount(AuthHero, { attachTo: document.body });
        wrapper.unmount();

        const removed = window.removeEventListener.mock.calls.map(([event]) => event);
        expect(removed).toContain('pointermove');
    });

    it('stays still on a touch device, where there is no pointer to follow', () => {
        setReducedMotion(false, { finePointer: false });

        mount(AuthHero, { attachTo: document.body });

        const listened = window.addEventListener.mock.calls.map(([event]) => event);
        expect(listened).not.toContain('pointermove');
    });
});
