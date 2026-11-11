import React from 'react';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    resolve: (name) => {
        const familyPages = import.meta.glob('./pages/Family/**/*.jsx');
        const familyPage = familyPages[`./pages/${name}.jsx`];
        if (familyPage) {
            return familyPage().then((page) => page.default);
        }

        const pages = import.meta.glob(['./pages/**/*.jsx', '!./pages/Family/**/*.jsx'], {
            eager: true,
        });

        const page = pages[`./pages/${name}.jsx`];

        if (!page) {
            throw new Error(`Inertia page not found: ${name}`);
        }

        return page.default;
    },

    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
