import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/import-products.js'
            ],
            refresh: true,
        }),
    ],
    // server: {
    //     https: true,
    //     host: '0.0.0.0',
    //     port: 8000,
    //     hmr: {
    //         host: 'localhost',
    //     },
    // },
});