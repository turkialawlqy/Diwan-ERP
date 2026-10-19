import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import i18n from './i18n';
import VueApexCharts from 'vue3-apexcharts';
import { PermissionsPlugin } from './permissions';
import PromptDialog from './Components/UI/PromptDialog.vue';

const appName = import.meta.env.VITE_APP_NAME || 'الأستاذ';

createInertiaApp({
    title: (title) => title ? `${title} - ${appName}` : appName,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        // PromptDialog is the single host for ask() / confirmAction() / notify().
        const app = createApp({ render: () => [h(App, props), h(PromptDialog)] });
        
        // Sync locale with backend
        if (props.initialPage?.props?.locale) {
            i18n.global.locale.value = props.initialPage.props.locale;
        }

        return app
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n)
            .use(VueApexCharts)
            .use(PermissionsPlugin)
            .mount(el);
    },
    progress: {
        color: '#4F46E5', // Al-Ostaz Indigo brand color
        showSpinner: true,
    },
});
