import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            // Never source: compiled views, and the temporary files LibreOffice makes while exporting a note
            // (one vanishing mid-scan crashes the watcher on Windows).
            ignored: ['**/storage/**'],
        },
    },
});
