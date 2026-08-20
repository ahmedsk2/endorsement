import { fileURLToPath } from 'node:url';

import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Fonts are self-hosted via @fontsource (imported in resources/css/app.css) rather
            // than fetched from a CDN — hospital networks may be restricted or offline.
        }),
        vue(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            // `@engine` — the pure conditions engine (packages/engine, P2).
            //
            // The alias is here BEFORE anything imports it, deliberately. D4 gives the engine two
            // runtimes and this is the browser one: a package the bundler cannot resolve is not a
            // browser runtime at all, and P3's workbench discovering that while wiring live hints
            // would be discovering it at the worst possible moment. It costs one entry now.
            //
            // It points at the TypeScript source rather than at a build output because the package
            // emits nothing — `tsconfig.base.json` sets `noEmit`, Vite transpiles the sources it
            // bundles, and a dist/ step would be a second artifact that can disagree with the one
            // `npm test` and `tsc --noEmit` actually read.
            '@engine': fileURLToPath(new URL('./packages/engine/src/index.ts', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
