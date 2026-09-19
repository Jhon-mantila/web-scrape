import '../css/app.css';
import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import PipelineRunOverlay from '@/Components/PipelineRunOverlay.vue';
import { applyBackgroundSnapshot, setPanelCollapsed } from '@/stores/backgroundRunStore.js';
import { backgroundRunSnapshotFromPage } from '@/support/backgroundRun.js';

const appName = import.meta.env.VITE_APP_NAME || 'Esquina AI';

function syncBackgroundRunFromPage(page) {
    const snapshot = backgroundRunSnapshotFromPage(page);

    if (snapshot?.runs?.length) {
        applyBackgroundSnapshot(snapshot);
        setPanelCollapsed(false);
    }
}

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const vueApp = createApp({
            render: () =>
                h('div', { class: 'contents' }, [h(App, props), h(PipelineRunOverlay)]),
        });

        vueApp.use(plugin);
        vueApp.use(ZiggyVue);

        router.on('success', (event) => {
            syncBackgroundRunFromPage(event.detail.page);
        });

        vueApp.mount(el);
    },
    progress: {
        color: '#c084fc',
        includeCSS: true,
        showSpinner: true,
    },
});
