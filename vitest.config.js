import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        // Two suites, one command. `tests/js` is the Vue component suite; `packages/*/test`
        // is the engine's TypeScript. A second npm script would be a second thing to forget
        // in CI, and a `.ts` file that no runner picks up reports the same green as one that
        // ran and passed — which is exactly how this include line was proved necessary.
        include: ['tests/js/**/*.test.js', 'packages/*/test/**/*.test.ts'],
        globals: true,
    },
});
