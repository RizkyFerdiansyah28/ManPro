import React from 'react';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.jsx', {
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
