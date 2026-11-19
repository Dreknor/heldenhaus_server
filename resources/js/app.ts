import '@fontsource/atkinson-hyperlegible/400.css';
import '@fontsource/atkinson-hyperlegible/700.css';
import '@fontsource/pixelify-sans/500.css';
import '@fontsource/pixelify-sans/700.css';
import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useAppearance';
import { applyDisplay } from './lib/display';
import type { Display } from './types';

const appName = import.meta.env.VITE_APP_NAME || 'Heldenhaus';

type DisplayProps = { auth?: { display?: Display | null } };

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        applyDisplay((props.initialPage.props as DisplayProps).auth?.display);
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// Darstellung (Theme, Kontrast, Schrift, Bewegung) nach jedem Seitenwechsel und Speichern neu anwenden
// (Teil-Reloads ohne `auth` lassen die aktuelle Darstellung stehen)
router.on('success', (event) => {
    const auth = (event.detail.page.props as DisplayProps).auth;
    if (auth && auth.display !== undefined) applyDisplay(auth.display);
});

// This will set light / dark mode on page load...
initializeTheme();

// PWA: Service Worker cacht nur die App-Hülle (public/sw.js)
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Ohne Service Worker läuft die Seite ganz normal weiter
        });
    });
}
