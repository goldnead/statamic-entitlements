import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';

export default defineConfig({
    // Keep classic `@media (max-width: …)` output. Without an older cssTarget the
    // minifier rewrites it to the Level-4 range syntax `(width <= …)`, which older
    // mobile browsers ignore — silently dropping the responsive rules.
    build: {
        cssTarget: ['chrome87', 'safari13.1', 'firefox78'],
    },
    plugins: [
        laravel({
            input: ['resources/js/cp.js'],
            publicDirectory: 'resources/dist',
            // Must byte-match the three values the provider hands to Statamic::vite(),
            // otherwise `npm run dev` writes a hot file the Control Panel never looks
            // at and HMR silently does nothing.
            hotFile: 'resources/dist/hot',
            refresh: true,
        }),
        // Externalises `vue` to the Control Panel runtime and resolves @statamic/cms/*
        // imports against the host CP instead of re-bundling them. Omitting it ships a
        // second Vue instance, and provide/inject then returns null across the boundary.
        statamic(),
    ],
});
