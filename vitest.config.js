import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        // The two Control Panel pages import from the host CP at runtime, which
        // does not exist in a unit test. The stubs stand in for it — the point of
        // this suite is the pages' own logic, not core's components.
        alias: {
            '@statamic/cms/ui': fileURLToPath(new URL('./tests/js/stubs/ui.js', import.meta.url)),
            '@statamic/cms/inertia': fileURLToPath(new URL('./tests/js/stubs/inertia.js', import.meta.url)),
            '@statamic/cms/api': fileURLToPath(new URL('./tests/js/stubs/api.js', import.meta.url)),
        },
    },
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/setup.js'],
    },
});
