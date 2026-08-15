import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

/**
 * The Inertia entrypoint.
 *
 * Pages are resolved eagerly rather than lazily. SaniTube is an internal tool
 * behind a login on installations that are frequently on modest shared
 * hosting: one slightly larger bundle fetched once beats a network round trip
 * on every navigation, which is what a code-split admin interface feels like
 * over a slow connection.
 *
 * `AppLayout` is applied here as a persistent layout, so the sidebar is not
 * re-created — and scroll position not lost — on every page change.
 */
createInertiaApp({
    title: (title) => (title ? `${title} — ${document.title}` : document.title),

    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];

        if (!page) {
            throw new Error(`Inertia page [${name}] was not found.`);
        }

        page.default.layout = page.default.layout ?? AppLayout;

        return page;
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: {
        // Matches the accent token. A progress bar in a different colour from
        // the rest of the interface is the first thing that looks bolted on.
        color: '#5b5bd6',
        showSpinner: false,
    },
});
