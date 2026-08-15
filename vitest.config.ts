import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

/*
 * Kept apart from vite.config.ts on purpose: the build config is what ships,
 * and a test environment, a jsdom dependency and a spec glob have no business
 * being read every time somebody builds the production bundle.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            // Root-relative rather than resolved through `node:url`, which
            // would pull @types/node into a tsconfig that deliberately types
            // this project as browser-only.
            '@': '/resources/js',
        },
    },
    test: {
        environment: 'jsdom',
        setupFiles: ['tests/js/support/environment.ts'],
        include: ['tests/js/**/*.spec.ts'],
        restoreMocks: true,
    },
});
